<?php

namespace App\Services\Tax\OfficialForms;

use App\Models\TaxProfile;
use App\Models\TaxReturnWorkpaper;
use App\Services\Tax\TaxBracketEngine;
use App\Services\Tax\TaxFormFieldRegistry;
use App\Services\Tax\TaxReturnPreparationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Fills official IRS and Oregon PDF forms using pure PHP (FPDI + FPDF).
 *
 * Imports blank PDF pages as backgrounds via FPDI, then overlays computed
 * values at x,y coordinates using FPDF. No system binaries required.
 *
 * Blank templates are auto-downloaded from IRS/Oregon on first use.
 * Coordinate mappings live in config/tax-form-fields.php.
 */
class FillableFormService
{
    public function __construct(
        protected TaxReturnPreparationService $taxReturnPreparationService,
        protected TaxFormFieldRegistry $fieldRegistry,
    ) {}

    /**
     * Fill all available official forms for a user and tax year.
     *
     * @return array{generated: int, skipped: int, paths: array<string, string>, forms: array<int, array{form: string, path: string, status: string}>}
     */
    public function fillAllForms(int $userId, int $year, ?array $annualProjection = null): array
    {
        $workpaper = $this->taxReturnPreparationService->persist($userId, $year, $annualProjection);
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->firstOrFail();
        $data = $this->buildDataForFilling($profile, $workpaper);

        $formDefinitions = $this->formDefinitions($profile);
        $outputBase = "tax-forms/{$userId}/{$year}/filed";
        $paths = [];
        $forms = [];
        $generated = 0;
        $skipped = 0;

        foreach ($formDefinitions as $formCode => $definition) {
            $templatePath = resource_path("tax-form-templates/{$year}/{$formCode}.pdf");

            if (! file_exists($templatePath)) {
                $forms[] = ['form' => $definition['label'], 'path' => '', 'status' => 'template_missing'];
                $skipped++;

                continue;
            }

            $coordinates = $this->fieldRegistry->fieldsFor($formCode);

            if ($coordinates === []) {
                $forms[] = ['form' => $definition['label'], 'path' => '', 'status' => 'no_coordinate_mapping'];
                $skipped++;

                continue;
            }

            $outputPath = "{$outputBase}/{$formCode}-filled.pdf";

            try {
                $formData = $definition['data_mapper']($data);
                $this->fillFormWithOverlay(
                    templateFilePath: $templatePath,
                    outputStoragePath: $outputPath,
                    coordinates: $coordinates,
                    formData: $formData,
                );

                $paths[$formCode] = $outputPath;
                $forms[] = ['form' => $definition['label'], 'path' => $outputPath, 'status' => 'filled'];
                $generated++;

                Log::info("FillableFormService: filled {$definition['label']}", [
                    'user_id' => $userId,
                    'year' => $year,
                    'path' => $outputPath,
                ]);
            } catch (\Throwable $e) {
                $forms[] = ['form' => $definition['label'], 'path' => '', 'status' => 'error: '.$e->getMessage()];
                $skipped++;

                Log::error("FillableFormService: failed to fill {$definition['label']}", [
                    'user_id' => $userId,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'paths' => $paths,
            'forms' => $forms,
        ];
    }

    /**
     * Build the field-by-field mapped data for every form without rendering PDFs.
     * Used by the audit tool to verify cross-form consistency and intra-form math
     * without the cost (or storage writes) of full PDF generation.
     *
     * @return array{
     *     shared_data: array<string, mixed>,
     *     forms: array<string, array<string, string>>
     * }
     */
    public function getMappedFormData(int $userId, int $year, ?array $annualProjection = null): array
    {
        $workpaper = $this->taxReturnPreparationService->persist($userId, $year, $annualProjection);
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->firstOrFail();
        $sharedData = $this->buildDataForFilling($profile, $workpaper);

        $formDefinitions = $this->formDefinitions($profile);
        $formData = [];

        foreach ($formDefinitions as $formCode => $definition) {
            try {
                $formData[$formCode] = $definition['data_mapper']($sharedData);
            } catch (\Throwable $e) {
                $formData[$formCode] = ['__error' => $e->getMessage()];
            }
        }

        return [
            'shared_data' => $sharedData,
            'forms' => $formData,
        ];
    }

    /**
     * @return array<int, array{form: string, description: string, path: string, exists: bool, status: string, status_label: string}>
     */
    public function getFormInventory(int $userId, int $year): array
    {
        $basePath = "tax-forms/{$userId}/{$year}/filed";
        $formCodes = [
            'f1120s' => ['Form 1120-S (Filed)', 'Filled official S corporation return'],
            'f1120ssk' => ['Schedule K-1 (Filed)', 'Filled official shareholder schedule'],
            'f1040' => ['Form 1040 (Filed)', 'Filled official individual return'],
            'f1040s1' => ['Schedule 1 (Filed)', 'Filled additional income schedule'],
            'f1040sa' => ['Schedule A (Filed)', 'Filled itemized deductions'],
            'f1040se' => ['Schedule E (Filed)', 'Filled supplemental income'],
            'f8995' => ['Form 8995 (Filed)', 'Filled QBI deduction'],
            'or-40' => ['OR-40 (Filed)', 'Filled Oregon individual return'],
            'or-a' => ['OR-A (Filed)', 'Filled Oregon itemized deductions'],
            'or-20-s' => ['OR-20-S (Filed)', 'Filled Oregon S corporation return'],
            'or-add-dep' => ['OR-ADD-DEP (Filed)', 'Filled Oregon supplemental dependents schedule'],
        ];

        $items = [];

        foreach ($formCodes as $code => [$label, $description]) {
            $storagePath = "{$basePath}/{$code}-filled.pdf";
            $exists = Storage::exists($storagePath);

            if ($exists) {
                $items[] = [
                    'form' => $label,
                    'description' => $description,
                    'path' => $storagePath,
                    'exists' => true,
                    'status' => 'ready',
                    'status_label' => 'Ready',
                ];
            }
        }

        return $items;
    }

    /**
     * Fill a PDF form by importing pages as backgrounds and overlaying text.
     *
     * Uses Storage::get() to read templates and Storage::put() to write output,
     * avoiding raw filesystem paths that break on cloud hosting.
     *
     * @param  string  $templateFilePath  Absolute filesystem path to the blank PDF template (from resources/)
     * @param  string  $outputStoragePath  Storage-relative path for the filled output
     * @param  array<string, array<string, mixed>>  $coordinates  Keyed by data key, each value has 'page', 'x', 'y', and optional 'size', 'align', 'font'
     * @param  array<string, string>  $formData  Keyed by data key, values are formatted strings
     */
    protected function fillFormWithOverlay(string $templateFilePath, string $outputStoragePath, array $coordinates, array $formData): void
    {
        $templateContent = file_get_contents($templateFilePath);

        if ($templateContent === false) {
            throw new RuntimeException("Template not found: {$templateFilePath}");
        }

        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);

        // Use StreamReader to feed PDF content directly — no filesystem path needed
        $stream = StreamReader::createByString($templateContent);
        $pageCount = $pdf->setSourceFile($stream);

        // Import all pages
        $importedPages = [];

        for ($i = 1; $i <= $pageCount; $i++) {
            $importedPages[$i] = $pdf->importPage($i);
        }

        // Group coordinates by page
        $fieldsByPage = [];

        foreach ($coordinates as $dataKey => $coord) {
            $page = (int) ($coord['page'] ?? 1);
            $fieldsByPage[$page][$dataKey] = $coord;
        }

        // Render each page with overlaid data
        for ($page = 1; $page <= $pageCount; $page++) {
            $size = $pdf->getTemplateSize($importedPages[$page]);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($importedPages[$page]);

            if (! isset($fieldsByPage[$page])) {
                continue;
            }

            foreach ($fieldsByPage[$page] as $dataKey => $coord) {
                if (! isset($formData[$dataKey])) {
                    continue;
                }

                $value = (string) $formData[$dataKey];

                if ($value === '' || $value === '0') {
                    continue;
                }

                $fontSize = (float) ($coord['size'] ?? 9);
                $font = (string) ($coord['font'] ?? 'Helvetica');
                $align = (string) ($coord['align'] ?? 'L');
                $width = (float) ($coord['width'] ?? 30);

                $pdf->SetFont($font, '', $fontSize);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY((float) $coord['x'], (float) $coord['y']);
                $pdf->Cell($width, $fontSize * 0.4, $value, 0, 0, $align);
            }
        }

        // Output to string and write via Storage facade
        $pdfContent = $pdf->Output('', 'S');
        Storage::put($outputStoragePath, $pdfContent);
    }

    protected function downloadTemplate(string $formCode, int $year): void
    {
        $urls = $this->templateUrls();

        if (! isset($urls[$formCode])) {
            return;
        }

        $storagePath = "tax-form-templates/{$year}/{$formCode}.pdf";

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/pdf,*/*',
            ])->timeout(30)->get($urls[$formCode]);

            $body = $response->body();

            if ($response->successful() && str_starts_with($body, '%PDF') && strlen($body) > 1000) {
                Storage::put($storagePath, $body);
                $this->convertToPdf14($storagePath);

                Log::info("FillableFormService: downloaded {$formCode}.pdf", [
                    'path' => $storagePath,
                    'size' => strlen($body),
                ]);
            } else {
                Log::warning("FillableFormService: download rejected for {$formCode}.pdf", [
                    'status' => $response->status(),
                    'is_pdf' => str_starts_with($body, '%PDF'),
                    'size' => strlen($body),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("FillableFormService: failed to download {$formCode}.pdf", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert a stored PDF to version 1.4 using Ghostscript so FPDI free parser can read it.
     */
    protected function convertToPdf14(string $storagePath): void
    {
        try {
            $result = Process::run(['gs', '--version']);

            if (! $result->successful()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $content = Storage::get($storagePath);
        $tempInput = tempnam(sys_get_temp_dir(), 'pdf_in_');
        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_out_');
        file_put_contents($tempInput, $content);

        try {
            $result = Process::run([
                'gs', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
                '-dNOPAUSE', '-dQUIET', '-dBATCH',
                '-sOutputFile='.$tempOutput, $tempInput,
            ]);

            if ($result->successful() && file_exists($tempOutput) && filesize($tempOutput) > 100) {
                Storage::put($storagePath, file_get_contents($tempOutput));

                Log::info("FillableFormService: converted {$storagePath} to PDF 1.4");
            }
        } catch (\Throwable $e) {
            Log::warning("FillableFormService: gs conversion failed for {$storagePath}", [
                'error' => $e->getMessage(),
            ]);
        } finally {
            @unlink($tempInput);
            @unlink($tempOutput);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function templateUrls(): array
    {
        return [
            'f1040' => 'https://www.irs.gov/pub/irs-pdf/f1040.pdf',
            'f1040s1' => 'https://www.irs.gov/pub/irs-pdf/f1040s1.pdf',
            'f1040sa' => 'https://www.irs.gov/pub/irs-pdf/f1040sa.pdf',
            'f1040se' => 'https://www.irs.gov/pub/irs-pdf/f1040se.pdf',
            'f1120s' => 'https://www.irs.gov/pub/irs-pdf/f1120s.pdf',
            'f1120ssk' => 'https://www.irs.gov/pub/irs-pdf/f1120ssk.pdf',
            'f8995' => 'https://www.irs.gov/pub/irs-pdf/f8995.pdf',
            'or-40' => 'https://www.oregon.gov/dor/forms/FormsPubs/form-or-40_101-040_2025.pdf',
            'or-a' => 'https://www.oregon.gov/dor/forms/FormsPubs/schedule-or-a_101-007_2025.pdf',
            'or-20-s' => 'https://www.oregon.gov/dor/forms/FormsPubs/form-or-20-s_102-025_2025.pdf',
            // Schedule OR-ADD-DEP — for OR-40 filers with more than 3 dependents.
            'or-add-dep' => 'https://www.oregon.gov/dor/forms/FormsPubs/schedule-or-add-dep_101-187_2025.pdf',
        ];
    }

    /**
     * @return array<string, array{label: string, data_mapper: \Closure}>
     */
    protected function formDefinitions(TaxProfile $profile): array
    {
        $definitions = [];

        if ($profile->entity_type === 's_corp') {
            $definitions['f1120s'] = [
                'label' => 'Form 1120-S',
                'data_mapper' => fn (array $d): array => $this->map1120S($d),
            ];
            $definitions['f1120ssk'] = [
                'label' => 'Schedule K-1 (1120-S)',
                'data_mapper' => fn (array $d): array => $this->mapScheduleK1($d),
            ];
        }

        $definitions['f1040'] = [
            'label' => 'Form 1040',
            'data_mapper' => fn (array $d): array => $this->map1040($d),
        ];
        $definitions['f1040s1'] = [
            'label' => 'Schedule 1',
            'data_mapper' => fn (array $d): array => $this->mapSchedule1($d),
        ];

        $definitions['f1040sa'] = [
            'label' => 'Schedule A',
            'data_mapper' => fn (array $d): array => $this->mapScheduleA($d),
        ];

        $definitions['f1040se'] = [
            'label' => 'Schedule E',
            'data_mapper' => fn (array $d): array => $this->mapScheduleE($d),
        ];

        $definitions['f8995'] = [
            'label' => 'Form 8995',
            'data_mapper' => fn (array $d): array => $this->mapForm8995($d),
        ];

        // Oregon
        if (strtolower($profile->resident_state ?? '') === 'or') {
            $definitions['or-40'] = [
                'label' => 'Oregon OR-40',
                'data_mapper' => fn (array $d): array => $this->mapOR40($d),
            ];
            $definitions['or-a'] = [
                'label' => 'Oregon Schedule OR-A',
                'data_mapper' => fn (array $d): array => $this->mapORA($d),
            ];

            // Schedule OR-ADD-DEP only generated when there are >3 dependents
            // (OR-40 page 2 only fits 3; the rest go on this supplemental schedule).
            if (count((array) ($profile->dependents ?? [])) > 3) {
                $definitions['or-add-dep'] = [
                    'label' => 'Oregon Schedule OR-ADD-DEP',
                    'data_mapper' => fn (array $d): array => $this->mapORAddDep($d),
                ];
            }
        }

        if ($profile->entity_type === 's_corp' && strtolower($profile->resident_state ?? '') === 'or') {
            $definitions['or-20-s'] = [
                'label' => 'Oregon OR-20-S',
                'data_mapper' => fn (array $d): array => $this->mapOR20S($d),
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDataForFilling(TaxProfile $profile, TaxReturnWorkpaper $workpaper): array
    {
        $packet = $workpaper->packet;
        $formsByCode = collect($packet['forms'] ?? [])->keyBy('code');
        $taxComp = $packet['draft_return_computation']['tax_computation'] ?? [];
        $draftComp = $packet['draft_return_computation'] ?? [];

        $fv = fn (?array $form, string $key): mixed => collect($form['fields'] ?? [])->keyBy('key')->get($key)['value'] ?? null;

        $f1040 = $formsByCode->get('1040');
        $f1120s = $formsByCode->get('1120s_k1_bridge');

        $wages = (float) $fv($f1040, 'wages');
        $passThrough = (float) $fv($f1040, 'pass_through_income');
        $agi = (float) ($taxComp['agi'] ?? 0);
        $totalIncome = round($wages + $passThrough, 2);
        $adjustments = round(max($totalIncome - $agi, 0), 2);

        $mortgage = (float) $fv($f1040, 'mortgage_interest');
        $propTax = (float) $fv($f1040, 'property_taxes');
        $charitable = (float) $fv($f1040, 'charitable_contributions');
        $medical = (float) $fv($f1040, 'medical_expenses');

        // Federal vs Oregon itemized are different totals.
        // - Federal Schedule A line 17 INCLUDES state income tax (SALT, capped at \$40k MFJ).
        // - Oregon Schedule OR-A EXCLUDES state income tax (Oregon doesn't let you deduct itself).
        $stateIncomeTax = (float) ($taxComp['oregon_tax'] ?? 0);
        $filingStatus = strtolower((string) ($profile->filing_status ?? 'single'));
        $saltCap = $filingStatus === 'mfs' ? 20000.0 : 40000.0;
        $cappedSalt = min($stateIncomeTax + $propTax, $saltCap);

        // Medical and dental: deductible only above 7.5% of AGI (Sched A line 4 / OR-A line 4).
        $medicalDeductible = max(0.0, $medical - $agi * 0.075);

        $oregonItemized = round($mortgage + $propTax + $charitable + $medicalDeductible, 2);
        $federalItemized = round($mortgage + $cappedSalt + $charitable + $medicalDeductible, 2);

        // 1040 line 12 picks the larger of standard or federal itemized.
        $standard = TaxBracketEngine::standardDeduction($workpaper->tax_year, $profile->filing_status ?? 'single');
        $deduction = max($standard, $federalItemized);
        $usesItemized = $federalItemized > $standard;
        $qbi = (float) ($taxComp['qbi_deduction'] ?? 0);
        $estPayments = (float) $fv($f1040, 'estimated_tax_payments');
        $priorCredit = (float) $fv($f1040, 'prior_year_federal_overpayment_credit');

        // Compute the form's OWN federal tax once, here — same logic map1040 uses —
        // so downstream mappers (OR-40) can depend on the corrected value rather
        // than the stale upstream $taxComp['federal_tax']. This is the cascade
        // that fixes OR-40 line 10 (federal tax subtraction).
        $formFederalTaxableIncome = round(max(0.0, $agi - $deduction - $qbi), 2);
        $ctcForForm = (float) ($taxComp['child_tax_credit'] ?? 0);
        $formFederalTaxBeforeCredits = TaxBracketEngine::calculateFederalIncomeTax(
            $formFederalTaxableIncome,
            (int) $workpaper->tax_year,
            $profile->filing_status ?? 'single'
        );
        $formFederalTax = round(max(0.0, $formFederalTaxBeforeCredits - $ctcForForm), 2);

        // Prefer the legal taxpayer name from TaxProfile (set explicitly via the MCP tool).
        // Fall back to splitting User->name only when no explicit name is recorded —
        // useful during initial onboarding before the profile is fully populated.
        $userNameParts = explode(' ', $workpaper->user->name ?? '', 2);
        $taxpayerFirst = $profile->taxpayer_first_name ?: ($userNameParts[0] ?? '');
        $taxpayerLast = $profile->taxpayer_last_name ?: ($userNameParts[1] ?? '');

        return [
            'tax_year' => (int) $workpaper->tax_year,
            'taxpayer_first_name' => $taxpayerFirst,
            'taxpayer_middle_initial' => $profile->taxpayer_middle_initial ?? '',
            'taxpayer_last_name' => $taxpayerLast,
            'taxpayer_dob' => $profile->taxpayer_dob?->format('m/d/Y') ?? '',
            'taxpayer_ssn' => $profile->ssn ?? '',
            'taxpayer_phone' => $profile->taxpayer_phone ?? '',
            'spouse_first_name' => explode(' ', $profile->spouse_name ?? '', 2)[0] ?? '',
            'spouse_last_name' => explode(' ', $profile->spouse_name ?? '', 2)[1] ?? '',
            'spouse_middle_initial' => $profile->spouse_middle_initial ?? '',
            'spouse_dob' => $profile->spouse_dob?->format('m/d/Y') ?? '',
            'spouse_name' => $profile->spouse_name ?? '',
            'spouse_ssn' => $profile->spouse_ssn ?? '',
            'address' => $profile->address ?? '',
            'city' => $profile->city ?? '',
            'state' => $profile->state ?? '',
            'zip' => $profile->zip ?? '',
            'city_state_zip' => trim(($profile->city ?? '').', '.($profile->state ?? '').' '.($profile->zip ?? '')),
            'filing_status' => $profile->filing_status ?? 'single',
            'dependents' => $profile->dependents ?? [],
            'entity_name' => $profile->entity_name ?? '',
            'entity_ein' => $profile->entity_ein ?? '',
            'entity_address' => $profile->entity_address ?? '',

            'wages' => $wages,
            'pass_through_income' => $passThrough,
            'total_income' => $totalIncome,
            'adjustments' => $adjustments,
            'agi' => $agi,
            'deduction' => $deduction,
            'uses_itemized' => $usesItemized,
            'qbi_deduction' => $qbi,
            'total_deductions' => round($deduction + $qbi, 2),
            'taxable_income' => (float) ($taxComp['federal_taxable_income'] ?? 0),
            'federal_tax' => (float) ($taxComp['federal_tax'] ?? 0),
            // form_federal_tax = recomputed against the form's own line 15 (correct
            // federal itemized); form_federal_tax should be used by anything that
            // needs "what line 24 actually prints" — e.g. OR-40 line 10 subtraction.
            'form_federal_tax' => $formFederalTax,
            'child_tax_credit' => (float) ($taxComp['child_tax_credit'] ?? 0),
            'estimated_payments' => $estPayments,
            'prior_year_credit' => $priorCredit,
            'total_payments' => round($estPayments + $priorCredit, 2),
            'refund' => round(max(($estPayments + $priorCredit) - (float) ($taxComp['federal_tax'] ?? 0), 0), 2),
            'amount_owed' => round(max((float) ($taxComp['federal_tax'] ?? 0) - ($estPayments + $priorCredit), 0), 2),

            'mortgage_interest' => $mortgage,
            'property_taxes' => $propTax,
            'charitable_contributions' => $charitable,
            'medical_expenses' => $medical,
            // Federal Sched A line 17 includes SALT (capped at \$40k MFJ); Oregon OR-A excludes
            // state income tax. We expose both so each form picks the right total.
            'itemized_total' => $federalItemized,
            'oregon_itemized_total' => $oregonItemized,
            'federal_itemized_total' => $federalItemized,
            'hsa_contributions' => (float) ($profile->hsa_contributions_paid ?? 0),
            'health_insurance' => (float) ($profile->health_insurance_annual ?? 0),

            'gross_receipts' => (float) ($draftComp['gross_receipts'] ?? 0),
            'business_expenses' => (float) ($draftComp['business_expenses'] ?? 0),
            'ordinary_business_income' => (float) ($draftComp['ordinary_business_income'] ?? 0),
            'officer_compensation' => (float) ($draftComp['officer_compensation'] ?? 0),
            'shareholder_distributions' => (float) ($draftComp['shareholder_distributions'] ?? 0),
            'qbi_wage_basis' => (float) ($draftComp['qbi_wage_basis'] ?? 0),

            // SALT (Schedule A line 5) — using the Oregon tax liability as a proxy for
            // the deductible state income tax paid. Refine when we track withholding +
            // estimated payments separately.
            'state_income_tax' => (float) ($taxComp['oregon_tax'] ?? 0),
            'personal_property_tax' => 0.0,
            'other_taxes' => 0.0,

            // Oregon
            'oregon_taxable_income' => (float) ($taxComp['oregon_taxable_income'] ?? 0),
            'oregon_tax' => (float) ($taxComp['oregon_tax'] ?? 0),
            'oregon_estimated_payments' => (float) $fv($formsByCode->get('oregon_or40'), 'oregon_estimated_payments'),
            'oregon_prior_year_credit' => (float) $fv($formsByCode->get('oregon_or40'), 'prior_year_oregon_overpayment_credit'),
            'oregon_kicker_credit' => (float) $fv($formsByCode->get('oregon_or40'), 'oregon_kicker_credit'),
        ];
    }

    // ──────────────────────────────────────────
    // Data mappers — return formatted strings keyed by coordinate keys
    // ──────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    protected function map1040(array $d): array
    {
        // Filing status checkboxes: only the active one renders an "X".
        // Others are emitted as empty strings so the overlay code skips them
        // (FillableFormService::fillFormWithOverlay() bails on '' or '0' values).
        $filingStatus = strtolower((string) ($d['filing_status'] ?? ''));
        $checkbox = fn (string $status): string => $filingStatus === $status ? 'X' : '';

        // Dependent grid: 4 slots on page 1, anything beyond goes on an attached statement.
        $dependents = is_array($d['dependents'] ?? null) ? $d['dependents'] : [];
        $dependentFields = $this->buildDependentFields($dependents);

        // Compute taxable income from form's own intermediate values, then recompute
        // federal tax against that — this guarantees self-consistency when the upstream
        // tax engine used a different deduction value (it currently uses the Oregon
        // itemized total instead of the federal one, which understates total_deductions).
        $line11 = (float) $d['agi'];
        $line14 = (float) $d['total_deductions'];
        $line15 = round(max(0.0, $line11 - $line14), 2);
        $taxYear = (int) ($d['tax_year'] ?? date('Y'));
        $grossFedTax = TaxBracketEngine::calculateFederalIncomeTax($line15, $taxYear, $filingStatus);
        $ctc = (float) $d['child_tax_credit'];
        $line16 = round($grossFedTax, 2);
        $line22 = round(max(0.0, $line16 - $ctc), 2);
        // line 24 = total tax (= line 22 here since we don't track Sched 2 other taxes).
        // Refund/owed cascade from line 24 minus total payments (line 33), NOT from the
        // upstream tax engine's $d['amount_owed'] which used the wrong taxable income.
        $line24 = $line22;
        $totalPayments = (float) ($d['total_payments'] ?? 0);
        $line34Refund = round(max($totalPayments - $line24, 0), 2);
        $line37Owed = round(max($line24 - $totalPayments, 0), 2);

        return [
            'taxpayer_first_name' => $d['taxpayer_first_name'],
            'taxpayer_last_name' => $d['taxpayer_last_name'],
            'taxpayer_ssn' => $d['taxpayer_ssn'],
            'spouse_first_name' => $d['spouse_first_name'],
            'spouse_last_name' => $d['spouse_last_name'],
            'spouse_ssn' => $d['spouse_ssn'],
            'address' => $d['address'],
            'city_state_zip' => $d['city_state_zip'],
            'filing_status_single' => $checkbox('single'),
            'filing_status_mfj' => $checkbox('mfj'),
            'filing_status_mfs' => $checkbox('mfs'),
            'filing_status_hoh' => $checkbox('hoh'),
            'filing_status_qss' => $checkbox('qss'),
            ...$dependentFields,
            'line_1a' => $this->fmt($d['wages']),
            'line_1z' => $this->fmt($d['wages']),
            'line_8' => $this->fmt($d['pass_through_income']),
            'line_9' => $this->fmt($d['total_income']),
            'line_10' => $this->fmt($d['adjustments']),
            'line_11' => $this->fmt($d['agi']),
            'line_12' => $this->fmt($d['deduction']),
            'line_13' => $this->fmt($d['qbi_deduction']),
            'line_14' => $this->fmt($line14),
            'line_15' => $this->fmt($line15),
            // Tax & credits chain (lines 16-24). Recomputed from the form's own line 15
            // (not the upstream engine's federal_tax) so the printed return is internally
            // consistent. Schedule 2/3 amounts are $0 — wire them up if you ever owe SE
            // tax or claim other credits.
            'line_16' => $this->fmt($line16),
            'line_17' => $this->fmt(0),
            'line_18' => $this->fmt($line16),
            'line_19' => $this->fmt($ctc),
            'line_20' => $this->fmt(0),
            'line_21' => $this->fmt($ctc),
            'line_22' => $this->fmt($line22),
            'line_23' => $this->fmt(0),
            'line_24' => $this->fmt($line22),
            // Withholding lines (25a-25d) — populated when we eventually track W-2/1099 withholding.
            'line_25a' => $this->fmt(0),
            'line_25b' => $this->fmt(0),
            'line_25c' => $this->fmt(0),
            'line_25d' => $this->fmt(0),
            'line_26' => $this->fmt($d['total_payments']),
            // Refundable credits chain.
            'line_27' => $this->fmt(0),
            'line_28' => $this->fmt(0),
            'line_29' => $this->fmt(0),
            'line_31' => $this->fmt(0),
            'line_32' => $this->fmt(0),
            'line_33' => $this->fmt($totalPayments),
            'line_34' => $this->fmt($line34Refund),
            'line_36' => $this->fmt(0),
            'line_37' => $this->fmt($line37Owed),
            'line_38' => $this->fmt(0),
        ];
    }

    /**
     * Build dep1_*..dep4_* fields from the profile's dependents array.
     *
     * The 1040 page 1 grid only holds 4 dependents. If there are more, the
     * "dependent_overflow_check" box gets an X and dependents 5+ should appear
     * on an attached statement (handled separately by a future overflow form).
     *
     * @param  array<int, array<string, mixed>>  $dependents
     * @return array<string, string>
     */
    protected function buildDependentFields(array $dependents): array
    {
        $fields = [];
        $cutoffYear = (int) date('Y') - 17;

        for ($slot = 1; $slot <= 4; $slot++) {
            $dep = $dependents[$slot - 1] ?? null;

            if (! is_array($dep)) {
                $fields["dep{$slot}_first_name"] = '';
                $fields["dep{$slot}_last_name"] = '';
                $fields["dep{$slot}_ssn"] = '';
                $fields["dep{$slot}_relationship"] = '';
                $fields["dep{$slot}_lived_with_us"] = '';
                $fields["dep{$slot}_ctc"] = '';

                continue;
            }

            $nameParts = explode(' ', (string) ($dep['name'] ?? ''), 2);
            $first = trim($nameParts[0] ?? '');
            $last = trim($nameParts[1] ?? '');

            // CTC eligibility: child under 17 at end of tax year with valid SSN.
            // We approximate this from DOB; users with mixed eligibility can override.
            $ctcEligible = false;

            if (! empty($dep['dob']) && ! empty($dep['ssn'])) {
                $birthYear = (int) substr((string) $dep['dob'], 0, 4);
                $ctcEligible = $birthYear >= $cutoffYear;
            }

            $fields["dep{$slot}_first_name"] = $first;
            $fields["dep{$slot}_last_name"] = $last;
            $fields["dep{$slot}_ssn"] = (string) ($dep['ssn'] ?? '');
            $fields["dep{$slot}_relationship"] = strtoupper((string) ($dep['relationship'] ?? ''));
            // Default: assume dependent lived with the taxpayer >half the year and is a US person.
            $fields["dep{$slot}_lived_with_us"] = 'X';
            $fields["dep{$slot}_in_us"] = 'X';
            // Full-time student / disabled checkboxes: rare cases — left blank by default;
            // user can override per-dependent later if any qualify (e.g. college-age kid).
            $fields["dep{$slot}_full_time_student"] = '';
            $fields["dep{$slot}_disabled"] = '';
            $fields["dep{$slot}_ctc"] = $ctcEligible ? 'X' : '';
            // ODC (Credit for Other Dependents): $500 credit for dependents who don't qualify for CTC.
            // Mutually exclusive with CTC — only one of the two should be checked.
            $fields["dep{$slot}_odc"] = ! $ctcEligible && ! empty($dep['ssn']) ? 'X' : '';
        }

        // Overflow: 5+ dependents triggers the "more than four" checkbox.
        $fields['dependent_overflow_check'] = count($dependents) > 4 ? 'X' : '';
        // MFS/HOH lived-apart checkbox — leave blank by default; user can override later.
        $fields['mfs_hoh_lived_apart'] = '';

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    protected function mapSchedule1(array $d): array
    {
        return [
            'name_ssn' => $d['taxpayer_first_name'].' '.$d['taxpayer_last_name'],
            'ssn' => $d['taxpayer_ssn'],
            'line_5' => $this->fmt($d['pass_through_income']),
            'line_10' => $this->fmt($d['pass_through_income']),
            'line_26' => $this->fmt($d['adjustments']),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapScheduleA(array $d): array
    {
        // SALT (State And Local Taxes) line 5 sub-totals.
        // 5a = state income tax (or sales tax) — using Oregon income tax as proxy.
        // 5b = real estate property tax (the property_taxes profile field).
        // 5c = personal property tax (vehicle reg etc.) — Oregon doesn't have this; default 0.
        // 5d = sum of 5a+5b+5c.
        // 5e = capped to $40,000 ($20,000 MFS) for 2025 per the One Big Beautiful Bill Act.
        $stateIncomeTax = (float) ($d['state_income_tax'] ?? 0);
        $realEstateTax = (float) $d['property_taxes'];
        $personalPropertyTax = (float) ($d['personal_property_tax'] ?? 0);
        $line5d = $stateIncomeTax + $realEstateTax + $personalPropertyTax;
        $saltCap = strtolower((string) ($d['filing_status'] ?? '')) === 'mfs' ? 20000 : 40000;
        $line5e = min($line5d, $saltCap);
        $line6 = (float) ($d['other_taxes'] ?? 0);
        $line7 = $line5e + $line6;

        return [
            'name_ssn' => $d['taxpayer_first_name'].' '.$d['taxpayer_last_name'],
            'ssn' => $d['taxpayer_ssn'],
            'line_5a' => $this->fmt($stateIncomeTax),
            'line_5b' => $this->fmt($realEstateTax),
            'line_5c' => $this->fmt($personalPropertyTax),
            'line_5d' => $this->fmt($line5d),
            'line_5e' => $this->fmt($line5e),
            'line_6' => $this->fmt($line6),
            'line_7' => $this->fmt($line7),
            'line_8a' => $this->fmt($d['mortgage_interest']),
            'line_10' => $this->fmt($d['mortgage_interest']),
            'line_11' => $this->fmt($d['charitable_contributions']),
            'line_14' => $this->fmt($d['charitable_contributions']),
            'line_17' => $this->fmt($d['itemized_total']),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapScheduleE(array $d): array
    {
        $fullName = $d['taxpayer_first_name'].' '.$d['taxpayer_last_name'];

        return [
            // Both pages of Schedule E need the taxpayer name at the top.
            'name_ssn_page1' => $fullName,
            'ssn_page1' => $d['taxpayer_ssn'],
            'name_ssn' => $fullName,
            'ssn' => $d['taxpayer_ssn'],
            'line_28_name' => $d['entity_name'],
            'line_28_ein' => $d['entity_ein'],
            'line_28_income' => $this->fmt($d['pass_through_income']),
            'line_32' => $this->fmt($d['pass_through_income']),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function map1120S(array $d): array
    {
        $otherDed = max($d['business_expenses'] - $d['officer_compensation'], 0);

        // Entity-level federal tax (excess net passive income tax + Sched D tax).
        // Most pass-through S-corps have $0 here.
        $corpTax = (float) ($d['corp_federal_tax'] ?? 0);
        $corpEstimatedPayments = (float) ($d['corp_estimated_payments'] ?? 0);
        $corpExtensionPayments = (float) ($d['corp_extension_payments'] ?? 0);
        $totalCorpPayments = $corpEstimatedPayments + $corpExtensionPayments;
        $estimatedTaxPenalty = (float) ($d['corp_estimated_tax_penalty'] ?? 0);
        $amountOwed = max(($corpTax + $estimatedTaxPenalty) - $totalCorpPayments, 0);
        $overpayment = max($totalCorpPayments - ($corpTax + $estimatedTaxPenalty), 0);

        return [
            'entity_name' => $d['entity_name'],
            'entity_ein' => $d['entity_ein'],
            'entity_address' => $d['entity_address'],
            'city_state_zip' => $d['city_state_zip'],
            'line_1a' => $this->fmt($d['gross_receipts']),
            'line_6' => $this->fmt($d['gross_receipts']),
            'line_7' => $this->fmt($d['officer_compensation']),
            'line_20' => $this->fmt($otherDed),
            'line_21' => $this->fmt($d['officer_compensation'] + $otherDed),
            'line_22' => $this->fmt($d['ordinary_business_income']),
            'line_23c' => $this->fmt($corpTax),
            'line_24a' => $this->fmt($corpEstimatedPayments),
            'line_24b' => $this->fmt($corpExtensionPayments),
            'line_24z' => $this->fmt($totalCorpPayments),
            'line_25' => $this->fmt($estimatedTaxPenalty),
            'line_26' => $this->fmt($amountOwed),
            'line_27' => $this->fmt($overpayment),
            'line_28b' => $this->fmt($overpayment),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapScheduleK1(array $d): array
    {
        return [
            'corp_name' => $d['entity_name'],
            'corp_ein' => $d['entity_ein'],
            'shareholder_name' => $d['taxpayer_first_name'].' '.$d['taxpayer_last_name'],
            'shareholder_ssn' => $d['taxpayer_ssn'],
            'box_1' => $this->fmt($d['ordinary_business_income']),
            'box_16d' => $this->fmt($d['shareholder_distributions']),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapForm8995(array $d): array
    {
        // QBI computation chain. Each line on Form 8995 carries a specific intermediate
        // value — putting the final deduction on line 10 (where it was) skips the income
        // limitation step entirely and looks like we never did the math.
        $totalQbi = (float) $d['ordinary_business_income'];
        $line2 = max(0.0, $totalQbi);            // Total QBI from line 1 column (c)
        $line3 = 0.0;                            // Prior-year QBI carryforward (we don't track yet)
        $line4 = max(0.0, $line2 + $line3);      // Total qualified business income
        $line5 = round($line4 * 0.20, 0);        // QBI component (the 20% before income limit)

        $line6 = 0.0;                            // REIT dividends + PTP income (typically 0 for S-corp owners)
        $line7 = 0.0;                            // REIT/PTP carryforward
        $line8 = max(0.0, $line6 + $line7);      // Total REIT/PTP income
        $line9 = round($line8 * 0.20, 0);        // REIT/PTP component (20%)

        $line10 = $line5 + $line9;               // QBI deduction BEFORE the income limitation
        $line11 = max(0.0, $d['taxable_income'] + $d['qbi_deduction']); // Taxable income before QBI
        $line12 = 0.0;                           // Net capital gain (qualified dividends + cap gains)
        $line13 = max(0.0, $line11 - $line12);
        $line14 = round($line13 * 0.20, 0);      // Income-limit cap (20% of pre-QBI taxable income)
        $line15 = min($line10, $line14);         // The actual QBI deduction goes here

        // Loss carryforward to next year (when QBI is negative; here we have positive QBI)
        $line16 = min(0.0, $line2 + $line3);
        $line17 = min(0.0, $line6 + $line7);

        return [
            'name_ssn' => $d['taxpayer_first_name'].' '.$d['taxpayer_last_name'],
            'ssn' => $d['taxpayer_ssn'],
            'line_1_name' => $d['entity_name'],
            'line_1_tin' => $d['entity_ein'],
            'line_1_qbi' => $this->fmt($totalQbi),
            'line_2' => $this->fmt($line2),
            'line_3' => $this->fmt($line3),
            'line_4' => $this->fmt($line4),
            'line_5' => $this->fmt($line5),
            'line_6' => $this->fmt($line6),
            'line_7' => $this->fmt($line7),
            'line_8' => $this->fmt($line8),
            'line_9' => $this->fmt($line9),
            'line_10' => $this->fmt($line10),
            'line_11' => $this->fmt($line11),
            'line_12' => $this->fmt($line12),
            'line_13' => $this->fmt($line13),
            'line_14' => $this->fmt($line14),
            'line_15' => $this->fmt($line15),
            'line_16' => $this->fmt($line16),
            'line_17' => $this->fmt($line17),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapOR40(array $d): array
    {
        $oregonEstPayments = (float) ($d['oregon_estimated_payments'] ?? 0);
        $oregonPriorCredit = (float) ($d['oregon_prior_year_credit'] ?? 0);
        $oregonKicker = (float) ($d['oregon_kicker_credit'] ?? 0);
        $agi = (float) $d['agi'];

        // Filing status checkboxes — Oregon uses single/mfj/mfs/hoh/qsw same as federal.
        $filingStatus = strtolower((string) ($d['filing_status'] ?? ''));
        $check = fn (string $status): string => $filingStatus === $status ? 'X' : '';

        // Spouse name parts
        $spouseParts = explode(' ', (string) ($d['spouse_name'] ?? ''), 2);
        $spouseFirst = trim($spouseParts[0] ?? '');
        $spouseLast = trim($spouseParts[1] ?? '');

        // Dependents (3 slots fit on page 2; remaining go on Schedule OR-ADD-DEP)
        $dependents = is_array($d['dependents'] ?? null) ? $d['dependents'] : [];
        $depFields = $this->buildOR40DependentFields($dependents);

        // Federal tax liability subtraction (Oregon line 10). Uses the form's own
        // recomputed federal tax (line 24 of 1040), NOT the upstream tax engine
        // value which was computed against a different taxable income. Then
        // capped per Oregon's published phaseout:
        //   MFJ/QSW: $8,250 max, phased out linearly from AGI $125k → $145k.
        //   Single/MFS/HOH: $4,125 max, phased out linearly from $62,500 → $72,500.
        // For this user's $219k MFJ AGI, the cap resolves to $0.
        $formFederalTax = (float) ($d['form_federal_tax'] ?? $d['federal_tax'] ?? 0);

        if (in_array($filingStatus, ['mfj', 'qw', 'qsw'], true)) {
            $fullCap = 8250.0;
            $phaseStart = 125000.0;
            $phaseEnd = 145000.0;
        } else {
            $fullCap = 4125.0;
            $phaseStart = 62500.0;
            $phaseEnd = 72500.0;
        }

        if ($agi <= $phaseStart) {
            $allowedCap = $fullCap;
        } elseif ($agi >= $phaseEnd) {
            $allowedCap = 0.0;
        } else {
            $allowedCap = $fullCap * (1 - ($agi - $phaseStart) / ($phaseEnd - $phaseStart));
        }

        $federalTaxSubtraction = round(min($formFederalTax, $allowedCap), 2);

        // Itemized vs standard deduction (line 16 vs 17)
        $usesItemized = (bool) ($d['uses_itemized'] ?? false);
        // Oregon line 16 uses the Oregon-specific itemized (no state income tax included).
        $itemizedDeduction = $usesItemized ? (float) ($d['oregon_itemized_total'] ?? $d['itemized_total'] ?? 0) : 0.0;
        $standardDeduction = $usesItemized ? 0.0 : 5495.0; // 2025 OR MFJ standard; refine if needed

        // Line 15 = AGI minus Oregon-specific subtractions; line 18 = line 15 - max(16, 17).
        // Compute these from the form's own intermediate values so the audit's math
        // checks pass and the printed return is internally consistent.
        $line15 = round(max($agi - $federalTaxSubtraction, 0), 2);
        $line18 = round(max($line15 - max($itemizedDeduction, $standardDeduction), 0), 2);

        // Recompute Oregon tax against the form's own line 18 (not the upstream engine's
        // figure, which was based on a different taxable income). Same self-consistency
        // principle as the federal side.
        $taxYear = (int) ($d['tax_year'] ?? date('Y'));
        $oregonTax = round(TaxBracketEngine::calculateOregonIncomeTax($line18, $taxYear, $filingStatus), 2);
        $oregonTotalPayments = round($oregonEstPayments + $oregonPriorCredit + $oregonKicker, 2);
        $oregonTaxableIncome = $line18;   // alias kept for any downstream references

        return array_merge([
            // Taxpayer block
            'taxpayer_first_name' => $d['taxpayer_first_name'],
            'taxpayer_initial' => $d['taxpayer_middle_initial'] ?? '',
            'taxpayer_last_name' => $d['taxpayer_last_name'],
            'taxpayer_ssn' => $d['taxpayer_ssn'],
            'taxpayer_dob' => $d['taxpayer_dob'] ?? '',
            // Spouse block
            'spouse_first_name' => $spouseFirst,
            'spouse_initial' => $d['spouse_middle_initial'] ?? '',
            'spouse_last_name' => $spouseLast,
            'spouse_ssn' => $d['spouse_ssn'],
            'spouse_dob' => $d['spouse_dob'] ?? '',
            // Address
            'mailing_address' => $d['address'] ?? '',
            'city' => $d['city'] ?? '',
            'state' => $d['state'] ?? '',
            'zip' => $d['zip'] ?? '',
            'country' => '',
            'phone' => $d['taxpayer_phone'] ?? '',
            // Filing status
            'filing_status_single' => $check('single'),
            'filing_status_mfj' => $check('mfj'),
            'filing_status_mfs' => $check('mfs'),
            'filing_status_hoh' => $check('hoh'),
            'filing_status_qsw' => $check('qw'),
            // Exemptions
            'exemption_self' => '1',
            'exemption_spouse' => $filingStatus === 'mfj' ? '1' : '',
            'total_dependents' => (string) count($dependents),
            'total_exemptions' => (string) (1 + ($filingStatus === 'mfj' ? 1 : 0) + count($dependents)),
            // Income / subtractions / deductions / tax (page 3-4)
            'line_7' => $this->fmt($agi),
            'line_8' => $this->fmt(0),                           // Additions from Sched OR-ASC
            'line_9' => $this->fmt($agi),                        // Income after additions
            'line_10' => $this->fmt($federalTaxSubtraction),     // Federal tax liability subtraction
            'line_11' => $this->fmt(0),                          // Social Security in federal
            'line_12' => $this->fmt(0),                          // Oregon refund in federal
            'line_13' => $this->fmt(0),                          // Other subtractions
            'line_14' => $this->fmt($federalTaxSubtraction),     // Total subtractions
            'line_15' => $this->fmt($line15),                    // Income after subtractions
            'line_16' => $this->fmt($itemizedDeduction),
            'line_17' => $this->fmt($standardDeduction),
            'line_18' => $this->fmt($line18),                    // Computed: line 15 - max(16, 17)
            'line_19' => $this->fmt($line18),                    // Same as line 18 (no additional adjustment)
            'line_20' => $this->fmt($oregonTax),

            // Page 4: Additions to tax (lines 21-24).
            'line_21' => $this->fmt(0),                          // Interest on certain installment sales
            'line_22' => $this->fmt(0),                          // Tax recaptures from Sched OR-ASC C5
            'line_23' => $this->fmt(0),                          // Total additions to tax (21 + 22)
            'line_24' => $this->fmt($oregonTax),                 // Total tax before credits (20 + 23)

            // Page 4: Standard and carryforward credits (lines 25-31).
            // Exemption credit (line 25) phases out at high AGI; for MFJ it's $0 above ~$200k.
            'line_25' => $this->fmt(0),                          // Exemption credit (phased out at high AGI)
            'line_26' => $this->fmt(0),                          // Political contribution credit
            'line_27' => $this->fmt(0),                          // Standard credits from OR-ASC D16
            'line_28' => $this->fmt(0),                          // Total standard credits (25 + 26 + 27)
            'line_29' => $this->fmt($oregonTax),                 // Tax minus standard credits (24 - 28)
            'line_30' => $this->fmt(0),                          // Carryforward credits from OR-ASC E9
            'line_31' => $this->fmt($oregonTax),                 // Tax after standard & carryforward credits (29 - 30)

            // Page 5: Kicker, Payments and refundable credits, Tax to pay or refund.
            'line_32' => $this->fmt($oregonKicker),              // Oregon kicker (DOR-published per taxpayer; biennial)
            'line_33' => $this->fmt(0),                          // Oregon income tax withheld (W-2 / 1099)
            'line_34' => $this->fmt($oregonPriorCredit),         // Prior-year refund applied
            'line_35' => $this->fmt($oregonEstPayments),         // Estimated tax payments for current year
            'line_36' => $this->fmt(0),                          // PTE owner payment from Sched OR-K-1
            'line_37' => $this->fmt(0),                          // Earned income credit (income too high)
            'line_38' => $this->fmt(0),                          // Oregon Kids Credit (income too high)
            'line_39' => $this->fmt(0),                          // Other refundable credits from OR-ASC F7
            'line_40' => $this->fmt($oregonTotalPayments),       // Total payments + refundable credits
            'line_41' => $this->fmt(round(max($oregonTotalPayments - $oregonTax, 0), 2)), // Overpayment
            'line_42' => $this->fmt(round(max($oregonTax - $oregonTotalPayments, 0), 2)), // Net tax owed
            'line_43' => $this->fmt(0),                          // Penalty/interest for late filing
            'line_44' => $this->fmt(0),                          // Underpayment-of-estimated-tax interest

            // Page 6: Tax to pay or refund (continued) + refund-allocation checkoffs.
            // We don't model penalties/interest, charitable checkoffs, party checkoffs, or 529
            // deposits here; they default to 0 so totals roll cleanly. line_46/47 mirror
            // line_42/41 because line_45 is 0.
            'page6_last_name' => strtoupper((string) ($d['taxpayer_last_name'] ?? '')),
            'page6_ssn' => (string) ($d['taxpayer_ssn'] ?? ''),
            'line_45' => $this->fmt(0),                          // Total penalty + interest (43 + 44)
            'line_46' => $this->fmt(round(max($oregonTax - $oregonTotalPayments, 0), 2)),     // Net tax incl. penalty/interest (42 + 45)
            'line_47' => $this->fmt(round(max($oregonTotalPayments - $oregonTax, 0), 2)),     // Overpayment less penalty/interest (41 - 45)
            'line_48' => $this->fmt(0),                          // Apply refund to next-year estimated tax
            'line_49' => $this->fmt(0),                          // Charitable checkoff (Sched OR-DONATE)
            'line_50' => $this->fmt(0),                          // Political party $3 checkoff
            'line_50a_party_code' => '',                         // Party code — taxpayer
            'line_50b_party_code' => '',                         // Party code — spouse
            'line_51' => $this->fmt(0),                          // Higher-ed savings deposits (Sched OR-529)
            'line_52' => $this->fmt(0),                          // Total of 48-51 (capped at line 47)
            'line_53' => $this->fmt(round(max($oregonTotalPayments - $oregonTax, 0), 2)),     // Net refund (47 - 52)
        ], $depFields);
    }

    /**
     * Build OR-40 dependent fields. The form has 3 slots on page 2; if there are more
     * dependents, the overflow checkbox flags that Schedule OR-ADD-DEP should be attached.
     *
     * @param  array<int, array<string, mixed>>  $dependents
     * @return array<string, string>
     */
    protected function buildOR40DependentFields(array $dependents): array
    {
        $fields = [];

        for ($slot = 1; $slot <= 3; $slot++) {
            $dep = $dependents[$slot - 1] ?? null;

            if (! is_array($dep)) {
                $fields["dep{$slot}_first_name"] = '';
                $fields["dep{$slot}_initial"] = '';
                $fields["dep{$slot}_last_name"] = '';
                $fields["dep{$slot}_dob"] = '';
                $fields["dep{$slot}_ssn"] = '';
                $fields["dep{$slot}_code"] = '';

                continue;
            }

            $nameParts = explode(' ', (string) ($dep['name'] ?? ''), 2);
            $first = trim($nameParts[0] ?? '');
            $last = trim($nameParts[1] ?? '');

            $fields["dep{$slot}_first_name"] = $first;
            $fields["dep{$slot}_initial"] = '';
            $fields["dep{$slot}_last_name"] = $last;
            $fields["dep{$slot}_dob"] = $this->formatDobMmDdYyyy((string) ($dep['dob'] ?? ''));
            $fields["dep{$slot}_ssn"] = (string) ($dep['ssn'] ?? '');
            // Oregon dependent relationship code: 5 = son/daughter (most common).
            $fields["dep{$slot}_code"] = '5';
        }

        // Attach Schedule OR-ADD-DEP if more than 3 dependents.
        $fields['add_dep_overflow_check'] = count($dependents) > 3 ? 'X' : '';

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    protected function mapORA(array $d): array
    {
        $name = trim((string) ($d['taxpayer_first_name'] ?? '').' '.(string) ($d['taxpayer_last_name'] ?? ''));
        $ssn = (string) ($d['taxpayer_ssn'] ?? '');
        $filingStatus = strtolower((string) ($d['filing_status'] ?? ''));

        // Inputs
        $medical = (float) ($d['medical_expenses'] ?? 0);
        $agi = (float) ($d['agi'] ?? 0);
        $stateIncomeTaxNonOregon = 0.0;        // We pay no state income tax to a non-Oregon jurisdiction.
        $realEstateTax = (float) ($d['property_taxes'] ?? 0);
        $personalPropertyTax = 0.0;            // No registered vehicles/boats on Schedule A line 7 in our case.
        $mortgageInterestFinancialInst = (float) ($d['mortgage_interest'] ?? 0);
        $mortgageInterestIndividuals = 0.0;
        $points = 0.0;
        $investmentInterest = 0.0;
        $charitableCash = (float) ($d['charitable_contributions'] ?? 0);
        $charitableNonCash = 0.0;
        $charitableCarryover = 0.0;
        $casualtyLosses = 0.0;

        // Lines 1-4: Medical & dental
        $line1 = round($medical, 2);
        $line2 = round($agi, 2);
        $line3 = round($agi * 0.075, 2);
        $line4 = round(max($line1 - $line3, 0), 2);

        // Lines 5-11: Taxes you paid (no line 8 — Oregon dropped that slot).
        // Line 5 specifically excludes Oregon income tax (OR-A line 5 instructions).
        // Line 9 = subtotal of 5+6+7; line 10 = SALT cap value; line 11 = smaller
        // of line 9 or line 10 (carries to the grand total on line 23).
        $line5 = round($stateIncomeTaxNonOregon, 2);
        $line6 = round($realEstateTax, 2);
        $line7 = round($personalPropertyTax, 2);
        $line9 = round($line5 + $line6 + $line7, 2);
        // Oregon mirrors the federal SALT cap: $10,000 ($5,000 MFS).
        $saltCap = $filingStatus === 'mfs' ? 5000.0 : 10000.0;
        $line10 = round($saltCap, 2);
        $line11 = round(min($line9, $line10), 2);

        // Lines 12-17: Interest you paid (no line 15 — federal reserved, OR mirrored)
        $line12 = round($mortgageInterestFinancialInst, 2);
        $line13 = round($mortgageInterestIndividuals, 2);
        $line14 = round($points, 2);
        $line16 = round($investmentInterest, 2);
        $line17 = round($line12 + $line13 + $line14 + $line16, 2);

        // Lines 18-21: Gifts to charity (line 21 is the subtotal)
        $line18 = round($charitableCash, 2);
        $line19 = round($charitableNonCash, 2);
        $line20 = round($charitableCarryover, 2);
        $line21 = round($line18 + $line19 + $line20, 2);

        // Lines 22-23: Casualty/theft + Oregon itemized deductions total
        $line22 = round($casualtyLosses, 2);
        $line23 = round($line4 + $line11 + $line17 + $line21 + $line22, 2);

        return [
            // Page 1 header
            'name_ssn' => $name,
            'ssn' => $ssn,

            // Page 1 — Medical (1-4)
            'line_1' => $this->fmt($line1),
            'line_2' => $this->fmt($line2),
            'line_3' => $this->fmt($line3),
            'line_4' => $this->fmt($line4),

            // Page 1 — Taxes you paid (5-7, 9-11; no line 8)
            'line_5' => $this->fmt($line5),
            'line_6' => $this->fmt($line6),
            'line_7' => $this->fmt($line7),
            'line_9' => $this->fmt($line9),
            'line_10' => $this->fmt($line10),
            'line_11' => $this->fmt($line11),

            // Page 2 — Interest (12-17, no 15)
            'line_12' => $this->fmt($line12),
            'line_13' => $this->fmt($line13),
            'line_14' => $this->fmt($line14),
            'line_16' => $this->fmt($line16),
            'line_17' => $this->fmt($line17),

            // Page 2 — Charity (18-21)
            'line_18' => $this->fmt($line18),
            'line_19' => $this->fmt($line19),
            'line_20' => $this->fmt($line20),
            'line_21' => $this->fmt($line21),

            // Page 2 — Casualty (22) + Total (23)
            'line_22' => $this->fmt($line22),
            'line_23' => $this->fmt($line23),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mapOR20S(array $d): array
    {
        $ordinaryIncome = (float) ($d['ordinary_business_income'] ?? 0);
        $grossReceipts = (float) ($d['gross_receipts'] ?? 0);
        $oregonSales = $grossReceipts;       // 100% Oregon-sourced for our case.

        // S-corps without built-in gains or excess net passive income: line 6 = 100%,
        // lines 7, 8, 10 = 0, line 11 = $150 minimum tax, line 12 = $150 (greater of 10 or 11).
        $line11MinTax = 150.0;
        $line12 = $line11MinTax;             // greater of line 10 (=0) or line 11 ($150)
        $line14 = $line12;                   // line 14 = line 12 + line 13 ($0 here)
        $line16 = $line14;                   // line 16 = line 14 - line 15 (no carryforward credits)
        $line18 = $line16;                   // line 18 = line 16 + line 17 (no LIFO recapture)
        $line19 = 0.0;                       // No estimated payments made.
        $line20 = round(max($line18 - $line19, 0), 2);   // tax due
        $line21 = round(max($line19 - $line18, 0), 2);   // overpayment
        $line22 = 0.0;                       // penalty
        $line23 = 0.0;                       // interest
        $line24 = 0.0;                       // estimated tax interest
        $line25 = round($line22 + $line23 + $line24, 2);
        $line26 = round($line20 + $line25, 2);              // total due
        $line27 = round(max($line21 - $line25, 0), 2);      // refund
        $line28 = 0.0;                       // refund credited to estimated tax
        $line29 = round(max($line27 - $line28, 0), 2);      // net refund

        $checked = fn (bool $on): string => $on ? 'X' : '';

        return [
            // ===== Page 1 =====
            'check_excise_tax' => $checked(true),       // S-corps file excise tax in Oregon
            'check_income_tax' => '',
            // Fiscal year fields blank for calendar-year filers.
            'entity_name' => strtoupper((string) ($d['entity_name'] ?? '')),
            'entity_ein' => (string) ($d['entity_ein'] ?? ''),
            'dba_name' => '',                                                 // No DBA
            'attn_first_name' => '',
            'attn_initial' => '',
            'attn_last_name' => '',
            'address' => strtoupper((string) ($d['address'] ?? '')),          // Home = entity address
            'city' => strtoupper((string) ($d['city'] ?? '')),
            'state' => strtoupper((string) ($d['state'] ?? '')),
            'zip' => (string) ($d['zip'] ?? ''),
            'contact_first_name' => strtoupper((string) ($d['taxpayer_first_name'] ?? '')),
            'contact_initial' => strtoupper((string) ($d['taxpayer_middle_initial'] ?? '')),
            'contact_last_name' => strtoupper((string) ($d['taxpayer_last_name'] ?? '')),
            'contact_phone' => (string) ($d['taxpayer_phone'] ?? ''),
            'email' => '',                                                    // Filled manually if desired

            // ===== Page 2 =====
            'naics_code' => (string) ($d['business_activity_code'] ?? ''),
            'ordinary_income_1120s' => $this->fmt($ordinaryIncome),
            'total_oregon_sales' => $this->fmt($oregonSales),

            // ===== Page 3 =====
            'line_1a' => $this->fmt(0),
            'line_1b' => $this->fmt(0),
            'line_1c' => $this->fmt(0),
            'line_2' => $this->fmt(0),
            'line_3' => $this->fmt(0),
            'line_4' => $this->fmt(0),
            'line_5' => $this->fmt(0),
            'line_6' => '100.0000',                       // Apportionment %
            'line_7' => $this->fmt(0),                    // Oregon taxable income (no built-in gains)
            'line_8' => $this->fmt(0),
            'line_9' => $this->fmt(0),
            'line_10' => $this->fmt(0),
            'line_11' => $this->fmt($line11MinTax),
            'line_12' => $this->fmt($line12),

            // ===== Page 4 =====
            'line_13' => $this->fmt(0),
            'line_14' => $this->fmt($line14),
            'line_15' => $this->fmt(0),
            'line_16' => $this->fmt($line16),
            'line_17' => $this->fmt(0),
            'line_18' => $this->fmt($line18),
            'line_19' => $this->fmt($line19),
            'line_20' => $this->fmt($line20),
            'line_21' => $this->fmt($line21),
            'line_22' => $this->fmt($line22),
            'line_23' => $this->fmt($line23),
            'line_24' => $this->fmt($line24),
            'line_25' => $this->fmt($line25),
            'line_26' => $this->fmt($line26),

            // ===== Page 5 =====
            'line_27' => $this->fmt($line27),
            'line_28' => $this->fmt($line28),
            'line_29' => $this->fmt($line29),
            // Schedule SM additions — all zero (no Oregon-specific modifications)
            'sm_add_1' => $this->fmt(0),
            'sm_add_2' => $this->fmt(0),
            'sm_add_3' => $this->fmt(0),
            'sm_add_4' => $this->fmt(0),
            'sm_sub_5' => $this->fmt(0),
            'sm_sub_6' => $this->fmt(0),
            'sm_sub_7' => $this->fmt(0),

            // ===== Page 6 =====
            'sm_sub_8' => $this->fmt(0),
            'sm_sub_9' => $this->fmt(0),
            // Schedule ES Q1-Q3 — empty (no estimated payments made for 2025)

            // ===== Page 7 =====
            'es_line_5' => $this->fmt(0),
            'es_line_6' => $this->fmt(0),
            'es_line_8' => $this->fmt(0),

            // Page 8 (signatures + preparer block) — left to be filled by hand on print.
        ];
    }

    /**
     * Schedule OR-ADD-DEP — supplemental dependents schedule for OR-40 filers
     * with more than 3 dependents. Holds dependents 4 through 8 (form supports
     * up to 8; multiple sheets are allowed if more than 8).
     *
     * @return array<string, string>
     */
    protected function mapORAddDep(array $d): array
    {
        $dependents = is_array($d['dependents'] ?? null) ? $d['dependents'] : [];
        // Skip the first 3 (those go on OR-40 page 2); take the next 5 for this schedule.
        $overflow = array_values(array_slice($dependents, 3, 5));

        $fields = [
            'last_name' => $d['taxpayer_last_name'],
            'ssn' => $d['taxpayer_ssn'],
        ];

        for ($i = 0; $i < 5; $i++) {
            $slot = $i + 4; // 4..8 in the overall sequence
            $dep = $overflow[$i] ?? null;

            if (! is_array($dep)) {
                $fields["dep{$slot}_first_name"] = '';
                $fields["dep{$slot}_initial"] = '';
                $fields["dep{$slot}_last_name"] = '';
                $fields["dep{$slot}_dob"] = '';
                $fields["dep{$slot}_ssn"] = '';
                $fields["dep{$slot}_code"] = '';

                continue;
            }

            $nameParts = explode(' ', (string) ($dep['name'] ?? ''), 2);
            $fields["dep{$slot}_first_name"] = trim($nameParts[0] ?? '');
            $fields["dep{$slot}_initial"] = '';
            $fields["dep{$slot}_last_name"] = trim($nameParts[1] ?? '');
            $fields["dep{$slot}_dob"] = $this->formatDobMmDdYyyy((string) ($dep['dob'] ?? ''));
            $fields["dep{$slot}_ssn"] = (string) ($dep['ssn'] ?? '');
            $fields["dep{$slot}_code"] = '5'; // 5 = son/daughter
        }

        $fields['additional_dep_count'] = (string) count($overflow);
        $fields['additional_disability_count'] = '0';

        return $fields;
    }

    protected function fmt(float $amount): string
    {
        if ($amount < 0) {
            return '('.number_format(abs($amount), 0).')';
        }

        return number_format($amount, 0);
    }

    /**
     * Convert a stored DOB (typically Y-m-d) to the m/d/Y format that IRS and
     * Oregon DOR expect on printed returns. Returns the input unchanged if it
     * doesn't parse — better to print SOMETHING than blank.
     */
    protected function formatDobMmDdYyyy(string $dob): string
    {
        $dob = trim($dob);
        if ($dob === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($dob)->format('m/d/Y');
        } catch (\Throwable) {
            return $dob;
        }
    }
}
