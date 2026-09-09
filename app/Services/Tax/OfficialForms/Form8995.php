<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Form 8995 - Qualified Business Income Deduction (Simplified Computation).
 *
 * Generates a substitute Form 8995 for taxpayers eligible for the
 * simplified QBI deduction calculation (taxable income at or below threshold).
 */
class Form8995 extends TaxFormPdf
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
        return 'Form 8995';
    }

    public function formTitle(): string
    {
        return 'Qualified Business Income Deduction Simplified Computation';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader();

        $this->infoRow('Name(s) shown on return', $this->data['taxpayer_name'] ?? '');
        $this->infoRow('Your social security number', $this->data['ssn'] ?? 'XXX-XX-XXXX');
        $this->pdf->Ln(2);

        $entityName = $this->data['entity_name'] ?? 'S Corporation';
        $entityEin = $this->data['entity_ein'] ?? 'XX-XXXXXXX';
        $qbi = (float) ($this->data['ordinary_business_income'] ?? 0);
        $qbiDeduction = (float) ($this->data['qbi_deduction'] ?? 0);
        $taxableIncome = (float) ($this->data['taxable_income'] ?? 0);

        // Taxable income before QBI deduction
        $taxableIncomeBeforeQbi = round($taxableIncome + $qbiDeduction, 2);

        // Line 1: Trade or business information
        $this->sectionHeader('Qualified Business Income or (Loss)');

        // Column headers
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(10, 4, '', 0, 0);
        $this->pdf->Cell(55, 4, '(a) Trade or business name', 'B', 0, 'L');
        $this->pdf->Cell(35, 4, '(b) Taxpayer ID number', 'B', 0, 'C');
        $this->pdf->Cell(0, 4, '(c) Qualified business income', 'B', 1, 'R');
        $this->pdf->Ln(1);

        // Line 1 data row
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(10, $this->lineHeight, '1', 0, 0, 'R');
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(55, $this->lineHeight, '  '.$entityName, 'B', 0, 'L');
        $this->pdf->Cell(35, $this->lineHeight, $entityEin, 'B', 0, 'C');
        $this->pdf->SetFont('courier', '', 9);
        $this->pdf->Cell(0, $this->lineHeight, $this->money($qbi), 'B', 1, 'R');

        $this->pdf->Ln(4);

        // QBI Deduction calculation
        $this->sectionHeader('Qualified Business Income Deduction');

        $qbiBeforeLimitation = round($qbi * 0.20, 2);
        $netCapitalGain = 0;
        $line6 = round($taxableIncomeBeforeQbi - $netCapitalGain, 2);
        $incomeLimitation = round($line6 * 0.20, 2);

        $this->lineItem('2', 'Total qualified business income or (loss). Combine lines 1i through 1v, column (c)', $qbi);
        $this->lineItem('3', 'Qualified business income component. Multiply line 2 by 20% (0.20)', $qbiBeforeLimitation);
        $this->lineItem('4', 'Taxable income before qualified business income deduction', $taxableIncomeBeforeQbi);
        $this->lineItem('5', 'Net capital gain (see instructions)', $netCapitalGain);
        $this->lineItem('6', 'Subtract line 5 from line 4. If zero or less, enter -0-', max($line6, 0));
        $this->lineItem('7', 'Income limitation. Multiply line 6 by 20% (0.20)', $incomeLimitation);
        $this->pdf->Ln(2);

        $this->lineItem('8', 'Qualified REIT dividends and publicly traded partnership income', 0);
        $this->lineItem('9', 'REIT and PTP component. Multiply line 8 by 20% (0.20)', 0);
        $this->pdf->Ln(2);

        $this->lineItem('10', 'Qualified business income deduction. Add lines 3 and 9 (limited by line 7)', $qbiDeduction, true);

        return $this->output();
    }
}
