<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Multnomah County Preschool For All (PFA) Tax Return.
 *
 * Generates a substitute PFA tax form. The PFA tax is a graduated
 * income tax on Multnomah County residents to fund the Preschool
 * For All program, with tiered rates based on taxable income.
 */
class MultnomahPfa extends TaxFormPdf
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
        return 'PFA Tax';
    }

    public function formTitle(): string
    {
        return 'Multnomah County Preschool For All Tax Return';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader('Multnomah County — Preschool For All Program');

        // Taxpayer information
        $this->sectionHeader('Taxpayer Information');

        $this->infoRow('Name', $this->data['taxpayer_name'] ?? '');

        if (($this->data['is_mfj'] ?? false) && ! empty($this->data['spouse_name'])) {
            $this->infoRow('Spouse name', $this->data['spouse_name']);
        }

        $this->infoRow('SSN', $this->data['ssn'] ?? 'XXX-XX-XXXX');
        $this->infoRow('Address', $this->data['address'] ?? '');

        $cityStateZip = trim(($this->data['city'] ?? '').', '.($this->data['state'] ?? '').' '.($this->data['zip'] ?? ''));
        $this->infoRow('City, State, ZIP', $cityStateZip);
        $this->pdf->Ln(2);

        // Filing status
        $filingStatus = $this->data['filing_status'] ?? 'single';

        $this->sectionHeader('Filing Status');
        $this->checkboxRow('Single', $filingStatus === 'single');
        $this->checkboxRow('Married filing jointly', $filingStatus === 'mfj');
        $this->checkboxRow('Married filing separately', $filingStatus === 'mfs');
        $this->checkboxRow('Head of household', $filingStatus === 'hoh');
        $this->pdf->Ln(2);

        // Income information
        $this->sectionHeader('Income');

        $isMfj = $this->data['is_mfj'] ?? false;
        $federalAgi = (float) ($this->data['oregon_agi'] ?? $this->data['agi'] ?? 0);
        $exemptionAmount = $isMfj ? 200000 : 100000;
        $taxableForPfa = round(max($federalAgi - $exemptionAmount, 0), 2);

        $this->lineItem('1', 'Federal adjusted gross income', $federalAgi);
        $this->lineItem('2', 'Exemption amount', $exemptionAmount);
        $this->lineItem('3', 'Taxable income for PFA. Subtract line 2 from line 1 (if zero or less, enter -0-)', $taxableForPfa, true);
        $this->pdf->Ln(2);

        // Tax rate tiers
        $this->sectionHeader('Tax Rate Schedule');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->Cell(0, 4, 'Single/MFS: 1.5% on income over $100,000; additional 1.5% on income over $250,000', 0, 1, 'L');
        $this->pdf->Cell(0, 4, 'MFJ/HOH/QW: 1.5% on income over $200,000; additional 1.5% on income over $400,000', 0, 1, 'L');
        $this->pdf->Ln(2);

        // Tax computation
        $this->sectionHeader('Tax Computation');

        $upperThreshold = $isMfj ? 400000 : 250000;
        $standardTierIncome = round(min(max($federalAgi - $exemptionAmount, 0), $upperThreshold - $exemptionAmount), 2);
        $upperTierIncome = round(max($federalAgi - $upperThreshold, 0), 2);

        $standardTierTax = round($standardTierIncome * 0.015, 2);
        $upperTierTax = round($upperTierIncome * 0.03, 2);
        $totalPfaTax = (float) ($this->data['multnomah_pfa_tax'] ?? round($standardTierTax + $upperTierTax, 2));

        $this->lineItem('4', 'Income subject to 1.5% rate', $standardTierIncome);
        $this->lineItem('5', 'Tax at 1.5%. Multiply line 4 by 0.015', $standardTierTax);
        $this->lineItem('6', 'Income subject to 3.0% rate (above upper threshold)', $upperTierIncome);
        $this->lineItem('7', 'Tax at 3.0%. Multiply line 6 by 0.03', $upperTierTax);
        $this->lineItem('8', 'Total Preschool For All tax. Add lines 5 and 7', $totalPfaTax, true);

        $this->pdf->Ln(2);

        // Payment information
        $this->sectionHeader('Payment Information');

        $this->lineItem('9', 'Amount paid with this return', $totalPfaTax);
        $this->lineItem('10', 'Penalty for late payment (see instructions)', 0);
        $this->lineItem('11', 'Interest on late payment', 0);
        $this->lineItem('12', 'Total amount due. Add lines 8, 10, and 11', $totalPfaTax, true);

        $this->pdf->Ln(4);
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->MultiCell(0, 4, 'The Preschool For All tax is administered by the Multnomah County Tax Administration. For more information, visit multco.us/preschool.', 0, 'L');

        return $this->output();
    }
}
