<?php

namespace App\Services\Tax\OfficialForms;

use App\Models\TaxProfile;
use App\Models\TaxReturnWorkpaper;
use App\Services\Tax\DraftReturnComputationService;
use App\Services\Tax\TaxBracketEngine;
use App\Services\Tax\TaxReturnPreparationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a complete set of official IRS/Oregon substitute tax forms
 * from the deterministic workpaper packet.
 *
 * Forms are generated in filing order:
 * 1. Corporate: 1120-S, Schedule K-1
 * 2. Personal:  1040, Schedule 1, Schedule A, Schedule E, Form 8995
 * 3. State:     Oregon OR-40
 */
class OfficialTaxFormService
{
    public function __construct(
        protected TaxReturnPreparationService $taxReturnPreparationService,
        protected DraftReturnComputationService $draftReturnComputationService,
    ) {}

    /**
     * Generate all required filing forms for a user and tax year.
     *
     * @return array{
     *     generated: int,
     *     paths: array<string, string>,
     *     forms: array<int, array{form: string, title: string, path: string}>,
     *     filing_package_path: string,
     * }
     */
    public function generateFilingPackage(int $userId, int $year, ?array $annualProjection = null): array
    {
        $workpaper = $this->taxReturnPreparationService->persist($userId, $year, $annualProjection);
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->firstOrFail();
        $packet = $workpaper->packet;
        $formsByCode = collect($packet['forms'] ?? [])->keyBy('code');
        $taxComputation = $packet['draft_return_computation']['tax_computation'] ?? [];
        $draftComputation = $packet['draft_return_computation'] ?? [];

        $data = $this->buildFormData($profile, $workpaper, $formsByCode, $taxComputation, $draftComputation);

        $basePath = "tax-forms/{$userId}/{$year}/official";
        $paths = [];
        $forms = [];

        $generators = $this->formGenerators($profile, $data, $year);

        foreach ($generators as $formCode => $generator) {
            $pdfContent = $generator->generate();
            $storagePath = "{$basePath}/{$formCode}.pdf";
            Storage::put($storagePath, $pdfContent);

            $paths[$formCode] = $storagePath;
            $forms[] = [
                'form' => $generator->formNumber(),
                'title' => $generator->formTitle(),
                'path' => $storagePath,
            ];

            Log::info("OfficialTaxFormService: generated {$generator->formNumber()}", [
                'user_id' => $userId,
                'year' => $year,
                'path' => $storagePath,
            ]);
        }

        return [
            'generated' => count($paths),
            'paths' => $paths,
            'forms' => $forms,
            'filing_package_path' => $basePath,
        ];
    }

    /**
     * Get inventory of existing official forms for the form downloads list.
     *
     * @return array<int, array{form: string, description: string, path: string, exists: bool, status: string, status_label: string}>
     */
    public function getFormInventory(int $userId, int $year): array
    {
        $basePath = "tax-forms/{$userId}/{$year}/official";
        $formCodes = [
            'form-1120s' => ['Form 1120-S', 'Official S corporation income tax return'],
            'schedule-k1' => ['Schedule K-1', 'Official shareholder share of income schedule'],
            'form-1040' => ['Form 1040', 'Official individual income tax return'],
            'schedule-1' => ['Schedule 1', 'Additional income and adjustments to income'],
            'schedule-a' => ['Schedule A', 'Itemized deductions'],
            'schedule-e' => ['Schedule E', 'Supplemental income and loss'],
            'form-8995' => ['Form 8995', 'Qualified business income deduction'],
            'oregon-or40' => ['Oregon OR-40', 'Official Oregon individual income tax return'],
            'portland-arts-tax' => ['Arts Tax', 'Portland Arts Tax return'],
            'multnomah-pfa' => ['PFA Tax', 'Multnomah County Preschool For All return'],
        ];

        $items = [];

        foreach ($formCodes as $code => [$label, $description]) {
            $storagePath = "{$basePath}/{$code}.pdf";
            $exists = Storage::exists($storagePath);

            $items[] = [
                'form' => "Official {$label}",
                'description' => $description,
                'path' => $storagePath,
                'exists' => $exists,
                'status' => $exists ? 'ready' : 'not_generated',
                'status_label' => $exists ? 'Ready' : 'Not generated',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $formsByCode
     * @param  array<string, mixed>  $taxComputation
     * @param  array<string, mixed>  $draftComputation
     * @return array<string, mixed>
     */
    protected function buildFormData(
        TaxProfile $profile,
        TaxReturnWorkpaper $workpaper,
        $formsByCode,
        array $taxComputation,
        array $draftComputation,
    ): array {
        $f1040Fields = $this->fieldValues($formsByCode->get('1040'));
        $f1120sFields = $this->fieldValues($formsByCode->get('1120s_k1_bridge'));
        $orFields = $this->fieldValues($formsByCode->get('oregon_or40'));
        $localFields = $this->fieldValues($formsByCode->get('local_tax_workpapers'));
        $isMfj = ($profile->filing_status ?? 'single') === 'mfj';

        $wages = (float) ($f1040Fields['wages'] ?? 0);
        $passThroughIncome = (float) ($f1040Fields['pass_through_income'] ?? 0);
        $totalIncome = round($wages + $passThroughIncome, 2);
        $agi = (float) ($taxComputation['agi'] ?? 0);
        $adjustments = round(max($totalIncome - $agi, 0), 2);

        $mortgageInterest = (float) ($f1040Fields['mortgage_interest'] ?? 0);
        $propertyTaxes = (float) ($f1040Fields['property_taxes'] ?? 0);
        $charitableContributions = (float) ($f1040Fields['charitable_contributions'] ?? 0);
        $medicalExpenses = (float) ($f1040Fields['medical_expenses'] ?? 0);
        $itemizedDeductions = round($mortgageInterest + $propertyTaxes + $charitableContributions + $medicalExpenses, 2);

        $standardDeduction = TaxBracketEngine::standardDeduction($workpaper->tax_year, $profile->filing_status ?? 'single');
        $deductionUsed = max($standardDeduction, $itemizedDeductions);
        $usesItemized = $itemizedDeductions > $standardDeduction;

        $qbiDeduction = (float) ($taxComputation['qbi_deduction'] ?? 0);
        $totalDeductions = round($deductionUsed + $qbiDeduction, 2);
        $taxableIncome = (float) ($taxComputation['federal_taxable_income'] ?? 0);
        $federalTax = (float) ($taxComputation['federal_tax'] ?? 0);

        $federalEstimatedPayments = (float) ($f1040Fields['estimated_tax_payments'] ?? 0);
        $priorYearCredit = (float) ($f1040Fields['prior_year_federal_overpayment_credit'] ?? 0);
        $totalPayments = round($federalEstimatedPayments + $priorYearCredit, 2);

        $grossReceipts = (float) ($f1120sFields['gross_receipts'] ?? 0);
        $businessExpenses = (float) ($f1120sFields['business_expenses'] ?? 0);
        $ordinaryBusinessIncome = (float) ($f1120sFields['ordinary_business_income'] ?? 0);
        $officerCompensation = (float) ($f1120sFields['officer_compensation'] ?? 0);
        $shareholderDistributions = (float) ($f1120sFields['shareholder_distributions'] ?? 0);
        $qbiWageBasis = (float) ($f1120sFields['qbi_wage_basis'] ?? 0);

        return [
            'profile' => $profile,
            'workpaper' => $workpaper,
            'taxpayer_name' => $workpaper->user->name ?? 'Taxpayer',
            'spouse_name' => $profile->spouse_name ?? '',
            'ssn' => $profile->ssn ?? 'XXX-XX-XXXX',
            'spouse_ssn' => $profile->spouse_ssn ?? 'XXX-XX-XXXX',
            'address' => $profile->address ?? '',
            'city' => $profile->city ?? '',
            'state' => $profile->state ?? '',
            'zip' => $profile->zip ?? '',
            'filing_status' => $profile->filing_status ?? 'single',
            'is_mfj' => $isMfj,
            'entity_name' => $profile->entity_name ?? 'S Corporation',
            'entity_ein' => $profile->entity_ein ?? 'XX-XXXXXXX',
            'entity_address' => $profile->entity_address ?? '',
            'dependent_count' => (int) ($profile->dependent_count ?? 0),

            // 1120-S
            'gross_receipts' => $grossReceipts,
            'business_expenses' => $businessExpenses,
            'ordinary_business_income' => $ordinaryBusinessIncome,
            'officer_compensation' => $officerCompensation,
            'other_deductions' => round(max($businessExpenses - $officerCompensation, 0), 2),
            'total_deductions_1120s' => round($officerCompensation + max($businessExpenses - $officerCompensation, 0), 2),
            'shareholder_distributions' => $shareholderDistributions,
            'qbi_wage_basis' => $qbiWageBasis,
            'shareholder_basis' => (float) ($f1120sFields['shareholder_basis_carryforward'] ?? 0),

            // 1040
            'wages' => $wages,
            'pass_through_income' => $passThroughIncome,
            'total_income' => $totalIncome,
            'adjustments' => $adjustments,
            'agi' => $agi,
            'standard_deduction' => $standardDeduction,
            'itemized_deductions' => $itemizedDeductions,
            'uses_itemized' => $usesItemized,
            'deduction_used' => $deductionUsed,
            'qbi_deduction' => $qbiDeduction,
            'total_deductions' => $totalDeductions,
            'taxable_income' => $taxableIncome,
            'federal_tax' => $federalTax,
            'child_tax_credit' => (float) ($taxComputation['child_tax_credit'] ?? 0),
            'total_fica' => (float) ($taxComputation['total_fica'] ?? 0),
            'federal_estimated_payments' => $federalEstimatedPayments,
            'prior_year_credit' => $priorYearCredit,
            'total_payments' => $totalPayments,
            'refund' => round(max($totalPayments - $federalTax, 0), 2),
            'amount_owed' => round(max($federalTax - $totalPayments, 0), 2),

            // Schedule A
            'mortgage_interest' => $mortgageInterest,
            'property_taxes' => $propertyTaxes,
            'charitable_contributions' => $charitableContributions,
            'medical_expenses' => $medicalExpenses,

            // Oregon
            'oregon_agi' => $agi,
            'oregon_taxable_income' => (float) ($taxComputation['oregon_taxable_income'] ?? 0),
            'oregon_tax' => (float) ($taxComputation['oregon_tax'] ?? 0),
            'oregon_estimated_payments' => (float) ($orFields['oregon_estimated_payments'] ?? 0),
            'oregon_prior_year_credit' => (float) ($orFields['prior_year_oregon_overpayment_credit'] ?? 0),

            // Local
            'portland_arts_tax' => (float) ($taxComputation['portland_arts_tax'] ?? 0),
            'multnomah_pfa_tax' => (float) ($taxComputation['multnomah_pfa_tax'] ?? 0),
            'is_portland_resident' => strtolower($profile->resident_city ?? '') === 'portland',

            // FICA breakdown
            'fica' => $taxComputation['fica'] ?? [],
        ];
    }

    /**
     * @return array<string, TaxFormPdf>
     */
    protected function formGenerators(TaxProfile $profile, array $data, int $year): array
    {
        $generators = [];

        if ($profile->entity_type === 's_corp') {
            $generators['form-1120s'] = new Form1120S($data, $year);
            $generators['schedule-k1'] = new ScheduleK1($data, $year);
        }

        $generators['form-1040'] = new Form1040($data, $year);
        $generators['schedule-1'] = new Schedule1($data, $year);

        if ($data['uses_itemized']) {
            $generators['schedule-a'] = new ScheduleA($data, $year);
        }

        $generators['schedule-e'] = new ScheduleE($data, $year);

        if ($data['qbi_deduction'] > 0) {
            $generators['form-8995'] = new Form8995($data, $year);
        }

        if (strtolower($profile->resident_state ?? '') === 'or') {
            $generators['oregon-or40'] = new OregonOR40($data, $year);
        }

        if ($data['is_portland_resident']) {
            $generators['portland-arts-tax'] = new PortlandArtsTax($data, $year);
            $generators['multnomah-pfa'] = new MultnomahPfa($data, $year);
        }

        return $generators;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldValues(?array $form): array
    {
        if ($form === null) {
            return [];
        }

        return collect($form['fields'] ?? [])
            ->keyBy('key')
            ->map(fn (array $field): mixed => $field['value'] ?? null)
            ->all();
    }
}
