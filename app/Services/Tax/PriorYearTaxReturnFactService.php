<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use App\Models\TaxEntityLifecycleDecision;
use App\Models\TaxProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PriorYearTaxReturnFactService
{
    /**
     * @return array{
     *     is_available: bool,
     *     coverage_status: string,
     *     next_action: string,
     *     latest_required_year: int,
     *     latest_return_year: int|null,
     *     latest_personal_return_year: int|null,
     *     latest_business_return_year: int|null,
     *     return_packet_count: int,
     *     extracted_return_count: int,
     *     documents_missing_fact_extraction: int,
     *     years_available: array<int, int>,
     *     missing_years: array<int, int>,
     *     form_types: array<int, string>,
     *     business_entities: array<int, array<string, mixed>>,
     *     all_entity_decisions_recorded: bool,
     *     final_return_entities: array<int, string>,
     *     dissolution_entities: array<int, string>,
     *     entity_lifecycle_status: string,
     *     entity_lifecycle_next_action: string,
     *     entity_lifecycle_questions: array<int, string>,
     *     entity_lifecycle_document_requests: array<int, string>,
     *     document_requests: array<int, string>,
     *     questions_needed: array<int, string>,
     *     facts: array<string, array<string, mixed>>,
     * }
     */
    public function summarize(int $userId, int $filingYear): array
    {
        $latestRequiredYear = $filingYear - 1;
        $taxProfile = TaxProfile::query()
            ->where('user_id', $userId)
            ->forYear($filingYear)
            ->first();
        $records = FinancialDocument::query()
            ->where('user_id', $userId)
            ->where('document_type', 'tax_return')
            ->latest('id')
            ->get()
            ->reject(fn (FinancialDocument $document): bool => $this->shouldIgnoreDocument($document))
            ->map(fn (FinancialDocument $document): ?array => $this->normalizeDocument($document, $filingYear))
            ->filter()
            ->sortByDesc('tax_year')
            ->values();

        $latestPersonalReturn = $records
            ->first(fn (array $record): bool => in_array($record['category'], ['personal', 'mixed'], true));
        $latestBusinessReturn = $records
            ->first(fn (array $record): bool => in_array($record['category'], ['business', 'mixed'], true));
        $latestRequiredReturn = $records
            ->first(fn (array $record): bool => $record['tax_year'] === $latestRequiredYear);
        $lifecycleDecisions = $this->lifecycleDecisions($userId, $filingYear);
        $closureRecordEntities = $this->closureRecordEntities($userId, $filingYear);
        $businessEntities = $this->businessEntities($records, $lifecycleDecisions, $closureRecordEntities);
        $entityLifecycleQuestions = $this->entityLifecycleQuestions($businessEntities, $filingYear);
        $entityLifecycleDocumentRequests = $this->entityLifecycleDocumentRequests($businessEntities, $filingYear);
        $finalReturnEntities = collect($businessEntities)
            ->filter(fn (array $entity): bool => (bool) ($entity['requires_final_return'] ?? false))
            ->pluck('name')
            ->values()
            ->all();
        $dissolutionEntities = collect($businessEntities)
            ->filter(fn (array $entity): bool => (bool) ($entity['requires_dissolution'] ?? false))
            ->pluck('name')
            ->values()
            ->all();

        $facts = $this->buildFacts($latestPersonalReturn, $latestBusinessReturn, $taxProfile, $latestRequiredYear);
        $extractedReturnCount = $records
            ->filter(fn (array $record): bool => $record['has_meaningful_facts'])
            ->count();
        $documentsMissingFactExtraction = $records
            ->filter(fn (array $record): bool => ! $record['has_meaningful_facts'])
            ->count();
        $yearsAvailable = $records
            ->pluck('tax_year')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $missingYears = in_array($latestRequiredYear, $yearsAvailable, true) ? [] : [$latestRequiredYear];
        $questionsNeeded = array_values(array_merge(
            $this->questionsNeeded($facts, $latestPersonalReturn, $latestBusinessReturn),
            $entityLifecycleQuestions,
        ));
        $coverageStatus = $this->coverageStatus(
            $records,
            $latestRequiredReturn,
            $extractedReturnCount,
            $documentsMissingFactExtraction,
            $questionsNeeded === [],
        );
        $documentRequests = $this->documentRequests(
            $coverageStatus,
            $latestRequiredYear,
            $documentsMissingFactExtraction,
            $latestPersonalReturn,
            $latestBusinessReturn,
            $entityLifecycleDocumentRequests,
            $questionsNeeded === [],
        );
        $allEntityDecisionsRecorded = count($businessEntities) <= 1
            || collect($businessEntities)->every(fn (array $entity): bool => ($entity['decision'] ?? null) !== null);

        return [
            'is_available' => $records->isNotEmpty(),
            'coverage_status' => $coverageStatus,
            'next_action' => $this->nextAction($coverageStatus, $latestRequiredYear, $documentsMissingFactExtraction),
            'latest_required_year' => $latestRequiredYear,
            'latest_return_year' => $records->max('tax_year'),
            'latest_personal_return_year' => $latestPersonalReturn['tax_year'] ?? null,
            'latest_business_return_year' => $latestBusinessReturn['tax_year'] ?? null,
            'return_packet_count' => $records->count(),
            'extracted_return_count' => $extractedReturnCount,
            'documents_missing_fact_extraction' => $documentsMissingFactExtraction,
            'years_available' => $yearsAvailable,
            'missing_years' => $missingYears,
            'form_types' => $records
                ->flatMap(fn (array $record): array => $record['form_types'])
                ->unique()
                ->values()
                ->all(),
            'business_entities' => $businessEntities,
            'all_entity_decisions_recorded' => $allEntityDecisionsRecorded,
            'final_return_entities' => $finalReturnEntities,
            'dissolution_entities' => $dissolutionEntities,
            'entity_lifecycle_status' => $allEntityDecisionsRecorded ? 'passed' : 'needs_review',
            'entity_lifecycle_next_action' => $this->entityLifecycleNextAction($businessEntities, $filingYear),
            'entity_lifecycle_questions' => $entityLifecycleQuestions,
            'entity_lifecycle_document_requests' => $entityLifecycleDocumentRequests,
            'document_requests' => $documentRequests,
            'questions_needed' => $questionsNeeded,
            'facts' => $facts,
        ];
    }

    protected function shouldIgnoreDocument(FinancialDocument $document): bool
    {
        return $document->document_type === 'tax_return'
            && data_get($document->extracted_data, 'draft') === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalizeDocument(FinancialDocument $document, int $filingYear): ?array
    {
        $taxYear = $this->documentTaxYear($document);

        if ($taxYear === null || $taxYear >= $filingYear) {
            return null;
        }

        $extractedData = is_array($document->extracted_data) ? $document->extracted_data : [];
        $fields = is_array($extractedData['extracted_fields'] ?? null) ? $extractedData['extracted_fields'] : [];
        $formTypes = $this->normalizeFormTypes($document, $extractedData, $fields);
        $category = $this->documentCategory($formTypes, $document, $extractedData, $fields);
        $facts = [
            'filing_status' => $this->stringField($extractedData, $fields, ['filing_status']),
            'adjusted_gross_income' => $this->numericField($extractedData, $fields, ['adjusted_gross_income', 'agi', 'federal_agi']),
            'federal_taxable_income' => $this->numericField($extractedData, $fields, ['federal_taxable_income', 'taxable_income']),
            'federal_income_tax' => $this->numericField($extractedData, $fields, ['federal_income_tax', 'federal_tax', 'total_tax']),
            'oregon_taxable_income' => $this->numericField($extractedData, $fields, ['oregon_taxable_income']),
            'oregon_income_tax' => $this->numericField($extractedData, $fields, ['oregon_income_tax', 'oregon_tax']),
            'wages' => $this->numericField($extractedData, $fields, ['wages', 'salary', 'w2_wages']),
            'officer_compensation' => $this->numericField($extractedData, $fields, ['officer_compensation', 'reasonable_compensation']),
            'pass_through_income' => $this->numericField($extractedData, $fields, ['pass_through_income', 'ordinary_business_income', 'k1_income', 'shareholder_income']),
            'qbi_deduction' => $this->numericField($extractedData, $fields, ['qbi_deduction']),
            'shareholder_basis' => $this->numericField($extractedData, $fields, ['shareholder_basis', 'ending_shareholder_basis', 'basis']),
            'shareholder_distributions' => $this->numericField($extractedData, $fields, ['shareholder_distributions', 'distributions']),
            'federal_estimated_payments' => $this->numericField($extractedData, $fields, ['federal_estimated_payments', 'estimated_payments_federal']),
            'oregon_estimated_payments' => $this->numericField($extractedData, $fields, ['oregon_estimated_payments', 'estimated_payments_oregon']),
            'federal_overpayment_applied' => $this->numericField($extractedData, $fields, ['federal_overpayment_applied', 'overpayment_applied_to_next_year_federal', 'federal_overpayment_credit']),
            'oregon_overpayment_applied' => $this->numericField($extractedData, $fields, ['oregon_overpayment_applied', 'overpayment_applied_to_next_year_oregon', 'oregon_overpayment_credit']),
            'capital_loss_carryforward' => $this->numericField($extractedData, $fields, ['capital_loss_carryforward']),
            'nol_carryforward' => $this->numericField($extractedData, $fields, ['nol_carryforward', 'net_operating_loss_carryforward']),
            'charitable_carryforward' => $this->numericField($extractedData, $fields, ['charitable_carryforward']),
        ];

        return [
            'document_id' => $document->id,
            'file_name' => $document->file_name,
            'tax_year' => $taxYear,
            'category' => $category,
            'entity_name' => $this->entityName($document, $category, $extractedData, $fields),
            'form_types' => $formTypes,
            'facts' => $facts,
            'has_meaningful_facts' => collect($facts)->contains(fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $latestPersonalReturn
     * @param  array<string, mixed>|null  $latestBusinessReturn
     * @return array<string, array<string, mixed>>
     */
    protected function buildFacts(?array $latestPersonalReturn, ?array $latestBusinessReturn, ?TaxProfile $taxProfile, int $latestRequiredYear): array
    {
        return [
            'latest_personal_filing_status' => $this->factRecord('Latest personal return filing status', $latestPersonalReturn, 'filing_status'),
            'latest_personal_agi' => $this->factRecord('Latest personal return AGI', $latestPersonalReturn, 'adjusted_gross_income'),
            'latest_personal_federal_taxable_income' => $this->factRecord('Latest personal return taxable income', $latestPersonalReturn, 'federal_taxable_income'),
            'latest_personal_federal_tax' => $this->factRecord('Latest personal return federal tax', $latestPersonalReturn, 'federal_income_tax'),
            'latest_personal_oregon_tax' => $this->factRecord('Latest personal return Oregon tax', $latestPersonalReturn, 'oregon_income_tax'),
            'latest_personal_qbi_deduction' => $this->factRecord('Latest personal return QBI deduction', $latestPersonalReturn, 'qbi_deduction'),
            'latest_personal_wages' => $this->factRecord('Latest personal return wages', $latestPersonalReturn, 'wages'),
            'latest_business_officer_compensation' => $this->factRecord('Latest business return officer compensation', $latestBusinessReturn, 'officer_compensation'),
            'latest_business_ordinary_income' => $this->factRecord('Latest business return ordinary income', $latestBusinessReturn, 'pass_through_income'),
            'latest_business_shareholder_basis' => $this->manualOverrideFactRecord(
                label: 'Latest business return shareholder basis',
                record: $latestBusinessReturn,
                fieldKey: 'shareholder_basis',
                overrideValue: $taxProfile?->prior_year_shareholder_basis,
                overrideTaxYear: $latestRequiredYear,
                overrideSourceLabel: 'Manual prior-year fact',
            ),
            'latest_business_distributions' => $this->factRecord('Latest business return distributions', $latestBusinessReturn, 'shareholder_distributions'),
            'federal_overpayment_applied' => $this->manualOverrideFactRecord(
                label: 'Federal overpayment applied forward',
                record: $latestPersonalReturn,
                fieldKey: 'federal_overpayment_applied',
                overrideValue: $taxProfile?->prior_year_federal_overpayment_applied,
                overrideTaxYear: $latestRequiredYear,
                overrideSourceLabel: 'Manual prior-year fact',
            ),
            'oregon_overpayment_applied' => $this->manualOverrideFactRecord(
                label: 'Oregon overpayment applied forward',
                record: $latestPersonalReturn,
                fieldKey: 'oregon_overpayment_applied',
                overrideValue: $taxProfile?->prior_year_oregon_overpayment_applied,
                overrideTaxYear: $latestRequiredYear,
                overrideSourceLabel: 'Manual prior-year fact',
            ),
            'capital_loss_carryforward' => $this->manualOverrideFactRecord(
                label: 'Capital loss carryforward',
                record: $latestPersonalReturn,
                fieldKey: 'capital_loss_carryforward',
                overrideValue: $taxProfile?->prior_year_capital_loss_carryforward,
                overrideTaxYear: $latestRequiredYear,
                overrideSourceLabel: 'Manual prior-year fact',
            ),
            'nol_carryforward' => $this->manualOverrideFactRecord(
                label: 'Net operating loss carryforward',
                record: $latestPersonalReturn,
                fieldKey: 'nol_carryforward',
                overrideValue: $taxProfile?->prior_year_nol_carryforward,
                overrideTaxYear: $latestRequiredYear,
                overrideSourceLabel: 'Manual prior-year fact',
            ),
            'charitable_carryforward' => $this->factRecord('Charitable carryforward', $latestBusinessReturn, 'charitable_carryforward'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array<string, mixed>
     */
    protected function factRecord(string $label, ?array $record, string $fieldKey): array
    {
        $value = data_get($record, "facts.{$fieldKey}");

        return [
            'label' => $label,
            'value' => $value,
            'display_value' => $this->displayValue($value),
            'tax_year' => $record['tax_year'] ?? null,
            'source' => $record ? "{$record['tax_year']} {$record['file_name']}" : null,
            'status' => $value !== null && $value !== '' ? 'ready' : 'missing',
        ];
    }

    protected function manualOverrideFactRecord(
        string $label,
        ?array $record,
        string $fieldKey,
        mixed $overrideValue,
        int $overrideTaxYear,
        string $overrideSourceLabel,
    ): array {
        if ($overrideValue !== null && $overrideValue !== '') {
            return [
                'label' => $label,
                'value' => round((float) $overrideValue, 2),
                'display_value' => $this->displayValue((float) $overrideValue),
                'tax_year' => $overrideTaxYear,
                'source' => "{$overrideTaxYear} {$overrideSourceLabel}",
                'status' => 'ready',
            ];
        }

        return $this->factRecord($label, $record, $fieldKey);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  array<string, mixed>|null  $latestRequiredReturn
     */
    protected function coverageStatus(
        Collection $records,
        ?array $latestRequiredReturn,
        int $extractedReturnCount,
        int $documentsMissingFactExtraction,
        bool $requiredFactsReady,
    ): string {
        if ($records->isEmpty()) {
            return 'missing';
        }

        if ($latestRequiredReturn === null) {
            return 'partial';
        }

        if ($extractedReturnCount === 0) {
            return 'partial';
        }

        if ($documentsMissingFactExtraction > 0 && ! $requiredFactsReady) {
            return 'partial';
        }

        return 'current';
    }

    /**
     * @param  array<string, mixed>|null  $latestPersonalReturn
     * @param  array<string, mixed>|null  $latestBusinessReturn
     * @return array<int, string>
     */
    protected function documentRequests(
        string $coverageStatus,
        int $latestRequiredYear,
        int $documentsMissingFactExtraction,
        ?array $latestPersonalReturn,
        ?array $latestBusinessReturn,
        array $entityLifecycleDocumentRequests,
        bool $requiredFactsReady,
    ): array {
        $requests = [];

        if ($coverageStatus === 'missing') {
            return [
                "Upload the {$latestRequiredYear} federal and Oregon return packets first",
                "If available, also upload {$latestRequiredYear} S-corp or partnership return packets with K-1 support",
            ];
        }

        if ($latestPersonalReturn === null) {
            $requests[] = "Upload the {$latestRequiredYear} personal return packet";
        }

        if ($latestBusinessReturn === null) {
            $requests[] = "Upload the {$latestRequiredYear} business return packet if a pass-through entity filed one";
        }

        if ($documentsMissingFactExtraction > 0 && ! $requiredFactsReady) {
            $requests[] = 'Review or re-upload prior-year return packets so AGI, tax, carryovers, overpayment credits, and basis can be extracted cleanly';
        }

        return array_values(array_unique(array_merge($requests, $entityLifecycleDocumentRequests)));
    }

    protected function nextAction(string $coverageStatus, int $latestRequiredYear, int $documentsMissingFactExtraction): string
    {
        return match ($coverageStatus) {
            'missing' => "Upload the {$latestRequiredYear} return packets so carryovers, overpayment credits, and basis can feed the current filing year.",
            'partial' => $documentsMissingFactExtraction > 0
                ? 'Prior-year return packets are on file, but the useful tax facts have not been extracted cleanly yet.'
                : "Prior-year return context is incomplete. The {$latestRequiredYear} return packet is the critical missing year.",
            default => 'Prior-year return facts are available for carryover, overpayment, basis, and consistency checks.',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function businessEntities(Collection $records, Collection $lifecycleDecisions, array $closureRecordEntities): array
    {
        return $records
            ->filter(fn (array $record): bool => in_array($record['category'], ['business', 'mixed'], true))
            ->groupBy(fn (array $record): string => $this->entityKey((string) ($record['entity_name'] ?: $record['file_name'])))
            ->map(function (Collection $group, string $entityKey) use ($lifecycleDecisions, $closureRecordEntities): array {
                $latestRecord = $group
                    ->sortByDesc('tax_year')
                    ->first();
                $decision = $lifecycleDecisions->get($entityKey);
                $displayName = (string) ($latestRecord['entity_name']
                    ?? $group->pluck('entity_name')->filter()->first()
                    ?? $latestRecord['file_name']);

                return [
                    'name' => $displayName,
                    'entity_key' => $entityKey,
                    'latest_return_year' => $latestRecord['tax_year'] ?? null,
                    'form_types' => $group
                        ->flatMap(fn (array $record): array => $record['form_types'])
                        ->unique()
                        ->values()
                        ->all(),
                    'has_meaningful_facts' => $group->contains(fn (array $record): bool => (bool) $record['has_meaningful_facts']),
                    'decision' => $decision?->decision,
                    'decision_label' => TaxEntityLifecycleDecision::labelFor($decision?->decision),
                    'requires_final_return' => (bool) ($decision?->requires_final_return ?? false),
                    'requires_dissolution' => (bool) ($decision?->requires_dissolution ?? false),
                    'has_closure_record' => in_array($entityKey, $closureRecordEntities, true),
                    'notes' => $decision?->notes,
                    'decided_at' => $decision?->decided_at?->format('M d, Y'),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $businessEntities
     * @return array<int, string>
     */
    protected function entityLifecycleQuestions(array $businessEntities, int $filingYear): array
    {
        if (count($businessEntities) <= 1) {
            return [];
        }

        $undecidedEntities = collect($businessEntities)
            ->filter(fn (array $entity): bool => ($entity['decision'] ?? null) === null)
            ->pluck('name')
            ->values();

        if ($undecidedEntities->isEmpty()) {
            return [];
        }

        $entityList = $undecidedEntities->implode(', ');

        return [
            "Which of these business entities is active in {$filingYear}, and which need a final {$filingYear} return and dissolution package: {$entityList}?",
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $businessEntities
     * @return array<int, string>
     */
    protected function entityLifecycleDocumentRequests(array $businessEntities, int $filingYear): array
    {
        if (count($businessEntities) <= 1) {
            return [];
        }

        $requests = collect($businessEntities)
            ->flatMap(function (array $entity) use ($filingYear): array {
                $entityRequests = [];

                if (($entity['decision'] ?? null) === null) {
                    return $entityRequests;
                }

                if ((bool) ($entity['requires_dissolution'] ?? false) && ! (bool) ($entity['has_closure_record'] ?? false)) {
                    $entityRequests[] = "Upload the dissolution / closure record for {$entity['name']} so the {$filingYear} final return package can document the shutdown cleanly.";
                }

                return $entityRequests;
            })
            ->filter()
            ->values()
            ->all();

        return $requests;
    }

    /**
     * @param  array<int, array<string, mixed>>  $businessEntities
     */
    protected function entityLifecycleNextAction(array $businessEntities, int $filingYear): string
    {
        if (count($businessEntities) <= 1) {
            return 'No extra entity lifecycle review is needed from prior-year returns.';
        }

        $undecidedEntities = collect($businessEntities)
            ->filter(fn (array $entity): bool => ($entity['decision'] ?? null) === null)
            ->pluck('name')
            ->implode(', ');

        if ($undecidedEntities !== '') {
            return "Confirm whether each prior-year business entity remains active in {$filingYear} or needs a final return and dissolution package: {$undecidedEntities}.";
        }

        $finalReturnEntities = collect($businessEntities)
            ->filter(fn (array $entity): bool => (bool) ($entity['requires_final_return'] ?? false))
            ->pluck('name')
            ->implode(', ');

        if ($finalReturnEntities !== '') {
            return "Complete the final {$filingYear} return and closure support for: {$finalReturnEntities}.";
        }

        $entityList = collect($businessEntities)
            ->pluck('name')
            ->implode(', ');

        return "Entity lifecycle decisions are recorded for {$filingYear}: {$entityList}.";
    }

    protected function lifecycleDecisions(int $userId, int $filingYear): Collection
    {
        return TaxEntityLifecycleDecision::query()
            ->where('user_id', $userId)
            ->forYear($filingYear)
            ->get()
            ->keyBy(fn (TaxEntityLifecycleDecision $decision): string => $this->entityKey($decision->entity_name));
    }

    /**
     * @return array<int, string>
     */
    protected function closureRecordEntities(int $userId, int $filingYear): array
    {
        return FinancialDocument::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->get()
            ->filter(function (FinancialDocument $document) use ($filingYear): bool {
                return $this->documentTaxYear($document) === $filingYear
                    && (
                        $document->document_type === 'entity_closure_record'
                        || Str::contains($this->documentSearchText($document), ['dissolution', 'closure record', 'articles of dissolution', 'termination'])
                    );
            })
            ->map(fn (FinancialDocument $document): string => $this->entityKey($this->closureRecordEntityName($document)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function entityKey(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->toString();
    }

    protected function closureRecordEntityName(FinancialDocument $document): string
    {
        $extractedData = is_array($document->extracted_data) ? $document->extracted_data : [];
        $fields = is_array($extractedData['extracted_fields'] ?? null) ? $extractedData['extracted_fields'] : [];
        $entityName = $this->stringField($extractedData, $fields, ['entity_name']);

        if ($entityName !== null) {
            return $entityName;
        }

        return (string) Str::of(pathinfo($document->file_name, PATHINFO_FILENAME))
            ->replaceMatches('/\b(dissolution|closure|record|articles|termination|final|202[0-9])\b/i', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }

    protected function documentSearchText(FinancialDocument $document): string
    {
        $searchable = [
            $document->document_type,
            $document->file_name,
            $document->file_path,
            data_get($document->extracted_data, 'summary'),
            json_encode(data_get($document->extracted_data, 'extracted_fields')),
        ];

        return Str::lower(collect($searchable)->filter()->implode(' '));
    }

    /**
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<string, mixed>|null  $latestPersonalReturn
     * @param  array<string, mixed>|null  $latestBusinessReturn
     * @return array<int, string>
     */
    protected function questionsNeeded(array $facts, ?array $latestPersonalReturn, ?array $latestBusinessReturn): array
    {
        $questions = [];

        if ($latestPersonalReturn !== null && ($facts['federal_overpayment_applied']['status'] ?? 'missing') !== 'ready') {
            $questions[] = "Did the {$latestPersonalReturn['tax_year']} federal return apply any overpayment to the current year?";
        }

        if ($latestPersonalReturn !== null && ($facts['oregon_overpayment_applied']['status'] ?? 'missing') !== 'ready') {
            $questions[] = "Did the {$latestPersonalReturn['tax_year']} Oregon return apply any overpayment to the current year?";
        }

        if ($latestPersonalReturn !== null
            && ($facts['capital_loss_carryforward']['status'] ?? 'missing') !== 'ready'
            && ($facts['nol_carryforward']['status'] ?? 'missing') !== 'ready') {
            $questions[] = "Did the {$latestPersonalReturn['tax_year']} personal return leave any capital loss or NOL carryforward into the current year?";
        }

        if ($latestBusinessReturn !== null && ($facts['latest_business_shareholder_basis']['status'] ?? 'missing') !== 'ready') {
            $questions[] = "What was the ending shareholder basis on the {$latestBusinessReturn['tax_year']} business return?";
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     */
    protected function entityName(FinancialDocument $document, string $category, array $extractedData, array $fields): ?string
    {
        $entityName = $this->stringField($extractedData, $fields, ['entity_name']);

        if ($entityName !== null) {
            return $entityName;
        }

        if ($category !== 'personal') {
            $pathEntityName = $this->entityNameFromPath($document, $extractedData);

            if ($pathEntityName !== null) {
                return $pathEntityName;
            }
        }

        $baseName = pathinfo($document->file_name, PATHINFO_FILENAME);
        $cleaned = preg_replace(
            '/\b(20\d{2}|1040|or-40|1120-s|1120s|1065|7203|return|tax|packet|federal|oregon|final)\b/i',
            ' ',
            $baseName,
        );
        $normalized = preg_replace('/\s+/', ' ', trim((string) $cleaned));

        if ($normalized === '') {
            return $category === 'personal' ? 'Personal return' : null;
        }

        return $this->normalizeEntityDisplayName($normalized);
    }

    /**
     * @param  array<string, mixed>  $extractedData
     */
    protected function entityNameFromPath(FinancialDocument $document, array $extractedData): ?string
    {
        $paths = array_filter([
            data_get($extractedData, 'archive_relative_path'),
            $document->file_path,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', (string) $path))));

            foreach ($segments as $index => $segment) {
                if (! preg_match('/^20\d{2}$/', $segment) || $index === 0) {
                    continue;
                }

                $candidate = trim($segments[$index - 1]);

                if ($candidate === '' || preg_match('/^tax returns?$/i', $candidate)) {
                    continue;
                }

                return $this->normalizeEntityDisplayName($candidate);
            }
        }

        return null;
    }

    protected function normalizeEntityDisplayName(string $value): string
    {
        return Str::of($value)
            ->replaceMatches('/\.[A-Za-z0-9]+$/', ' ')
            ->replaceMatches('/\b(client copy|client cop|client|copy)\b/i', ' ')
            ->replaceMatches('/\b(and|or)\b/i', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->title()
            ->replaceMatches('/\bLlc\b/', 'LLC')
            ->replaceMatches('/\bInc\b/', 'INC')
            ->replaceMatches('/\bLp\b/', 'LP')
            ->replaceMatches('/\bLlp\b/', 'LLP')
            ->toString();

    }

    protected function documentTaxYear(FinancialDocument $document): ?int
    {
        $yearCandidates = [
            data_get($document->extracted_data, 'tax_year'),
            data_get($document->extracted_data, 'extracted_fields.tax_year'),
        ];

        foreach ($yearCandidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        preg_match_all('/\b(20\d{2})\b/', implode(' ', array_filter([$document->file_name, $document->file_path])), $matches);

        if (($matches[1] ?? []) === []) {
            return null;
        }

        return (int) max($matches[1]);
    }

    /**
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     * @return array<int, string>
     */
    protected function normalizeFormTypes(FinancialDocument $document, array $extractedData, array $fields): array
    {
        $candidate = $fields['form_types'] ?? $extractedData['form_types'] ?? null;
        $formTypes = collect(is_array($candidate) ? $candidate : preg_split('/[,;|]+/', (string) $candidate))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->values();

        $searchText = $this->documentHeuristicText($document, $extractedData, $fields);

        foreach (['1040', '1120-s', '1120s', '1065', 'or-40', '7203'] as $token) {
            if (Str::contains($searchText, Str::lower($token))) {
                $formTypes->push(Str::lower($token));
            }
        }

        return $formTypes
            ->map(fn (string $value): string => str_replace('1120s', '1120-s', $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $formTypes
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     */
    protected function documentCategory(array $formTypes, FinancialDocument $document, array $extractedData, array $fields): string
    {
        $hasPersonalForm = collect($formTypes)->contains(fn (string $type): bool => in_array($type, ['1040', 'or-40'], true));
        $hasBusinessForm = collect($formTypes)->contains(fn (string $type): bool => in_array($type, ['1120-s', '1065', '7203'], true));

        if ($hasPersonalForm && $hasBusinessForm) {
            return 'mixed';
        }

        if ($hasBusinessForm) {
            return 'business';
        }

        if ($hasPersonalForm) {
            return 'personal';
        }

        $requestedScope = data_get($extractedData, 'requested_scope');
        $searchText = $this->documentHeuristicText($document, $extractedData, $fields);

        if ($requestedScope === 'business') {
            return 'business';
        }

        if (Str::contains($searchText, [
            's corp',
            's-corp',
            '1120-s',
            '1120s',
            'k-1',
            'schedule k-1',
            'llc',
            'inc',
            'corp',
            'corporation',
            'partnership',
            'shareholder',
            'basis worksheet',
        ])) {
            return 'business';
        }

        return 'personal';
    }

    /**
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     */
    protected function documentHeuristicText(FinancialDocument $document, array $extractedData, array $fields): string
    {
        return Str::lower(implode(' ', array_filter([
            $document->file_name,
            $document->file_path,
            data_get($extractedData, 'archive_relative_path'),
            data_get($extractedData, 'requested_scope'),
            data_get($extractedData, 'summary'),
            data_get($fields, 'entity_name'),
        ])));
    }

    /**
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     */
    protected function numericField(array $extractedData, array $fields, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? $extractedData[$key] ?? null;
            $normalized = $this->normalizeNumeric($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extractedData
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     */
    protected function stringField(array $extractedData, array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? $extractedData[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $isNegative = Str::startsWith($normalized, '(') && Str::endsWith($normalized, ')');
        $normalized = str_replace(['$', ',', '(', ')'], '', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;

        return $isNegative ? $amount * -1 : $amount;
    }

    protected function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Missing';
        }

        if (is_numeric($value)) {
            return '$'.number_format((float) $value, 2);
        }

        return (string) $value;
    }
}
