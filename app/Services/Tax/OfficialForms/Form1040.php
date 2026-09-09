<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Generates IRS Form 1040 — U.S. Individual Income Tax Return.
 *
 * Two-page substitute form following Pub 1167 standards.
 */
class Form1040 extends TaxFormPdf
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
        return 'Form 1040';
    }

    public function formTitle(): string
    {
        return 'U.S. Individual Income Tax Return';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->generatePageOne();
        $this->generatePageTwo();

        return $this->output();
    }

    protected function generatePageOne(): void
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader();

        $this->filingStatusSection();
        $this->taxpayerInfoSection();
        $this->standardDeductionSection();
        $this->incomeSection();
    }

    protected function generatePageTwo(): void
    {
        $this->addPage();
        $this->draftWatermark();

        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(0, 6, 'Form 1040 ('.$this->year.') — Page 2', 0, 1, 'C');
        $this->pdf->Ln(2);

        $this->taxAndCreditsSection();
        $this->paymentsSection();
        $this->refundSection();
        $this->amountOwedSection();
        $this->signatureBlock();
    }

    protected function filingStatusSection(): void
    {
        $this->sectionHeader('Filing Status');

        $status = $this->data['filing_status'] ?? 'single';

        $this->checkboxRow('Single', $status === 'single');
        $this->checkboxRow('Married filing jointly (MFJ)', $status === 'mfj');
        $this->checkboxRow('Married filing separately (MFS)', $status === 'mfs');
        $this->checkboxRow('Head of household (HOH)', $status === 'hoh');
    }

    protected function taxpayerInfoSection(): void
    {
        $this->sectionHeader('Taxpayer Information');

        $this->infoRow('Your first name and middle initial, last name', $this->data['taxpayer_name'] ?? '');
        $this->infoRow('Your social security number', $this->data['ssn'] ?? 'XXX-XX-XXXX');

        if ($this->data['is_mfj'] ?? false) {
            $this->infoRow("Spouse's first name and middle initial, last name", $this->data['spouse_name'] ?? '');
            $this->infoRow("Spouse's social security number", $this->data['spouse_ssn'] ?? 'XXX-XX-XXXX');
        }

        $this->infoRow('Home address (number, street, and apt. no.)', $this->data['address'] ?? '');

        $cityStateZip = trim(
            ($this->data['city'] ?? '').', '.
            ($this->data['state'] ?? '').' '.
            ($this->data['zip'] ?? ''),
            ', '
        );
        $this->infoRow('City, town or post office, state, and ZIP code', $cityStateZip);

        $dependentCount = (int) ($this->data['dependent_count'] ?? 0);
        if ($dependentCount > 0) {
            $this->pdf->Ln(1);
            $this->pdf->SetFont('helvetica', '', 8);
            $this->pdf->Cell(0, $this->lineHeight, 'Dependents: '.$dependentCount, 0, 1, 'L');
        }
    }

    protected function standardDeductionSection(): void
    {
        $usesItemized = $this->data['uses_itemized'] ?? false;

        $this->pdf->Ln(1);
        $this->checkboxRow('Standard deduction', ! $usesItemized);
        $this->checkboxRow('Itemized deductions (from Schedule A)', $usesItemized);
    }

    protected function incomeSection(): void
    {
        $this->sectionHeader('Income');

        $this->lineItem('1a', 'Wages, salaries, tips, etc. (W-2)', $this->data['wages'] ?? 0);
        $this->lineItem('1z', 'Add lines 1a through 1h', $this->data['wages'] ?? 0, true);

        $this->pdf->Ln(1);
        $this->lineItem('8', 'Other income from Schedule 1, line 10', $this->data['pass_through_income'] ?? 0);
        $this->lineItem('9', 'Total income. Add lines 1z and 8', $this->data['total_income'] ?? 0, true);

        $this->pdf->Ln(1);
        $this->lineItem('10', 'Adjustments to income from Schedule 1, line 26', $this->data['adjustments'] ?? 0);
        $this->lineItem('11', 'Adjusted gross income. Subtract line 10 from line 9', $this->data['agi'] ?? 0, true);

        $this->pdf->Ln(1);
        $usesItemized = $this->data['uses_itemized'] ?? false;
        $deductionLabel = $usesItemized
            ? 'Itemized deductions (from Schedule A)'
            : 'Standard deduction';
        $this->lineItem('12', $deductionLabel, $this->data['deduction_used'] ?? 0);
        $this->lineItem('13', 'Qualified business income deduction (Form 8995)', $this->data['qbi_deduction'] ?? 0);
        $this->lineItem('14', 'Total deductions. Add lines 12 and 13', $this->data['total_deductions'] ?? 0, true);

        $this->pdf->Ln(1);
        $this->lineItem('15', 'Taxable income. Subtract line 14 from line 11', $this->data['taxable_income'] ?? 0, true);
    }

    protected function taxAndCreditsSection(): void
    {
        $this->sectionHeader('Tax and Credits');

        $federalTax = (float) ($this->data['federal_tax'] ?? 0);
        $childCredit = (float) ($this->data['child_tax_credit'] ?? 0);
        $taxBeforeCredits = round($federalTax + $childCredit, 2);

        $this->lineItem('16', 'Tax (from Tax Table or Tax Computation Worksheet)', $taxBeforeCredits);

        $this->pdf->Ln(1);
        $this->lineItem('19', 'Child tax credit / credit for other dependents', $childCredit);

        $this->pdf->Ln(1);
        $this->lineItem('22', 'Sum of credits (line 19)', $childCredit, true);

        $this->pdf->Ln(1);
        $this->lineItem('24', 'Total tax', $federalTax, true);
    }

    protected function paymentsSection(): void
    {
        $this->sectionHeader('Payments');

        $federalEstimated = (float) ($this->data['federal_estimated_payments'] ?? 0);
        $priorYearCredit = (float) ($this->data['prior_year_credit'] ?? 0);
        $estimatedTotal = round($federalEstimated + $priorYearCredit, 2);

        $this->lineItem('26', 'Estimated tax payments and amount applied from prior year return', $estimatedTotal);

        $this->pdf->Ln(1);
        $this->lineItem('33', 'Total payments', $this->data['total_payments'] ?? 0, true);
    }

    protected function refundSection(): void
    {
        $this->sectionHeader('Refund');

        $refund = (float) ($this->data['refund'] ?? 0);
        $this->lineItem('34', 'If line 33 is more than line 24, overpayment', $refund > 0 ? $refund : null);
    }

    protected function amountOwedSection(): void
    {
        $this->sectionHeader('Amount You Owe');

        $owed = (float) ($this->data['amount_owed'] ?? 0);
        $this->lineItem('37', 'Amount you owe. Subtract line 33 from line 24', $owed > 0 ? $owed : null);
    }

    protected function signatureBlock(): void
    {
        $this->pdf->Ln(4);
        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->Line($this->leftMargin, $this->pdf->GetY(), $this->pageWidth - $this->rightMargin, $this->pdf->GetY());
        $this->pdf->Ln(2);

        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(0, $this->lineHeight, 'Sign Here', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->Cell(0, $this->lineHeight, 'Under penalties of perjury, I declare that I have examined this return and accompanying schedules and statements,', 0, 1, 'L');
        $this->pdf->Cell(0, $this->lineHeight, 'and to the best of my knowledge and belief, they are true, correct, and complete.', 0, 1, 'L');

        $this->pdf->Ln(4);
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(90, $this->lineHeight, 'Your signature', 'B', 0, 'L');
        $this->pdf->Cell(5, $this->lineHeight, '', 0, 0);
        $this->pdf->Cell(0, $this->lineHeight, 'Date', 'B', 1, 'L');

        if ($this->data['is_mfj'] ?? false) {
            $this->pdf->Ln(2);
            $this->pdf->Cell(90, $this->lineHeight, "Spouse's signature (if joint return)", 'B', 0, 'L');
            $this->pdf->Cell(5, $this->lineHeight, '', 0, 0);
            $this->pdf->Cell(0, $this->lineHeight, 'Date', 'B', 1, 'L');
        }
    }
}
