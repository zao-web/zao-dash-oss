<?php

namespace App\Services\Tax;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TaxFilingSprintService
{
    public function defaultFocusYear(?CarbonInterface $today = null): int
    {
        $today = $today ? Carbon::instance($today) : now();
        $annualDueDate = Carbon::create($today->year, 4, 15)->startOfDay();

        if ($today->lessThanOrEqualTo($annualDueDate)) {
            return $today->year - 1;
        }

        return $today->year;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $taxCalendarEvents
     * @param  iterable<int, array<string, mixed>|object>  $taxDocuments
     * @return array{
     *     focus_year: int,
     *     headline: string,
     *     status: string,
     *     status_label: string,
     *     deadline_date: string,
     *     days_until_deadline: int,
     *     primary_message: string,
     *     current_step: array<string, mixed>,
     *     steps: array<int, array<string, mixed>>,
     *     completed_step_count: int,
     *     remaining_step_count: int,
     *     total_step_count: int,
     *     automatic_sources: array<int, array<string, mixed>>,
     *     source_integrity: array<string, mixed>,
     *     source_summary: string,
     *     source_highlights: array<int, string>,
     *     upcoming_steps: array<int, array<string, string>>,
     *     documents_needed: array<int, string>,
     *     questions_needed: array<int, string>,
     *     deep_dive_note: string,
     * }
     */
    public function build(
        int $filingYear,
        array $taxReturnPrep,
        array $taxAutopilot,
        iterable $taxCalendarEvents = [],
        iterable $taxDocuments = [],
        ?CarbonInterface $today = null,
    ): array {
        $today = $today ? Carbon::instance($today) : now();
        $deadline = $this->annualDeadline($filingYear, $taxCalendarEvents);
        $daysUntilDeadline = (int) $today->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);

        $gateMap = collect($taxReturnPrep['validation_gates'] ?? [])->keyBy('code');
        $artifactState = $taxReturnPrep['artifacts'] ?? [];
        $payrollArtifactState = $taxReturnPrep['payroll_artifacts'] ?? [];
        $payrollCompliance = $taxReturnPrep['payroll_compliance'] ?? [];
        $payrollOperations = $taxReturnPrep['payroll_operations'] ?? [];
        $entityCloseoutArtifactState = $taxReturnPrep['entity_closeout'] ?? [];
        $bookkeepingReadiness = $taxReturnPrep['bookkeeping_readiness'] ?? [];
        $ownerPaymentReview = $taxReturnPrep['owner_payment_review'] ?? [];
        $documentEvidence = $taxReturnPrep['document_evidence'] ?? [];
        $archiveEvidence = $taxReturnPrep['archive_evidence'] ?? [];
        $historicalReturnContext = $taxReturnPrep['historical_return_context'] ?? [];
        $revenueRecognition = $taxReturnPrep['revenue_recognition'] ?? [];
        $sourceFacts = collect($taxReturnPrep['source_facts'] ?? [])->keyBy('key');

        $documentRequests = $this->documentRequests(
            $filingYear,
            $documentEvidence,
            $archiveEvidence,
            $historicalReturnContext,
            $gateMap,
            $sourceFacts,
            $taxAutopilot,
        );
        $payrollDocumentRequests = $this->payrollDocumentRequests($documentRequests);
        $payrollStepRequests = $this->payrollStepRequests($filingYear, $payrollDocumentRequests, $payrollArtifactState, $payrollCompliance, $payrollOperations);
        $currentYearSupportRequests = array_values(array_diff($documentRequests, $payrollDocumentRequests));
        $priorYearDocumentRequests = $this->priorYearRequests(
            $archiveEvidence,
            $historicalReturnContext,
            $filingYear,
            $payrollArtifactState,
            $payrollCompliance,
            $payrollOperations,
        );
        $finalReturnDocumentRequests = $this->finalReturnDocumentRequests($historicalReturnContext);
        $finalReturnStepRequests = $this->finalReturnStepRequests($filingYear, $finalReturnDocumentRequests, $entityCloseoutArtifactState);
        $questionRequests = $this->questionRequests($gateMap, $filingYear);
        $compensationQuestions = $this->compensationQuestions($gateMap, $filingYear);
        $sourceFeedQuestions = $this->sourceFeedQuestions($gateMap, $filingYear);
        $bookkeepingQuestions = $this->bookkeepingQuestions($bookkeepingReadiness, $gateMap, $filingYear);
        $bookkeepingDocuments = $this->bookkeepingDocuments($bookkeepingReadiness);
        $ownerPaymentQuestions = $this->ownerPaymentQuestions($ownerPaymentReview, $filingYear);
        $priorYearQuestions = $this->priorYearQuestions($historicalReturnContext);
        $personalReturnQuestions = $this->personalReturnQuestions($gateMap, $filingYear);
        $reviewQuestions = $this->reviewQuestions($gateMap, $filingYear, $revenueRecognition);
        $documentsNeedingReview = $this->documentsNeedingReview($taxDocuments, $filingYear);
        $bookkeepingAction = $this->bookkeepingStepAction($bookkeepingReadiness, $filingYear);
        $ownerPaymentAction = $this->ownerPaymentStepAction($ownerPaymentReview, $filingYear);
        $payrollDocumentAction = $this->payrollStepAction($filingYear, $payrollStepRequests, $payrollArtifactState, $payrollCompliance, $payrollOperations);
        $priorYearDocumentAction = $this->documentAction($filingYear, $priorYearDocumentRequests);
        $personalReturnAction = $this->personalReturnStepAction($gateMap, $filingYear);
        $finalReturnDocumentAction = $this->finalReturnStepAction($filingYear, $finalReturnStepRequests, $entityCloseoutArtifactState);
        $currentYearDocumentAction = $this->documentAction($filingYear, $currentYearSupportRequests);
        $compensationAction = $this->compensationStepAction($gateMap, $filingYear);
        $reviewFlagsAction = $this->reviewFlagsAction(
            gateMap: $gateMap,
            filingYear: $filingYear,
            documentsNeedingReview: $documentsNeedingReview,
            ownerPaymentReview: $ownerPaymentReview,
            revenueRecognition: $revenueRecognition,
        );
        $reviewFlagsNeedsRevenueAudit = $this->reviewFlagsNeedsRevenueAudit($gateMap, $revenueRecognition);
        $bookkeepingShouldLeadPayroll = $bookkeepingReadiness !== []
            && ! (bool) ($bookkeepingReadiness['close_ready'] ?? false);

        $bookkeepingStep = $this->step(
            code: 'bookkeeping_close',
            title: "Close {$filingYear} books",
            complete: $this->bookkeepingStepComplete($bookkeepingReadiness, $gateMap),
            summary: $this->bookkeepingStepSummary($bookkeepingReadiness, $filingYear),
            documents: $bookkeepingDocuments,
            questions: $bookkeepingQuestions,
            actionLabel: $bookkeepingAction['label'] ?? null,
            actionHref: $bookkeepingAction['href'] ?? null,
            actionMethod: $bookkeepingAction['method'] ?? null,
            actionData: $bookkeepingAction['data'] ?? null,
        );
        $payrollComplianceStep = $this->step(
            code: 'payroll_compliance',
            title: "Close payroll filings for {$filingYear}",
            complete: $this->payrollStepComplete($payrollStepRequests, $payrollArtifactState, $payrollCompliance, $payrollOperations),
            summary: 'Payroll should now be explicit work: planned pay runs, deposit checkpoints, and the generated filing packet for W-2, W-3, 941, 940, and Oregon payroll filings.',
            documents: $payrollStepRequests,
            questions: [],
            actionLabel: $payrollDocumentAction['label'] ?? null,
            actionHref: $payrollDocumentAction['href'] ?? null,
            actionMethod: $payrollDocumentAction['method'] ?? null,
            actionData: $payrollDocumentAction['data'] ?? null,
        );
        $ownerPaymentStep = $this->step(
            code: 'owner_payment_review',
            title: "Review owner payments for {$filingYear}",
            complete: $this->ownerPaymentStepComplete($ownerPaymentReview),
            summary: $this->ownerPaymentStepSummary($ownerPaymentReview, $filingYear),
            documents: [],
            questions: $ownerPaymentQuestions,
            actionLabel: $ownerPaymentAction['label'] ?? null,
            actionHref: $ownerPaymentAction['href'] ?? null,
            actionMethod: $ownerPaymentAction['method'] ?? null,
            actionData: $ownerPaymentAction['data'] ?? null,
        );
        $compensationStep = $this->step(
            code: 'compensation_posture',
            title: "Set {$filingYear} S-corp compensation posture",
            complete: ! $this->gateBlocks($gateMap, 'reasonable_compensation'),
            summary: 'Decide whether owner cash movement supports zero wages or whether some 2025 payments need compensation reclassification.',
            documents: [],
            questions: $compensationQuestions,
            actionLabel: $compensationAction['label'] ?? null,
            actionHref: $compensationAction['href'] ?? null,
        );

        $steps = [
            $this->step(
                code: 'filing_facts',
                title: "Confirm {$filingYear} filing facts",
                complete: $this->gatePassed($gateMap, 'tax_profile'),
                summary: 'Make sure filing status, residency, and entity posture are correct before drafting the return.',
                documents: [],
                questions: $questionRequests,
            ),
            $this->step(
                code: 'source_feeds',
                title: 'Pull source ledgers',
                complete: $this->gatePassed($gateMap, 'books_available')
                    && $this->gatePassed($gateMap, 'bank_feed_available')
                    && $this->gatePassed($gateMap, 'live_projection'),
                summary: 'QuickBooks, bank feeds, and the live projection should already be carrying most of the return.',
                documents: [],
                questions: $sourceFeedQuestions,
            ),
            ...($bookkeepingShouldLeadPayroll
                ? [$bookkeepingStep, $ownerPaymentStep, $compensationStep, $payrollComplianceStep]
                : [$ownerPaymentStep, $compensationStep, $payrollComplianceStep]),
            $this->step(
                code: 'prior_year_context',
                title: 'Load prior-year context',
                complete: $this->priorYearStepComplete($gateMap, $priorYearDocumentRequests, $priorYearQuestions),
                summary: 'Prior returns should be doing real work here: carryovers, basis, overpayment credits, compensation history, and consistency checks.',
                documents: $priorYearDocumentRequests,
                questions: $priorYearQuestions,
                actionLabel: $priorYearDocumentAction['label'] ?? null,
                actionHref: $priorYearDocumentAction['href'] ?? null,
            ),
            $this->step(
                code: 'personal_return_inputs',
                title: "Review {$filingYear} personal-return inputs",
                complete: $this->gatePassedOrMissing($gateMap, 'personal_return_inputs'),
                summary: 'Make the 1040-specific deduction and education inputs explicit before treating the personal return bridge as coherent.',
                documents: [],
                questions: $personalReturnQuestions,
                actionLabel: $personalReturnAction['label'] ?? null,
                actionHref: $personalReturnAction['href'] ?? null,
            ),
            $this->step(
                code: 'final_return_closeout',
                title: $this->finalReturnStepTitle($historicalReturnContext, $filingYear),
                complete: ($this->gatePassedOrMissing($gateMap, 'final_return_support')
                    && (! ($entityCloseoutArtifactState['is_applicable'] ?? false) || (bool) ($entityCloseoutArtifactState['has_current_package'] ?? false)))
                    || $finalReturnStepRequests === [],
                summary: $this->finalReturnStepSummary($historicalReturnContext, $filingYear),
                documents: $finalReturnStepRequests,
                questions: [],
                actionLabel: $finalReturnDocumentAction['label'] ?? null,
                actionHref: $finalReturnDocumentAction['href'] ?? null,
                actionMethod: $finalReturnDocumentAction['method'] ?? null,
                actionData: $finalReturnDocumentAction['data'] ?? null,
            ),
            ...($bookkeepingShouldLeadPayroll ? [] : [$bookkeepingStep]),
            $this->step(
                code: 'current_year_support',
                title: "Add {$filingYear} filing support",
                complete: $currentYearSupportRequests === [],
                summary: $this->currentYearSupportSummary($gateMap),
                documents: $currentYearSupportRequests,
                questions: [],
                actionLabel: $currentYearDocumentAction['label'] ?? null,
                actionHref: $currentYearDocumentAction['href'] ?? null,
            ),
            $this->step(
                code: 'review_flags',
                title: 'Review flagged documents and reconciliation issues',
                complete: ! $this->gateBlocks($gateMap, 'tax_document_review_queue')
                    && ! $reviewFlagsNeedsRevenueAudit
                    && ! $this->gateBlocks($gateMap, 'agency_payment_reconciliation')
                    && ! $this->gateBlocks($gateMap, 'agency_notice_clearance'),
                summary: 'Anything contradictory should surface here before you sign anything.',
                documents: $documentsNeedingReview,
                questions: $reviewQuestions,
                actionLabel: $reviewFlagsAction['label'] ?? null,
                actionHref: $reviewFlagsAction['href'] ?? null,
                actionMethod: $reviewFlagsAction['method'] ?? null,
                actionData: $reviewFlagsAction['data'] ?? null,
            ),
            $this->step(
                code: 'generate_packet',
                title: 'Generate draft return package',
                complete: (bool) ($artifactState['has_current_draft_package'] ?? false)
                    && (bool) ($artifactState['has_current_signoff_packet'] ?? false),
                summary: 'Once the packet is coherent, generate the draft returns and the owner signoff packet.',
                documents: [],
                questions: [],
                actionLabel: $this->generationActionLabel($taxReturnPrep, $artifactState),
                actionHref: $this->generationActionHref($taxReturnPrep, $artifactState),
                actionMethod: $this->generationActionHref($taxReturnPrep, $artifactState) ? 'post' : null,
                actionData: $this->generationActionHref($taxReturnPrep, $artifactState) ? ['year' => $filingYear, 'format' => 'html'] : null,
            ),
            $this->step(
                code: 'approve_and_file',
                title: 'Approve and file',
                complete: ($artifactState['approval_status'] ?? null) === 'approved',
                summary: 'Owner approval should only happen after the packet hash is stable and the draft forms are current.',
                documents: [],
                questions: [],
                actionLabel: ($artifactState['can_request_owner_approval'] ?? false) ? 'Approve filing packet' : null,
                actionHref: ($artifactState['can_request_owner_approval'] ?? false) ? '/life/tax-forms/approve-annual-return' : null,
                actionMethod: ($artifactState['can_request_owner_approval'] ?? false) ? 'post' : null,
                actionData: ($artifactState['can_request_owner_approval'] ?? false) ? ['year' => $filingYear, 'confirm_owner_review' => true] : null,
            ),
        ];

        $completedStepCount = collect($steps)->where('status', 'complete')->count();
        $totalStepCount = count($steps);
        $remainingStepCount = max($totalStepCount - $completedStepCount, 0);
        $steps = $this->assignStepStates($steps);
        $currentStep = $remainingStepCount === 0
            ? $this->step(
                code: 'done',
                title: 'Packet is ready',
                complete: true,
                summary: 'All filing sprint steps are complete. Review the generated packet, then file manually with the relevant federal, state, and local agencies.',
                documents: [],
                questions: [],
                actionLabel: (($artifactState['has_current_draft_package'] ?? false) || ($artifactState['has_current_signoff_packet'] ?? false))
                    ? 'Open generated forms'
                    : null,
                actionHref: (($artifactState['has_current_draft_package'] ?? false) || ($artifactState['has_current_signoff_packet'] ?? false))
                    ? "/life/tax-optimizer?year={$filingYear}#generated-filing-packet"
                    : null,
            )
            : (collect($steps)->firstWhere('status', 'current')
                ?? collect($steps)->first()
                ?? $this->step(
                    code: 'done',
                    title: 'Packet is ready',
                    complete: true,
                    summary: 'No further filing sprint steps are open.',
                    documents: [],
                    questions: [],
                ));
        $automaticSources = $this->automaticSources($taxAutopilot);
        $sourceIntegrity = $this->sourceIntegrity($gateMap, $bookkeepingReadiness);

        return [
            'focus_year' => $filingYear,
            'headline' => "File {$filingYear} returns by {$deadline->format('M d, Y')}",
            'status' => $this->deadlineStatus($daysUntilDeadline),
            'status_label' => $this->deadlineStatusLabel($daysUntilDeadline),
            'deadline_date' => $deadline->format('M d, Y'),
            'days_until_deadline' => $daysUntilDeadline,
            'primary_message' => $this->primaryMessage($daysUntilDeadline, $currentStep),
            'current_step' => $currentStep,
            'steps' => $steps,
            'completed_step_count' => $completedStepCount,
            'remaining_step_count' => $remainingStepCount,
            'total_step_count' => $totalStepCount,
            'automatic_sources' => $automaticSources,
            'source_integrity' => $sourceIntegrity,
            'source_summary' => $this->sourceSummary($automaticSources),
            'source_highlights' => $this->sourceHighlights($automaticSources),
            'upcoming_steps' => $this->upcomingSteps($steps),
            'documents_needed' => array_values(array_unique(array_merge(
                $bookkeepingDocuments,
                $priorYearDocumentRequests,
                $payrollStepRequests,
                $finalReturnStepRequests,
                $currentYearSupportRequests,
                $documentsNeedingReview,
            ))),
            'questions_needed' => array_values(array_unique(array_merge(
                $questionRequests,
                $sourceFeedQuestions,
                $bookkeepingQuestions,
                $ownerPaymentQuestions,
                $priorYearQuestions,
                $personalReturnQuestions,
                $reviewQuestions,
            ))),
            'deep_dive_note' => 'Open the deep dive only when you want line-level mappings, validation gates, generated forms, or optimization detail.',
        ];
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $taxCalendarEvents
     */
    protected function annualDeadline(int $filingYear, iterable $taxCalendarEvents): Carbon
    {
        $deadline = collect($taxCalendarEvents)
            ->filter(function (mixed $event) use ($filingYear): bool {
                return (int) data_get($event, 'tax_year') === $filingYear
                    && in_array((string) data_get($event, 'event_type'), ['annual_return', 'extension'], true)
                    && data_get($event, 'status') !== 'completed'
                    && data_get($event, 'due_date') !== null;
            })
            ->map(fn (mixed $event): Carbon => Carbon::parse(data_get($event, 'due_date')))
            ->sort()
            ->first();

        return $deadline instanceof Carbon
            ? $deadline->startOfDay()
            : Carbon::create($filingYear + 1, 4, 15)->startOfDay();
    }

    protected function deadlineStatus(int $daysUntilDeadline): string
    {
        return match (true) {
            $daysUntilDeadline < 0 => 'overdue',
            $daysUntilDeadline <= 7 => 'urgent',
            $daysUntilDeadline <= 30 => 'active',
            default => 'planned',
        };
    }

    protected function deadlineStatusLabel(int $daysUntilDeadline): string
    {
        return match (true) {
            $daysUntilDeadline < 0 => abs($daysUntilDeadline).' day(s) overdue',
            $daysUntilDeadline === 0 => 'Due today',
            $daysUntilDeadline === 1 => 'Due tomorrow',
            $daysUntilDeadline <= 7 => "Due in {$daysUntilDeadline} days",
            default => 'On track',
        };
    }

    /**
     * @param  array<int, string>  $documents
     * @param  array<int, string>  $questions
     * @param  array<string, mixed>|null  $actionData
     * @return array<string, mixed>
     */
    protected function step(
        string $code,
        string $title,
        bool $complete,
        string $summary,
        array $documents,
        array $questions,
        ?string $actionLabel = null,
        ?string $actionHref = null,
        ?string $actionMethod = null,
        ?array $actionData = null,
    ): array {
        $ownerRequests = $this->ownerRequests($questions, $documents);

        return [
            'code' => $code,
            'title' => $title,
            'complete' => $complete,
            'summary' => $summary,
            'documents' => $documents,
            'questions' => $questions,
            'owner_requests' => $ownerRequests,
            'primary_request' => $ownerRequests[0] ?? null,
            'remaining_request_count' => max(count($ownerRequests) - 1, 0),
            'action_label' => $actionLabel,
            'action_href' => $actionHref,
            'action_method' => $actionMethod,
            'action_data' => $actionData,
            'status' => $complete ? 'complete' : 'upcoming',
            'status_label' => $complete ? 'Done' : 'Next up',
        ];
    }

    /**
     * @param  array<int, string>  $questions
     * @param  array<int, string>  $documents
     * @return array<int, array{kind: string, label: string}>
     */
    protected function ownerRequests(array $questions, array $documents): array
    {
        return collect($questions)
            ->filter(fn (string $question): bool => $question !== '')
            ->map(fn (string $question): array => [
                'kind' => 'question',
                'label' => $question,
            ])
            ->merge(
                collect($documents)
                    ->filter(fn (string $document): bool => $document !== '')
                    ->map(fn (string $document): array => [
                        'kind' => 'document',
                        'label' => $document,
                    ])
            )
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function assignStepStates(array $steps): array
    {
        $currentAssigned = false;

        foreach ($steps as &$step) {
            if ($step['complete']) {
                $step['status'] = 'complete';
                $step['status_label'] = 'Done';

                continue;
            }

            if (! $currentAssigned) {
                $step['status'] = 'current';
                $step['status_label'] = 'Do this now';
                $currentAssigned = true;

                continue;
            }

            $step['status'] = 'upcoming';
            $step['status_label'] = 'Then';
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $documentEvidence
     * @param  array<string, mixed>  $archiveEvidence
     * @param  array<string, mixed>  $historicalReturnContext
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @param  Collection<string, array<string, mixed>>  $sourceFacts
     * @param  array<string, mixed>  $taxAutopilot
     * @return array<int, string>
     */
    protected function documentRequests(
        int $filingYear,
        array $documentEvidence,
        array $archiveEvidence,
        array $historicalReturnContext,
        Collection $gateMap,
        Collection $sourceFacts,
        array $taxAutopilot,
    ): array {
        $requests = [];
        $entityType = (string) data_get($sourceFacts->get('profile.entity_type'), 'value', '');
        $usesPassThroughBusiness = count($historicalReturnContext['business_entities'] ?? []) > 0 || $entityType === 's_corp';
        $hasHealthyBooksFeed = $this->gatePassed($gateMap, 'books_available');
        $hasHealthyBankFeed = $this->gatePassed($gateMap, 'bank_feed_available');
        $booksToBankNeedsBackup = $this->gateNeedsAttention($gateMap, 'books_to_bank_tie_out');
        $hasLiveQuickBooksFeed = $this->hasLiveQuickBooksFeed($taxAutopilot);
        $hasPriorBusinessIncome = $this->sourceFactIsReady($sourceFacts, 'prior_return.latest_business_ordinary_income');
        $hasPriorBusinessBasis = $this->sourceFactIsReady($sourceFacts, 'prior_return.latest_business_shareholder_basis');
        $hasPriorBusinessDistributions = $this->sourceFactIsReady($sourceFacts, 'prior_return.latest_business_distributions');
        $projectedDistributions = (float) data_get($sourceFacts->get('tax.distributions'), 'value', 0);
        $hasEstimatedPaymentsToCorroborate = $this->hasEstimatedPaymentsToCorroborate($sourceFacts);

        if ($entityType === 's_corp' && (int) ($documentEvidence['payroll_document_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} payroll packet: W-2, W-3, Forms 941/940, and Oregon payroll filings if applicable";
        }
        if ((! $hasHealthyBooksFeed || $booksToBankNeedsBackup)
            && ! $hasLiveQuickBooksFeed
            && (int) ($documentEvidence['books_export_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} QuickBooks exports: trial balance, general ledger, profit and loss, and balance sheet";
        }
        if ((! $hasHealthyBankFeed || $booksToBankNeedsBackup) && (int) ($documentEvidence['bank_statement_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} business bank statements for the accounts that fed the return";
        }
        if ($hasEstimatedPaymentsToCorroborate && (int) ($documentEvidence['payment_confirmation_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} tax payment confirmations for federal and Oregon estimated payments already made";
        }
        if ($usesPassThroughBusiness
            && ! $hasHealthyBooksFeed
            && ! $hasPriorBusinessIncome
            && (int) ($documentEvidence['k1_document_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} pass-through income support if books do not fully explain owner income";
        }
        if ($usesPassThroughBusiness
            && ! $hasPriorBusinessBasis
            && (int) ($documentEvidence['basis_document_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} basis / QBI workpapers such as Form 7203 or 8995 support";
        }
        if ($usesPassThroughBusiness
            && $projectedDistributions > 0
            && ! $hasHealthyBooksFeed
            && ! $hasPriorBusinessDistributions
            && (int) ($documentEvidence['distribution_document_count'] ?? 0) === 0) {
            $requests[] = "{$filingYear} shareholder distribution ledger";
        }
        if (($archiveEvidence['prior_business_return_years'] ?? []) === [] && ($archiveEvidence['prior_personal_return_years'] ?? []) === []) {
            $requests[] = 'Prior-year federal and Oregon return packets so carryovers and consistency checks can be grounded';
        }

        return array_values(array_unique($requests));
    }

    /**
     * @param  array<string, mixed>  $taxAutopilot
     */
    protected function hasLiveQuickBooksFeed(array $taxAutopilot): bool
    {
        $quickBooksFeed = collect($taxAutopilot['source_feeds'] ?? [])
            ->first(fn (mixed $feed): bool => data_get($feed, 'code') === 'quickbooks');

        $status = (string) data_get($quickBooksFeed, 'status', '');

        return in_array($status, ['connected', 'needs_refresh'], true);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $sourceFacts
     */
    protected function hasEstimatedPaymentsToCorroborate(Collection $sourceFacts): bool
    {
        return $this->sourceFactNumericValue($sourceFacts, 'payments.federal_estimates') > 0
            || $this->sourceFactNumericValue($sourceFacts, 'payments.oregon_estimates') > 0
            || $this->sourceFactNumericValue($sourceFacts, 'agency.federal_portal_payments') > 0
            || $this->sourceFactNumericValue($sourceFacts, 'agency.oregon_portal_payments') > 0;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $sourceFacts
     */
    protected function sourceFactNumericValue(Collection $sourceFacts, string $key): float
    {
        return (float) data_get($sourceFacts->get($key), 'value', 0);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function currentYearSupportSummary(Collection $gateMap): string
    {
        if ($this->gatePassed($gateMap, 'books_available')
            && $this->gatePassed($gateMap, 'bank_feed_available')
            && ! $this->gateNeedsAttention($gateMap, 'books_to_bank_tie_out')) {
            return 'Live QuickBooks and bank feeds are already carrying the packet. I only want uploads that close real support gaps or corroborate sensitive items before filing.';
        }

        return 'I only want the support documents that are still missing for pre-filing work, not post-filing evidence.';
    }

    /**
     * @param  array<int, string>  $documentRequests
     * @return array<int, string>
     */
    protected function payrollDocumentRequests(array $documentRequests): array
    {
        return collect($documentRequests)
            ->filter(fn (string $request): bool => str_contains(strtolower($request), 'payroll'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $payrollDocumentRequests
     * @param  array<string, mixed>  $payrollArtifactState
     * @param  array<string, mixed>  $payrollCompliance
     * @param  array<string, mixed>  $payrollOperations
     * @return array<int, string>
     */
    protected function payrollStepRequests(int $filingYear, array $payrollDocumentRequests, array $payrollArtifactState, array $payrollCompliance, array $payrollOperations): array
    {
        if (($payrollCompliance['status'] ?? null) === 'needs_actual_wages') {
            if (! ($payrollOperations['has_plan'] ?? false)
                && (int) ($payrollArtifactState['current_draft_forms_count'] ?? 0) === 0
                && ! ($payrollArtifactState['has_current_draft_package'] ?? false)) {
                return [];
            }

            return [
                "Record actual {$filingYear} W-2 wages paid before generating payroll filings. If no wages were paid, walk back the projected payroll plan and drafts instead of filing them.",
            ];
        }

        if (($payrollArtifactState['has_current_draft_package'] ?? false)
            && ($payrollOperations['has_plan'] ?? false)) {
            return [];
        }

        if (! ($payrollOperations['has_plan'] ?? false) && ($payrollOperations['can_generate_plan'] ?? false)) {
            return ["Generate {$filingYear} payroll operating plan with pay runs and deposit checkpoints."];
        }

        if ($payrollArtifactState['can_generate_draft_package'] ?? false) {
            return ["Generate {$filingYear} payroll draft package: W-2, W-3, Forms 941/940, Oregon OQ, and OR-WR"];
        }

        return $payrollDocumentRequests;
    }

    /**
     * @param  array<int, string>  $payrollStepRequests
     * @param  array<string, mixed>  $payrollArtifactState
     * @param  array<string, mixed>  $payrollCompliance
     * @param  array<string, mixed>  $payrollOperations
     */
    protected function payrollStepComplete(array $payrollStepRequests, array $payrollArtifactState, array $payrollCompliance, array $payrollOperations): bool
    {
        if (($payrollCompliance['status'] ?? null) === 'needs_actual_wages') {
            return ! ($payrollOperations['has_plan'] ?? false)
                && (int) ($payrollArtifactState['current_draft_forms_count'] ?? 0) === 0
                && ! ($payrollArtifactState['has_current_draft_package'] ?? false);
        }

        return $payrollStepRequests === []
            || (
                (bool) ($payrollArtifactState['has_current_draft_package'] ?? false)
                && (bool) ($payrollOperations['has_plan'] ?? false)
            );
    }

    /**
     * @param  array<string, mixed>  $archiveEvidence
     * @param  array<string, mixed>  $historicalReturnContext
     * @return array<int, string>
     */
    protected function priorYearRequests(
        array $archiveEvidence,
        array $historicalReturnContext,
        int $filingYear,
        array $payrollArtifactState,
        array $payrollCompliance,
        array $payrollOperations,
    ): array {
        $requests = collect($historicalReturnContext['document_requests'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        if (($archiveEvidence['prior_business_return_years'] ?? []) === []) {
            $requests[] = 'Prior business return packets, especially 2023 and 2024 if available';
        }

        if (($archiveEvidence['prior_personal_return_years'] ?? []) === []) {
            $requests[] = 'Prior personal return packets, especially 2023 and 2024 if available';
        }

        $zeroWagePayrollCleared = $this->payrollStepComplete(
            payrollStepRequests: [],
            payrollArtifactState: $payrollArtifactState,
            payrollCompliance: $payrollCompliance,
            payrollOperations: $payrollOperations,
        ) && (($payrollCompliance['status'] ?? null) === 'needs_actual_wages');

        if (($archiveEvidence['payroll_document_count'] ?? 0) === 0 && ! $zeroWagePayrollCleared) {
            $requests[] = "Historical payroll support that explains how {$filingYear} officer compensation should be framed";
        }

        return array_values(array_unique($requests));
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     * @return array<int, string>
     */
    protected function priorYearQuestions(array $historicalReturnContext): array
    {
        return collect($historicalReturnContext['questions_needed'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array<int, string>
     */
    protected function personalReturnQuestions(Collection $gateMap, int $filingYear): array
    {
        if ($this->gatePassedOrMissing($gateMap, 'personal_return_inputs')) {
            return [];
        }

        return [
            "How much {$filingYear} mortgage interest from Form 1098 should the personal return claim?",
            "How much {$filingYear} residence property tax should the personal return claim?",
            "How much {$filingYear} charitable giving should the personal return track?",
            "How much {$filingYear} unreimbursed medical expense should the personal return track?",
            "How much {$filingYear} HSA contribution should the personal return claim?",
            "How much {$filingYear} education expense or 1098-T support should the personal return track?",
        ];
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     * @return array<int, string>
     */
    protected function finalReturnDocumentRequests(array $historicalReturnContext): array
    {
        return collect($historicalReturnContext['entity_lifecycle_document_requests'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function finalReturnStepTitle(array $historicalReturnContext, int $filingYear): string
    {
        $entities = collect($historicalReturnContext['final_return_entities'] ?? [])->values();

        if ($entities->count() === 1) {
            return "Close out {$entities->first()} for {$filingYear}";
        }

        if ($entities->count() > 1) {
            return "Close out final-return entities for {$filingYear}";
        }

        return "Close out final-return entities for {$filingYear}";
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function finalReturnStepSummary(array $historicalReturnContext, int $filingYear): string
    {
        $finalReturnEntities = collect($historicalReturnContext['final_return_entities'] ?? [])->values();
        $dissolutionEntities = collect($historicalReturnContext['dissolution_entities'] ?? [])->values();

        if ($finalReturnEntities->isEmpty()) {
            return "No extra entities need a final {$filingYear} return or dissolution package.";
        }

        $entityList = $finalReturnEntities->implode(', ');

        if ($dissolutionEntities->isNotEmpty()) {
            return "Treat {$entityList} as a dedicated final-return package. Closure support should be gathered before owner signoff.";
        }

        return "Treat {$entityList} as a dedicated final-return package instead of burying it inside general support requests.";
    }

    /**
     * @param  array<int, string>  $documents
     * @return array{label: string, href: string}|null
     */
    protected function documentAction(int $filingYear, array $documents): ?array
    {
        $primaryRequest = collect($documents)
            ->first(fn (string $document): bool => $document !== '');

        if (! is_string($primaryRequest) || $primaryRequest === '') {
            return null;
        }

        $query = array_filter([
            'open_upload' => '1',
            'tax_year' => $filingYear,
            'scope' => $this->suggestedDocumentScope($primaryRequest),
            'document_type' => $this->suggestedDocumentType($primaryRequest),
            'request_label' => $primaryRequest,
            'return_to' => '/life/tax-optimizer',
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'label' => $this->documentActionLabel($primaryRequest),
            'href' => '/life/documents?'.http_build_query($query),
        ];
    }

    /**
     * @param  array<int, string>  $payrollStepRequests
     * @param  array<string, mixed>  $payrollArtifactState
     * @param  array<string, mixed>  $payrollCompliance
     * @param  array<string, mixed>  $payrollOperations
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>}|null
     */
    protected function payrollStepAction(int $filingYear, array $payrollStepRequests, array $payrollArtifactState, array $payrollCompliance, array $payrollOperations): ?array
    {
        if (($payrollCompliance['status'] ?? null) === 'needs_actual_wages') {
            if ((int) ($payrollArtifactState['current_draft_forms_count'] ?? 0) > 0 || ($payrollOperations['has_plan'] ?? false)) {
                return [
                    'label' => 'Walk back payroll drafts',
                    'href' => '/life/tax-payroll/reset',
                    'method' => 'post',
                    'data' => [
                        'year' => $filingYear,
                        'return_to' => '/life/tax-optimizer',
                    ],
                ];
            }

            return [
                'label' => 'Open filing facts',
                'href' => '/life/tax-optimizer#filing-facts',
            ];
        }

        if (! ($payrollOperations['has_plan'] ?? false) && ($payrollOperations['can_generate_plan'] ?? false)) {
            return [
                'label' => 'Generate payroll plan',
                'href' => '/life/tax-payroll/generate-operations',
                'method' => 'post',
                'data' => [
                    'year' => $filingYear,
                    'return_to' => '/life/tax-optimizer',
                ],
            ];
        }

        if (($payrollArtifactState['can_generate_draft_package'] ?? false)
            && ! ($payrollArtifactState['has_current_draft_package'] ?? false)) {
            return [
                'label' => 'Generate payroll package',
                'href' => '/life/tax-forms/generate-payroll-drafts',
                'method' => 'post',
                'data' => [
                    'year' => $filingYear,
                    'format' => 'html',
                ],
            ];
        }

        return $this->documentAction($filingYear, $payrollStepRequests);
    }

    /**
     * @param  array<int, string>  $finalReturnDocumentRequests
     * @param  array<string, mixed>  $entityCloseoutArtifactState
     * @return array<int, string>
     */
    protected function finalReturnStepRequests(int $filingYear, array $finalReturnDocumentRequests, array $entityCloseoutArtifactState): array
    {
        if (! ($entityCloseoutArtifactState['is_applicable'] ?? false)) {
            return $finalReturnDocumentRequests;
        }

        if ($entityCloseoutArtifactState['has_current_package'] ?? false) {
            return [];
        }

        if (($entityCloseoutArtifactState['can_generate_package'] ?? false)
            && ($entityCloseoutArtifactState['missing_document_requests'] ?? []) === []) {
            $entityList = collect($entityCloseoutArtifactState['entity_names'] ?? [])
                ->filter(fn (mixed $entity): bool => is_string($entity) && $entity !== '')
                ->implode(', ');

            return ["Generate {$filingYear} final-return closeout package for {$entityList}"];
        }

        return $finalReturnDocumentRequests;
    }

    /**
     * @param  array<int, string>  $finalReturnStepRequests
     * @param  array<string, mixed>  $entityCloseoutArtifactState
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>}|null
     */
    protected function finalReturnStepAction(int $filingYear, array $finalReturnStepRequests, array $entityCloseoutArtifactState): ?array
    {
        if (($entityCloseoutArtifactState['is_applicable'] ?? false)
            && ($entityCloseoutArtifactState['can_generate_package'] ?? false)
            && ! ($entityCloseoutArtifactState['has_current_package'] ?? false)
            && ($entityCloseoutArtifactState['missing_document_requests'] ?? []) === []) {
            return [
                'label' => 'Generate closeout package',
                'href' => '/life/tax-forms/generate-entity-closeout',
                'method' => 'post',
                'data' => [
                    'year' => $filingYear,
                    'format' => 'html',
                ],
            ];
        }

        return $this->documentAction($filingYear, $finalReturnStepRequests);
    }

    protected function documentActionLabel(string $primaryRequest): string
    {
        $normalized = strtolower($primaryRequest);

        return match (true) {
            str_contains($normalized, 'closeout package') => 'Generate closeout package',
            str_contains($normalized, 'closure record') => 'Upload closure record',
            str_contains($normalized, 'return packet') || str_contains($normalized, 'return packets') => 'Upload prior return',
            str_contains($normalized, 'payroll') => 'Upload payroll packet',
            str_contains($normalized, 'payment confirmation') => 'Upload payment confirmation',
            str_contains($normalized, 'bank statement') => 'Upload bank statement',
            str_contains($normalized, 'quickbooks') || str_contains($normalized, 'trial balance') || str_contains($normalized, 'general ledger') => 'Upload books export',
            str_contains($normalized, 'basis') || str_contains($normalized, 'qbi') => 'Upload basis workpaper',
            str_contains($normalized, 'distribution') => 'Upload distribution ledger',
            str_contains($normalized, 'k-1') || str_contains($normalized, 'pass-through') => 'Upload K-1 support',
            default => 'Upload document',
        };
    }

    protected function suggestedDocumentType(string $primaryRequest): ?string
    {
        $normalized = strtolower($primaryRequest);

        return match (true) {
            str_contains($normalized, 'closure record') => 'entity_closure_record',
            str_contains($normalized, 'return packet') || str_contains($normalized, 'return packets') => 'tax_return',
            str_contains($normalized, 'payroll') => 'payroll_record',
            str_contains($normalized, 'payment confirmation') => 'payment_confirmation',
            str_contains($normalized, 'bank statement') => 'bank_statement',
            str_contains($normalized, 'quickbooks') || str_contains($normalized, 'trial balance') || str_contains($normalized, 'general ledger') => 'qbo_export',
            str_contains($normalized, 'basis') || str_contains($normalized, 'qbi') => 'basis_workpaper',
            str_contains($normalized, 'distribution') => 'distribution_ledger',
            str_contains($normalized, 'k-1') || str_contains($normalized, 'pass-through') => 'k1_package',
            default => null,
        };
    }

    protected function suggestedDocumentScope(string $primaryRequest): ?string
    {
        $normalized = strtolower($primaryRequest);

        return match (true) {
            str_contains($normalized, 'personal return') => 'personal',
            str_contains($normalized, 'closure record'),
            str_contains($normalized, 'business return'),
            str_contains($normalized, 'payroll'),
            str_contains($normalized, 'quickbooks'),
            str_contains($normalized, 'trial balance'),
            str_contains($normalized, 'general ledger'),
            str_contains($normalized, 'basis'),
            str_contains($normalized, 'qbi'),
            str_contains($normalized, 'distribution'),
            str_contains($normalized, 'k-1'),
            str_contains($normalized, 'pass-through'),
            str_contains($normalized, 'bank statement') => 'business',
            default => null,
        };
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array<int, string>
     */
    protected function questionRequests(Collection $gateMap, int $filingYear): array
    {
        $questions = [];

        if (! $this->gatePassed($gateMap, 'tax_profile')) {
            $questions[] = "What filing status should {$filingYear} use?";
            $questions[] = "What state and local residency applied on December 31, {$filingYear}?";
        }

        return $questions;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array<int, string>
     */
    protected function compensationQuestions(Collection $gateMap, int $filingYear): array
    {
        if (! $this->gateBlocks($gateMap, 'reasonable_compensation')) {
            return [];
        }

        return [
            "What officer salary / W-2 should the S-corp report for {$filingYear}?",
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array{label: string, href: string}|null
     */
    protected function compensationStepAction(Collection $gateMap, int $filingYear): ?array
    {
        if (! $this->gateBlocks($gateMap, 'reasonable_compensation')) {
            return null;
        }

        return [
            'label' => 'Open filing facts',
            'href' => "/life/tax-optimizer?year={$filingYear}#filing-facts",
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array{label: string, href: string}|null
     */
    protected function personalReturnStepAction(Collection $gateMap, int $filingYear): ?array
    {
        if ($this->gatePassedOrMissing($gateMap, 'personal_return_inputs')) {
            return null;
        }

        return [
            'label' => 'Answer personal-return inputs',
            'href' => "/life/tax-optimizer?year={$filingYear}#personal-return-inputs",
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array<int, string>
     */
    protected function sourceFeedQuestions(Collection $gateMap, int $filingYear): array
    {
        $questions = [];

        if (! $this->gatePassed($gateMap, 'books_available')) {
            $questions[] = "Reconnect QuickBooks so {$filingYear} books can feed the return packet.";
        }
        if (! $this->gatePassed($gateMap, 'bank_feed_available')) {
            $questions[] = "Reconnect the business bank feeds so {$filingYear} deposits and payments can tie to books.";
        }
        if (! $this->gatePassed($gateMap, 'live_projection')) {
            $questions[] = "Make sure the live projection can compute from {$filingYear} invoices, books, and payments.";
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @return array<int, string>
     */
    protected function bookkeepingQuestions(array $bookkeepingReadiness, Collection $gateMap, int $filingYear): array
    {
        $questions = collect($bookkeepingReadiness['questions'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        if ($questions !== []) {
            return $questions;
        }

        if ($this->gatePassed($gateMap, 'books_available')
            && $this->gatePassed($gateMap, 'bank_feed_available')
            && $this->gatePassed($gateMap, 'books_to_bank_tie_out')) {
            return [];
        }

        return ["Make sure {$filingYear} books are current, categorized, and tied to the bank activity before drafting returns."];
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @return array<int, string>
     */
    protected function bookkeepingDocuments(array $bookkeepingReadiness): array
    {
        return collect($bookkeepingReadiness['documents'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function bookkeepingStepComplete(array $bookkeepingReadiness, Collection $gateMap): bool
    {
        if ($bookkeepingReadiness !== []) {
            return (bool) ($bookkeepingReadiness['close_ready'] ?? false);
        }

        return $this->gatePassed($gateMap, 'books_available')
            && $this->gatePassed($gateMap, 'bank_feed_available')
            && $this->gatePassed($gateMap, 'books_to_bank_tie_out');
    }

    /**
     * @param  array<int, string>  $priorYearDocumentRequests
     * @param  array<int, string>  $priorYearQuestions
     */
    protected function priorYearStepComplete(Collection $gateMap, array $priorYearDocumentRequests, array $priorYearQuestions): bool
    {
        if ($priorYearDocumentRequests === [] && $priorYearQuestions === []) {
            return true;
        }

        return $this->gatePassed($gateMap, 'prior_year_return_context')
            && $this->gatePassedOrMissing($gateMap, 'entity_lifecycle_review');
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     */
    protected function bookkeepingStepSummary(array $bookkeepingReadiness, int $filingYear): string
    {
        if (($bookkeepingReadiness['summary'] ?? null) !== null) {
            return (string) $bookkeepingReadiness['summary'];
        }

        return "Keep {$filingYear} books current enough that the tax packet can trust them without manual cleanup.";
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>|null}|null
     */
    protected function bookkeepingStepAction(array $bookkeepingReadiness, int $filingYear): ?array
    {
        $action = $bookkeepingReadiness['action'] ?? null;

        if (! is_array($action) || ! isset($action['label'], $action['href'])) {
            return null;
        }

        $data = isset($action['data']) && is_array($action['data']) ? $action['data'] : null;

        if (($action['method'] ?? null) === 'post') {
            $data = [
                ...($data ?? []),
                'return_to' => "/life/tax-optimizer?year={$filingYear}",
            ];
        }

        return [
            'label' => (string) $action['label'],
            'href' => (string) $action['href'],
            'method' => isset($action['method']) ? (string) $action['method'] : null,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $ownerPaymentReview
     * @return array<int, string>
     */
    protected function ownerPaymentQuestions(array $ownerPaymentReview, int $filingYear): array
    {
        if (! (bool) ($ownerPaymentReview['is_applicable'] ?? false)) {
            return [];
        }

        $unresolvedCount = (int) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0);

        if ($unresolvedCount <= 0) {
            return [];
        }

        return [
            "Label the remaining {$unresolvedCount} owner-related {$filingYear} transfer item(s) so distributions, reimbursements, loans, and compensation candidates are explicit.",
        ];
    }

    /**
     * @param  array<string, mixed>  $ownerPaymentReview
     */
    protected function ownerPaymentStepComplete(array $ownerPaymentReview): bool
    {
        if (! (bool) ($ownerPaymentReview['is_applicable'] ?? false)) {
            return true;
        }

        return (bool) ($ownerPaymentReview['review_complete'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $ownerPaymentReview
     */
    protected function ownerPaymentStepSummary(array $ownerPaymentReview, int $filingYear): string
    {
        if (($ownerPaymentReview['summary'] ?? null) !== null) {
            return (string) $ownerPaymentReview['summary'];
        }

        return "Review owner-related {$filingYear} cash movement before relying on the distribution and compensation posture.";
    }

    /**
     * @param  array<string, mixed>  $ownerPaymentReview
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>|null}|null
     */
    protected function ownerPaymentStepAction(array $ownerPaymentReview, int $filingYear): ?array
    {
        if (! (bool) ($ownerPaymentReview['is_applicable'] ?? false)) {
            return null;
        }

        if ((int) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0) <= 0) {
            return null;
        }

        return [
            'label' => 'Review owner payments',
            'href' => "/life/tax-optimizer?year={$filingYear}#owner-payment-review",
            'method' => null,
            'data' => null,
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @param  array<string, mixed>  $revenueRecognition
     * @return array<int, string>
     */
    protected function reviewQuestions(Collection $gateMap, int $filingYear, array $revenueRecognition): array
    {
        $questions = [];

        if ($this->reviewFlagsNeedsRevenueAudit($gateMap, $revenueRecognition)) {
            $questions[] = "Do any {$filingYear} deposits represent transfers, loans, reimbursements, or owner contributions instead of revenue?";
        }
        if ($this->gateNeedsAttention($gateMap, 'reasonable_compensation')) {
            $questions[] = "Is the {$filingYear} salary posture aggressive but still defensible for officer compensation?";
        }
        if ($this->gateBlocks($gateMap, 'agency_payment_reconciliation')) {
            $questions[] = 'Do any recorded tax payments belong to another year or jurisdiction?';
        }
        if ($this->gateBlocks($gateMap, 'agency_notice_clearance')) {
            $questions[] = 'Are there agency notices or balances that still need to be resolved before filing?';
        }

        return $questions;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $taxDocuments
     * @return array<int, string>
     */
    protected function documentsNeedingReview(iterable $taxDocuments, int $filingYear): array
    {
        return collect($taxDocuments)
            ->filter(fn (mixed $document): bool => $this->documentRequiresSprintReview($document, $filingYear))
            ->map(fn (mixed $document): string => (string) data_get($document, 'file_name'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $documentsNeedingReview
     * @param  array<string, mixed>  $ownerPaymentReview
     * @param  array<string, mixed>  $revenueRecognition
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>|null}|null
     */
    protected function reviewFlagsAction(
        Collection $gateMap,
        int $filingYear,
        array $documentsNeedingReview,
        array $ownerPaymentReview,
        array $revenueRecognition,
    ): ?array {
        if ($this->reviewFlagsNeedsRevenueAudit($gateMap, $revenueRecognition)) {
            if ((int) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0) > 0) {
                return [
                    'label' => 'Review owner payments',
                    'href' => "/life/tax-optimizer?year={$filingYear}#owner-payment-review",
                ];
            }

            return [
                'label' => 'Open revenue audit',
                'href' => "/life/tax-optimizer?year={$filingYear}#revenue-audit",
            ];
        }

        if ($this->gateNeedsAttention($gateMap, 'reasonable_compensation')) {
            return [
                'label' => 'Open filing facts',
                'href' => "/life/tax-optimizer?year={$filingYear}#filing-facts",
            ];
        }

        if ($documentsNeedingReview !== []) {
            return [
                'label' => 'Review documents',
                'href' => '/life/documents',
            ];
        }

        return null;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @param  array<string, mixed>  $revenueRecognition
     */
    protected function reviewFlagsNeedsRevenueAudit(Collection $gateMap, array $revenueRecognition): bool
    {
        if (! $this->gateNeedsAttention($gateMap, 'books_to_bank_tie_out')) {
            return false;
        }

        if ($revenueRecognition === []) {
            return true;
        }

        return (int) data_get($revenueRecognition, 'metrics.unresolved_inflow_count', 0) > 0;
    }

    /**
     * @param  array<string, mixed>|object  $document
     */
    protected function documentRequiresSprintReview(mixed $document, int $filingYear): bool
    {
        if (! (bool) data_get($document, 'needs_review')) {
            return false;
        }

        $documentType = (string) data_get($document, 'document_type', '');

        if (in_array($documentType, ['bank_statement', 'qbo_export', 'general_ledger', 'trial_balance'], true)) {
            return false;
        }

        $documentYear = $this->taxDocumentYear($document);

        if ($documentYear !== null && $documentYear !== $filingYear) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|object  $document
     */
    protected function taxDocumentYear(mixed $document): ?int
    {
        $candidates = [
            data_get($document, 'tax_year'),
            data_get($document, 'extracted_data.tax_year'),
            data_get($document, 'extracted_data.extracted_fields.tax_year'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        $fileName = (string) data_get($document, 'file_name', '');

        if (preg_match('/\b(20\d{2})\b/', $fileName, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $taxReturnPrep
     * @param  array<string, mixed>  $artifactState
     */
    protected function generationActionLabel(array $taxReturnPrep, array $artifactState): ?string
    {
        if (! ($taxReturnPrep['can_generate_draft_forms'] ?? false)) {
            return null;
        }

        if (! ($artifactState['has_current_draft_package'] ?? false)) {
            return 'Generate draft return package';
        }

        if (! ($artifactState['has_current_signoff_packet'] ?? false)) {
            return 'Generate signoff packet';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $taxReturnPrep
     * @param  array<string, mixed>  $artifactState
     */
    protected function generationActionHref(array $taxReturnPrep, array $artifactState): ?string
    {
        if (! ($taxReturnPrep['can_generate_draft_forms'] ?? false)) {
            return null;
        }

        if (! ($artifactState['has_current_draft_package'] ?? false)) {
            return '/life/tax-forms/generate-annual-drafts';
        }

        if (! ($artifactState['has_current_signoff_packet'] ?? false)) {
            return '/life/tax-forms/generate-annual-signoff';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $taxAutopilot
     * @return array<int, array<string, string>>
     */
    protected function automaticSources(array $taxAutopilot): array
    {
        return collect($taxAutopilot['source_feeds'] ?? [])
            ->filter(fn (array $feed): bool => in_array($feed['code'] ?? '', ['quickbooks', 'bank_accounts', 'financial_archive'], true))
            ->map(fn (array $feed): array => [
                'code' => (string) $feed['code'],
                'title' => (string) $feed['title'],
                'status' => (string) $feed['status'],
                'status_label' => (string) $feed['status_label'],
                'detail' => (string) $feed['detail'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $automaticSources
     */
    protected function sourceSummary(array $automaticSources): string
    {
        $quickbooksReady = collect($automaticSources)->contains(fn (array $source): bool => $source['code'] === 'quickbooks' && $this->sourceIsHealthy($source['status']));
        $bankReady = collect($automaticSources)->contains(fn (array $source): bool => $source['code'] === 'bank_accounts' && $this->sourceIsHealthy($source['status']));

        if ($quickbooksReady && $bankReady) {
            return 'Live QuickBooks and bank feeds are primary. Statements and exports are only needed when a feed is missing or the tie-out needs backup.';
        }

        return 'The filing sprint will ask for manual support only when a live source is missing, stale, or contradicted.';
    }

    /**
     * @param  array<int, array<string, string>>  $automaticSources
     * @return array<int, string>
     */
    protected function sourceHighlights(array $automaticSources): array
    {
        return collect($automaticSources)
            ->filter(fn (array $source): bool => $this->sourceIsHealthy($source['status']))
            ->map(fn (array $source): string => $source['title'])
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     checks: array<int, array{label: string, status: string, status_label: string}>
     * }
     */
    protected function sourceIntegrity(Collection $gateMap, array $bookkeepingReadiness = []): array
    {
        $hasBooks = $this->gatePassed($gateMap, 'books_available');
        $hasBankFeed = $this->gatePassed($gateMap, 'bank_feed_available');
        $hasProjection = $this->gatePassed($gateMap, 'live_projection');
        $booksToBankPassed = $this->gatePassed($gateMap, 'books_to_bank_tie_out');
        $booksToBankNeedsAttention = $this->gateNeedsAttention($gateMap, 'books_to_bank_tie_out');
        $bookkeepingReady = ($bookkeepingReadiness['close_ready'] ?? null) === null
            ? true
            : (bool) ($bookkeepingReadiness['close_ready'] ?? false);

        $status = match (true) {
            ! $hasBooks || ! $hasBankFeed => 'needs_reconnect',
            ! $hasProjection || $booksToBankNeedsAttention || ! $bookkeepingReady => 'needs_review',
            default => 'ready',
        };

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Ready',
                'needs_review' => 'Needs review',
                default => 'Needs reconnect',
            },
            'summary' => match ($status) {
                'ready' => 'QuickBooks, bank feeds, bookkeeping close, live projection, and the books-to-bank tie-out are all in place for drafting.',
                'needs_review' => 'The live connections are present, but the packet still needs bookkeeping cleanup, a projection refresh, or a books-to-bank review before it is trustworthy.',
                default => 'One or more live ledgers are missing, so the packet is leaning on manual uploads until the feeds are reconnected.',
            },
            'checks' => [
                $this->sourceIntegrityCheck('QuickBooks', $hasBooks ? 'connected' : 'not_connected', $hasBooks ? 'Connected' : 'Reconnect needed'),
                $this->sourceIntegrityCheck('Bank feeds', $hasBankFeed ? 'connected' : 'not_connected', $hasBankFeed ? 'Connected' : 'Reconnect needed'),
                $this->sourceIntegrityCheck(
                    'Bookkeeping close',
                    $bookkeepingReady ? 'passed' : 'needs_review',
                    $bookkeepingReady ? 'Ready' : 'Needs cleanup',
                ),
                $this->sourceIntegrityCheck('Live projection', $hasProjection ? 'passed' : 'needs_review', $hasProjection ? 'Computed' : 'Needs refresh'),
                $this->sourceIntegrityCheck(
                    'Books-to-bank tie-out',
                    $booksToBankPassed ? 'passed' : ($booksToBankNeedsAttention ? 'needs_review' : 'not_ready'),
                    $booksToBankPassed ? 'Within tolerance' : ($booksToBankNeedsAttention ? 'Needs review' : 'Waiting on data'),
                ),
            ],
        ];
    }

    /**
     * @return array{label: string, status: string, status_label: string}
     */
    protected function sourceIntegrityCheck(string $label, string $status, string $statusLabel): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array{title: string, status_label: string}>
     */
    protected function upcomingSteps(array $steps): array
    {
        return collect($steps)
            ->filter(fn (array $step): bool => in_array($step['status'], ['upcoming'], true))
            ->take(2)
            ->map(fn (array $step): array => [
                'title' => (string) $step['title'],
                'status_label' => (string) $step['status_label'],
            ])
            ->values()
            ->all();
    }

    protected function sourceIsHealthy(string $status): bool
    {
        return in_array($status, ['ready', 'running', 'connected', 'complete', 'ready_to_prepare', 'passed'], true);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $sourceFacts
     */
    protected function sourceFactIsReady(Collection $sourceFacts, string $key): bool
    {
        return ($sourceFacts->get($key)['status'] ?? null) === 'ready';
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function gatePassed(Collection $gateMap, string $code): bool
    {
        return ($gateMap->get($code)['status'] ?? null) === 'passed';
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function gatePassedOrMissing(Collection $gateMap, string $code): bool
    {
        if (! $gateMap->has($code)) {
            return true;
        }

        return $this->gatePassed($gateMap, $code);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function gateBlocks(Collection $gateMap, string $code): bool
    {
        return (bool) ($gateMap->get($code)['blocks_packet'] ?? false);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $gateMap
     */
    protected function gateNeedsAttention(Collection $gateMap, string $code): bool
    {
        $gate = $gateMap->get($code);

        if (! is_array($gate)) {
            return false;
        }

        return ($gate['status'] ?? 'passed') !== 'passed';
    }

    /**
     * @param  array<string, mixed>  $currentStep
     */
    protected function primaryMessage(int $daysUntilDeadline, array $currentStep): string
    {
        if (($currentStep['code'] ?? null) === 'done') {
            return 'The filing sprint is complete. Review the generated packet and file manually.';
        }

        if ($daysUntilDeadline <= 7) {
            return "There is no room for a broad dashboard here. Finish \"{$currentStep['title']}\" first.";
        }

        return "Start with \"{$currentStep['title']}\". Everything else can wait until that step is clear.";
    }
}
