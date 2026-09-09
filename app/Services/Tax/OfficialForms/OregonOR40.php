<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Oregon Form OR-40 - Oregon Individual Income Tax Return.
 *
 * Generates a substitute Oregon OR-40 including federal AGI tie-in,
 * Oregon additions/subtractions, state taxable income, tax computation,
 * estimated payments, credits, and refund or amount owed.
 */
class OregonOR40 extends TaxFormPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected array $data,
        protected int $year,
    ) {
        parent::__construct();
    }

    public function formNumber(): string
    {
        return 'Form OR-40';
    }

    public function formTitle(): string
    {
        return 'Oregon Individual Income Tax Return';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader('Oregon Department of Revenue');

        // Taxpayer information
        $filingStatus = $this->data['filing_status'] ?? 'single';
        $filingStatusLabel = match ($filingStatus) {
            'mfj' => 'Married filing jointly',
            'mfs' => 'Married filing separately',
            'hoh' => 'Head of household',
            'qw' => 'Qualifying surviving spouse',
            default => 'Single',
        };

        $this->sectionHeader('Filing Information');
        $this->infoRow('Filing status', $filingStatusLabel);
        $this->infoRow('Name', $this->data['taxpayer_name'] ?? '');

        if (($this->data['is_mfj'] ?? false) && ! empty($this->data['spouse_name'])) {
            $this->infoRow('Spouse name', $this->data['spouse_name']);
        }

        $this->infoRow('SSN', $this->data['ssn'] ?? 'XXX-XX-XXXX');
        $this->infoRow('Address', $this->data['address'] ?? '');

        $cityStateZip = trim(($this->data['city'] ?? '').', '.($this->data['state'] ?? '').' '.($this->data['zip'] ?? ''));
        $this->infoRow('City, State, ZIP', $cityStateZip);
        $this->pdf->Ln(2);

        // Income
        $this->sectionHeader('Income');

        $oregonAgi = (float) ($this->data['oregon_agi'] ?? 0);
        $oregonTaxableIncome = (float) ($this->data['oregon_taxable_income'] ?? 0);
        $oregonTax = (float) ($this->data['oregon_tax'] ?? 0);

        $this->lineItem('7', 'Federal adjusted gross income (from federal Form 1040)', $oregonAgi, true);
        $this->lineItem('8', 'Oregon additions', 0);
        $this->lineItem('9', 'Total. Add lines 7 and 8', $oregonAgi);

        // Subtractions and adjustments
        $this->sectionHeader('Subtractions and Adjustments');

        $oregonSubtractions = round(max($oregonAgi - $oregonTaxableIncome, 0), 2);

        $this->lineItem('10', 'Oregon social security modification', 0);
        $this->lineItem('11', 'Oregon standard deduction or itemized deductions', $oregonSubtractions);
        $this->lineItem('12', 'Total subtractions', $oregonSubtractions);
        $this->lineItem('13', 'Oregon adjusted income. Line 9 minus line 12', $oregonTaxableIncome);
        $this->lineItem('14', 'Oregon special deductions', 0);

        $this->pdf->Ln(2);

        // Tax computation
        $this->sectionHeader('Tax Computation');

        $this->lineItem('19', 'Oregon taxable income', $oregonTaxableIncome, true);
        $this->lineItem('20', 'Tax from tax rate chart or tables', $oregonTax, true);
        $this->lineItem('21', 'Interest on certain installment sales', 0);
        $this->lineItem('22', 'Total Oregon income tax. Add lines 20 and 21', $oregonTax);

        // Standard credits
        $this->sectionHeader('Standard Credits');

        $this->lineItem('23', 'Exemption credit', 0);
        $this->lineItem('24', 'Political contribution credit', 0);
        $this->lineItem('25', 'Total standard credits', 0);
        $this->lineItem('26', 'Net income tax. Line 22 minus line 25', $oregonTax);

        $this->pdf->Ln(2);

        // Payments and credits
        $this->sectionHeader('Payments and Credits');

        $estimatedPayments = (float) ($this->data['oregon_estimated_payments'] ?? 0);
        $priorYearCredit = (float) ($this->data['oregon_prior_year_credit'] ?? 0);
        $totalPayments = round($estimatedPayments + $priorYearCredit, 2);

        $this->lineItem('38', 'Oregon income tax withheld / estimated payments', $estimatedPayments);
        $this->lineItem('39', 'Other payments', 0);
        $this->lineItem('40', 'Earned income credit', 0);
        $this->lineItem('41', 'Other refundable credits', 0);
        $this->lineItem('42', 'Prior year credit applied', $priorYearCredit);
        $this->lineItem('43', 'Total payments and credits. Add lines 38 through 42', $totalPayments, true);

        $this->pdf->Ln(2);

        // Refund or amount owed
        $this->sectionHeader('Refund or Amount Owed');

        $refund = round(max($totalPayments - $oregonTax, 0), 2);
        $amountOwed = round(max($oregonTax - $totalPayments, 0), 2);

        if ($refund > 0) {
            $this->lineItem('44', 'Overpayment. Line 43 minus line 26', $refund, true);
            $this->lineItem('45', 'Amount of line 44 you want refunded to you', $refund);
            $this->lineItem('46', 'Amount of line 44 you want applied to next year', 0);
        } else {
            $this->lineItem('44', 'Overpayment', 0);
        }

        if ($amountOwed > 0) {
            $this->lineItem('47', 'Tax to pay. Line 26 minus line 43', $amountOwed, true);
            $this->lineItem('48', 'Estimated tax penalty (see instructions)', 0);
            $this->lineItem('49', 'Interest on underpayment of estimated tax', 0);
            $this->lineItem('50', 'Total due. Add lines 47, 48, and 49', $amountOwed, true);
        }

        return $this->output();
    }
}
