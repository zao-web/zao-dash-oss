<?php

namespace App\Services\Tax;

use App\Models\Contractor1099Data;
use App\Models\EstimatedTaxPayment;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\Log;

/**
 * 3-pass validation before any tax form is finalized.
 * Pass 1: Accuracy — cross-reference values against source data
 * Pass 2: Optimization — check for missed savings
 * Pass 3: Compliance — audit risk and filing requirements
 */
class TaxReturnValidationService
{
    public function __construct(
        protected TaxBracketEngine $engine,
        protected TaxOptimizationService $optimizationService,
    ) {}

    /**
     * Run full 3-pass validation on a tax computation.
     *
     * @return array{
     *     accuracy_score: int,
     *     accuracy_issues: array,
     *     savings_found: float,
     *     savings_details: array,
     *     compliance_risks: array,
     *     audit_risk_level: string,
     *     ready_to_file: bool,
     *     blocking_issues: array,
     *     summary: string,
     * }
     */
    public function validate(TaxProfile $profile, array $taxComputation, array $sourceData = []): array
    {
        $accuracyResult = $this->passOneAccuracy($profile, $taxComputation, $sourceData);
        $optimizationResult = $this->passTwoOptimization($profile, $taxComputation);
        $complianceResult = $this->passThreeCompliance($profile, $taxComputation);

        $blockingIssues = array_merge(
            array_filter($accuracyResult['issues'], fn ($i) => $i['severity'] === 'blocking'),
            array_filter($complianceResult['risks'], fn ($r) => $r['severity'] === 'blocking'),
        );

        $readyToFile = empty($blockingIssues);

        $summary = $readyToFile
            ? "Return is ready for filing. Accuracy: {$accuracyResult['score']}%. "
                .($optimizationResult['total_savings'] > 0
                    ? 'Found $'.number_format($optimizationResult['total_savings']).' in potential savings.'
                    : 'No additional savings identified.')
            : count($blockingIssues).' blocking issue(s) must be resolved before filing.';

        Log::info('TaxReturnValidation: completed', [
            'user_id' => $profile->user_id,
            'year' => $profile->tax_year,
            'accuracy_score' => $accuracyResult['score'],
            'savings_found' => $optimizationResult['total_savings'],
            'blocking_issues' => count($blockingIssues),
            'ready' => $readyToFile,
        ]);

        return [
            'accuracy_score' => $accuracyResult['score'],
            'accuracy_issues' => $accuracyResult['issues'],
            'savings_found' => $optimizationResult['total_savings'],
            'savings_details' => $optimizationResult['strategies'],
            'compliance_risks' => $complianceResult['risks'],
            'audit_risk_level' => $complianceResult['audit_risk'],
            'ready_to_file' => $readyToFile,
            'blocking_issues' => $blockingIssues,
            'summary' => $summary,
        ];
    }

    /**
     * Pass 1: Accuracy Check
     * Cross-reference form values against source data.
     */
    protected function passOneAccuracy(TaxProfile $profile, array $taxComp, array $sourceData): array
    {
        $issues = [];
        $checks = 0;
        $passed = 0;

        // Check 1: Gross income matches source
        if (isset($sourceData['gross_income']) && isset($taxComp['gross_income'])) {
            $checks++;
            $diff = abs($sourceData['gross_income'] - $taxComp['gross_income']);
            if ($diff > 1) {
                $issues[] = [
                    'check' => 'Gross income mismatch',
                    'expected' => $sourceData['gross_income'],
                    'actual' => $taxComp['gross_income'],
                    'difference' => $diff,
                    'severity' => $diff > 1000 ? 'blocking' : 'warning',
                ];
            } else {
                $passed++;
            }
        }

        // Check 2: Salary + distributions = gross income (S-corp)
        if (isset($taxComp['salary']) && isset($taxComp['distributions'])) {
            $checks++;
            $sum = $taxComp['salary'] + $taxComp['distributions'];
            if (isset($taxComp['gross_income']) && abs($sum - $taxComp['gross_income'] + ($taxComp['other_income'] ?? 0)) > 1) {
                $issues[] = [
                    'check' => 'Salary + distributions does not equal gross income',
                    'expected' => $taxComp['gross_income'],
                    'actual' => $sum,
                    'severity' => 'warning',
                ];
            } else {
                $passed++;
            }
        }

        // Check 3: Tax computation math — recalculate and compare
        if (isset($taxComp['federal_taxable_income'])) {
            $checks++;
            $recomputed = TaxBracketEngine::calculateFederalIncomeTax(
                $taxComp['federal_taxable_income'],
                $profile->tax_year,
                $profile->filing_status,
            );
            $diff = abs(($taxComp['federal_tax'] ?? 0) - $recomputed);
            if ($diff > 10) { // allow small rounding
                $issues[] = [
                    'check' => 'Federal tax computation discrepancy',
                    'expected' => $recomputed,
                    'actual' => $taxComp['federal_tax'] ?? 0,
                    'difference' => $diff,
                    'severity' => $diff > 500 ? 'blocking' : 'warning',
                ];
            } else {
                $passed++;
            }
        }

        // Check 4: Estimated payments match records
        $checks++;
        $recordedPayments = EstimatedTaxPayment::ytdPayments($profile->user_id, $profile->tax_year, 'federal');
        $reportedPayments = $taxComp['estimated_payments_made'] ?? 0;
        if (abs($recordedPayments - $reportedPayments) > 1) {
            $issues[] = [
                'check' => 'Estimated payment records mismatch',
                'recorded' => $recordedPayments,
                'reported_on_return' => $reportedPayments,
                'severity' => 'warning',
            ];
        } else {
            $passed++;
        }

        // Check 5: FICA computation (S-corp)
        if (isset($taxComp['salary']) && isset($taxComp['total_fica'])) {
            $checks++;
            $ficaCheck = TaxBracketEngine::calculateSCorpFica(
                $taxComp['salary'],
                $profile->tax_year,
                $profile->filing_status,
            );
            if (abs($ficaCheck['total_fica'] - $taxComp['total_fica']) > 10) {
                $issues[] = [
                    'check' => 'FICA computation mismatch',
                    'expected' => $ficaCheck['total_fica'],
                    'actual' => $taxComp['total_fica'],
                    'severity' => 'warning',
                ];
            } else {
                $passed++;
            }
        }

        // Check 6: Oregon tax computation
        if (isset($taxComp['oregon_taxable_income']) && isset($taxComp['oregon_tax'])) {
            $checks++;
            $recomputedOr = TaxBracketEngine::calculateOregonIncomeTax(
                $taxComp['oregon_taxable_income'],
                $profile->tax_year,
                $profile->filing_status,
            );
            if (abs($recomputedOr - $taxComp['oregon_tax']) > 10) {
                $issues[] = [
                    'check' => 'Oregon tax computation discrepancy',
                    'expected' => $recomputedOr,
                    'actual' => $taxComp['oregon_tax'],
                    'severity' => 'warning',
                ];
            } else {
                $passed++;
            }
        }

        $score = $checks > 0 ? (int) round(($passed / $checks) * 100) : 100;

        return ['score' => $score, 'issues' => $issues];
    }

    /**
     * Pass 2: Optimization Review — check for missed savings.
     */
    protected function passTwoOptimization(TaxProfile $profile, array $taxComp): array
    {
        $strategies = [];
        $totalSavings = 0;

        $grossIncome = $taxComp['gross_income'] ?? 0;
        $salary = $taxComp['salary'] ?? 0;

        // Check 1: Salary optimization (S-corp)
        if ($profile->entity_type === 's_corp' && $grossIncome > 0) {
            $salaryPct = $salary / $grossIncome;

            if ($salaryPct > 0.50) {
                // Salary is high — more distributions could save FICA
                $optimalSalary = $grossIncome * 0.40;
                $currentFica = TaxBracketEngine::calculateSCorpFica($salary, $profile->tax_year, $profile->filing_status)['total_fica'];
                $optimalFica = TaxBracketEngine::calculateSCorpFica($optimalSalary, $profile->tax_year, $profile->filing_status)['total_fica'];
                $ficaSavings = $currentFica - $optimalFica;

                if ($ficaSavings > 500) {
                    $strategies[] = [
                        'strategy' => 'Reduce reasonable compensation',
                        'description' => 'Lowering salary from $'.number_format($salary).' to $'.number_format($optimalSalary).' would save $'.number_format($ficaSavings).' in FICA.',
                        'savings' => round($ficaSavings, 2),
                        'risk' => 'IRS may challenge unreasonably low salary. Consult guidance.',
                    ];
                    $totalSavings += $ficaSavings;
                }
            }
        }

        // Check 2: Solo 401(k) contributions
        if ($profile->has_solo_401k && $salary > 0) {
            $maxEmployee = min(23500, $salary); // 2026 projected
            $maxEmployer = $salary * 0.25;
            $maxTotal = min($maxEmployee + $maxEmployer, 70000);

            // Assume no contributions made (conservative — would need actual data)
            $potentialSavings = $maxTotal * ($taxComp['marginal_federal_rate'] ?? 22) / 100;
            if ($potentialSavings > 1000) {
                $strategies[] = [
                    'strategy' => 'Maximize Solo 401(k) contributions',
                    'description' => 'You could contribute up to $'.number_format($maxTotal).' to your Solo 401(k), saving ~$'.number_format($potentialSavings).' in taxes.',
                    'savings' => round($potentialSavings, 2),
                    'risk' => 'None — this is a standard tax-advantaged retirement strategy.',
                ];
                $totalSavings += $potentialSavings;
            }
        }

        // Check 3: Home office deduction
        if ($profile->home_office_sqft > 0 && $profile->home_total_sqft > 0) {
            $simplifiedDeduction = min($profile->home_office_sqft, 300) * 5; // $5/sqft, max 300 sqft
            $currentlyDeducting = $taxComp['home_office_deduction'] ?? 0;

            if ($simplifiedDeduction > $currentlyDeducting + 100) {
                $savings = ($simplifiedDeduction - $currentlyDeducting) * ($taxComp['marginal_federal_rate'] ?? 22) / 100;
                $strategies[] = [
                    'strategy' => 'Claim home office deduction',
                    'description' => 'Simplified method: $'.number_format($simplifiedDeduction).' deduction for '.$profile->home_office_sqft.' sqft office.',
                    'savings' => round($savings, 2),
                    'risk' => 'Low — simplified method is straightforward.',
                ];
                $totalSavings += $savings;
            }
        }

        // Check 4: Health insurance deduction
        if ($profile->health_insurance_annual > 0) {
            $currentlyDeducting = $taxComp['health_insurance_deduction'] ?? 0;
            if ($profile->health_insurance_annual > $currentlyDeducting + 100) {
                $additionalDeduction = $profile->health_insurance_annual - $currentlyDeducting;
                $savings = $additionalDeduction * ($taxComp['marginal_federal_rate'] ?? 22) / 100;
                $strategies[] = [
                    'strategy' => 'Claim self-employed health insurance deduction',
                    'description' => 'Deduct $'.number_format($profile->health_insurance_annual).' in health insurance premiums.',
                    'savings' => round($savings, 2),
                    'risk' => 'None — standard above-the-line deduction.',
                ];
                $totalSavings += $savings;
            }
        }

        // Check 5: Business mileage
        if ($profile->business_mileage_annual > 0) {
            $mileageRate = 0.70; // 2026 projected
            $deduction = $profile->business_mileage_annual * $mileageRate;
            $currentlyDeducting = $taxComp['vehicle_deduction'] ?? 0;

            if ($deduction > $currentlyDeducting + 100) {
                $savings = ($deduction - $currentlyDeducting) * ($taxComp['marginal_federal_rate'] ?? 22) / 100;
                $strategies[] = [
                    'strategy' => 'Claim standard mileage deduction',
                    'description' => number_format($profile->business_mileage_annual).' miles × $'.number_format($mileageRate, 2).'/mile = $'.number_format($deduction).' deduction.',
                    'savings' => round($savings, 2),
                    'risk' => 'Keep a mileage log for documentation.',
                ];
                $totalSavings += $savings;
            }
        }

        return [
            'total_savings' => round($totalSavings, 2),
            'strategies' => $strategies,
        ];
    }

    /**
     * Pass 3: Compliance & Risk Review.
     */
    protected function passThreeCompliance(TaxProfile $profile, array $taxComp): array
    {
        $risks = [];

        // Check 1: 1099 compliance — all contractors over $600 have forms
        $contractorsNeedingForms = Contractor1099Data::where('tax_year', $profile->tax_year)
            ->where('requires_1099', true)
            ->where('status', '!=', 'filed')
            ->count();

        if ($contractorsNeedingForms > 0) {
            $risks[] = [
                'check' => '1099-NEC compliance',
                'description' => "{$contractorsNeedingForms} contractor(s) over \$600 need 1099-NEC forms filed.",
                'severity' => 'warning',
                'action' => 'Generate and file 1099-NEC forms before January 31.',
            ];
        }

        // Check 2: W-9 compliance
        $missingW9 = Contractor1099Data::where('tax_year', $profile->tax_year)
            ->where('requires_1099', true)
            ->where('has_w9', false)
            ->count();

        if ($missingW9 > 0) {
            $risks[] = [
                'check' => 'Missing W-9s',
                'description' => "{$missingW9} contractor(s) are missing W-9 forms.",
                'severity' => 'blocking',
                'action' => 'Request W-9s from these contractors before filing 1099s.',
            ];
        }

        // Check 3: Estimated payment safe harbor
        $ytdPaid = EstimatedTaxPayment::ytdPayments($profile->user_id, $profile->tax_year, 'federal');
        $totalTax = $taxComp['total_tax'] ?? 0;
        $safeHarborAmount = $profile->prior_year_agi > 150000
            ? $profile->prior_year_tax_liability * 1.10
            : $profile->prior_year_tax_liability;

        if ($ytdPaid < $safeHarborAmount * 0.90 && $totalTax > 1000) {
            $shortfall = $safeHarborAmount - $ytdPaid;
            $risks[] = [
                'check' => 'Underpayment penalty risk',
                'description' => 'Estimated payments ($'.number_format($ytdPaid).') are below safe harbor ($'.number_format($safeHarborAmount).'). Potential penalty.',
                'severity' => 'warning',
                'action' => 'Make additional estimated payment of $'.number_format($shortfall).' to avoid penalty.',
            ];
        }

        // Check 4: Reasonable compensation (S-corp)
        if ($profile->entity_type === 's_corp') {
            $salary = $taxComp['salary'] ?? 0;
            $grossIncome = $taxComp['gross_income'] ?? 0;

            if ($grossIncome > 50000 && $salary < 30000) {
                $risks[] = [
                    'check' => 'Reasonable compensation may be too low',
                    'description' => 'S-corp salary of $'.number_format($salary).' on $'.number_format($grossIncome).' gross income may be challenged by IRS.',
                    'severity' => 'warning',
                    'action' => 'Consider increasing reasonable salary. IRS typically expects 40-60% for service businesses.',
                ];
            }
        }

        // Check 5: Oregon QBI deduction error
        if (($taxComp['oregon_qbi_deduction'] ?? 0) > 0) {
            $risks[] = [
                'check' => 'Oregon does not allow QBI deduction',
                'description' => 'A QBI deduction was applied to Oregon taxable income. Oregon does not conform to Section 199A.',
                'severity' => 'blocking',
                'action' => 'Remove QBI deduction from Oregon calculation.',
            ];
        }

        // Check 6: Audit risk indicators
        $auditIndicators = 0;
        $grossIncome = $taxComp['gross_income'] ?? 0;
        $totalDeductions = $taxComp['total_deductions'] ?? 0;

        if ($grossIncome > 0 && $totalDeductions / $grossIncome > 0.60) {
            $auditIndicators++;
            $risks[] = [
                'check' => 'High deduction-to-income ratio',
                'description' => 'Deductions are '.round(($totalDeductions / $grossIncome) * 100).'% of gross income. This may trigger additional IRS scrutiny.',
                'severity' => 'info',
                'action' => 'Ensure all deductions are well-documented.',
            ];
        }

        $auditRisk = match (true) {
            $auditIndicators >= 3 => 'high',
            $auditIndicators >= 1 => 'medium',
            default => 'low',
        };

        return [
            'risks' => $risks,
            'audit_risk' => $auditRisk,
        ];
    }
}
