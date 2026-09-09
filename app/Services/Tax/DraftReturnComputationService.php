<?php

namespace App\Services\Tax;

use App\Models\TaxProfile;
use Illuminate\Support\Arr;

class DraftReturnComputationService
{
    /**
     * @param  array<string, mixed>  $annualProjection
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @param  array<string, mixed>  $revenueRecognition
     * @param  array<string, mixed>  $ownerPaymentReview
     * @return array{
     *     basis_code: string,
     *     basis_label: string,
     *     basis_detail: string,
     *     gross_receipts: float,
     *     business_expenses: float,
     *     ordinary_business_income: float,
     *     officer_compensation: float,
     *     pass_through_income: float,
     *     shareholder_distributions: float,
     *     qbi_wage_basis: float,
     *     itemized_deductions: float,
     *     tax_computation: array<string, mixed>,
     * }
     */
    public function compute(
        ?TaxProfile $profile,
        int $year,
        array $annualProjection = [],
        array $bookkeepingReadiness = [],
        array $revenueRecognition = [],
        array $ownerPaymentReview = [],
    ): array {
        $sourceSummary = Arr::get($bookkeepingReadiness, 'ledger_summary', []);
        $sourceOfTruthCode = (string) Arr::get($bookkeepingReadiness, 'source_of_truth.code', 'incomplete');

        $recognizedRevenue = round((float) Arr::get($revenueRecognition, 'metrics.recognized_revenue_total', 0), 2);
        $quickBooksBookIncome = round((float) Arr::get($sourceSummary, 'book_income', 0), 2);
        $internalBookIncome = round((float) Arr::get($sourceSummary, 'internal_book_income', $quickBooksBookIncome), 2);
        $quickBooksBookExpenses = round((float) Arr::get($sourceSummary, 'book_expenses', 0), 2);
        $internalBookExpenses = round((float) Arr::get($sourceSummary, 'internal_book_expenses', $quickBooksBookExpenses), 2);
        $projectedAnnualRevenue = round((float) Arr::get($annualProjection, 'projected_annual_revenue', 0), 2);
        $projectedAnnualExpenses = round((float) Arr::get($annualProjection, 'projected_annual_expenses', 0), 2);

        [$grossReceipts, $basisCode, $basisLabel, $basisDetail] = $this->grossReceiptsBasis(
            recognizedRevenue: $recognizedRevenue,
            quickBooksBookIncome: $quickBooksBookIncome,
            internalBookIncome: $internalBookIncome,
            projectedAnnualRevenue: $projectedAnnualRevenue,
            sourceOfTruthCode: $sourceOfTruthCode,
        );

        $businessExpenses = $this->businessExpenseBasis(
            quickBooksBookExpenses: $quickBooksBookExpenses,
            internalBookExpenses: $internalBookExpenses,
            projectedAnnualExpenses: $projectedAnnualExpenses,
            sourceOfTruthCode: $sourceOfTruthCode,
        );

        $w2Wages = round((float) ($profile?->w2_wages_paid ?? 0), 2);
        $reasonableSalary = round((float) ($profile?->reasonable_salary ?? 0), 2);
        $officerCompensation = $w2Wages > 0 ? $w2Wages : $reasonableSalary;
        $ordinaryBusinessIncome = round($grossReceipts - $businessExpenses, 2);
        $passThroughIncome = $ordinaryBusinessIncome;
        $shareholderDistributions = round((float) Arr::get($ownerPaymentReview, 'metrics.distribution_total', 0), 2);
        $itemizedDeductions = round($this->itemizedDeductions($profile), 2);
        $aboveTheLineAdjustments = round((float) ($profile?->hsa_contributions_paid ?? 0), 2);
        $preSalaryBusinessProfit = round($ordinaryBusinessIncome + $officerCompensation, 2);

        $taxComputation = TaxBracketEngine::computeSCorpTax(
            netBusinessIncome: $preSalaryBusinessProfit,
            salary: $officerCompensation,
            year: $year,
            filingStatus: (string) ($profile?->filing_status ?? 'single'),
            otherIncome: 0,
            w2WagesPaid: $officerCompensation,
            qualifyingChildren: (int) ($profile?->dependent_count ?? 0),
            isPortlandResident: strtolower((string) ($profile?->resident_city ?? '')) === 'portland',
            itemizedDeductions: $itemizedDeductions,
            healthInsurance: (float) ($profile?->health_insurance_annual ?? 0),
            retirementContributions: $aboveTheLineAdjustments,
        );

        return [
            'basis_code' => $basisCode,
            'basis_label' => $basisLabel,
            'basis_detail' => $basisDetail,
            'gross_receipts' => $grossReceipts,
            'business_expenses' => $businessExpenses,
            'ordinary_business_income' => $ordinaryBusinessIncome,
            'officer_compensation' => $officerCompensation,
            'pass_through_income' => $passThroughIncome,
            'shareholder_distributions' => $shareholderDistributions,
            'qbi_wage_basis' => $officerCompensation,
            'itemized_deductions' => $itemizedDeductions,
            'tax_computation' => $taxComputation,
        ];
    }

    /**
     * @return array{0: float, 1: string, 2: string, 3: string}
     */
    protected function grossReceiptsBasis(
        float $recognizedRevenue,
        float $quickBooksBookIncome,
        float $internalBookIncome,
        float $projectedAnnualRevenue,
        string $sourceOfTruthCode,
    ): array {
        if ($recognizedRevenue > 0) {
            return [
                $recognizedRevenue,
                'reviewed_revenue_recognition',
                'Reviewed bank-ledger revenue',
                'Draft returns are using the reviewed revenue-recognition ledger, not the invoice projection.',
            ];
        }

        if ($sourceOfTruthCode === 'quickbooks_plus_bank' && $quickBooksBookIncome > 0) {
            return [
                $quickBooksBookIncome,
                'quickbooks_books',
                'QuickBooks books',
                'Draft returns are using QuickBooks books corroborated against the bank ledger.',
            ];
        }

        if ($internalBookIncome > 0) {
            return [
                $internalBookIncome,
                'internal_cash_books',
                'Internal cash books',
                'Draft returns are using categorized bank-ledger income because that is the current reviewed book basis.',
            ];
        }

        return [
            $projectedAnnualRevenue,
            'projection_fallback',
            'Annual projection fallback',
            'No reviewed book-income basis was available, so the draft fell back to the projection layer.',
        ];
    }

    protected function businessExpenseBasis(
        float $quickBooksBookExpenses,
        float $internalBookExpenses,
        float $projectedAnnualExpenses,
        string $sourceOfTruthCode,
    ): float {
        if ($sourceOfTruthCode === 'quickbooks_plus_bank' && $quickBooksBookExpenses > 0) {
            return $quickBooksBookExpenses;
        }

        if ($internalBookExpenses > 0) {
            return $internalBookExpenses;
        }

        if ($quickBooksBookExpenses > 0) {
            return $quickBooksBookExpenses;
        }

        return $projectedAnnualExpenses;
    }

    protected function itemizedDeductions(?TaxProfile $profile): float
    {
        return (float) ($profile?->mortgage_interest_paid ?? 0)
            + (float) ($profile?->property_tax_paid ?? 0)
            + (float) ($profile?->charitable_contributions_paid ?? 0)
            + (float) ($profile?->medical_expenses_paid ?? 0);
    }
}
