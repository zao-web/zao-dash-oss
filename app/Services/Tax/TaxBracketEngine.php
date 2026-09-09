<?php

namespace App\Services\Tax;

/**
 * TaxBracketEngine — Pure computational tax engine.
 *
 * All methods are stateless and take parameters. No database access.
 * Fully unit-testable with deterministic results.
 *
 * Supports: Federal (all filing statuses), Oregon, Portland, Multnomah,
 * SE tax with wage base cap, S-corp split, QBI deduction, safe harbor.
 */
class TaxBracketEngine
{
    // ──────────────────────────────────────────
    // Federal Income Tax Brackets (2026)
    // ──────────────────────────────────────────

    /**
     * @return array<int, array{min: float, max: float|null, rate: float}>
     */
    public static function federalBrackets(int $year, string $filingStatus = 'single'): array
    {
        $brackets = match ($filingStatus) {
            'mfj', 'qw' => [
                ['min' => 0, 'max' => 24800, 'rate' => 0.10],
                ['min' => 24800, 'max' => 100800, 'rate' => 0.12],
                ['min' => 100800, 'max' => 211400, 'rate' => 0.22],
                ['min' => 211400, 'max' => 403550, 'rate' => 0.24],
                ['min' => 403550, 'max' => 512450, 'rate' => 0.32],
                ['min' => 512450, 'max' => 768700, 'rate' => 0.35],
                ['min' => 768700, 'max' => null, 'rate' => 0.37],
            ],
            'mfs' => [
                ['min' => 0, 'max' => 12400, 'rate' => 0.10],
                ['min' => 12400, 'max' => 50400, 'rate' => 0.12],
                ['min' => 50400, 'max' => 105700, 'rate' => 0.22],
                ['min' => 105700, 'max' => 201775, 'rate' => 0.24],
                ['min' => 201775, 'max' => 256225, 'rate' => 0.32],
                ['min' => 256225, 'max' => 384350, 'rate' => 0.35],
                ['min' => 384350, 'max' => null, 'rate' => 0.37],
            ],
            'hoh' => [
                ['min' => 0, 'max' => 17700, 'rate' => 0.10],
                ['min' => 17700, 'max' => 67450, 'rate' => 0.12],
                ['min' => 67450, 'max' => 105700, 'rate' => 0.22],
                ['min' => 105700, 'max' => 201750, 'rate' => 0.24],
                ['min' => 201750, 'max' => 256200, 'rate' => 0.32],
                ['min' => 256200, 'max' => 640600, 'rate' => 0.35],
                ['min' => 640600, 'max' => null, 'rate' => 0.37],
            ],
            default => [ // single
                ['min' => 0, 'max' => 12400, 'rate' => 0.10],
                ['min' => 12400, 'max' => 50400, 'rate' => 0.12],
                ['min' => 50400, 'max' => 105700, 'rate' => 0.22],
                ['min' => 105700, 'max' => 201775, 'rate' => 0.24],
                ['min' => 201775, 'max' => 256225, 'rate' => 0.32],
                ['min' => 256225, 'max' => 640600, 'rate' => 0.35],
                ['min' => 640600, 'max' => null, 'rate' => 0.37],
            ],
        };

        return $brackets;
    }

    public static function standardDeduction(int $year, string $filingStatus = 'single'): float
    {
        return match ($filingStatus) {
            'mfj', 'qw' => 32200.0,
            'hoh' => 24150.0,
            'mfs' => 16100.0,
            default => 16100.0, // single
        };
    }

    // ──────────────────────────────────────────
    // Federal Income Tax Calculation
    // ──────────────────────────────────────────

    public static function calculateFederalIncomeTax(float $taxableIncome, int $year, string $filingStatus = 'single'): float
    {
        if ($taxableIncome <= 0) {
            return 0;
        }

        $brackets = self::federalBrackets($year, $filingStatus);
        $tax = 0;

        foreach ($brackets as $bracket) {
            if ($taxableIncome <= $bracket['min']) {
                break;
            }

            $upperBound = $bracket['max'] ?? PHP_FLOAT_MAX;
            $taxableInBracket = min($taxableIncome, $upperBound) - $bracket['min'];
            $tax += $taxableInBracket * $bracket['rate'];
        }

        return round($tax, 2);
    }

    // ──────────────────────────────────────────
    // Oregon State Income Tax
    // ──────────────────────────────────────────

    /**
     * Oregon progressive brackets — Oregon does NOT conform to federal QBI deduction.
     *
     * @return array<int, array{min: float, max: float|null, rate: float}>
     */
    public static function oregonBrackets(int $year, string $filingStatus = 'single'): array
    {
        return match ($filingStatus) {
            'mfj', 'qw', 'hoh' => [
                ['min' => 0, 'max' => 9100, 'rate' => 0.0475],
                ['min' => 9100, 'max' => 22800, 'rate' => 0.0675],
                ['min' => 22800, 'max' => 250000, 'rate' => 0.0875],
                ['min' => 250000, 'max' => null, 'rate' => 0.099],
            ],
            default => [ // single, mfs
                ['min' => 0, 'max' => 4550, 'rate' => 0.0475],
                ['min' => 4550, 'max' => 11400, 'rate' => 0.0675],
                ['min' => 11400, 'max' => 125000, 'rate' => 0.0875],
                ['min' => 125000, 'max' => null, 'rate' => 0.099],
            ],
        };
    }

    public static function oregonStandardDeduction(int $year, string $filingStatus = 'single'): float
    {
        return match ($filingStatus) {
            'mfj', 'qw' => 5800.0,
            'hoh' => 4700.0,
            default => 2900.0,
        };
    }

    public static function calculateOregonIncomeTax(float $oregonTaxableIncome, int $year, string $filingStatus = 'single'): float
    {
        if ($oregonTaxableIncome <= 0) {
            return 0;
        }

        $brackets = self::oregonBrackets($year, $filingStatus);
        $tax = 0;

        foreach ($brackets as $bracket) {
            if ($oregonTaxableIncome <= $bracket['min']) {
                break;
            }

            $upperBound = $bracket['max'] ?? PHP_FLOAT_MAX;
            $taxableInBracket = min($oregonTaxableIncome, $upperBound) - $bracket['min'];
            $tax += $taxableInBracket * $bracket['rate'];
        }

        return round($tax, 2);
    }

    // ──────────────────────────────────────────
    // Portland & Multnomah County Local Taxes
    // ──────────────────────────────────────────

    public static function calculatePortlandArtsTax(int $adultsInHousehold = 1): float
    {
        return 35.0 * $adultsInHousehold;
    }

    /**
     * Multnomah County Preschool For All (PFA) tax.
     * 1.5% on taxable income under $125k (single) / $200k (MFJ)
     * 3.0% on taxable income above those thresholds
     */
    public static function calculateMultnomahPfaTax(float $taxableIncome, string $filingStatus = 'single'): float
    {
        $threshold = match ($filingStatus) {
            'mfj', 'qw' => 200000,
            default => 125000,
        };

        $exemption = match ($filingStatus) {
            'mfj', 'qw' => 30000,
            default => 15000,
        };

        $taxableAmount = max(0, $taxableIncome - $exemption);

        if ($taxableAmount <= 0) {
            return 0;
        }

        $belowThreshold = min($taxableAmount, max(0, $threshold - $exemption));
        $aboveThreshold = max(0, $taxableAmount - ($threshold - $exemption));

        return round(($belowThreshold * 0.015) + ($aboveThreshold * 0.03), 2);
    }

    // ──────────────────────────────────────────
    // Self-Employment Tax
    // ──────────────────────────────────────────

    public static function socialSecurityWageBase(int $year): float
    {
        return match (true) {
            $year >= 2026 => 184500.0,
            $year === 2025 => 168600.0,
            $year === 2024 => 168600.0,
            default => 160200.0,
        };
    }

    /**
     * Calculate SE tax on net self-employment earnings.
     * Does NOT apply to S-corp distributions — only to sole prop / partnership income.
     *
     * @return array{se_tax: float, se_base: float, ss_tax: float, medicare_tax: float, additional_medicare: float, se_deduction: float}
     */
    public static function calculateSelfEmploymentTax(
        float $netEarnings,
        int $year,
        string $filingStatus = 'single',
        float $existingW2Wages = 0
    ): array {
        // 92.35% of net earnings subject to SE tax
        $seBase = round($netEarnings * 0.9235, 2);

        if ($seBase <= 0) {
            return [
                'se_tax' => 0, 'se_base' => 0, 'ss_tax' => 0,
                'medicare_tax' => 0, 'additional_medicare' => 0, 'se_deduction' => 0,
            ];
        }

        $wageBase = self::socialSecurityWageBase($year);

        // Social Security (12.4%) — capped at wage base, reduced by existing W-2 wages
        $remainingWageBase = max(0, $wageBase - $existingW2Wages);
        $ssTaxableAmount = min($seBase, $remainingWageBase);
        $ssTax = round($ssTaxableAmount * 0.124, 2);

        // Medicare (2.9%) — no cap
        $medicareTax = round($seBase * 0.029, 2);

        // Additional Medicare Tax (0.9%) — above $200k single / $250k MFJ
        $additionalMedicareThreshold = match ($filingStatus) {
            'mfj', 'qw' => 250000,
            'mfs' => 125000,
            default => 200000,
        };
        $totalEarnings = $existingW2Wages + $seBase;
        $additionalMedicare = $totalEarnings > $additionalMedicareThreshold
            ? round(max(0, $seBase - max(0, $additionalMedicareThreshold - $existingW2Wages)) * 0.009, 2)
            : 0;

        $seTax = $ssTax + $medicareTax + $additionalMedicare;

        // Deductible portion: 50% of SE tax (excluding additional Medicare)
        $seDeduction = round(($ssTax + $medicareTax) / 2, 2);

        return [
            'se_tax' => round($seTax, 2),
            'se_base' => $seBase,
            'ss_tax' => $ssTax,
            'medicare_tax' => $medicareTax,
            'additional_medicare' => $additionalMedicare,
            'se_deduction' => $seDeduction,
        ];
    }

    // ──────────────────────────────────────────
    // S-Corp FICA (on salary only)
    // ──────────────────────────────────────────

    /**
     * S-corp owner pays FICA on reasonable salary only.
     * Employer + employee shares.
     *
     * @return array{employee_ss: float, employee_medicare: float, employer_ss: float, employer_medicare: float, total_fica: float, additional_medicare: float}
     */
    public static function calculateSCorpFica(float $salary, int $year, string $filingStatus = 'single'): array
    {
        $wageBase = self::socialSecurityWageBase($year);
        $ssTaxable = min($salary, $wageBase);

        $employeeSs = round($ssTaxable * 0.062, 2);
        $employeeMedicare = round($salary * 0.0145, 2);
        $employerSs = round($ssTaxable * 0.062, 2);
        $employerMedicare = round($salary * 0.0145, 2);

        // Additional Medicare Tax (0.9%) on employee side above threshold
        $threshold = match ($filingStatus) {
            'mfj', 'qw' => 250000,
            'mfs' => 125000,
            default => 200000,
        };
        $additionalMedicare = $salary > $threshold
            ? round(($salary - $threshold) * 0.009, 2)
            : 0;

        return [
            'employee_ss' => $employeeSs,
            'employee_medicare' => $employeeMedicare,
            'employer_ss' => $employerSs,
            'employer_medicare' => $employerMedicare,
            'total_fica' => round($employeeSs + $employeeMedicare + $employerSs + $employerMedicare + $additionalMedicare, 2),
            'additional_medicare' => $additionalMedicare,
        ];
    }

    // ──────────────────────────────────────────
    // QBI Deduction (Section 199A)
    // ──────────────────────────────────────────

    /**
     * Calculate QBI deduction for S-corp distributions.
     * Oregon does NOT allow QBI deduction — federal only.
     *
     * Limitation: lesser of:
     * (a) 20% of QBI, or
     * (b) greater of: 50% of W-2 wages OR 25% of W-2 wages + 2.5% of UBIA
     * Also limited to 20% of taxable income before QBI deduction.
     */
    public static function calculateQbiDeduction(
        float $qbi,
        float $w2WagesPaid,
        float $taxableIncomeBeforeQbi,
        string $filingStatus = 'single',
        float $ubia = 0
    ): float {
        if ($qbi <= 0 || $taxableIncomeBeforeQbi <= 0) {
            return 0;
        }

        // 20% of QBI
        $twentyPercentQbi = $qbi * 0.20;

        // W-2 wage limitation
        $fiftyPercentWages = $w2WagesPaid * 0.50;
        $twentyFivePercentPlusUbia = ($w2WagesPaid * 0.25) + ($ubia * 0.025);
        $wageLimitation = max($fiftyPercentWages, $twentyFivePercentPlusUbia);

        // Phase-in threshold (below this, no W-2 wage limitation applies)
        $phaseInThreshold = match ($filingStatus) {
            'mfj', 'qw' => 403500,
            'mfs' => 201775,
            default => 201750,
        };
        $phaseInRange = match ($filingStatus) {
            'mfj', 'qw' => 150000,
            default => 75000,
        };

        if ($taxableIncomeBeforeQbi <= $phaseInThreshold) {
            // Below threshold: full 20% QBI, no wage limitation
            $qbiDeduction = $twentyPercentQbi;
        } elseif ($taxableIncomeBeforeQbi >= ($phaseInThreshold + $phaseInRange)) {
            // Above phase-out: W-2 wage limitation fully applies
            $qbiDeduction = min($twentyPercentQbi, $wageLimitation);
        } else {
            // In phase-in range: proportional limitation
            $excessAmount = $taxableIncomeBeforeQbi - $phaseInThreshold;
            $phaseInPct = $excessAmount / $phaseInRange;
            $reduction = ($twentyPercentQbi - min($twentyPercentQbi, $wageLimitation)) * $phaseInPct;
            $qbiDeduction = $twentyPercentQbi - $reduction;
        }

        // Cannot exceed 20% of taxable income before QBI
        $taxableIncomeLimit = $taxableIncomeBeforeQbi * 0.20;

        return round(min($qbiDeduction, $taxableIncomeLimit), 2);
    }

    // ──────────────────────────────────────────
    // Child Tax Credit
    // ──────────────────────────────────────────

    public static function calculateChildTaxCredit(int $qualifyingChildren, float $agi, string $filingStatus = 'single'): float
    {
        if ($qualifyingChildren <= 0) {
            return 0;
        }

        $creditPerChild = 2000;
        $totalCredit = $qualifyingChildren * $creditPerChild;

        // Phase-out: $50 per $1000 above threshold
        $threshold = match ($filingStatus) {
            'mfj', 'qw' => 400000,
            default => 200000,
        };

        if ($agi > $threshold) {
            $reduction = ceil(($agi - $threshold) / 1000) * 50;
            $totalCredit = max(0, $totalCredit - $reduction);
        }

        return round((float) $totalCredit, 2);
    }

    // ──────────────────────────────────────────
    // Safe Harbor Quarterly Estimate
    // ──────────────────────────────────────────

    /**
     * Calculate quarterly estimated payment using safe harbor.
     * Pay the lesser of:
     * (a) 90% of current year projected tax
     * (b) 100% of prior year tax (110% if prior year AGI > $150k)
     *
     * Then divide by 4 for quarterly payment.
     */
    public static function calculateQuarterlyEstimate(
        float $projectedCurrentYearTax,
        float $priorYearTaxLiability,
        float $priorYearAgi,
        float $ytdPaymentsMade = 0,
        int $remainingQuarters = 4
    ): array {
        $ninetyPercentCurrent = $projectedCurrentYearTax * 0.90;

        $safeHarborPct = $priorYearAgi > 150000 ? 1.10 : 1.00;
        $priorYearSafeHarbor = $priorYearTaxLiability * $safeHarborPct;

        $safeHarborAmount = min($ninetyPercentCurrent, $priorYearSafeHarbor);
        $totalRequired = max(0, $safeHarborAmount - $ytdPaymentsMade);

        $quarterlyPayment = $remainingQuarters > 0
            ? round($totalRequired / $remainingQuarters, 2)
            : 0;

        return [
            'annual_projected_tax' => round($projectedCurrentYearTax, 2),
            'ninety_percent_current' => round($ninetyPercentCurrent, 2),
            'prior_year_safe_harbor' => round($priorYearSafeHarbor, 2),
            'safe_harbor_method' => $priorYearSafeHarbor <= $ninetyPercentCurrent ? 'prior_year' : 'current_year',
            'total_required' => round($safeHarborAmount, 2),
            'ytd_payments' => round($ytdPaymentsMade, 2),
            'remaining_due' => round($totalRequired, 2),
            'quarterly_payment' => $quarterlyPayment,
            'remaining_quarters' => $remainingQuarters,
        ];
    }

    // ──────────────────────────────────────────
    // Complete S-Corp Tax Computation
    // ──────────────────────────────────────────

    /**
     * Full tax computation for an S-corp owner.
     *
     * @return array{
     *     gross_income: float,
     *     salary: float,
     *     distributions: float,
     *     k1_pass_through_income: float,
     *     fica: array,
     *     agi: float,
     *     standard_deduction: float,
     *     qbi_deduction: float,
     *     taxable_income: float,
     *     federal_tax: float,
     *     oregon_tax: float,
     *     portland_arts_tax: float,
     *     multnomah_pfa_tax: float,
     *     child_tax_credit: float,
     *     total_tax: float,
     *     effective_rate: float,
     *     marginal_federal_rate: float,
     *     marginal_state_rate: float,
     * }
     */
    public static function computeSCorpTax(
        float $netBusinessIncome,
        float $salary,
        int $year,
        string $filingStatus = 'single',
        float $otherIncome = 0,
        float $w2WagesPaid = 0,
        int $qualifyingChildren = 0,
        bool $isPortlandResident = false,
        float $itemizedDeductions = 0,
        float $healthInsurance = 0,
        float $retirementContributions = 0,
    ): array {
        // K-1 pass-through income can be negative (business loss flows to 1040)
        $k1PassThroughIncome = $netBusinessIncome - $salary;
        $distributions = max(0, $k1PassThroughIncome);

        // FICA on salary only
        $fica = self::calculateSCorpFica($salary, $year, $filingStatus);

        // AGI uses K-1 pass-through (not capped distributions) so losses reduce AGI
        $aboveTheLineDeductions = $healthInsurance + $retirementContributions;
        $agi = $salary + $k1PassThroughIncome + $otherIncome - $aboveTheLineDeductions;

        // Standard vs itemized deduction
        $standardDeduction = self::standardDeduction($year, $filingStatus);
        $deduction = max($standardDeduction, $itemizedDeductions);
        $usedStandard = $deduction === $standardDeduction;

        // Taxable income before QBI
        $taxableIncomeBeforeQbi = max(0, $agi - $deduction);

        // QBI deduction (federal only, not Oregon) — only on positive QBI
        $qbi = max(0, $k1PassThroughIncome);
        $qbiDeduction = self::calculateQbiDeduction(
            $qbi,
            $w2WagesPaid > 0 ? $w2WagesPaid : $salary,
            $taxableIncomeBeforeQbi,
            $filingStatus,
        );

        $federalTaxableIncome = max(0, $taxableIncomeBeforeQbi - $qbiDeduction);

        // Federal income tax
        $federalTax = self::calculateFederalIncomeTax($federalTaxableIncome, $year, $filingStatus);

        // Child tax credit
        $childTaxCredit = self::calculateChildTaxCredit($qualifyingChildren, $agi, $filingStatus);
        $federalTaxAfterCredits = max(0, $federalTax - $childTaxCredit);

        // Oregon income tax (no QBI deduction — Oregon doesn't conform)
        $oregonDeduction = max(self::oregonStandardDeduction($year, $filingStatus), $itemizedDeductions);
        $oregonTaxableIncome = max(0, $agi - $oregonDeduction);
        $oregonTax = self::calculateOregonIncomeTax($oregonTaxableIncome, $year, $filingStatus);

        // Local taxes
        $portlandArtsTax = $isPortlandResident ? self::calculatePortlandArtsTax($filingStatus === 'mfj' ? 2 : 1) : 0;
        $multnomahPfaTax = $isPortlandResident ? self::calculateMultnomahPfaTax($agi, $filingStatus) : 0;

        // Total tax
        $totalTax = $federalTaxAfterCredits + $fica['total_fica'] + $oregonTax + $portlandArtsTax + $multnomahPfaTax;
        $grossIncome = $salary + $k1PassThroughIncome + $otherIncome;
        $effectiveRate = $grossIncome > 0 ? round(($totalTax / $grossIncome) * 100, 2) : 0;

        // Marginal rates
        $marginalFederal = self::marginalRate(self::federalBrackets($year, $filingStatus), $federalTaxableIncome);
        $marginalState = self::marginalRate(self::oregonBrackets($year, $filingStatus), $oregonTaxableIncome);

        return [
            'gross_income' => round($grossIncome, 2),
            'salary' => round($salary, 2),
            'distributions' => round($distributions, 2),
            'k1_pass_through_income' => round($k1PassThroughIncome, 2),
            'fica' => $fica,
            'agi' => round($agi, 2),
            'standard_deduction' => round($deduction, 2),
            'used_standard_deduction' => $usedStandard,
            'qbi_deduction' => round($qbiDeduction, 2),
            'federal_taxable_income' => round($federalTaxableIncome, 2),
            'federal_tax' => round($federalTaxAfterCredits, 2),
            'oregon_taxable_income' => round($oregonTaxableIncome, 2),
            'oregon_tax' => round($oregonTax, 2),
            'portland_arts_tax' => round($portlandArtsTax, 2),
            'multnomah_pfa_tax' => round($multnomahPfaTax, 2),
            'child_tax_credit' => round($childTaxCredit, 2),
            'total_fica' => round($fica['total_fica'], 2),
            'total_tax' => round($totalTax, 2),
            'effective_rate' => $effectiveRate,
            'marginal_federal_rate' => $marginalFederal,
            'marginal_state_rate' => $marginalState,
        ];
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    protected static function marginalRate(array $brackets, float $taxableIncome): float
    {
        $rate = 0;
        foreach ($brackets as $bracket) {
            if ($taxableIncome > $bracket['min']) {
                $rate = $bracket['rate'];
            }
        }

        return round($rate * 100, 2);
    }
}
