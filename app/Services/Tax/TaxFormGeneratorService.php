<?php

namespace App\Services\Tax;

use App\Models\Contractor1099Data;
use App\Models\FinancialDocument;
use App\Models\TaxProfile;
use App\Models\TaxReturnWorkpaper;
use App\Services\Pdf\TailwindPdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates tax form artifacts from the current deterministic workpaper packet.
 */
class TaxFormGeneratorService
{
    /**
     * Generate a 1040-ES payment voucher for a specific quarter.
     *
     * @return string Storage path of the generated PDF
     */
    public function generate1040ES(TaxProfile $profile, int $quarter, float $paymentAmount, int $year): string
    {
        $storagePath = "tax-forms/{$profile->user_id}/{$year}/1040-ES-Q{$quarter}.pdf";

        $dueDate = $this->getQuarterlyDueDate($quarter, $year);

        TailwindPdf::view('pdf.tax-forms.1040-es', [
            'profile' => $profile,
            'quarter' => $quarter,
            'year' => $year,
            'payment_amount' => $paymentAmount,
            'due_date' => $dueDate,
            'payment_instructions' => $this->getFederalPaymentInstructions($paymentAmount, $quarter, $year),
        ])->save($storagePath);

        Log::info('TaxFormGenerator: 1040-ES generated', [
            'user_id' => $profile->user_id,
            'year' => $year,
            'quarter' => $quarter,
            'amount' => $paymentAmount,
        ]);

        return $storagePath;
    }

    /**
     * Generate an Oregon OR-40-V estimated payment voucher.
     */
    public function generateOR40V(TaxProfile $profile, int $quarter, float $paymentAmount, int $year): string
    {
        $storagePath = "tax-forms/{$profile->user_id}/{$year}/OR-40-V-Q{$quarter}.pdf";

        $dueDate = $this->getQuarterlyDueDate($quarter, $year);

        TailwindPdf::view('pdf.tax-forms.or-40-v', [
            'profile' => $profile,
            'quarter' => $quarter,
            'year' => $year,
            'payment_amount' => $paymentAmount,
            'due_date' => $dueDate,
            'payment_instructions' => $this->getOregonPaymentInstructions($paymentAmount, $quarter, $year),
        ])->save($storagePath);

        return $storagePath;
    }

    /**
     * Generate 1099-NEC for a specific contractor.
     */
    public function generate1099NEC(TaxProfile $profile, Contractor1099Data $contractor, int $year): string
    {
        $storagePath = "tax-forms/{$profile->user_id}/{$year}/1099-NEC-{$contractor->vendor_id}.pdf";

        TailwindPdf::view('pdf.tax-forms.1099-nec', [
            'profile' => $profile,
            'contractor' => $contractor,
            'year' => $year,
            'filing_deadline' => 'January 31, '.($year + 1),
        ])->save($storagePath);

        return $storagePath;
    }

    /**
     * Generate all 1099-NECs for a tax year.
     *
     * @return array{generated: int, paths: array<string>}
     */
    public function generateAll1099s(TaxProfile $profile, int $year): array
    {
        $contractors = Contractor1099Data::where('tax_year', $year)
            ->where('requires_1099', true)
            ->get();

        $paths = [];
        foreach ($contractors as $contractor) {
            $paths[] = $this->generate1099NEC($profile, $contractor, $year);
        }

        return [
            'generated' => count($paths),
            'paths' => $paths,
        ];
    }

    /**
     * Generate Form 1096 transmittal (cover sheet for 1099s).
     */
    public function generate1096(TaxProfile $profile, int $year): string
    {
        $storagePath = "tax-forms/{$profile->user_id}/{$year}/1096-transmittal.pdf";

        $contractors = Contractor1099Data::where('tax_year', $year)
            ->where('requires_1099', true)
            ->get();

        $totalAmount = $contractors->sum('total_payments');

        TailwindPdf::view('pdf.tax-forms.1096', [
            'profile' => $profile,
            'year' => $year,
            'total_forms' => $contractors->count(),
            'total_amount' => $totalAmount,
        ])->save($storagePath);

        return $storagePath;
    }

    /**
     * Generate all quarterly vouchers (federal + Oregon) for a quarter.
     *
     * @return array{federal: string|null, oregon: string|null}
     */
    public function generateQuarterlyVouchers(
        TaxProfile $profile,
        int $quarter,
        int $year,
        float $federalAmount,
        float $oregonAmount
    ): array {
        $paths = ['federal' => null, 'oregon' => null];

        if ($federalAmount > 0) {
            $paths['federal'] = $this->generate1040ES($profile, $quarter, $federalAmount, $year);
        }

        if ($oregonAmount > 0) {
            $paths['oregon'] = $this->generateOR40V($profile, $quarter, $oregonAmount, $year);
        }

        return $paths;
    }

    /**
     * Generate a source-linked annual return signoff packet from the persisted workpaper.
     */
    public function generateAnnualReturnSignoffPacket(TaxReturnWorkpaper $workpaper, string $format = 'html'): string
    {
        if (! in_array($format, ['html', 'pdf'], true)) {
            throw new InvalidArgumentException('Annual return signoff packets must be generated as html or pdf.');
        }

        if (! $workpaper->is_ready_for_signoff) {
            throw new RuntimeException('Annual return workpaper is not ready for owner signoff.');
        }

        $extension = $format === 'pdf' ? 'pdf' : 'html';
        $mimeType = $format === 'pdf' ? 'application/pdf' : 'text/html';
        $storagePath = $this->annualReturnSignoffPath($workpaper, $format);
        $viewData = ['workpaper' => $workpaper, 'packet' => $workpaper->packet];

        if ($format === 'pdf') {
            TailwindPdf::view('pdf.tax-forms.annual-return-signoff-packet', $viewData)
                ->margin(10)
                ->save($storagePath);
        } else {
            Storage::put($storagePath, view('pdf.tax-forms.annual-return-signoff-packet', $viewData)->render());
        }

        $document = FinancialDocument::updateOrCreate(
            [
                'user_id' => $workpaper->user_id,
                'file_path' => $storagePath,
            ],
            [
                'document_type' => 'tax_return_workpaper',
                'file_name' => "annual-return-signoff-packet-{$workpaper->tax_year}.{$extension}",
                'file_size' => Storage::size($storagePath),
                'mime_type' => $mimeType,
                'processing_status' => 'completed',
                'processing_notes' => 'Generated from deterministic tax return workpaper packet.',
                'extracted_data' => [
                    'tax_year' => $workpaper->tax_year,
                    'status' => $workpaper->status,
                    'readiness_percent' => $workpaper->readiness_percent,
                    'mapped_field_count' => $workpaper->mapped_field_count,
                    'total_field_count' => $workpaper->total_field_count,
                    'packet_hash' => $workpaper->packet_hash,
                    'forms' => collect($workpaper->packet['forms'] ?? [])
                        ->map(fn (array $form): array => [
                            'code' => $form['code'],
                            'title' => $form['title'],
                            'status' => $form['status'],
                            'readiness_percent' => $form['readiness_percent'],
                        ])
                        ->values()
                        ->all(),
                ],
                'extraction_confidence' => 1.0,
                'needs_review' => ! $workpaper->is_approved,
                'reviewed_at' => $workpaper->is_approved ? now() : null,
                'effective_date' => now()->toDateString(),
            ],
        );

        $workpaper->forceFill([
            'signoff_document_id' => $document->id,
            'generated_at' => now(),
            'superseded_at' => null,
        ])->save();

        Log::info('TaxFormGenerator: annual return signoff packet generated', [
            'user_id' => $workpaper->user_id,
            'year' => $workpaper->tax_year,
            'format' => $format,
            'path' => $storagePath,
        ]);

        return $storagePath;
    }

    /**
     * Generate draft annual return outputs for every mapped annual-return form.
     *
     * @return array{generated: int, paths: array<string, string>}
     */
    public function generateAnnualReturnDraftPackage(TaxReturnWorkpaper $workpaper, string $format = 'html'): array
    {
        if (! in_array($format, ['html', 'pdf'], true)) {
            throw new InvalidArgumentException('Annual return drafts must be generated as html or pdf.');
        }

        if (! $workpaper->is_ready_for_signoff) {
            throw new RuntimeException('Annual return workpaper is not ready for draft return generation.');
        }

        $definitions = $this->annualReturnDraftDefinitions();
        $formsByCode = collect($workpaper->packet['forms'] ?? [])->keyBy('code');
        $missingFormCodes = collect(array_keys($definitions))
            ->reject(fn (string $code): bool => $formsByCode->has($code))
            ->values()
            ->all();

        if ($missingFormCodes !== []) {
            throw new RuntimeException('Annual return workpaper is missing mapped forms: '.implode(', ', $missingFormCodes).'.');
        }

        $extension = $format === 'pdf' ? 'pdf' : 'html';
        $mimeType = $format === 'pdf' ? 'application/pdf' : 'text/html';
        $paths = [];

        foreach ($definitions as $formCode => $definition) {
            $form = $formsByCode->get($formCode);
            $storagePath = $this->annualReturnDraftPath($workpaper, $formCode, $format);
            $profile = TaxProfile::query()
                ->where('user_id', $workpaper->user_id)
                ->forYear($workpaper->tax_year)
                ->first();
            $viewData = $this->annualReturnDraftViewData($workpaper, $formCode, $form, $definition, $profile);
            $view = $this->annualReturnDraftView($formCode);

            if ($format === 'pdf') {
                TailwindPdf::view($view, $viewData)
                    ->margin(10)
                    ->save($storagePath);
            } else {
                Storage::put($storagePath, view($view, $viewData)->render());
            }

            FinancialDocument::updateOrCreate(
                [
                    'user_id' => $workpaper->user_id,
                    'file_path' => $storagePath,
                ],
                [
                    'document_type' => 'tax_return',
                    'file_name' => "{$definition['file_stub']}-{$workpaper->tax_year}.{$extension}",
                    'file_size' => Storage::size($storagePath),
                    'mime_type' => $mimeType,
                    'processing_status' => 'completed',
                    'processing_notes' => 'Generated as a line-mapped annual return draft artifact from the current workpaper packet.',
                    'extracted_data' => [
                        'tax_year' => $workpaper->tax_year,
                        'form_code' => $formCode,
                        'form_title' => $definition['inventory_label'],
                        'status' => $form['status'] ?? 'needs_facts',
                        'readiness_percent' => $form['readiness_percent'] ?? 0,
                        'mapped_field_count' => $form['mapped_field_count'] ?? 0,
                        'total_field_count' => $form['total_field_count'] ?? 0,
                        'packet_hash' => $workpaper->packet_hash,
                        'draft' => true,
                    ],
                    'extraction_confidence' => 1.0,
                    'needs_review' => ! $workpaper->is_approved,
                    'reviewed_at' => $workpaper->is_approved ? now() : null,
                    'effective_date' => now()->toDateString(),
                ],
            );

            $paths[$formCode] = $storagePath;
        }

        Log::info('TaxFormGenerator: annual return draft package generated', [
            'user_id' => $workpaper->user_id,
            'year' => $workpaper->tax_year,
            'format' => $format,
            'generated' => count($paths),
        ]);

        return [
            'generated' => count($paths),
            'paths' => $paths,
        ];
    }

    /**
     * Generate draft payroll tax forms for an owner-employee S-corp payroll packet.
     *
     * @param  array<string, mixed>  $annualProjection
     * @return array{generated: int, paths: array<string, string>}
     */
    public function generatePayrollTaxDraftPackage(TaxProfile $profile, array $annualProjection, string $format = 'html'): array
    {
        if (! in_array($format, ['html', 'pdf'], true)) {
            throw new InvalidArgumentException('Payroll filing packets must be generated as html or pdf.');
        }

        $artifactState = $this->getPayrollTaxArtifactState($profile, $annualProjection);

        if (! $artifactState['can_generate_draft_package']) {
            throw new RuntimeException($artifactState['next_action']);
        }

        $payload = $this->payrollTaxDraftPayload($profile, $annualProjection);
        $definitions = $this->payrollTaxDraftDefinitions();
        $extension = $format === 'pdf' ? 'pdf' : 'html';
        $mimeType = $format === 'pdf' ? 'application/pdf' : 'text/html';
        $paths = [];

        foreach ($definitions as $formCode => $definition) {
            $storagePath = $this->payrollTaxDraftPath($profile, $formCode, $format);
            $viewData = [
                'profile' => $profile,
                'definition' => $definition,
                'payload' => $payload,
                'sections' => $this->payrollTaxDraftSections($formCode, $payload),
            ];

            if ($format === 'pdf') {
                TailwindPdf::view('pdf.tax-forms.payroll-tax-draft-form', $viewData)
                    ->margin(10)
                    ->save($storagePath);
            } else {
                Storage::put($storagePath, view('pdf.tax-forms.payroll-tax-draft-form', $viewData)->render());
            }

            FinancialDocument::updateOrCreate(
                [
                    'user_id' => $profile->user_id,
                    'file_path' => $storagePath,
                ],
                [
                    'document_type' => 'payroll_tax_draft',
                    'file_name' => "{$definition['file_stub']}-{$profile->tax_year}.{$extension}",
                    'file_size' => Storage::size($storagePath),
                    'mime_type' => $mimeType,
                    'processing_status' => 'completed',
                    'processing_notes' => 'Generated as a draft payroll filing form from the current salary and tax projection.',
                    'extracted_data' => [
                        'tax_year' => $profile->tax_year,
                        'form_code' => $formCode,
                        'form_title' => $definition['inventory_label'],
                        'draft' => true,
                        'salary' => $payload['salary'],
                        'salary_source' => $payload['salary_source'],
                        'federal_withholding_target' => $payload['federal_withholding_target'],
                        'oregon_withholding_target' => $payload['oregon_withholding_target'],
                    ],
                    'extraction_confidence' => 1.0,
                    'needs_review' => true,
                    'reviewed_at' => null,
                    'effective_date' => now()->toDateString(),
                ],
            );

            $paths[$formCode] = $storagePath;
        }

        Log::info('TaxFormGenerator: payroll tax draft package generated', [
            'user_id' => $profile->user_id,
            'year' => $profile->tax_year,
            'format' => $format,
            'generated' => count($paths),
        ]);

        return [
            'generated' => count($paths),
            'paths' => $paths,
        ];
    }

    /**
     * @return array{
     *     has_current_draft_package: bool,
     *     current_draft_forms_count: int,
     *     required_draft_forms_count: int,
     *     can_generate_draft_package: bool,
     *     next_action: string,
     *     draft_document_ids: array<int, int>,
     * }
     */
    public function emptyPayrollTaxArtifactState(): array
    {
        return [
            'has_current_draft_package' => false,
            'current_draft_forms_count' => 0,
            'required_draft_forms_count' => count($this->payrollTaxDraftDefinitions()),
            'can_generate_draft_package' => false,
            'next_action' => 'Set the entity type, reasonable salary, and live projection before generating payroll filings.',
            'draft_document_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $annualProjection
     * @return array{
     *     has_current_draft_package: bool,
     *     current_draft_forms_count: int,
     *     required_draft_forms_count: int,
     *     can_generate_draft_package: bool,
     *     next_action: string,
     *     draft_document_ids: array<int, int>,
     * }
     */
    public function getPayrollTaxArtifactState(TaxProfile $profile, ?array $annualProjection = null): array
    {
        $requiredDraftFormsCount = count($this->payrollTaxDraftDefinitions());
        $salary = $this->payrollAnnualSalary($profile, $annualProjection ?? []);
        $canGenerateDraftPackage = $profile->entity_type === 's_corp'
            && $salary > 0
            && $annualProjection !== null;

        $draftArtifacts = collect($this->payrollTaxDraftDefinitions())
            ->map(function (array $definition, string $formCode) use ($profile): array {
                return $this->resolveStoredArtifact(
                    userId: $profile->user_id,
                    candidatePaths: [
                        $this->payrollTaxDraftPath($profile, $formCode, 'pdf'),
                        $this->payrollTaxDraftPath($profile, $formCode, 'html'),
                    ],
                );
            })
            ->values();

        $currentDraftDocumentIds = $draftArtifacts
            ->pluck('document')
            ->filter()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $currentDraftFormsCount = $draftArtifacts
            ->filter(fn (array $artifact): bool => $artifact['status'] === 'ready')
            ->count();
        $hasAnyDraftArtifacts = $currentDraftFormsCount > 0;
        $hasCurrentDraftPackage = $canGenerateDraftPackage
            && $currentDraftFormsCount === $requiredDraftFormsCount;

        $nextAction = match (true) {
            $profile->entity_type !== 's_corp' => 'Payroll draft generation is only available when the tax profile is marked as an S-corp.',
            $salary <= 0 && $hasAnyDraftArtifacts => 'Delete the projected payroll drafts and record actual W-2 wages paid before regenerating payroll filings.',
            $salary <= 0 => 'Record actual W-2 wages paid before generating payroll filings. If no wages were paid, do not generate payroll forms from the salary plan alone.',
            $annualProjection === null => 'Refresh the live projection before generating payroll filings.',
            ! $hasCurrentDraftPackage => 'Generate the draft payroll filing packet for W-2, W-3, 941, 940, and Oregon payroll forms.',
            default => 'Draft payroll filings are current for the stored salary and projection.',
        };

        return [
            'has_current_draft_package' => $hasCurrentDraftPackage,
            'current_draft_forms_count' => $currentDraftFormsCount,
            'required_draft_forms_count' => $requiredDraftFormsCount,
            'can_generate_draft_package' => $canGenerateDraftPackage,
            'next_action' => $nextAction,
            'draft_document_ids' => $currentDraftDocumentIds,
        ];
    }

    public function resetPayrollTaxDraftPackage(TaxProfile $profile): int
    {
        $deleted = 0;

        foreach ($this->payrollTaxDraftDefinitions() as $formCode => $definition) {
            foreach (['pdf', 'html'] as $format) {
                $storagePath = $this->payrollTaxDraftPath($profile, $formCode, $format);

                if (Storage::exists($storagePath)) {
                    Storage::delete($storagePath);
                }

                $deleted += FinancialDocument::query()
                    ->where('user_id', $profile->user_id)
                    ->where('file_path', $storagePath)
                    ->delete();
            }
        }

        return $deleted;
    }

    /**
     * @return array{
     *     has_current_signoff_packet: bool,
     *     has_current_draft_package: bool,
     *     current_draft_forms_count: int,
     *     required_draft_forms_count: int,
     *     can_request_owner_approval: bool,
     *     approval_status: string,
     *     approval_status_label: string,
     *     approved_at: string|null,
     *     next_action: string,
     *     signoff_document_id: int|null,
     *     draft_document_ids: array<int, int>,
     * }
     */
    public function emptyAnnualReturnArtifactState(): array
    {
        return [
            'has_current_signoff_packet' => false,
            'has_current_draft_package' => false,
            'current_draft_forms_count' => 0,
            'required_draft_forms_count' => count($this->annualReturnDraftDefinitions()),
            'can_request_owner_approval' => false,
            'approval_status' => 'awaiting_generation',
            'approval_status_label' => 'Awaiting generation',
            'approved_at' => null,
            'next_action' => 'Generate the current signoff packet and draft annual return forms before owner approval.',
            'signoff_document_id' => null,
            'draft_document_ids' => [],
        ];
    }

    /**
     * @return array{
     *     has_current_signoff_packet: bool,
     *     has_current_draft_package: bool,
     *     current_draft_forms_count: int,
     *     required_draft_forms_count: int,
     *     can_request_owner_approval: bool,
     *     approval_status: string,
     *     approval_status_label: string,
     *     approved_at: string|null,
     *     next_action: string,
     *     signoff_document_id: int|null,
     *     draft_document_ids: array<int, int>,
     * }
     */
    public function getAnnualReturnArtifactState(TaxReturnWorkpaper $workpaper): array
    {
        $signoffArtifact = $this->resolveStoredArtifact(
            userId: $workpaper->user_id,
            candidatePaths: [
                $this->annualReturnSignoffPath($workpaper, 'pdf'),
                $this->annualReturnSignoffPath($workpaper, 'html'),
            ],
            currentPacketHash: $workpaper->packet_hash,
        );

        $draftArtifacts = collect($this->annualReturnDraftDefinitions())
            ->map(function (array $definition, string $formCode) use ($workpaper): array {
                return $this->resolveStoredArtifact(
                    userId: $workpaper->user_id,
                    candidatePaths: [
                        $this->annualReturnDraftPath($workpaper, $formCode, 'pdf'),
                        $this->annualReturnDraftPath($workpaper, $formCode, 'html'),
                    ],
                    currentPacketHash: $workpaper->packet_hash,
                );
            })
            ->values();

        $currentDraftDocumentIds = $draftArtifacts
            ->pluck('document')
            ->filter()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $requiredDraftFormsCount = count($this->annualReturnDraftDefinitions());
        $currentDraftFormsCount = $draftArtifacts
            ->filter(fn (array $artifact): bool => $artifact['status'] === 'ready')
            ->count();
        $hasCurrentSignoffPacket = $signoffArtifact['status'] === 'ready';
        $hasCurrentDraftPackage = $currentDraftFormsCount === $requiredDraftFormsCount;
        $ownerApprovalReady = $workpaper->is_ready_for_owner_approval;
        $canRequestOwnerApproval = ! $workpaper->is_approved && $ownerApprovalReady && $hasCurrentSignoffPacket && $hasCurrentDraftPackage;

        $approvalStatus = match (true) {
            $workpaper->is_approved && $ownerApprovalReady => 'approved',
            $canRequestOwnerApproval => 'ready_to_approve',
            ! $ownerApprovalReady => 'needs_mapping',
            default => 'awaiting_generation',
        };

        $approvalStatusLabel = match ($approvalStatus) {
            'approved' => 'Approved',
            'ready_to_approve' => 'Ready to approve',
            'needs_mapping' => 'Needs mapping',
            default => 'Awaiting generation',
        };

        $nextAction = match (true) {
            $workpaper->is_approved && $ownerApprovalReady => 'Owner signoff is frozen on the current packet. Filing and payment actions can proceed against this packet hash.',
            ! $ownerApprovalReady => 'Map the remaining required return fields before owner approval. Current drafts are review copies only.',
            ! $hasCurrentSignoffPacket => 'Generate the current signoff packet for the latest annual return workpaper.',
            ! $hasCurrentDraftPackage => 'Generate the current draft annual return forms so the owner can review the prepared packet.',
            default => 'Owner can approve the current filing packet.',
        };

        return [
            'has_current_signoff_packet' => $hasCurrentSignoffPacket,
            'has_current_draft_package' => $hasCurrentDraftPackage,
            'current_draft_forms_count' => $currentDraftFormsCount,
            'required_draft_forms_count' => $requiredDraftFormsCount,
            'can_request_owner_approval' => $canRequestOwnerApproval,
            'approval_status' => $approvalStatus,
            'approval_status_label' => $approvalStatusLabel,
            'approved_at' => $approvalStatus === 'approved' ? $workpaper->approved_at?->format('M d, Y g:i A') : null,
            'next_action' => $nextAction,
            'signoff_document_id' => $signoffArtifact['document']?->id,
            'draft_document_ids' => $currentDraftDocumentIds,
        ];
    }

    /**
     * Get a summary of all generated forms for a tax year.
     *
     * @return array<int, array{form: string, description: string, path: string, exists: bool, status: string, status_label: string}>
     */
    public function getFormInventory(int $userId, int $year): array
    {
        $basePath = "tax-forms/{$userId}/{$year}";
        $forms = [];
        $currentWorkpaper = TaxReturnWorkpaper::where('user_id', $userId)
            ->where('tax_year', $year)
            ->first();

        $forms[] = $this->inventoryItem(
            label: 'Annual Return Signoff Packet',
            description: 'Source-linked draft return workpaper and owner approval packet',
            userId: $userId,
            candidatePaths: [
                "{$basePath}/annual-return-signoff-packet.pdf",
                "{$basePath}/annual-return-signoff-packet.html",
            ],
            currentPacketHash: $currentWorkpaper?->packet_hash,
        );

        foreach ($this->annualReturnDraftDefinitions() as $formCode => $definition) {
            $forms[] = $this->inventoryItem(
                label: $definition['inventory_label'],
                description: $definition['description'],
                userId: $userId,
                candidatePaths: [
                    "{$basePath}/{$definition['file_stub']}.pdf",
                    "{$basePath}/{$definition['file_stub']}.html",
                ],
                currentPacketHash: $currentWorkpaper?->packet_hash,
            );
        }

        foreach ($this->payrollTaxDraftDefinitions() as $formCode => $definition) {
            $forms[] = $this->inventoryItem(
                label: $definition['inventory_label'],
                description: $definition['description'],
                userId: $userId,
                candidatePaths: [
                    "{$basePath}/{$definition['file_stub']}.pdf",
                    "{$basePath}/{$definition['file_stub']}.html",
                ],
            );
        }

        // Quarterly vouchers
        for ($q = 1; $q <= 4; $q++) {
            $forms[] = $this->inventoryItem(
                label: "1040-ES Q{$q}",
                description: "Federal Estimated Tax Payment Voucher — Quarter {$q}",
                userId: $userId,
                candidatePaths: ["{$basePath}/1040-ES-Q{$q}.pdf"],
            );
            $forms[] = $this->inventoryItem(
                label: "OR-40-V Q{$q}",
                description: "Oregon Estimated Payment Voucher — Quarter {$q}",
                userId: $userId,
                candidatePaths: ["{$basePath}/OR-40-V-Q{$q}.pdf"],
            );
        }

        // 1099-NEC
        $necPaths = collect(Storage::files($basePath))
            ->filter(fn (string $file) => str_contains(basename($file), '1099-NEC'))
            ->values();

        if ($necPaths->isEmpty()) {
            $forms[] = $this->inventoryItem(
                label: '1099-NEC',
                description: 'Nonemployee Compensation forms for contractors',
                userId: $userId,
                candidatePaths: ["{$basePath}/1099-NEC.pdf"],
            );
        } else {
            foreach ($necPaths as $path) {
                $forms[] = $this->inventoryItem(
                    label: basename($path, '.pdf'),
                    description: 'Nonemployee Compensation form for contractor',
                    userId: $userId,
                    candidatePaths: [$path],
                );
            }
        }

        // 1096
        $forms[] = $this->inventoryItem(
            label: '1096',
            description: 'Annual Summary and Transmittal',
            userId: $userId,
            candidatePaths: ["{$basePath}/1096-transmittal.pdf"],
        );

        return $forms;
    }

    /**
     * @return array<string, array{inventory_label: string, description: string, official_form: string, file_stub: string}>
     */
    protected function annualReturnDraftDefinitions(): array
    {
        return [
            '1040' => [
                'inventory_label' => 'Form 1040 Draft Return',
                'description' => 'Line-mapped federal individual return draft from the current workpaper packet',
                'official_form' => 'Form 1040',
                'file_stub' => 'annual-return-draft-1040',
            ],
            '1120s_k1_bridge' => [
                'inventory_label' => '1120-S / K-1 Draft Package',
                'description' => 'Line-mapped S-corp return and shareholder schedule draft from the current workpaper packet',
                'official_form' => 'Form 1120-S / Schedule K-1',
                'file_stub' => 'annual-return-draft-1120s-k1-bridge',
            ],
            'oregon_or40' => [
                'inventory_label' => 'Oregon OR-40 Draft Return',
                'description' => 'Line-mapped Oregon resident return draft from the current workpaper packet',
                'official_form' => 'Form OR-40',
                'file_stub' => 'annual-return-draft-oregon-or40',
            ],
            'local_tax_workpapers' => [
                'inventory_label' => 'Local Tax Draft Workpapers',
                'description' => 'Prepared draft local tax workpapers from the current workpaper packet',
                'official_form' => 'Local tax workpapers',
                'file_stub' => 'annual-return-draft-local-tax-workpapers',
            ],
        ];
    }

    /**
     * @return array<string, array{inventory_label: string, description: string, official_form: string, file_stub: string}>
     */
    protected function payrollTaxDraftDefinitions(): array
    {
        $definitions = [
            'w2' => [
                'inventory_label' => 'Form W-2 Draft',
                'description' => 'Prepared draft W-2 for the owner-employee salary posture',
                'official_form' => 'Form W-2',
                'file_stub' => 'payroll-draft-w2',
            ],
            'w3' => [
                'inventory_label' => 'Form W-3 Draft',
                'description' => 'Prepared draft W-3 transmittal for the owner-employee salary posture',
                'official_form' => 'Form W-3',
                'file_stub' => 'payroll-draft-w3',
            ],
            '940' => [
                'inventory_label' => 'Form 940 Draft',
                'description' => 'Prepared draft annual FUTA return from the owner payroll packet',
                'official_form' => 'Form 940',
                'file_stub' => 'payroll-draft-940',
            ],
            'oregon_orwr' => [
                'inventory_label' => 'Oregon OR-WR Draft',
                'description' => 'Prepared draft Oregon annual withholding reconciliation',
                'official_form' => 'Form OR-WR',
                'file_stub' => 'payroll-draft-oregon-or-wr',
            ],
        ];

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $definitions["941_q{$quarter}"] = [
                'inventory_label' => "Form 941 Q{$quarter} Draft",
                'description' => "Prepared draft quarterly federal payroll return for quarter {$quarter}",
                'official_form' => 'Form 941',
                'file_stub' => "payroll-draft-941-q{$quarter}",
            ];
            $definitions["oregon_oq_q{$quarter}"] = [
                'inventory_label' => "Oregon OQ Q{$quarter} Draft",
                'description' => "Prepared draft Oregon combined quarterly payroll report for quarter {$quarter}",
                'official_form' => 'Oregon Form OQ',
                'file_stub' => "payroll-draft-oregon-oq-q{$quarter}",
            ];
        }

        return $definitions;
    }

    protected function annualReturnSignoffPath(TaxReturnWorkpaper $workpaper, string $format): string
    {
        $extension = $format === 'pdf' ? 'pdf' : 'html';

        return "tax-forms/{$workpaper->user_id}/{$workpaper->tax_year}/annual-return-signoff-packet.{$extension}";
    }

    protected function annualReturnDraftPath(TaxReturnWorkpaper $workpaper, string $formCode, string $format): string
    {
        $definition = $this->annualReturnDraftDefinitions()[$formCode] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unsupported annual return draft form [{$formCode}].");
        }

        $extension = $format === 'pdf' ? 'pdf' : 'html';

        return "tax-forms/{$workpaper->user_id}/{$workpaper->tax_year}/{$definition['file_stub']}.{$extension}";
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $definition
     * @return array<string, mixed>
     */
    protected function annualReturnDraftViewData(
        TaxReturnWorkpaper $workpaper,
        string $formCode,
        array $form,
        array $definition,
        ?TaxProfile $profile,
    ): array {
        return [
            'workpaper' => $workpaper,
            'packet' => $workpaper->packet,
            'form' => $form,
            'definition' => $definition,
            'sections' => $this->annualReturnDraftSections($formCode, $form['fields'] ?? []),
            'profile' => $profile,
        ];
    }

    protected function annualReturnDraftView(string $formCode): string
    {
        return match ($formCode) {
            '1040' => 'pdf.tax-forms.annual-return-drafts.1040',
            '1120s_k1_bridge' => 'pdf.tax-forms.annual-return-drafts.1120s-k1',
            'oregon_or40' => 'pdf.tax-forms.annual-return-drafts.oregon-or40',
            default => 'pdf.tax-forms.annual-return-draft-form',
        };
    }

    protected function payrollTaxDraftPath(TaxProfile $profile, string $formCode, string $format): string
    {
        $definition = $this->payrollTaxDraftDefinitions()[$formCode] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unsupported payroll draft form [{$formCode}].");
        }

        $extension = $format === 'pdf' ? 'pdf' : 'html';

        return "tax-forms/{$profile->user_id}/{$profile->tax_year}/{$definition['file_stub']}.{$extension}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array{title: string, fields: array<int, array<string, mixed>>}>
     */
    protected function annualReturnDraftSections(string $formCode, array $fields): array
    {
        $fieldsByKey = collect($fields)->keyBy('key');
        $sectionBlueprint = match ($formCode) {
            '1040' => [
                ['title' => 'Filing posture', 'field_keys' => ['filing_status']],
                ['title' => 'Income and deductions', 'field_keys' => ['wages', 'pass_through_income', 'adjusted_gross_income', 'qbi_deduction', 'taxable_income']],
                ['title' => 'Personal-return inputs', 'field_keys' => ['mortgage_interest', 'property_taxes', 'charitable_contributions', 'medical_expenses', 'hsa_contributions', 'education_expenses']],
                ['title' => 'Carryforwards and credits', 'field_keys' => ['prior_year_federal_overpayment_credit', 'capital_loss_carryforward', 'net_operating_loss_carryforward']],
                ['title' => 'Tax and payments', 'field_keys' => ['federal_income_tax', 'estimated_tax_payments']],
            ],
            '1120s_k1_bridge' => [
                ['title' => 'Business activity', 'field_keys' => ['gross_receipts', 'business_expenses', 'ordinary_business_income']],
                ['title' => 'Officer and shareholder items', 'field_keys' => ['officer_compensation', 'shareholder_distributions', 'qbi_wage_basis', 'shareholder_basis_carryforward', 'charitable_carryforward']],
            ],
            'oregon_or40' => [
                ['title' => 'Residency and federal bridge', 'field_keys' => ['resident_jurisdiction', 'federal_agi']],
                ['title' => 'Oregon computation', 'field_keys' => ['oregon_taxable_income', 'oregon_income_tax', 'prior_year_oregon_overpayment_credit', 'oregon_estimated_payments']],
            ],
            'local_tax_workpapers' => [
                ['title' => 'Residency posture', 'field_keys' => ['portland_residency']],
                ['title' => 'Local liabilities', 'field_keys' => ['multnomah_pfa_tax', 'portland_arts_tax']],
                ['title' => 'Entity closure posture', 'field_keys' => ['final_return_entities', 'dissolution_closure_support']],
            ],
            default => [
                ['title' => 'Mapped fields', 'field_keys' => array_column($fields, 'key')],
            ],
        };

        return collect($sectionBlueprint)
            ->map(function (array $section) use ($fieldsByKey): array {
                return [
                    'title' => $section['title'],
                    'fields' => collect($section['field_keys'])
                        ->map(fn (string $fieldKey): ?array => $fieldsByKey->get($fieldKey))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $section): bool => $section['fields'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{title: string, fields: array<int, array{label: string, value: string, line: string, note: string|null}>}>
     */
    protected function payrollTaxDraftSections(string $formCode, array $payload): array
    {
        if (str_starts_with($formCode, '941_q')) {
            $quarter = (int) str_replace('941_q', '', $formCode);
            $quarterData = $payload['quarters'][$quarter] ?? [];

            return [
                [
                    'title' => "Quarter {$quarter} payroll tax lines",
                    'fields' => [
                        $this->payrollField('Wages paid this quarter', $quarterData['wages'] ?? 0, 'Form 941, line 2'),
                        $this->payrollField('Federal income tax withholding target', $quarterData['federal_withholding'] ?? 0, 'Form 941, line 3', 'Recommended withholding target based on the live annual projection.'),
                        $this->payrollField('Employee Social Security tax', $quarterData['employee_ss'] ?? 0, 'Form 941, line 5a'),
                        $this->payrollField('Employer Social Security tax', $quarterData['employer_ss'] ?? 0, 'Form 941, line 5a'),
                        $this->payrollField('Employee Medicare tax', $quarterData['employee_medicare'] ?? 0, 'Form 941, line 5c'),
                        $this->payrollField('Employer Medicare tax', $quarterData['employer_medicare'] ?? 0, 'Form 941, line 5c'),
                        $this->payrollField('Additional Medicare tax withheld', $quarterData['additional_medicare'] ?? 0, 'Form 941, line 5d'),
                        $this->payrollField('Total quarterly 941 tax', $quarterData['total_941_tax'] ?? 0, 'Form 941, line 12'),
                        $this->payrollField('Quarter due date', $quarterData['941_due_date'] ?? 'Unknown', 'Return due date'),
                    ],
                ],
            ];
        }

        if (str_starts_with($formCode, 'oregon_oq_q')) {
            $quarter = (int) str_replace('oregon_oq_q', '', $formCode);
            $quarterData = $payload['quarters'][$quarter] ?? [];

            return [
                [
                    'title' => "Quarter {$quarter} Oregon payroll lines",
                    'fields' => [
                        $this->payrollField('Subject wages this quarter', $quarterData['wages'] ?? 0, 'Oregon OQ subject wages'),
                        $this->payrollField('Oregon withholding target', $quarterData['oregon_withholding'] ?? 0, 'Oregon OQ withholding', 'Recommended withholding target based on the projected Oregon liability.'),
                        $this->payrollField('Statewide transit tax', $quarterData['oregon_statewide_transit_tax'] ?? 0, 'Oregon OQ statewide transit tax'),
                        $this->payrollField('OQ due date', $quarterData['oregon_oq_due_date'] ?? 'Unknown', 'Return due date'),
                        $this->payrollField('Employer UI rate', $payload['oregon_ui_rate_note'] ?? 'Manual entry required', 'Oregon unemployment insurance rate notice', 'The employer unemployment rate is account-specific and should be loaded from the Oregon notice before filing.'),
                    ],
                ],
            ];
        }

        return match ($formCode) {
            'w2' => [[
                'title' => 'Employee wage statement',
                'fields' => [
                    $this->payrollField('Employee / owner', $payload['employee_name'], 'Form W-2 employee'),
                    $this->payrollField('Annual wages', $payload['salary'], 'Form W-2 box 1'),
                    $this->payrollField('Federal income tax withheld target', $payload['federal_withholding_target'], 'Form W-2 box 2', 'Recommended withholding target based on the live annual projection.'),
                    $this->payrollField('Social Security wages', $payload['social_security_wages'], 'Form W-2 box 3'),
                    $this->payrollField('Social Security tax withheld', $payload['employee_social_security_tax'], 'Form W-2 box 4'),
                    $this->payrollField('Medicare wages', $payload['salary'], 'Form W-2 box 5'),
                    $this->payrollField('Medicare tax withheld', $payload['employee_medicare_tax'] + $payload['additional_medicare_tax'], 'Form W-2 box 6'),
                    $this->payrollField('Oregon wages', $payload['salary'], 'Form W-2 state wages'),
                    $this->payrollField('Oregon income tax withheld target', $payload['oregon_withholding_target'], 'Form W-2 state withholding'),
                ],
            ]],
            'w3' => [[
                'title' => 'Annual transmittal totals',
                'fields' => [
                    $this->payrollField('Total wages', $payload['salary'], 'Form W-3 box 1'),
                    $this->payrollField('Federal withholding target', $payload['federal_withholding_target'], 'Form W-3 box 2'),
                    $this->payrollField('Social Security wages', $payload['social_security_wages'], 'Form W-3 box 3'),
                    $this->payrollField('Social Security tax', $payload['employee_social_security_tax'], 'Form W-3 box 4'),
                    $this->payrollField('Medicare wages and tips', $payload['salary'], 'Form W-3 box 5'),
                    $this->payrollField('Medicare tax', $payload['employee_medicare_tax'] + $payload['additional_medicare_tax'], 'Form W-3 box 6'),
                    $this->payrollField('Employer EIN', $payload['employer_ein_label'], 'Form W-3 EIN'),
                    $this->payrollField('Employer name', $payload['employer_name'], 'Form W-3 employer'),
                ],
            ]],
            '940' => [[
                'title' => 'Annual FUTA computation',
                'fields' => [
                    $this->payrollField('Total payments to employees', $payload['salary'], 'Form 940, line 3'),
                    $this->payrollField('FUTA taxable wages', $payload['futa_taxable_wages'], 'Form 940, line 7'),
                    $this->payrollField('Net FUTA tax', $payload['futa_tax'], 'Form 940, line 12', 'Assumes the standard 5.4% state credit and timely state unemployment filings.'),
                    $this->payrollField('Due date', $payload['annual_due_date'], 'Return due date'),
                ],
            ]],
            'oregon_orwr' => [[
                'title' => 'Annual Oregon withholding reconciliation',
                'fields' => [
                    $this->payrollField('Annual Oregon wages', $payload['salary'], 'Form OR-WR wages'),
                    $this->payrollField('Annual Oregon withholding target', $payload['oregon_withholding_target'], 'Form OR-WR withholding', 'Recommended withholding target based on the projected Oregon liability.'),
                    $this->payrollField('Annual statewide transit tax', $payload['oregon_statewide_transit_tax'], 'Form OR-WR statewide transit'),
                    $this->payrollField('Due date', $payload['annual_due_date'], 'Return due date'),
                ],
            ]],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $annualProjection
     * @return array<string, mixed>
     */
    protected function payrollTaxDraftPayload(TaxProfile $profile, array $annualProjection): array
    {
        $taxComputation = $annualProjection['tax_computation'] ?? [];
        $salary = $this->payrollAnnualSalary($profile, $annualProjection);
        $filingStatus = (string) ($profile->filing_status ?? 'single');
        $year = (int) $profile->tax_year;
        $fica = TaxBracketEngine::calculateSCorpFica($salary, $year, $filingStatus);
        $federalWithholdingTarget = max(0, round((float) ($taxComputation['federal_tax'] ?? 0) - (float) data_get($annualProjection, 'payments_made.federal', 0), 2));
        $oregonWithholdingTarget = max(0, round((float) ($taxComputation['oregon_tax'] ?? 0) - (float) data_get($annualProjection, 'payments_made.state_or', 0), 2));
        $oregonStatewideTransitTax = round($salary * 0.001, 2);
        $quarters = $this->payrollQuarterBreakdown(
            salary: $salary,
            federalWithholdingTarget: $federalWithholdingTarget,
            oregonWithholdingTarget: $oregonWithholdingTarget,
            oregonStatewideTransitTax: $oregonStatewideTransitTax,
            year: $year,
            filingStatus: $filingStatus,
        );

        return [
            'year' => $year,
            'employee_name' => 'Owner employee',
            'employer_name' => (string) ($profile->entity_name ?: config('app.name')),
            'employer_ein_label' => $profile->entity_ein_encrypted ? 'Stored in tax profile' : 'Not stored in tax profile',
            'employer_address' => $this->payrollEmployerAddress($profile),
            'salary' => round($salary, 2),
            'salary_source' => $salary === (float) ($profile->w2_wages_paid ?? 0) && $salary > 0
                ? 'Stored W-2 wages paid'
                : ($salary === (float) ($profile->reasonable_salary ?? 0) && $salary > 0
                    ? 'Reasonable salary setting'
                    : 'Live annual projection salary'),
            'federal_withholding_target' => $federalWithholdingTarget,
            'oregon_withholding_target' => $oregonWithholdingTarget,
            'social_security_wages' => min($salary, TaxBracketEngine::socialSecurityWageBase($year)),
            'employee_social_security_tax' => (float) $fica['employee_ss'],
            'employee_medicare_tax' => (float) $fica['employee_medicare'],
            'additional_medicare_tax' => (float) $fica['additional_medicare'],
            'employer_social_security_tax' => (float) $fica['employer_ss'],
            'employer_medicare_tax' => (float) $fica['employer_medicare'],
            'oregon_statewide_transit_tax' => $oregonStatewideTransitTax,
            'futa_taxable_wages' => min($salary, 7000),
            'futa_tax' => round(min($salary, 7000) * 0.006, 2),
            'annual_due_date' => 'January 31, '.($year + 1),
            'oregon_ui_rate_note' => 'Load the Oregon employer unemployment rate notice before filing.',
            'quarters' => $quarters,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function payrollQuarterBreakdown(
        float $salary,
        float $federalWithholdingTarget,
        float $oregonWithholdingTarget,
        float $oregonStatewideTransitTax,
        int $year,
        string $filingStatus,
    ): array {
        $quarterlyWages = round($salary / 4, 2);
        $quarterlyFederalWithholding = round($federalWithholdingTarget / 4, 2);
        $quarterlyOregonWithholding = round($oregonWithholdingTarget / 4, 2);
        $quarterlyTransitTax = round($oregonStatewideTransitTax / 4, 2);
        $socialSecurityWageBase = TaxBracketEngine::socialSecurityWageBase($year);
        $medicareThreshold = match ($filingStatus) {
            'mfj', 'qw' => 250000.0,
            'mfs' => 125000.0,
            default => 200000.0,
        };
        $quarters = [];

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $priorYearToDateWages = $quarterlyWages * ($quarter - 1);
            $yearToDateWages = $quarterlyWages * $quarter;
            $socialSecurityTaxableWages = max(
                min($socialSecurityWageBase, $yearToDateWages) - min($socialSecurityWageBase, $priorYearToDateWages),
                0
            );
            $employeeSocialSecurityTax = round($socialSecurityTaxableWages * 0.062, 2);
            $employerSocialSecurityTax = round($socialSecurityTaxableWages * 0.062, 2);
            $employeeMedicareTax = round($quarterlyWages * 0.0145, 2);
            $employerMedicareTax = round($quarterlyWages * 0.0145, 2);
            $additionalMedicareTax = round(
                max($yearToDateWages - $medicareThreshold, 0) - max($priorYearToDateWages - $medicareThreshold, 0),
                2,
            ) * 0.009;

            $quarters[$quarter] = [
                'quarter' => $quarter,
                'wages' => $quarterlyWages,
                'federal_withholding' => $quarter === 4
                    ? round($federalWithholdingTarget - ($quarterlyFederalWithholding * 3), 2)
                    : $quarterlyFederalWithholding,
                'oregon_withholding' => $quarter === 4
                    ? round($oregonWithholdingTarget - ($quarterlyOregonWithholding * 3), 2)
                    : $quarterlyOregonWithholding,
                'employee_ss' => $employeeSocialSecurityTax,
                'employer_ss' => $employerSocialSecurityTax,
                'employee_medicare' => $employeeMedicareTax,
                'employer_medicare' => $employerMedicareTax,
                'additional_medicare' => round($additionalMedicareTax, 2),
                'total_941_tax' => round(
                    ($quarter === 4
                        ? round($federalWithholdingTarget - ($quarterlyFederalWithholding * 3), 2)
                        : $quarterlyFederalWithholding)
                    + $employeeSocialSecurityTax
                    + $employerSocialSecurityTax
                    + $employeeMedicareTax
                    + $employerMedicareTax
                    + round($additionalMedicareTax, 2),
                    2
                ),
                'oregon_statewide_transit_tax' => $quarter === 4
                    ? round($oregonStatewideTransitTax - ($quarterlyTransitTax * 3), 2)
                    : $quarterlyTransitTax,
                '941_due_date' => $this->quarterlyPayrollDueDate($quarter, $year),
                'oregon_oq_due_date' => $this->quarterlyPayrollDueDate($quarter, $year),
            ];
        }

        return $quarters;
    }

    /**
     * @param  array<string, mixed>  $annualProjection
     */
    protected function payrollAnnualSalary(TaxProfile $profile, array $annualProjection): float
    {
        return round((float) ($profile->w2_wages_paid ?? 0), 2);
    }

    protected function payrollEmployerAddress(TaxProfile $profile): string
    {
        $address = array_filter([
            $profile->entity_address ?: $profile->address,
            trim(implode(', ', array_filter([
                $profile->city,
                $profile->state,
                $profile->zip,
            ]))),
        ]);

        return $address === [] ? 'Complete employer address in the tax profile before filing.' : implode("\n", $address);
    }

    /**
     * @return array{label: string, value: string, line: string, note: string|null}
     */
    protected function payrollField(string $label, mixed $value, string $line, ?string $note = null): array
    {
        return [
            'label' => $label,
            'value' => is_numeric($value) ? '$'.number_format((float) $value, 2) : (string) $value,
            'line' => $line,
            'note' => $note,
        ];
    }

    protected function quarterlyPayrollDueDate(int $quarter, int $year): string
    {
        return match ($quarter) {
            1 => "April 30, {$year}",
            2 => "July 31, {$year}",
            3 => "October 31, {$year}",
            default => 'January 31, '.($year + 1),
        };
    }

    /**
     * @param  array<int, string>  $candidatePaths
     * @return array{path: string, exists: bool, status: string, status_label: string, document: FinancialDocument|null}
     */
    protected function resolveStoredArtifact(int $userId, array $candidatePaths, ?string $currentPacketHash = null): array
    {
        foreach ($candidatePaths as $candidatePath) {
            if (! Storage::exists($candidatePath)) {
                continue;
            }

            $document = FinancialDocument::where('user_id', $userId)
                ->where('file_path', $candidatePath)
                ->first();
            $documentPacketHash = $document?->extracted_data['packet_hash'] ?? null;
            $isCurrent = $currentPacketHash === null
                || $documentPacketHash === null
                || $documentPacketHash === $currentPacketHash;

            return [
                'path' => $candidatePath,
                'exists' => true,
                'status' => $isCurrent ? 'ready' : 'stale',
                'status_label' => $isCurrent ? 'Current' : 'Stale',
                'document' => $document,
            ];
        }

        return [
            'path' => $candidatePaths[array_key_last($candidatePaths)],
            'exists' => false,
            'status' => 'missing',
            'status_label' => 'Not generated',
            'document' => null,
        ];
    }

    /**
     * @param  array<int, string>  $candidatePaths
     * @return array{form: string, description: string, path: string, exists: bool, status: string, status_label: string}
     */
    protected function inventoryItem(
        string $label,
        string $description,
        int $userId,
        array $candidatePaths,
        ?string $currentPacketHash = null,
    ): array {
        $artifact = $this->resolveStoredArtifact($userId, $candidatePaths, $currentPacketHash);

        return [
            'form' => $label,
            'description' => $description,
            'path' => $artifact['path'],
            'exists' => $artifact['exists'],
            'status' => $artifact['status'],
            'status_label' => $artifact['status_label'],
        ];
    }

    // ──────────────────────────────────────────
    // Payment Instructions
    // ──────────────────────────────────────────

    protected function getFederalPaymentInstructions(float $amount, int $quarter, int $year): array
    {
        return [
            'online' => [
                'method' => 'EFTPS (Electronic Federal Tax Payment System)',
                'url' => 'https://www.eftps.gov',
                'steps' => [
                    'Log in to eftps.gov',
                    'Select "Make a Payment"',
                    'Tax Form: 1040-ES',
                    "Tax Period: Q{$quarter} {$year}",
                    'Amount: $'.number_format($amount, 2),
                    'Submit payment',
                ],
            ],
            'alternative' => [
                'method' => 'IRS Direct Pay',
                'url' => 'https://www.irs.gov/payments/direct-pay',
                'steps' => [
                    'Go to irs.gov/payments/direct-pay',
                    'Select "Estimated Tax" as reason',
                    "Tax Period: {$year}",
                    'Amount: $'.number_format($amount, 2),
                    'Enter bank account information',
                ],
            ],
            'mail' => [
                'method' => 'Mail with voucher',
                'address' => "Internal Revenue Service\nP.O. Box 802501\nCincinnati, OH 45280-2501",
                'note' => 'Make check payable to "United States Treasury"',
            ],
        ];
    }

    protected function getOregonPaymentInstructions(float $amount, int $quarter, int $year): array
    {
        return [
            'online' => [
                'method' => 'Oregon Revenue Online',
                'url' => 'https://revenueonline.dor.oregon.gov',
                'steps' => [
                    'Log in to Revenue Online',
                    'Select "Make a Payment"',
                    'Payment type: Estimated Income Tax',
                    "Tax Year: {$year}, Quarter: Q{$quarter}",
                    'Amount: $'.number_format($amount, 2),
                ],
            ],
            'mail' => [
                'method' => 'Mail with voucher',
                'address' => "Oregon Department of Revenue\nPO Box 14950\nSalem, OR 97309-0950",
                'note' => 'Make check payable to "Oregon Department of Revenue"',
            ],
        ];
    }

    protected function getQuarterlyDueDate(int $quarter, int $year): string
    {
        $dates = [
            1 => "{$year}-04-15",
            2 => "{$year}-06-15",
            3 => "{$year}-09-15",
            4 => ($year + 1).'-01-15',
        ];

        $date = \Carbon\Carbon::parse($dates[$quarter] ?? "{$year}-04-15");

        // Adjust for weekends
        if ($date->isWeekend()) {
            $date = $date->nextWeekday();
        }

        return $date->format('F j, Y');
    }
}
