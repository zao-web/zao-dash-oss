<?php

namespace App\Services\Tax;

use App\Models\Contractor1099Data;
use App\Models\QuickBooksConnection;
use App\Models\TaxCalendarEvent;
use App\Models\TaxEstimate;
use App\Models\TaxStrategy;
use App\Services\QuickBooks\QuickBooksApiService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaxCalculationService
{
    protected QuickBooksApiService $qboService;

    // 2024 Tax Constants
    protected const STANDARD_DEDUCTION_SINGLE = 14600;

    protected const STANDARD_DEDUCTION_MFJ = 29200;

    protected const SE_TAX_RATE = 0.153;

    protected const SE_DEDUCTION_RATE = 0.5;

    protected const QBI_DEDUCTION_RATE = 0.20;

    protected const THRESHOLD_1099 = 600;

    // State tax rates (simplified - major states only)
    protected const STATE_TAX_RATES = [
        'CA' => 0.093, // California top marginal
        'NY' => 0.0882,
        'TX' => 0.00, // No state income tax
        'FL' => 0.00,
        'WA' => 0.00,
        'NV' => 0.00,
        'IL' => 0.0495,
        'PA' => 0.0307,
        'OH' => 0.04,
        'GA' => 0.055,
        'NC' => 0.0525,
        'MI' => 0.0425,
        'NJ' => 0.0897,
        'VA' => 0.0575,
        'MA' => 0.05,
        'AZ' => 0.045,
        'CO' => 0.044,
    ];

    public function __construct(QuickBooksApiService $qboService)
    {
        $this->qboService = $qboService;
    }

    // =========================================================================
    // QUARTERLY ESTIMATES
    // =========================================================================

    /**
     * Calculate quarterly estimated tax payment.
     */
    public function calculateQuarterlyEstimate(
        QuickBooksConnection $connection,
        int $quarter,
        int $year,
        string $entityType = 's_corp',
        ?string $stateCode = null
    ): TaxEstimate {
        $ytdData = $this->getYtdFinancials($connection, $year, $quarter);
        $projected = $this->projectAnnualIncome($ytdData, $quarter);

        // Calculate taxes based on entity type
        $taxCalc = match ($entityType) {
            's_corp' => $this->calculateSCorpTax($projected['net_income'], $stateCode),
            'llc', 'sole_prop' => $this->calculateSoleProprietorTax($projected['net_income'], $stateCode),
            'c_corp' => $this->calculateCCorpTax($projected['net_income'], $stateCode),
            default => $this->calculateSCorpTax($projected['net_income'], $stateCode),
        };

        // Get YTD estimated payments already made
        $ytdPayments = $this->getYtdEstimatedPayments($connection, $year);

        // Calculate quarterly payment due
        $quarterlyDue = $this->calculateQuarterlyPaymentDue(
            $taxCalc['total_tax'],
            $ytdPayments,
            $quarter
        );

        // Create or update estimate record
        return TaxEstimate::updateOrCreate(
            [
                'qbo_connection_id' => $connection->id,
                'tax_year' => $year,
                'quarter' => $quarter,
            ],
            [
                'calculation_date' => now(),
                'ytd_gross_income' => $ytdData['gross_income'],
                'ytd_deductions' => $ytdData['deductions'],
                'ytd_net_income' => $ytdData['net_income'],
                'projected_annual_income' => $projected['net_income'],
                'projected_annual_tax' => $taxCalc['total_tax'],
                'quarterly_payment_due' => $quarterlyDue,
                'ytd_payments_made' => $ytdPayments,
                'calculation_breakdown' => [
                    'ytd' => $ytdData,
                    'projected' => $projected,
                    'tax_calc' => $taxCalc,
                ],
                'entity_type' => $entityType,
                'effective_tax_rate' => $taxCalc['effective_rate'],
                'self_employment_tax' => $taxCalc['se_tax'] ?? 0,
                'state_code' => $stateCode,
                'state_tax_estimate' => $taxCalc['state_tax'] ?? 0,
            ]
        );
    }

    /**
     * Get YTD financials from QuickBooks.
     */
    public function getYtdFinancials(QuickBooksConnection $connection, int $year, int $quarter): array
    {
        $startDate = "{$year}-01-01";
        $endDate = $this->getQuarterEndDate($year, $quarter);

        $pnl = $this->qboService->getProfitAndLoss($connection, $startDate, $endDate);

        // Parse P&L report
        $grossIncome = $this->extractPnlValue($pnl, 'TotalIncome') ?? 0;
        $totalExpenses = $this->extractPnlValue($pnl, 'TotalExpenses') ?? 0;
        $netIncome = $this->extractPnlValue($pnl, 'NetIncome') ?? ($grossIncome - $totalExpenses);

        return [
            'gross_income' => (float) $grossIncome,
            'deductions' => (float) $totalExpenses,
            'net_income' => (float) $netIncome,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'months_elapsed' => $quarter * 3,
        ];
    }

    /**
     * Project annual income based on YTD.
     */
    public function projectAnnualIncome(array $ytdData, int $quarter): array
    {
        $monthsElapsed = $quarter * 3;
        $projectionFactor = 12 / $monthsElapsed;

        return [
            'gross_income' => round($ytdData['gross_income'] * $projectionFactor, 2),
            'deductions' => round($ytdData['deductions'] * $projectionFactor, 2),
            'net_income' => round($ytdData['net_income'] * $projectionFactor, 2),
            'projection_factor' => $projectionFactor,
            'confidence' => $this->getProjectionConfidence($quarter),
        ];
    }

    /**
     * Calculate S-Corp tax (no SE tax on distributions, just income tax).
     */
    public function calculateSCorpTax(float $netIncome, ?string $stateCode = null): array
    {
        // S-Corp owner pays income tax on their share, but NOT self-employment tax on distributions
        // Assume reasonable salary is already deducted as expense

        // QBI deduction (20% of qualified business income for pass-through entities)
        $qbiDeduction = min($netIncome * self::QBI_DEDUCTION_RATE, $netIncome);
        $taxableIncome = max(0, $netIncome - self::STANDARD_DEDUCTION_SINGLE - $qbiDeduction);

        $federalTax = TaxEstimate::calculateFederalTax($taxableIncome);
        $stateTax = $this->calculateStateTax($netIncome, $stateCode);

        $totalTax = $federalTax + $stateTax;
        $effectiveRate = $netIncome > 0 ? ($totalTax / $netIncome) * 100 : 0;

        return [
            'gross_income' => $netIncome,
            'qbi_deduction' => round($qbiDeduction, 2),
            'standard_deduction' => self::STANDARD_DEDUCTION_SINGLE,
            'taxable_income' => round($taxableIncome, 2),
            'federal_tax' => round($federalTax, 2),
            'state_tax' => round($stateTax, 2),
            'se_tax' => 0,
            'total_tax' => round($totalTax, 2),
            'effective_rate' => round($effectiveRate, 2),
            'entity_type' => 's_corp',
        ];
    }

    /**
     * Calculate Sole Proprietor/LLC tax (includes SE tax).
     */
    public function calculateSoleProprietorTax(float $netIncome, ?string $stateCode = null): array
    {
        // Self-employment tax calculation
        $seCalc = TaxEstimate::calculateSelfEmploymentTax($netIncome);

        // QBI deduction
        $qbiDeduction = min($netIncome * self::QBI_DEDUCTION_RATE, $netIncome);

        // Taxable income after deductions
        $taxableIncome = max(0, $netIncome - self::STANDARD_DEDUCTION_SINGLE - $qbiDeduction - $seCalc['deduction']);

        $federalTax = TaxEstimate::calculateFederalTax($taxableIncome);
        $stateTax = $this->calculateStateTax($netIncome, $stateCode);

        $totalTax = $federalTax + $seCalc['tax'] + $stateTax;
        $effectiveRate = $netIncome > 0 ? ($totalTax / $netIncome) * 100 : 0;

        return [
            'gross_income' => $netIncome,
            'qbi_deduction' => round($qbiDeduction, 2),
            'se_deduction' => round($seCalc['deduction'], 2),
            'standard_deduction' => self::STANDARD_DEDUCTION_SINGLE,
            'taxable_income' => round($taxableIncome, 2),
            'federal_tax' => round($federalTax, 2),
            'state_tax' => round($stateTax, 2),
            'se_tax' => round($seCalc['tax'], 2),
            'total_tax' => round($totalTax, 2),
            'effective_rate' => round($effectiveRate, 2),
            'entity_type' => 'sole_prop',
        ];
    }

    /**
     * Calculate C-Corp tax (flat 21% corporate rate).
     */
    public function calculateCCorpTax(float $netIncome, ?string $stateCode = null): array
    {
        $corporateRate = 0.21;
        $federalTax = $netIncome * $corporateRate;
        $stateTax = $this->calculateStateTax($netIncome, $stateCode, 'c_corp');

        $totalTax = $federalTax + $stateTax;
        $effectiveRate = $netIncome > 0 ? ($totalTax / $netIncome) * 100 : 0;

        return [
            'gross_income' => $netIncome,
            'taxable_income' => round($netIncome, 2),
            'federal_tax' => round($federalTax, 2),
            'state_tax' => round($stateTax, 2),
            'se_tax' => 0,
            'total_tax' => round($totalTax, 2),
            'effective_rate' => round($effectiveRate, 2),
            'entity_type' => 'c_corp',
        ];
    }

    protected function calculateStateTax(float $income, ?string $stateCode, string $entityType = 's_corp'): float
    {
        if (! $stateCode || ! isset(self::STATE_TAX_RATES[$stateCode])) {
            return 0;
        }

        // Simplified: apply flat state rate
        return $income * self::STATE_TAX_RATES[$stateCode];
    }

    protected function calculateQuarterlyPaymentDue(float $totalAnnualTax, float $ytdPayments, int $quarter): float
    {
        // Safe harbor: pay 100% of prior year tax or 90% of current year
        // Simplified: divide total by 4 quarters, minus what's been paid
        $quarterlyAmount = $totalAnnualTax / 4;
        $shouldHavePaid = $quarterlyAmount * $quarter;

        return max(0, round($shouldHavePaid - $ytdPayments, 2));
    }

    protected function getYtdEstimatedPayments(QuickBooksConnection $connection, int $year): float
    {
        return \App\Models\EstimatedTaxPayment::ytdPayments($connection->user_id, $year, 'federal');
    }

    // =========================================================================
    // 1099 VENDOR DATA
    // =========================================================================

    /**
     * Get vendor payments requiring 1099s.
     */
    public function get1099VendorPayments(QuickBooksConnection $connection, int $year): array
    {
        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";

        // Get all vendor payments from QBO
        $vendors = $this->qboService->getVendors($connection);
        $vendorPayments = [];

        foreach ($vendors as $vendor) {
            // Get vendor's transactions for the year
            $totalPaid = $this->getVendorPaymentsTotal($connection, $vendor['Id'], $startDate, $endDate);

            if ($totalPaid > 0) {
                $vendorPayments[] = [
                    'vendor_id' => $vendor['Id'],
                    'vendor_name' => $vendor['DisplayName'],
                    'vendor_type' => $vendor['Vendor1099'] ?? false ? '1099Vendor' : 'Regular',
                    'total_payments' => $totalPaid,
                    'requires_1099' => $totalPaid >= self::THRESHOLD_1099,
                ];
            }
        }

        // Sync to local database
        Contractor1099Data::syncFromQbo($connection, $year, $vendorPayments);

        return $vendorPayments;
    }

    protected function getVendorPaymentsTotal(QuickBooksConnection $connection, string $vendorId, string $startDate, string $endDate): float
    {
        // Query purchases/bills for this vendor
        $sql = "SELECT * FROM Purchase WHERE EntityRef = '{$vendorId}' AND TxnDate >= '{$startDate}' AND TxnDate <= '{$endDate}'";
        try {
            $result = $this->qboService->query($connection, $sql);
            $total = 0;
            foreach ($result['Purchase'] ?? [] as $purchase) {
                $total += $purchase['TotalAmt'] ?? 0;
            }

            return $total;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Generate 1099 filing data.
     */
    public function generate1099Data(QuickBooksConnection $connection, int $year): array
    {
        $vendors = Contractor1099Data::where('qbo_connection_id', $connection->id)
            ->where('tax_year', $year)
            ->where('requires_1099', true)
            ->get();

        $readyCount = $vendors->where('status', Contractor1099Data::STATUS_READY)->count();
        $needsW9Count = $vendors->where('status', Contractor1099Data::STATUS_W9_NEEDED)->count();

        return [
            'tax_year' => $year,
            'total_vendors' => $vendors->count(),
            'ready_to_file' => $readyCount,
            'needs_w9' => $needsW9Count,
            'total_payments' => $vendors->sum('total_payments'),
            'vendors' => $vendors->map(fn ($v) => [
                'name' => $v->vendor_name,
                'amount' => $v->total_payments,
                'status' => $v->status,
                'has_w9' => $v->has_w9,
                'tin_masked' => $v->masked_tin,
            ])->toArray(),
            'deadline' => ($year + 1).'-01-31',
            'days_until_deadline' => now()->diffInDays(Carbon::parse(($year + 1).'-01-31'), false),
        ];
    }

    // =========================================================================
    // TAX STRATEGIES
    // =========================================================================

    /**
     * Evaluate applicable tax strategies for the business.
     */
    public function evaluateTaxStrategies(QuickBooksConnection $connection, int $year): Collection
    {
        $ytdData = $this->getYtdFinancials($connection, $year, $this->getCurrentQuarter());
        $projected = $this->projectAnnualIncome($ytdData, $this->getCurrentQuarter());

        $strategies = collect();

        // 1. S-Corp Salary Optimization
        $salaryStrategy = $this->evaluateSalaryOptimization($projected['net_income']);
        if ($salaryStrategy) {
            $strategies->push($this->createOrUpdateStrategy($connection, $year, $salaryStrategy));
        }

        // 2. Retirement Contributions
        $retirementStrategy = $this->evaluateRetirementContributions($projected['net_income']);
        if ($retirementStrategy) {
            $strategies->push($this->createOrUpdateStrategy($connection, $year, $retirementStrategy));
        }

        // 3. Section 179 Deductions
        $section179Strategy = $this->evaluateSection179($connection, $year);
        if ($section179Strategy) {
            $strategies->push($this->createOrUpdateStrategy($connection, $year, $section179Strategy));
        }

        // 4. Year-end timing strategies
        if ($this->getCurrentQuarter() >= 3) {
            $timingStrategy = $this->evaluateYearEndTiming($connection, $projected);
            if ($timingStrategy) {
                $strategies->push($this->createOrUpdateStrategy($connection, $year, $timingStrategy));
            }
        }

        return $strategies;
    }

    protected function evaluateSalaryOptimization(float $projectedIncome): ?array
    {
        // Only relevant if income is substantial
        if ($projectedIncome < 50000) {
            return null;
        }

        // S-Corp salary optimization: pay ~40% as salary, rest as distribution
        $optimalSalary = $projectedIncome * 0.40;
        $currentSeTax = $projectedIncome * 0.153 * 0.9235;
        $optimizedSeTax = $optimalSalary * 0.153;
        $savings = $currentSeTax - $optimizedSeTax;

        if ($savings < 1000) {
            return null;
        }

        return [
            'strategy_type' => TaxStrategy::TYPE_SALARY_OPTIMIZATION,
            'title' => 'S-Corp Salary Optimization',
            'description' => sprintf(
                'By structuring as S-Corp with %s reasonable salary, you could save ~%s in self-employment taxes annually. '.
                'The remaining %s would be taken as distributions, avoiding SE tax.',
                '$'.number_format($optimalSalary),
                '$'.number_format($savings),
                '$'.number_format($projectedIncome - $optimalSalary)
            ),
            'estimated_savings' => $savings,
            'timing_sensitivity' => TaxStrategy::TIMING_FLEXIBLE,
            'complexity' => TaxStrategy::COMPLEXITY_MEDIUM,
            'requirements' => ['S-Corp election or conversion', 'Payroll setup', 'Reasonable compensation analysis'],
            'action_items' => [
                'Consult with CPA on reasonable salary determination',
                'File Form 2553 if not already S-Corp',
                'Set up payroll with Gusto or similar',
                'Process regular salary payments with payroll taxes',
            ],
        ];
    }

    protected function evaluateRetirementContributions(float $projectedIncome): ?array
    {
        if ($projectedIncome < 30000) {
            return null;
        }

        // Solo 401(k) limits for 2024
        $employeeLimit = 23000;
        $totalLimit = 69000;
        $employerContribution = min($projectedIncome * 0.25, $totalLimit - $employeeLimit);
        $maxContribution = min($employeeLimit + $employerContribution, $totalLimit);

        // Tax savings at estimated 30% effective rate
        $savings = $maxContribution * 0.30;

        return [
            'strategy_type' => TaxStrategy::TYPE_RETIREMENT_CONTRIBUTION,
            'title' => 'Maximize Solo 401(k) Contributions',
            'description' => sprintf(
                'You could contribute up to %s to a Solo 401(k) this year (%s employee + %s employer match), '.
                'reducing taxable income and saving approximately %s in taxes.',
                '$'.number_format($maxContribution),
                '$'.number_format($employeeLimit),
                '$'.number_format($employerContribution),
                '$'.number_format($savings)
            ),
            'estimated_savings' => $savings,
            'timing_sensitivity' => TaxStrategy::TIMING_YEAR_END,
            'complexity' => TaxStrategy::COMPLEXITY_LOW,
            'requirements' => ['Self-employed or S-Corp owner', 'No full-time employees (other than spouse)'],
            'action_items' => [
                'Open Solo 401(k) if not already established (deadline: Dec 31)',
                'Calculate maximum contribution based on net self-employment income',
                'Make contributions before tax filing deadline (can extend to April 15 or Oct 15 with extension)',
            ],
        ];
    }

    protected function evaluateSection179($connection, int $year): ?array
    {
        // Check for equipment purchases
        // This would need actual asset data from QBO

        return [
            'strategy_type' => TaxStrategy::TYPE_SECTION_179,
            'title' => 'Section 179 Equipment Deduction',
            'description' => 'If you\'re planning any equipment or software purchases, Section 179 allows you to deduct the full cost '.
                'in the year of purchase (up to $1,160,000 for 2024) instead of depreciating over multiple years. '.
                'This includes computers, software, office furniture, and vehicles used for business.',
            'estimated_savings' => 0, // Depends on purchases
            'timing_sensitivity' => TaxStrategy::TIMING_YEAR_END,
            'complexity' => TaxStrategy::COMPLEXITY_LOW,
            'requirements' => ['Business equipment purchases needed', 'Sufficient business income'],
            'action_items' => [
                'Identify any needed equipment or software purchases',
                'Complete purchases before Dec 31 to claim this year',
                'Ensure equipment is placed in service before year end',
                'Document business use percentage for mixed-use items',
            ],
        ];
    }

    protected function evaluateYearEndTiming(QuickBooksConnection $connection, array $projected): ?array
    {
        return [
            'strategy_type' => TaxStrategy::TYPE_INCOME_TIMING,
            'title' => 'Year-End Income & Expense Timing',
            'description' => 'With projected income of $'.number_format($projected['net_income']).
                ', consider timing invoices and expenses strategically. Delay invoicing to push income to next year, '.
                'or accelerate expenses/purchases to increase this year\'s deductions.',
            'estimated_savings' => $projected['net_income'] * 0.05, // Conservative 5% savings
            'timing_sensitivity' => TaxStrategy::TIMING_URGENT,
            'complexity' => TaxStrategy::COMPLEXITY_LOW,
            'requirements' => ['Cash-basis accounting', 'Flexibility in billing timing'],
            'action_items' => [
                'Review outstanding invoices - delay sending until Jan if cash flow allows',
                'Pre-pay recurring annual expenses (insurance, software) before Dec 31',
                'Stock up on office supplies before year end',
                'Schedule and pay for any planned professional services',
            ],
        ];
    }

    protected function createOrUpdateStrategy(QuickBooksConnection $connection, int $year, array $data): TaxStrategy
    {
        return TaxStrategy::updateOrCreate(
            [
                'qbo_connection_id' => $connection->id,
                'tax_year' => $year,
                'strategy_type' => $data['strategy_type'],
            ],
            $data
        );
    }

    // =========================================================================
    // TAX PACKAGE / YEAR-END PREP
    // =========================================================================

    /**
     * Generate comprehensive year-end tax package.
     */
    public function generateTaxPackage(QuickBooksConnection $connection, int $year): array
    {
        // Get all financial data
        $ytdFinancials = $this->getYtdFinancials($connection, $year, 4);

        // Get P&L and Balance Sheet
        $pnl = $this->qboService->getProfitAndLoss($connection, "{$year}-01-01", "{$year}-12-31");
        $balanceSheet = $this->qboService->getBalanceSheet($connection, "{$year}-12-31");

        // Get 1099 data
        $data1099 = $this->generate1099Data($connection, $year);

        // Get all estimates made during the year
        $estimates = TaxEstimate::where('qbo_connection_id', $connection->id)
            ->where('tax_year', $year)
            ->get();

        // Get strategies
        $strategies = TaxStrategy::where('qbo_connection_id', $connection->id)
            ->where('tax_year', $year)
            ->active()
            ->get();

        return [
            'tax_year' => $year,
            'generated_at' => now()->toIso8601String(),
            'financial_summary' => [
                'gross_income' => $ytdFinancials['gross_income'],
                'total_expenses' => $ytdFinancials['deductions'],
                'net_income' => $ytdFinancials['net_income'],
            ],
            'estimated_payments' => [
                'q1' => $estimates->firstWhere('quarter', 1)?->quarterly_payment_due ?? 0,
                'q2' => $estimates->firstWhere('quarter', 2)?->quarterly_payment_due ?? 0,
                'q3' => $estimates->firstWhere('quarter', 3)?->quarterly_payment_due ?? 0,
                'q4' => $estimates->firstWhere('quarter', 4)?->quarterly_payment_due ?? 0,
                'total_paid' => $estimates->sum('ytd_payments_made'),
            ],
            'contractor_1099s' => $data1099,
            'pending_strategies' => $strategies->map(fn ($s) => [
                'title' => $s->title,
                'estimated_savings' => $s->estimated_savings,
                'status' => $s->status,
            ])->toArray(),
            'important_deadlines' => $this->getUpcomingDeadlines($connection->user_id, $year),
            'documents_needed' => [
                'W-9s from contractors' => $data1099['needs_w9'].' outstanding',
                'Profit & Loss Statement' => 'Generated',
                'Balance Sheet' => 'Generated',
                'Bank Statements' => 'Request from banks',
                'Estimated Tax Payment Receipts' => 'Gather from records',
            ],
        ];
    }

    protected function getUpcomingDeadlines(int $userId, int $year): array
    {
        return TaxCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $year)
            ->upcoming()
            ->take(5)
            ->get()
            ->map(fn ($e) => [
                'event' => $e->event_type,
                'due_date' => $e->due_date->format('M j, Y'),
                'days_until' => $e->daysUntilDue(),
                'form' => $e->form_type,
            ])
            ->toArray();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function getQuarterEndDate(int $year, int $quarter): string
    {
        return match ($quarter) {
            1 => "{$year}-03-31",
            2 => "{$year}-06-30",
            3 => "{$year}-09-30",
            4 => "{$year}-12-31",
            default => "{$year}-12-31",
        };
    }

    protected function getCurrentQuarter(): int
    {
        return (int) ceil(now()->month / 3);
    }

    protected function getProjectionConfidence(int $quarter): string
    {
        return match ($quarter) {
            1 => 'low - only 3 months of data',
            2 => 'medium - 6 months of data',
            3 => 'high - 9 months of data',
            4 => 'very high - full year data',
            default => 'unknown',
        };
    }

    protected function extractPnlValue(array $pnl, string $key): ?float
    {
        // Navigate QBO report structure to find value
        // This is simplified - real implementation needs to parse the report structure
        $rows = $pnl['Rows']['Row'] ?? [];

        foreach ($rows as $row) {
            if (isset($row['group']) && $row['group'] === $key) {
                return (float) ($row['Summary']['ColData'][1]['value'] ?? 0);
            }
            if (isset($row['type']) && $row['type'] === 'Section') {
                foreach ($row['Rows']['Row'] ?? [] as $subRow) {
                    if (isset($subRow['group']) && $subRow['group'] === $key) {
                        return (float) ($subRow['Summary']['ColData'][1]['value'] ?? 0);
                    }
                }
            }
        }

        // Check for direct summary values
        if (isset($pnl['Rows']['Row'])) {
            foreach ($pnl['Rows']['Row'] as $row) {
                if (($row['group'] ?? '') === $key && isset($row['Summary'])) {
                    return (float) ($row['Summary']['ColData'][1]['value'] ?? 0);
                }
            }
        }

        return null;
    }
}
