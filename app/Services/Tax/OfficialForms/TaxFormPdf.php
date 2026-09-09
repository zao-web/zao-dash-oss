<?php

namespace App\Services\Tax\OfficialForms;

use TCPDF;

/**
 * Base class for IRS-substitute tax form PDF generation.
 *
 * Generates forms that conform to IRS Publication 1167 substitute form
 * standards using TCPDF. Each subclass represents one official form.
 */
abstract class TaxFormPdf
{
    protected TCPDF $pdf;

    protected float $leftMargin = 15;

    protected float $rightMargin = 15;

    protected float $lineHeight = 5.5;

    protected float $pageWidth = 215.9; // Letter width in mm

    protected float $contentWidth;

    public function __construct()
    {
        $this->pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $this->pdf->SetCreator('ZAO Tax Office');
        $this->pdf->SetAuthor('ZAO Web Design');
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins($this->leftMargin, 10, $this->rightMargin);
        $this->pdf->SetAutoPageBreak(true, 15);
        $this->contentWidth = $this->pageWidth - $this->leftMargin - $this->rightMargin;
    }

    abstract public function generate(): string;

    abstract public function formNumber(): string;

    abstract public function formTitle(): string;

    abstract public function taxYear(): int;

    protected function addPage(): void
    {
        $this->pdf->AddPage();
    }

    protected function formHeader(string $department = 'Department of the Treasury — Internal Revenue Service'): void
    {
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 4, $department, 0, 1, 'L');

        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Cell(100, 8, $this->formNumber(), 0, 0, 'L');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 8, 'OMB No. 1545-0123', 0, 1, 'R');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Cell(0, 5, $this->formTitle(), 0, 1, 'L');

        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->Cell(0, 5, 'For calendar year '.$this->taxYear().' or tax year beginning __________, '.$this->taxYear().', ending __________, 20__', 0, 1, 'L');

        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->Line($this->leftMargin, $this->pdf->GetY() + 1, $this->pageWidth - $this->rightMargin, $this->pdf->GetY() + 1);
        $this->pdf->Ln(3);
    }

    protected function sectionHeader(string $title): void
    {
        $this->pdf->Ln(2);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColor(230, 230, 230);
        $this->pdf->Cell(0, 6, '  '.$title, 0, 1, 'L', true);
        $this->pdf->Ln(1);
    }

    protected function lineItem(string $lineNumber, string $description, float|string|null $amount, bool $bold = false): void
    {
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(10, $this->lineHeight, $lineNumber, 0, 0, 'R');

        $this->pdf->SetFont('helvetica', $bold ? 'B' : '', 8);
        $this->pdf->Cell($this->contentWidth - 50, $this->lineHeight, '  '.$description, 0, 0, 'L');

        $this->pdf->SetFont('courier', $bold ? 'B' : '', 9);

        if (is_numeric($amount)) {
            $formatted = $this->money($amount);
            $this->pdf->Cell(40, $this->lineHeight, $formatted, 0, 1, 'R');
        } else {
            $this->pdf->Cell(40, $this->lineHeight, (string) ($amount ?? ''), 0, 1, 'R');
        }
    }

    protected function lineItemWide(string $lineNumber, string $description, float|string|null $amount, bool $bold = false): void
    {
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(10, $this->lineHeight, $lineNumber, 0, 0, 'R');

        $this->pdf->SetFont('helvetica', $bold ? 'B' : '', 8);
        $this->pdf->Cell($this->contentWidth - 35, $this->lineHeight, '  '.$description, 'B', 0, 'L');

        $this->pdf->SetFont('courier', $bold ? 'B' : '', 9);
        if (is_numeric($amount)) {
            $this->pdf->Cell(25, $this->lineHeight, $this->money($amount), 'B', 1, 'R');
        } else {
            $this->pdf->Cell(25, $this->lineHeight, (string) ($amount ?? ''), 'B', 1, 'R');
        }
    }

    protected function infoRow(string $label, string $value): void
    {
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(45, $this->lineHeight, $label, 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(0, $this->lineHeight, $value, 'B', 1, 'L');
    }

    protected function checkboxRow(string $label, bool $checked): void
    {
        $this->pdf->SetFont('helvetica', '', 8);
        $marker = $checked ? '[X]' : '[ ]';
        $this->pdf->Cell(0, $this->lineHeight, $marker.'  '.$label, 0, 1, 'L');
    }

    protected function draftWatermark(): void
    {
        $this->pdf->SetFont('helvetica', 'B', 50);
        $this->pdf->SetTextColor(220, 220, 220);
        $this->pdf->StartTransform();
        $this->pdf->Rotate(45, 105, 150);
        $this->pdf->Text(40, 150, 'DRAFT — DO NOT FILE');
        $this->pdf->StopTransform();
        $this->pdf->SetTextColor(0, 0, 0);
    }

    protected function money(float $amount): string
    {
        if ($amount < 0) {
            return '('.number_format(abs($amount), 0).')';
        }

        return number_format($amount, 0);
    }

    protected function moneyDecimal(float $amount): string
    {
        if ($amount < 0) {
            return '('.number_format(abs($amount), 2).')';
        }

        return number_format($amount, 2);
    }

    protected function output(): string
    {
        return $this->pdf->Output('', 'S');
    }
}
