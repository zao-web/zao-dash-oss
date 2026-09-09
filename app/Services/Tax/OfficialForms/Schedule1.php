<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Generates Schedule 1 (Form 1040) — Additional Income and Adjustments to Income.
 *
 * Reports pass-through income from Schedule E and above-the-line adjustments
 * such as HSA contributions and health insurance deductions.
 */
class Schedule1 extends TaxFormPdf
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
        return 'Schedule 1';
    }

    public function formTitle(): string
    {
        return 'Additional Income and Adjustments to Income';
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

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(0, $this->lineHeight, 'Attach to Form 1040 or 1040-SR.', 0, 1, 'L');
        $this->pdf->Ln(1);

        $this->infoRow('Name(s) shown on Form 1040', $this->data['taxpayer_name'] ?? '');
        $this->infoRow('Your social security number', $this->data['ssn'] ?? 'XXX-XX-XXXX');

        $this->additionalIncomeSection();
        $this->adjustmentsSection();

        return $this->output();
    }

    protected function additionalIncomeSection(): void
    {
        $this->sectionHeader('Part I — Additional Income');

        $this->lineItem('1', 'Taxable refunds, credits, or offsets of state and local income taxes', 0);
        $this->lineItem('2a', 'Alimony received', null);
        $this->lineItem('3', 'Business income or (loss) (Schedule C)', 0);
        $this->lineItem('4', 'Other gains or (losses) (Form 4797)', null);
        $this->lineItem('5', 'Rental real estate, royalties, partnerships, S corporations (Schedule E)', $this->data['pass_through_income'] ?? 0);
        $this->lineItem('6', 'Farm income or (loss) (Schedule F)', null);
        $this->lineItem('7', 'Unemployment compensation', null);
        $this->lineItem('8', 'Other income', null);

        $this->pdf->Ln(1);
        $this->lineItem('10', 'Total additional income. Add lines 1 through 8', $this->data['pass_through_income'] ?? 0, true);
    }

    protected function adjustmentsSection(): void
    {
        $this->sectionHeader('Part II — Adjustments to Income');

        $profile = $this->data['profile'] ?? null;
        $hsaContributions = (float) ($profile?->hsa_contributions_paid ?? 0);
        $healthInsurance = (float) ($profile?->health_insurance_annual ?? 0);

        $this->lineItem('11', 'Educator expenses', null);
        $this->lineItem('12', 'Certain business expenses of reservists, performing artists, etc.', null);
        $this->lineItem('13', 'Moving expenses for Armed Forces', null);
        $this->lineItem('14', 'Deductible part of self-employment tax', null);
        $this->lineItem('15', 'HSA deduction', $hsaContributions > 0 ? $hsaContributions : null);
        $this->lineItem('16', 'Self-employed SEP, SIMPLE, and qualified plans', null);
        $this->lineItem('17', 'Self-employed health insurance deduction', null);
        $this->lineItem('18', 'Penalty on early withdrawal of savings', null);
        $this->lineItem('19', 'IRA deduction', null);
        $this->lineItem('20', 'Self-employment tax deduction', 0);
        $this->lineItem('21', 'Student loan interest deduction', null);
        $this->lineItem('22', 'Reserved for future use', null);
        $this->lineItem('23', 'Archer MSA deduction', null);
        $this->lineItem('24', 'Other adjustments', null);
        $this->lineItem('25', 'Health insurance deduction', $healthInsurance > 0 ? $healthInsurance : null);

        $this->pdf->Ln(1);
        $this->lineItem('26', 'Total adjustments to income. Add lines 11 through 25', $this->data['adjustments'] ?? 0, true);
    }
}
