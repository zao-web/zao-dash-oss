<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Schedule A (Form 1040) - Itemized Deductions.
 *
 * Generates an IRS-substitute Schedule A showing medical expenses,
 * taxes paid (with SALT cap), interest, charitable contributions,
 * and total itemized deductions.
 */
class ScheduleA extends TaxFormPdf
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
        return 'Schedule A (Form 1040)';
    }

    public function formTitle(): string
    {
        return 'Itemized Deductions';
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

        $this->infoRow('Name(s) shown on Form 1040', $this->data['taxpayer_name'] ?? '');
        $this->infoRow('Your social security number', $this->data['ssn'] ?? 'XXX-XX-XXXX');
        $this->pdf->Ln(2);

        // Medical and Dental Expenses
        $this->sectionHeader('Medical and Dental Expenses');

        $medicalExpenses = (float) ($this->data['medical_expenses'] ?? 0);
        $agi = (float) ($this->data['agi'] ?? 0);
        $medicalThreshold = round($agi * 0.075, 2);
        $medicalDeduction = round(max($medicalExpenses - $medicalThreshold, 0), 2);

        $this->lineItem('1', 'Medical and dental expenses', $medicalExpenses);
        $this->lineItem('2', 'Enter amount from Form 1040, line 11 (AGI)', $agi);
        $this->lineItem('3', 'Multiply line 2 by 7.5% (0.075)', $medicalThreshold);
        $this->lineItem('4', 'Subtract line 3 from line 1. If line 3 is more than line 1, enter -0-', $medicalDeduction, true);

        // Taxes You Paid
        $this->sectionHeader('Taxes You Paid');

        $propertyTaxes = (float) ($this->data['property_taxes'] ?? 0);
        $saltCap = 10000;
        $saltDeduction = min($propertyTaxes, $saltCap);

        $this->lineItem('5a', 'State and local income taxes or general sales taxes', 0);
        $this->lineItem('5b', 'State and local personal property taxes', 0);
        $this->lineItem('5c', 'State and local real estate taxes', $propertyTaxes);
        $this->lineItem('5d', 'Add lines 5a through 5c', $propertyTaxes);
        $this->lineItem('5e', 'Enter the smaller of line 5d or $10,000 ($5,000 if MFS)', $saltDeduction);
        $this->lineItem('6', 'Other taxes', 0);
        $this->lineItem('7', 'Total taxes paid. Add lines 5e and 6', $saltDeduction, true);

        // Interest You Paid
        $this->sectionHeader('Interest You Paid');

        $mortgageInterest = (float) ($this->data['mortgage_interest'] ?? 0);

        $this->lineItem('8a', 'Home mortgage interest and points reported on Form 1098', $mortgageInterest);
        $this->lineItem('8b', 'Home mortgage interest not reported on Form 1098', 0);
        $this->lineItem('8c', 'Points not reported on Form 1098', 0);
        $this->lineItem('9', 'Investment interest (attach Form 4952)', 0);
        $this->lineItem('10', 'Total interest paid. Add lines 8a through 9', $mortgageInterest, true);

        // Gifts to Charity
        $this->sectionHeader('Gifts to Charity');

        $charitableContributions = (float) ($this->data['charitable_contributions'] ?? 0);

        $this->lineItem('11', 'Gifts by cash or check', $charitableContributions);
        $this->lineItem('12', 'Other than by cash or check', 0);
        $this->lineItem('13', 'Carryover from prior year', 0);
        $this->lineItem('14', 'Total charitable contributions. Add lines 11 through 13', $charitableContributions, true);

        // Other Itemized Deductions
        $this->sectionHeader('Other Itemized Deductions');
        $this->lineItem('15', 'Casualty and theft loss(es) from Form 4684', 0);
        $this->lineItem('16', 'Other itemized deductions', 0);

        // Total Itemized Deductions
        $this->sectionHeader('Total Itemized Deductions');
        $totalItemized = (float) ($this->data['itemized_deductions'] ?? 0);
        $this->lineItem('17', 'Total itemized deductions. Add lines 4, 7, 10, 14, 15, and 16', $totalItemized, true);

        return $this->output();
    }
}
