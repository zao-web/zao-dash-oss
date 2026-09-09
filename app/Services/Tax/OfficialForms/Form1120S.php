<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Generates IRS Form 1120-S (U.S. Income Tax Return for an S Corporation).
 *
 * Produces a substitute form PDF conforming to IRS Publication 1167 standards.
 * Covers Page 1 income/deductions, Schedule K summary, and signature block.
 */
class Form1120S extends TaxFormPdf
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
        return 'Form 1120-S';
    }

    public function formTitle(): string
    {
        return 'U.S. Income Tax Return for an S Corporation';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->generatePageOne();
        $this->generateScheduleK();

        return $this->output();
    }

    protected function generatePageOne(): void
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader();

        $this->renderEntityInfo();
        $this->renderIncomeSection();
        $this->renderDeductionsSection();
        $this->renderTaxAndPayments();
        $this->renderSignatureBlock();
    }

    protected function renderEntityInfo(): void
    {
        $this->sectionHeader('Entity Information');

        $this->infoRow('Name of corporation:', $this->data['entity_name'] ?? 'S Corporation');
        $this->infoRow('Employer identification number:', $this->data['entity_ein'] ?? 'XX-XXXXXXX');
        $this->infoRow('Address:', $this->data['entity_address'] ?? '');

        $profile = $this->data['profile'] ?? null;
        $dateIncorporated = $profile?->entity_date_incorporated ?? '';
        $businessCode = $profile?->business_activity_code ?? '541500';

        $this->infoRow('Date incorporated:', $dateIncorporated ?: 'N/A');
        $this->infoRow('Business activity code:', $businessCode);
        $this->infoRow('Number of shareholders:', '1');

        $this->checkboxRow('S election effective date for this tax year', true);
        $this->checkboxRow('Initial return', false);
        $this->checkboxRow('Final return', false);
        $this->checkboxRow('Amended return', false);

        $this->pdf->Ln(1);
    }

    protected function renderIncomeSection(): void
    {
        $grossReceipts = (float) ($this->data['gross_receipts'] ?? 0);
        $ordinaryIncome = (float) ($this->data['ordinary_business_income'] ?? 0);

        $this->sectionHeader('Income');

        $this->lineItem('1a', 'Gross receipts or sales', $grossReceipts);
        $this->lineItem('1b', 'Returns and allowances', 0);
        $this->lineItem('1c', 'Balance. Subtract line 1b from line 1a', $grossReceipts);
        $this->lineItem('2', 'Cost of goods sold (attach Form 1125-A)', 0);
        $this->lineItem('3', 'Gross profit. Subtract line 2 from line 1c', $grossReceipts, true);
        $this->lineItem('4', 'Net gain (loss) from Form 4797, Part II, line 17', 0);
        $this->lineItem('5', 'Other income (loss) (see instructions — attach statement)', 0);
        $this->lineItem('6', 'Total income (loss). Add lines 3 through 5', $grossReceipts, true);
    }

    protected function renderDeductionsSection(): void
    {
        $officerComp = (float) ($this->data['officer_compensation'] ?? 0);
        $otherDeductions = (float) ($this->data['other_deductions'] ?? 0);
        $totalDeductions = (float) ($this->data['total_deductions_1120s'] ?? 0);
        $ordinaryIncome = (float) ($this->data['ordinary_business_income'] ?? 0);

        $this->sectionHeader('Deductions (see instructions for limitations)');

        $this->lineItem('7', 'Compensation of officers (see instructions — attach Form 1125-E)', $officerComp);
        $this->lineItem('8', 'Salaries and wages (less employment credits)', 0);
        $this->lineItem('9', 'Repairs and maintenance', 0);
        $this->lineItem('10', 'Bad debts', 0);
        $this->lineItem('11', 'Rents', 0);
        $this->lineItem('12', 'Taxes and licenses', 0);
        $this->lineItem('13', 'Interest', 0);
        $this->lineItem('14', 'Depreciation not claimed on Form 1125-A or elsewhere on return', 0);
        $this->lineItem('15', 'Depletion (Do not deduct oil and gas depletion.)', 0);
        $this->lineItem('16', 'Advertising', 0);
        $this->lineItem('17', 'Pension, profit-sharing, etc., plans', 0);
        $this->lineItem('18', 'Employee benefit programs', 0);
        $this->lineItem('19', 'Other deductions (attach statement)', $otherDeductions);
        $this->lineItem('20', 'Total deductions. Add lines 7 through 19', $totalDeductions, true);
        $this->lineItem('21', 'Ordinary business income (loss). Subtract line 20 from line 6', $ordinaryIncome, true);
    }

    protected function renderTaxAndPayments(): void
    {
        $this->sectionHeader('Tax and Payments');

        $this->lineItem('22a', 'Excess net passive income or LIFO recapture tax', 0);
        $this->lineItem('22b', 'Tax from Schedule D (Form 1120-S)', 0);
        $this->lineItem('22c', 'Add lines 22a and 22b', 0);
        $this->lineItem('23a', 'Estimated tax payments', 0);
        $this->lineItem('23b', 'Tax deposited with Form 7004', 0);
        $this->lineItem('23c', 'Credit for federal tax paid on fuels (attach Form 4136)', 0);
        $this->lineItem('23d', 'Add lines 23a through 23c', 0);
        $this->lineItem('24', 'Estimated tax penalty (see instructions)', 0);
        $this->lineItem('25', 'Amount owed', 0);
        $this->lineItem('26', 'Overpayment', 0);
    }

    protected function renderSignatureBlock(): void
    {
        $this->pdf->Ln(4);
        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->Line(
            $this->leftMargin,
            $this->pdf->GetY(),
            $this->pageWidth - $this->rightMargin,
            $this->pdf->GetY()
        );
        $this->pdf->Ln(2);

        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell(0, $this->lineHeight, 'Sign Here', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->Cell(0, $this->lineHeight, 'Under penalties of perjury, I declare that I have examined this return, including accompanying schedules and statements, and to the', 0, 1, 'L');
        $this->pdf->Cell(0, $this->lineHeight, 'best of my knowledge and belief, it is true, correct, and complete. Declaration of preparer (other than taxpayer) is based on all information of which preparer has any knowledge.', 0, 1, 'L');

        $this->pdf->Ln(3);
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(90, $this->lineHeight, 'Signature of officer: ________________________________', 0, 0, 'L');
        $this->pdf->Cell(0, $this->lineHeight, 'Date: ______________', 0, 1, 'L');

        $this->pdf->Cell(90, $this->lineHeight, 'Title: ________________________________', 0, 0, 'L');
        $this->pdf->Cell(0, $this->lineHeight, 'Phone: ______________', 0, 1, 'L');
    }

    protected function generateScheduleK(): void
    {
        $ordinaryIncome = (float) ($this->data['ordinary_business_income'] ?? 0);
        $distributions = (float) ($this->data['shareholder_distributions'] ?? 0);
        $qbiWageBasis = (float) ($this->data['qbi_wage_basis'] ?? 0);

        $this->addPage();
        $this->draftWatermark();

        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 7, 'Schedule K — Shareholders\' Pro Rata Share Items', 0, 1, 'L');
        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->Line(
            $this->leftMargin,
            $this->pdf->GetY(),
            $this->pageWidth - $this->rightMargin,
            $this->pdf->GetY()
        );
        $this->pdf->Ln(3);

        $this->sectionHeader('Income (Loss)');
        $this->lineItem('1', 'Ordinary business income (loss)', $ordinaryIncome, true);
        $this->lineItem('2', 'Net rental real estate income (loss)', 0);
        $this->lineItem('3', 'Other net rental income (loss)', 0);
        $this->lineItem('4', 'Interest income', 0);
        $this->lineItem('5a', 'Ordinary dividends', 0);
        $this->lineItem('5b', 'Qualified dividends', 0);
        $this->lineItem('6', 'Royalties', 0);
        $this->lineItem('7', 'Net short-term capital gain (loss)', 0);
        $this->lineItem('8a', 'Net long-term capital gain (loss)', 0);
        $this->lineItem('9', 'Net section 1231 gain (loss)', 0);
        $this->lineItem('10', 'Other income (loss)', 0);

        $this->sectionHeader('Deductions');
        $this->lineItem('11', 'Section 179 deduction', 0);
        $this->lineItem('12', 'Other deductions', 0);

        $this->sectionHeader('Credits & Foreign Transactions');
        $this->lineItem('13a', 'Low-income housing credit (section 42(j)(5))', 0);
        $this->lineItem('14', 'Foreign transactions — not applicable', '—');
        $this->lineItem('15', 'Alternative minimum tax (AMT) items — not applicable', '—');

        $this->sectionHeader('Items Affecting Shareholder Basis');
        $this->lineItem('16a', 'Tax-exempt interest income', 0);
        $this->lineItem('16b', 'Other tax-exempt income', 0);
        $this->lineItem('16c', 'Nondeductible expenses', 0);
        $this->lineItem('16d', 'Distributions (property and cash)', $distributions);

        $this->sectionHeader('Other Information — Section 199A (QBI)');
        $this->lineItem('17a', 'Ordinary business income (loss) — QBI', $ordinaryIncome);
        $this->lineItem('17b', 'W-2 wages', $qbiWageBasis);
        $this->lineItem('17c', 'UBIA of qualified property', 0);
    }
}
