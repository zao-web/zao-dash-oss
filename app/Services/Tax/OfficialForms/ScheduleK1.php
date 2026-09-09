<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Generates Schedule K-1 (Form 1120-S) — Shareholder's Share of Income,
 * Deductions, Credits, etc.
 *
 * Produces a substitute form PDF conforming to IRS Publication 1167 standards.
 * Covers Part I (Corporation Info), Part II (Shareholder Info), and
 * Part III (Shareholder's Share of Current Year Income).
 */
class ScheduleK1 extends TaxFormPdf
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
        return 'Schedule K-1 (Form 1120-S)';
    }

    public function formTitle(): string
    {
        return "Shareholder's Share of Income, Deductions, Credits, etc.";
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

        $this->renderPartI();
        $this->renderPartII();
        $this->renderPartIII();

        return $this->output();
    }

    /**
     * Part I — Information About the Corporation.
     */
    protected function renderPartI(): void
    {
        $this->sectionHeader('Part I — Information About the Corporation');

        $this->infoRow('A  Corporation\'s EIN:', $this->data['entity_ein'] ?? 'XX-XXXXXXX');
        $this->infoRow('B  Corporation\'s name:', $this->data['entity_name'] ?? 'S Corporation');
        $this->infoRow('   Corporation\'s address:', $this->data['entity_address'] ?? '');

        $this->pdf->Ln(1);

        $this->checkboxRow('C  IRS Center where corporation filed return: Ogden, UT', true);
        $this->checkboxRow('D  Corporation\'s total number of shares: beginning _____ / ending _____', false);

        $this->pdf->Ln(1);
    }

    /**
     * Part II — Information About the Shareholder.
     */
    protected function renderPartII(): void
    {
        $this->sectionHeader('Part II — Information About the Shareholder');

        $this->infoRow('E  Shareholder\'s SSN:', $this->formatSsn($this->data['ssn'] ?? 'XXX-XX-XXXX'));
        $this->infoRow('F  Shareholder\'s name:', $this->data['taxpayer_name'] ?? 'Taxpayer');

        $address = $this->buildShareholderAddress();
        $this->infoRow('   Shareholder\'s address:', $address);

        $this->pdf->Ln(1);

        $this->infoRow('G  Shareholder\'s percentage of stock ownership:', '100%');
        $this->infoRow('   for tax year:', (string) $this->year);

        $this->pdf->Ln(1);

        $this->checkboxRow('H  Current year allocation percentage: 100.000000%', true);
        $this->checkboxRow('I  Shareholder\'s share of liabilities: Nonrecourse $0', true);

        $this->pdf->Ln(1);
    }

    /**
     * Part III — Shareholder's Share of Current Year Income, Deductions, Credits, and Other Items.
     */
    protected function renderPartIII(): void
    {
        $ordinaryIncome = (float) ($this->data['ordinary_business_income'] ?? 0);
        $distributions = (float) ($this->data['shareholder_distributions'] ?? 0);
        $qbiWageBasis = (float) ($this->data['qbi_wage_basis'] ?? 0);

        $this->sectionHeader('Part III — Shareholder\'s Share of Current Year Income, Deductions, Credits, and Other Items');

        $this->lineItem('1', 'Ordinary business income (loss)', $ordinaryIncome, true);
        $this->lineItem('2', 'Net rental real estate income (loss)', 0);
        $this->lineItem('3', 'Other net rental income (loss)', 0);
        $this->lineItem('4', 'Interest income', 0);
        $this->lineItem('5a', 'Ordinary dividends', 0);
        $this->lineItem('5b', 'Qualified dividends', 0);
        $this->lineItem('6', 'Royalties', 0);
        $this->lineItem('7', 'Net short-term capital gain (loss)', 0);
        $this->lineItem('8a', 'Net long-term capital gain (loss)', 0);
        $this->lineItem('8b', 'Collectibles (28%) gain (loss)', 0);
        $this->lineItem('8c', 'Unrecaptured section 1250 gain', 0);
        $this->lineItem('9', 'Net section 1231 gain (loss)', 0);
        $this->lineItem('10', 'Other income (loss)', 0);

        $this->pdf->Ln(1);

        $this->lineItem('11', 'Section 179 deduction', 0);
        $this->lineItem('12', 'Other deductions', 0);

        $this->pdf->Ln(1);

        $this->lineItem('13', 'Credits', 0);
        $this->lineItem('14', 'Foreign transactions — not applicable', '—');
        $this->lineItem('15', 'Alternative minimum tax (AMT) items — not applicable', '—');

        $this->pdf->Ln(1);

        $this->sectionHeader('Items Affecting Shareholder Basis');
        $this->lineItem('16a', 'Tax-exempt interest income', 0);
        $this->lineItem('16b', 'Other tax-exempt income', 0);
        $this->lineItem('16c', 'Nondeductible expenses', 0);
        $this->lineItem('16d', 'Distributions (property and cash)', $distributions);

        $this->pdf->Ln(1);

        $this->sectionHeader('Section 199A — Qualified Business Income (QBI)');
        $this->lineItemWide('17V', 'Section 199A — QBI', $ordinaryIncome, true);
        $this->lineItemWide('17W', 'W-2 wages', $qbiWageBasis);
        $this->lineItemWide('17X', 'UBIA of qualified property', 0);

        $this->pdf->Ln(2);

        $this->renderFooterNotes();
    }

    protected function renderFooterNotes(): void
    {
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 4, '* If the corporation has multiple activities, it must report information for each activity on attached statements.', 0, 1, 'L');
        $this->pdf->Cell(0, 4, 'See separate instructions for Schedule K-1 (Form 1120-S).', 0, 1, 'L');
        $this->pdf->SetTextColor(0, 0, 0);
    }

    protected function buildShareholderAddress(): string
    {
        $parts = array_filter([
            $this->data['address'] ?? '',
            $this->data['city'] ?? '',
            $this->data['state'] ?? '',
            $this->data['zip'] ?? '',
        ]);

        if (empty($parts)) {
            return '';
        }

        $address = $this->data['address'] ?? '';
        $cityStateZip = implode(', ', array_filter([
            $this->data['city'] ?? '',
            $this->data['state'] ?? '',
        ]));

        $zip = $this->data['zip'] ?? '';
        if ($zip !== '') {
            $cityStateZip .= ' '.$zip;
        }

        return trim($address.' '.$cityStateZip);
    }

    protected function formatSsn(string $ssn): string
    {
        $digits = preg_replace('/\D/', '', $ssn);

        if ($digits !== null && strlen($digits) === 9) {
            return substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5, 4);
        }

        return $ssn;
    }
}
