<?php

namespace App\Services\Tax;

use App\Models\FinancialSnapshot;
use App\Models\RdActivityLog;
use App\Models\RealEstateProperty;

class TaxOptimizationService
{
    public function __construct(protected TaxCalculationService $taxCalc) {}

    /**
     * Build and compare current vs optimized tax scenarios.
     *
     * @return array{current: array<string, mixed>, optimized: array<string, mixed>, total_savings: float, strategies: array<int, array<string, mixed>>}
     */
    public function compareScenarios(int $userId, int $taxYear): array
    {
        $current = $this->buildCurrentScenario($userId, $taxYear);
        $optimized = $this->buildOptimizedScenario($userId, $taxYear);

        return [
            'current' => $current,
            'optimized' => $optimized,
            'total_savings' => round($current['total_tax'] - $optimized['total_tax'], 2),
            'strategies' => $this->getApplicableStrategies($userId, $taxYear),
        ];
    }

    /**
     * Model S-Corp salary at different levels to find optimal split.
     *
     * @return array{scenarios: array<int, array<string, mixed>>, optimal: array<string, mixed>, total_income: float}
     */
    public function optimizeSalary(float $totalIncome, int $taxYear = 2026): array
    {
        $results = [];

        // Model from 30% to 60% in 5% steps
        for ($pct = 30; $pct <= 60; $pct += 5) {
            $salary = round($totalIncome * ($pct / 100));
            $distribution = $totalIncome - $salary;

            // FICA savings (compare to full SE tax)
            $fullSeTax = $totalIncome * 0.9235 * 0.153;
            $ficaOnSalary = min($salary, 168600) * 0.153 + max(0, $salary - 168600) * 0.029;
            $ficaSavings = $fullSeTax - $ficaOnSalary;

            // QBI impact
            $qbiDeduction = min($distribution * 0.20, $salary * 0.50);
            $qbiTaxSavings = $qbiDeduction * 0.32;

            // Retirement room (25% of salary for employer match)
            $maxEmployerMatch = $salary * 0.25;
            $totalRetirement = min(23500 + $maxEmployerMatch, 70000);
            $retirementTaxSavings = $totalRetirement * 0.32;

            $results[] = [
                'salary_percent' => $pct,
                'salary' => $salary,
                'distribution' => $distribution,
                'fica_savings' => round($ficaSavings, 2),
                'qbi_deduction' => round($qbiDeduction, 2),
                'qbi_tax_savings' => round($qbiTaxSavings, 2),
                'max_retirement' => round($totalRetirement, 2),
                'retirement_tax_savings' => round($retirementTaxSavings, 2),
                'total_benefit' => round($ficaSavings + $qbiTaxSavings + $retirementTaxSavings, 2),
            ];
        }

        $optimal = collect($results)->sortByDesc('total_benefit')->first();

        return [
            'scenarios' => $results,
            'optimal' => $optimal,
            'total_income' => $totalIncome,
        ];
    }

    /**
     * Calculate retirement strategy comparison.
     *
     * @return array{solo_401k: array<string, mixed>, sep_ira: array<string, mixed>, defined_benefit: array<string, mixed>}
     */
    public function calculateRetirementStrategies(float $salary, float $netIncome, int $age): array
    {
        $catchUp = $age >= 50 ? 7500 : 0;
        $superCatchUp = ($age >= 60 && $age <= 63) ? 11250 : 0;
        $employeeDeferral = 23500 + $catchUp + $superCatchUp;
        $employerMatch = $salary * 0.25;

        return [
            'solo_401k' => [
                'employee_deferral' => $employeeDeferral,
                'employer_match' => round($employerMatch, 2),
                'total' => round(min($employeeDeferral + $employerMatch, 70000 + $catchUp + $superCatchUp), 2),
                'tax_savings' => round(min($employeeDeferral + $employerMatch, 70000 + $catchUp + $superCatchUp) * 0.32, 2),
                'mega_backdoor_roth_room' => round(max(0, 70000 - $employeeDeferral - $employerMatch), 2),
            ],
            'sep_ira' => [
                'contribution' => round(min($salary * 0.25, 70000), 2),
                'tax_savings' => round(min($salary * 0.25, 70000) * 0.32, 2),
            ],
            'defined_benefit' => [
                'estimated_max' => $this->estimateDbPlanMax($age, $netIncome),
                'tax_savings' => round($this->estimateDbPlanMax($age, $netIncome) * 0.32, 2),
                'note' => 'Requires actuary. Best for stable income >$300K. Must fund 3-5 years.',
            ],
        ];
    }

    /**
     * Estimate cost segregation benefits for a property.
     *
     * @return array{purchase_price: float, building_value: float, standard_annual: float, cost_seg: array<string, float>, year_one_standard: float, year_one_accelerated: float, year_one_tax_savings: float, additional_year_one_deduction: float, additional_tax_savings: float}
     */
    public function estimateCostSegregation(float $purchasePrice, float $landPercent = 25, string $propertyType = 'residential'): array
    {
        $buildingValue = $purchasePrice * (1 - $landPercent / 100);
        $usefulLife = $propertyType === 'commercial' ? 39 : 27.5;

        // Standard depreciation
        $standardAnnual = $buildingValue / $usefulLife;

        // Cost seg estimates (typical percentages)
        $fiveYear = $buildingValue * 0.20;
        $sevenYear = $buildingValue * 0.08;
        $fifteenYear = $buildingValue * 0.12;
        $structure = $buildingValue - $fiveYear - $sevenYear - $fifteenYear;

        // Bonus depreciation (40% in 2025, 20% in 2026)
        $bonusRate = 0.40;
        $yearOneAccelerated = ($fiveYear * $bonusRate) + ($sevenYear * $bonusRate) + ($fifteenYear * $bonusRate) + ($structure / $usefulLife);

        return [
            'purchase_price' => $purchasePrice,
            'building_value' => round($buildingValue, 2),
            'standard_annual' => round($standardAnnual, 2),
            'cost_seg' => [
                'five_year' => round($fiveYear, 2),
                'seven_year' => round($sevenYear, 2),
                'fifteen_year' => round($fifteenYear, 2),
                'structure' => round($structure, 2),
            ],
            'year_one_standard' => round($standardAnnual, 2),
            'year_one_accelerated' => round($yearOneAccelerated, 2),
            'year_one_tax_savings' => round($yearOneAccelerated * 0.32, 2),
            'additional_year_one_deduction' => round($yearOneAccelerated - $standardAnnual, 2),
            'additional_tax_savings' => round(($yearOneAccelerated - $standardAnnual) * 0.32, 2),
        ];
    }

    /**
     * Calculate R&D tax credit using Alternative Simplified Credit method.
     *
     * @return array{current_year_qre: float, three_year_avg_qre: float, base_amount: float, excess_qre: float, credit_amount: float, note: string, prior_years: array<int, float>}
     */
    public function calculateRdCredit(int $userId, int $taxYear): array
    {
        $currentQre = RdActivityLog::where('user_id', $userId)
            ->whereYear('activity_date', $taxYear)
            ->where('qualifies_for_rd', true)
            ->sum('wage_amount');

        $priorYears = [];
        for ($y = $taxYear - 3; $y < $taxYear; $y++) {
            $priorYears[$y] = (float) RdActivityLog::where('user_id', $userId)
                ->whereYear('activity_date', $y)
                ->where('qualifies_for_rd', true)
                ->sum('wage_amount');
        }
        $nonZeroPriorYears = array_filter($priorYears);
        $avgPrior = count($nonZeroPriorYears) > 0 ? array_sum($priorYears) / count($nonZeroPriorYears) : 0;

        // ASC: 14% of QRE above 50% of 3-year average
        $baseAmount = $avgPrior * 0.50;
        $excessQre = max(0, (float) $currentQre - $baseAmount);
        $credit = $excessQre * 0.14;

        return [
            'current_year_qre' => round((float) $currentQre, 2),
            'three_year_avg_qre' => round($avgPrior, 2),
            'base_amount' => round($baseAmount, 2),
            'excess_qre' => round($excessQre, 2),
            'credit_amount' => round($credit, 2),
            'note' => 'R&D tax credit is dollar-for-dollar. $1 credit = $1 less tax.',
            'prior_years' => $priorYears,
        ];
    }

    /**
     * Calculate all deductions and their tax impact.
     *
     * @return array<string, array{deduction: float|int, tax_savings: float|int, description: string}>
     */
    public function calculateDeductions(int $userId): array
    {
        return [
            'augusta_rule' => ['deduction' => 7000, 'tax_savings' => 2240, 'description' => '14-day home rental to S-Corp at FMV'],
            'hsa' => ['deduction' => 8550, 'tax_savings' => 2736, 'description' => 'Family HSA contribution (requires HDHP)'],
            'home_office' => ['deduction' => 3500, 'tax_savings' => 1120, 'description' => 'Actual expense method estimate'],
            'health_insurance' => ['deduction' => 18000, 'tax_savings' => 5760, 'description' => 'Self-employed health insurance (family)'],
            'section_179' => ['deduction' => 0, 'tax_savings' => 0, 'description' => 'Equipment purchases (add your amounts)'],
            'vehicle' => ['deduction' => 5000, 'tax_savings' => 1600, 'description' => 'Standard mileage or actual expense'],
            'charitable' => ['deduction' => 0, 'tax_savings' => 0, 'description' => 'DAF bunching strategy available'],
        ];
    }

    /**
     * Build a complete "stacked" optimization showing total savings.
     *
     * @return array{gross_income: float, strategies: array<int, array{name: string, savings: float}>, total_savings: float, estimated_tax_without: float, estimated_tax_with: float, effective_rate_without: float, effective_rate_with: float}
     */
    public function getStackedSavings(float $grossIncome, float $salary, int $age, ?float $propertyPurchasePrice = null, ?float $rdQualifyingExpenses = null): array
    {
        $strategies = [];
        $totalSavings = 0;

        // S-Corp FICA
        $distribution = $grossIncome - $salary;
        $ficaSaved = ($distribution * 0.9235 * 0.153);
        $strategies[] = ['name' => 'S-Corp FICA Optimization', 'savings' => round($ficaSaved, 2)];
        $totalSavings += $ficaSaved;

        // Retirement
        $retirement = $this->calculateRetirementStrategies($salary, $grossIncome, $age);
        $retirementSavings = (float) $retirement['solo_401k']['tax_savings'];
        $strategies[] = ['name' => 'Solo 401(k) Contributions', 'savings' => round($retirementSavings, 2)];
        $totalSavings += $retirementSavings;

        // QBI
        $qbiDeduction = min($distribution * 0.20, $salary * 0.50);
        $qbiSavings = $qbiDeduction * 0.32;
        $strategies[] = ['name' => 'QBI Deduction (Section 199A)', 'savings' => round($qbiSavings, 2)];
        $totalSavings += $qbiSavings;

        // Real estate
        if ($propertyPurchasePrice) {
            $costSeg = $this->estimateCostSegregation($propertyPurchasePrice);
            $strategies[] = ['name' => 'Real Estate Depreciation (Cost Seg)', 'savings' => round((float) $costSeg['additional_tax_savings'], 2)];
            $totalSavings += (float) $costSeg['additional_tax_savings'];
        }

        // R&D credit
        if ($rdQualifyingExpenses) {
            $rdCredit = $rdQualifyingExpenses * 0.14 * 0.5;
            $strategies[] = ['name' => 'R&D Tax Credit', 'savings' => round($rdCredit, 2)];
            $totalSavings += $rdCredit;
        }

        // Deductions
        $deductions = $this->calculateDeductions(0);
        $deductionSavings = collect($deductions)->sum('tax_savings');
        $strategies[] = ['name' => 'Other Deductions (HSA, Augusta, etc.)', 'savings' => round((float) $deductionSavings, 2)];
        $totalSavings += $deductionSavings;

        return [
            'gross_income' => $grossIncome,
            'strategies' => $strategies,
            'total_savings' => round($totalSavings, 2),
            'estimated_tax_without' => round($grossIncome * 0.30, 2),
            'estimated_tax_with' => round(max(0, ($grossIncome * 0.30) - $totalSavings), 2),
            'effective_rate_without' => 30.0,
            'effective_rate_with' => round(max(0, (($grossIncome * 0.30) - $totalSavings) / $grossIncome * 100), 1),
        ];
    }

    /**
     * Estimate defined benefit plan maximum contribution based on age and income.
     */
    protected function estimateDbPlanMax(int $age, float $income): float
    {
        $baseMax = match (true) {
            $age >= 60 => 275000,
            $age >= 55 => 225000,
            $age >= 50 => 180000,
            $age >= 45 => 140000,
            $age >= 40 => 100000,
            default => 75000,
        };

        return min($baseMax, $income * 0.80);
    }

    /**
     * Build the current (unoptimized) tax scenario from existing data.
     *
     * @return array<string, mixed>
     */
    protected function buildCurrentScenario(int $userId, int $taxYear): array
    {
        $snapshot = FinancialSnapshot::latest('monthly');
        $grossIncome = (float) ($snapshot?->total_income ?? 0) * 12;

        // Default: sole prop / no optimization
        $seTax = $grossIncome * 0.9235 * 0.153;
        $seDeduction = $seTax * 0.5;
        $standardDeduction = 14600;
        $taxableIncome = max(0, $grossIncome - $seDeduction - $standardDeduction);
        $federalTax = $taxableIncome * 0.24;
        $stateTax = $grossIncome * 0.05;
        $totalTax = $federalTax + $stateTax + $seTax;

        return [
            'gross_income' => round($grossIncome, 2),
            'total_tax' => round($totalTax, 2),
            'effective_rate' => $grossIncome > 0 ? round(($totalTax / $grossIncome) * 100, 2) : 0,
            'federal_tax' => round($federalTax, 2),
            'state_tax' => round($stateTax, 2),
            'se_tax' => round($seTax, 2),
        ];
    }

    /**
     * Build the optimized tax scenario applying all available strategies.
     *
     * @return array<string, mixed>
     */
    protected function buildOptimizedScenario(int $userId, int $taxYear): array
    {
        $snapshot = FinancialSnapshot::latest('monthly');
        $grossIncome = (float) ($snapshot?->total_income ?? 0) * 12;

        // Optimal S-Corp split
        $salary = $grossIncome * 0.40;
        $distribution = $grossIncome - $salary;

        // FICA only on salary
        $ficaTax = min($salary, 168600) * 0.153 + max(0, $salary - 168600) * 0.029;

        // Retirement deduction
        $retirementDeduction = min(23500 + ($salary * 0.25), 70000);

        // QBI
        $qbiDeduction = min($distribution * 0.20, $salary * 0.50);

        // Standard deduction
        $standardDeduction = 14600;

        // Deductions
        $otherDeductions = collect($this->calculateDeductions($userId))->sum('deduction');

        $taxableIncome = max(0, $grossIncome - $retirementDeduction - $qbiDeduction - $standardDeduction - $otherDeductions);
        $federalTax = $taxableIncome * 0.22;
        $stateTax = $taxableIncome * 0.05;
        $totalTax = $federalTax + $stateTax + $ficaTax;

        // R&D credit
        $rdCredit = $this->calculateRdCredit($userId, $taxYear);
        $totalTax = max(0, $totalTax - $rdCredit['credit_amount']);

        return [
            'gross_income' => round($grossIncome, 2),
            'total_tax' => round($totalTax, 2),
            'effective_rate' => $grossIncome > 0 ? round(($totalTax / $grossIncome) * 100, 2) : 0,
            'federal_tax' => round($federalTax, 2),
            'state_tax' => round($stateTax, 2),
            'se_tax' => 0,
            'fica_tax' => round($ficaTax, 2),
            'retirement_deduction' => round($retirementDeduction, 2),
            'qbi_deduction' => round($qbiDeduction, 2),
            'rd_credit' => round($rdCredit['credit_amount'], 2),
        ];
    }

    /**
     * Get all applicable strategies for a user and tax year.
     *
     * @return array<int, array{name: string, category: string, estimated_savings: float, description: string}>
     */
    protected function getApplicableStrategies(int $userId, int $taxYear): array
    {
        $strategies = [];

        $strategies[] = [
            'name' => 'S-Corp Election',
            'category' => 'entity_structure',
            'estimated_savings' => 15000,
            'description' => 'Reduce SE tax by paying reasonable salary and taking distributions.',
        ];

        $strategies[] = [
            'name' => 'Solo 401(k) Maximization',
            'category' => 'retirement',
            'estimated_savings' => 22400,
            'description' => 'Contribute up to $70K pre-tax to reduce taxable income.',
        ];

        $strategies[] = [
            'name' => 'QBI Deduction',
            'category' => 'deduction',
            'estimated_savings' => 10000,
            'description' => '20% deduction on qualified business income (Section 199A).',
        ];

        $properties = RealEstateProperty::where('user_id', $userId)->count();
        if ($properties > 0) {
            $strategies[] = [
                'name' => 'Cost Segregation',
                'category' => 'real_estate',
                'estimated_savings' => 25000,
                'description' => 'Accelerate depreciation on real estate properties.',
            ];
        }

        $rdActivities = RdActivityLog::where('user_id', $userId)
            ->whereYear('activity_date', $taxYear)
            ->where('qualifies_for_rd', true)
            ->exists();
        if ($rdActivities) {
            $strategies[] = [
                'name' => 'R&D Tax Credit',
                'category' => 'credit',
                'estimated_savings' => 5000,
                'description' => 'Dollar-for-dollar tax credit for qualifying R&D activities.',
            ];
        }

        return $strategies;
    }
}
