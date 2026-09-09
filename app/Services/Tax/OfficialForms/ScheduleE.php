<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Schedule E (Form 1040) - Supplemental Income and Loss, Page 2.
 *
 * Generates Part II of Schedule E showing income or loss from
 * partnerships and S corporations (pass-through entities).
 */
class ScheduleE extends TaxFormPdf
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
        return 'Schedule E (Form 1040)';
    }

    public function formTitle(): string
    {
        return 'Supplemental Income and Loss';
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

        // Part II header
        $this->sectionHeader('Part II — Income or Loss From Partnerships and S Corporations');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->Cell(0, 4, 'If you report a loss, receive a distribution, dispose of stock, or receive a loan repayment from an S corporation, see instructions.', 0, 1, 'L');
        $this->pdf->Ln(2);

        // Column headers
        $this->pdf->SetFont('helvetica', 'B', 7);
        $col1 = 10;
        $col2 = 55;
        $col3 = 25;
        $col4 = 15;
        $col5 = 15;
        $col6 = 30;
        $col7 = 30;

        $this->pdf->Cell($col1, 4, '', 0, 0);
        $this->pdf->Cell($col2, 4, '(a) Name', 'B', 0, 'L');
        $this->pdf->Cell($col3, 4, '(b) EIN', 'B', 0, 'C');
        $this->pdf->Cell($col4, 4, '(c) Check', 'B', 0, 'C');
        $this->pdf->Cell($col5, 4, '', 'B', 0, 'C');
        $this->pdf->Cell($col6, 4, '(d) Passive income', 'B', 0, 'R');
        $this->pdf->Cell($col7, 4, '(e) Nonpassive income', 'B', 1, 'R');
        $this->pdf->Ln(1);

        // Entity line
        $entityName = $this->data['entity_name'] ?? 'S Corporation';
        $entityEin = $this->data['entity_ein'] ?? 'XX-XXXXXXX';
        $passThroughIncome = (float) ($this->data['pass_through_income'] ?? 0);

        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell($col1, $this->lineHeight, '28', 0, 0, 'R');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell($col2, $this->lineHeight, '  '.$entityName, 'B', 0, 'L');
        $this->pdf->Cell($col3, $this->lineHeight, $entityEin, 'B', 0, 'C');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->Cell($col4, $this->lineHeight, 'S corp', 'B', 0, 'C');
        $this->pdf->Cell($col5, $this->lineHeight, '', 'B', 0, 'C');

        $this->pdf->SetFont('courier', '', 9);
        $this->pdf->Cell($col6, $this->lineHeight, '', 'B', 0, 'R');
        $this->pdf->Cell($col7, $this->lineHeight, $this->money($passThroughIncome), 'B', 1, 'R');

        $this->pdf->Ln(4);

        // Totals section
        $this->lineItem('29a', 'Totals — passive income', 0);
        $this->lineItem('29b', 'Totals — passive loss', 0);
        $this->lineItem('30', 'Totals — nonpassive income', $passThroughIncome);
        $this->lineItem('31', 'Totals — nonpassive loss', 0);
        $this->pdf->Ln(2);

        $this->lineItem('32', 'Total partnership and S corporation income or (loss). Combine lines 29a through 31', $passThroughIncome, true);

        return $this->output();
    }
}
