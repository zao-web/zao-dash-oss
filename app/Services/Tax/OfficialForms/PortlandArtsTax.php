<?php

namespace App\Services\Tax\OfficialForms;

/**
 * Portland Arts Tax Return.
 *
 * Generates a substitute Portland Arts Tax form. The Arts Tax is a flat
 * $35 per-adult head tax for Portland residents aged 18 and over.
 */
class PortlandArtsTax extends TaxFormPdf
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
        return 'Arts Tax';
    }

    public function formTitle(): string
    {
        return 'Portland Arts Tax Return';
    }

    public function taxYear(): int
    {
        return $this->year;
    }

    public function generate(): string
    {
        $this->addPage();
        $this->draftWatermark();
        $this->formHeader('City of Portland Revenue Division');

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
        $filingStatusLabel = match ($filingStatus) {
            'mfj' => 'Married filing jointly',
            'mfs' => 'Married filing separately',
            'hoh' => 'Head of household',
            'qw' => 'Qualifying surviving spouse',
            default => 'Single',
        };

        $this->sectionHeader('Filing Status');
        $this->checkboxRow('Single', $filingStatus === 'single');
        $this->checkboxRow('Married filing jointly', $filingStatus === 'mfj');
        $this->checkboxRow('Married filing separately', $filingStatus === 'mfs');
        $this->checkboxRow('Head of household', $filingStatus === 'hoh');
        $this->pdf->Ln(2);

        // Tax calculation
        $this->sectionHeader('Arts Tax Calculation');

        $isMfj = $this->data['is_mfj'] ?? false;
        $numberOfAdults = $isMfj ? 2 : 1;
        $taxPerAdult = 35;
        $totalTax = (float) ($this->data['portland_arts_tax'] ?? ($numberOfAdults * $taxPerAdult));

        $this->lineItem('1', 'Number of adults (18+) in household', $numberOfAdults);
        $this->lineItem('2', 'Tax per adult', $taxPerAdult);
        $this->lineItem('3', 'Total Arts Tax. Multiply line 1 by line 2', $totalTax, true);

        $this->pdf->Ln(2);

        // Payment information
        $this->sectionHeader('Payment Information');

        $this->lineItem('4', 'Amount paid with this return', $totalTax);
        $this->lineItem('5', 'Penalty for late payment (see instructions)', 0);
        $this->lineItem('6', 'Interest on late payment', 0);
        $this->lineItem('7', 'Total amount due. Add lines 3, 5, and 6', $totalTax, true);

        $this->pdf->Ln(4);
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->MultiCell(0, 4, 'The Arts Tax is due by April 15 following the tax year. Payment can be made online at www.portlandoregon.gov/artstax or by mail to the City of Portland Revenue Division.', 0, 'L');

        return $this->output();
    }
}
