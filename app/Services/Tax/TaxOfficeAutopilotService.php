<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use App\Models\PersonalAccount;
use App\Models\PlaidConnection;
use App\Models\QuickBooksConnection;
use App\Models\TaxCalendarEvent;
use App\Models\TaxProfile;
use App\Models\TaxStrategy;

class TaxOfficeAutopilotService
{
    public function __construct(
        protected FinancialArchiveEvidenceService $financialArchiveEvidenceService,
    ) {}

    /**
     * Build the operating model for the tax autopilot.
     *
     * @return array{
     *     mode: string,
     *     mission: string,
     *     status: string,
     *     status_label: string,
     *     primary_next_step: string,
     *     approval_policy: array<string, mixed>,
     *     source_feeds: array<int, array<string, mixed>>,
     *     workstreams: array<int, array<string, mixed>>,
     *     filing_packages: array<int, array<string, mixed>>,
     * }
     */
    public function build(
        int $userId,
        int $year,
        ?array $annualProjection = null,
        ?array $taxConfidenceReview = null,
        ?array $taxReturnPrep = null,
        ?array $taxAgencyPortals = null,
    ): array {
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->first();
        $qboConnections = QuickBooksConnection::where('user_id', $userId)->get();
        $activeQboConnections = QuickBooksConnection::active()->where('user_id', $userId)->get();
        $plaidConnections = PlaidConnection::where('user_id', $userId)->get();
        $activePlaidConnections = PlaidConnection::active()->where('user_id', $userId)->get();
        $activeBankAccountCount = PersonalAccount::where('user_id', $userId)->active()->count();

        $qboConnectionIds = $qboConnections->pluck('id');
        $activeStrategyCount = TaxStrategy::whereIn('qbo_connection_id', $qboConnectionIds)
            ->where('tax_year', $year)
            ->active()
            ->count();
        $openDeadlineCount = TaxCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('status', '!=', TaxCalendarEvent::STATUS_COMPLETED)
            ->count();
        $taxDocumentCount = FinancialDocument::where('user_id', $userId)
            ->whereIn('document_type', [
                'tax_return',
                'tax_return_workpaper',
                'irs_notice',
                'state_tax_notice',
                'payment_confirmation',
                'efile_acceptance',
                'filing_acceptance',
                'tax_transcript',
                'account_transcript',
                'k1_package',
                'basis_workpaper',
                'payroll_record',
                'w2_packet',
                'distribution_ledger',
                'bank_statement',
                'qbo_export',
                'general_ledger',
                'trial_balance',
            ])
            ->count();

        $hasBooks = $activeQboConnections->isNotEmpty();
        $hasBankFeed = $activePlaidConnections->isNotEmpty() || $activeBankAccountCount > 0;
        $hasSourceData = $hasBooks && $hasBankFeed;
        $hasProfile = $profile !== null;
        $hasLiveProjection = $annualProjection !== null
            && (
                (float) ($annualProjection['projected_annual_revenue'] ?? 0) > 0
                || (float) ($annualProjection['total_tax_liability'] ?? 0) > 0
            );
        $financialArchive = $this->financialArchiveEvidenceService->summarize($year, $userId);

        return [
            'mode' => 'autopilot_with_owner_signoff',
            'mission' => 'Prepare tax decisions and filing packages from source financial data, then ask for owner approval before filing, paying, exporting, or changing records.',
            'status' => $this->overallStatus($hasProfile, $hasSourceData, $hasLiveProjection),
            'status_label' => $this->overallStatusLabel($hasProfile, $hasSourceData, $hasLiveProjection),
            'primary_next_step' => $this->primaryNextStep($hasProfile, $hasSourceData, $hasLiveProjection),
            'approval_policy' => [
                'preparation_posture' => 'Forensic CPA accuracy with source-linked workpapers.',
                'strategy_posture' => 'CFO-aggressive positions that remain source-defensible.',
                'system_can_prepare' => [
                    'Live tax position',
                    'Estimated payment recommendations',
                    'Draft vouchers and filing packages',
                    'Source-to-field explanations',
                ],
                'owner_approval_required_for' => [
                    'Filing returns',
                    'Making tax payments',
                    'Submitting portal messages or documents',
                    'Exporting or changing accounting records',
                ],
            ],
            'source_feeds' => $this->sourceFeeds(
                $qboConnections->count(),
                $activeQboConnections->max('last_synced_at')?->toIso8601String(),
                $plaidConnections->count(),
                $activePlaidConnections->max('last_synced_at')?->toIso8601String(),
                $activeBankAccountCount,
                $hasProfile,
                $taxAgencyPortals,
                $financialArchive,
            ),
            'workstreams' => [
                $this->liveTaxPositionWorkstream($annualProjection, $hasProfile, $hasSourceData, $hasLiveProjection),
                $this->filingPrepWorkstream($annualProjection, $hasProfile, $hasSourceData, $hasLiveProjection),
                $this->strategyWorkstream($activeStrategyCount, $hasLiveProjection),
                $this->filedEvidenceWorkstream($taxConfidenceReview, $taxDocumentCount, $openDeadlineCount),
            ],
            'filing_packages' => [
                $this->estimatedTaxPackage($annualProjection, $hasProfile, $hasLiveProjection),
                $this->annualReturnPackage($hasProfile, $hasSourceData, $taxReturnPrep),
            ],
        ];
    }

    protected function overallStatus(bool $hasProfile, bool $hasSourceData, bool $hasLiveProjection): string
    {
        if (! $hasProfile || ! $hasSourceData) {
            return 'needs_source_inputs';
        }

        return $hasLiveProjection ? 'running' : 'needs_projection';
    }

    protected function overallStatusLabel(bool $hasProfile, bool $hasSourceData, bool $hasLiveProjection): string
    {
        if (! $hasProfile) {
            return 'Tax profile needed';
        }

        if (! $hasSourceData) {
            return 'Source feeds needed';
        }

        return $hasLiveProjection ? 'Autopilot running' : 'Projection needed';
    }

    protected function primaryNextStep(bool $hasProfile, bool $hasSourceData, bool $hasLiveProjection): string
    {
        if (! $hasProfile) {
            return 'Complete the tax profile so the system can map entity type, filing status, Oregon residency, salary, dependents, and prior-year safe harbor facts.';
        }

        if (! $hasSourceData) {
            return 'Connect or refresh QuickBooks and bank feeds so the system can reconcile books, deposits, payments, owner transfers, and deductions.';
        }

        if (! $hasLiveProjection) {
            return 'Sync paid invoices, bank transactions, and budget categories until the live tax projection has enough source data to compute liability.';
        }

        return 'Review the current projection and generate the next estimated-payment package for owner sign-off.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sourceFeeds(
        int $qboConnectionCount,
        ?string $qboLastSyncedAt,
        int $plaidConnectionCount,
        ?string $plaidLastSyncedAt,
        int $activeBankAccountCount,
        bool $hasProfile,
        ?array $taxAgencyPortals = null,
        ?array $financialArchive = null,
    ): array {
        $agencyFeed = $this->agencyPortalFeed($taxAgencyPortals);
        $archiveFeed = $this->financialArchiveFeed($financialArchive);

        return [
            [
                'code' => 'tax_profile',
                'title' => 'Tax profile',
                'status' => $hasProfile ? 'ready' : 'needs_input',
                'status_label' => $hasProfile ? 'Ready' : 'Needs input',
                'detail' => $hasProfile
                    ? 'Entity, filing status, Oregon residency, salary, safe harbor, and deduction profile are available.'
                    : 'Required before final form mapping and source-to-field workpapers can be trusted.',
            ],
            [
                'code' => 'quickbooks',
                'title' => 'QuickBooks books',
                'status' => $qboConnectionCount > 0 ? ($qboLastSyncedAt ? 'connected' : 'needs_refresh') : 'not_connected',
                'status_label' => $qboConnectionCount > 0 ? ($qboLastSyncedAt ? 'Connected' : 'Needs refresh') : 'Not connected',
                'detail' => $qboConnectionCount > 0
                    ? 'Book income, invoices, and accounting categories can feed projections and return workpapers.'
                    : 'Connect QuickBooks before treating books as a return source.',
                'last_synced_at' => $qboLastSyncedAt,
            ],
            [
                'code' => 'bank_accounts',
                'title' => 'Bank accounts',
                'status' => $plaidConnectionCount > 0 || $activeBankAccountCount > 0 ? ($plaidLastSyncedAt ? 'connected' : 'needs_refresh') : 'not_connected',
                'status_label' => $plaidConnectionCount > 0 || $activeBankAccountCount > 0 ? ($plaidLastSyncedAt ? 'Connected' : 'Needs refresh') : 'Not connected',
                'detail' => $activeBankAccountCount > 0
                    ? "{$activeBankAccountCount} active account(s) can support payment proof, deposit tie-out, and owner-transfer review."
                    : 'Bank feeds are required for a forensic books-to-bank tie-out.',
                'last_synced_at' => $plaidLastSyncedAt,
            ],
            [
                'code' => 'agency_portals',
                'title' => 'IRS and Oregon portals',
                'status' => $agencyFeed['status'],
                'status_label' => $agencyFeed['status_label'],
                'detail' => $agencyFeed['detail'],
            ],
            [
                'code' => 'financial_archive',
                'title' => 'Financial archive',
                'status' => $archiveFeed['status'],
                'status_label' => $archiveFeed['status_label'],
                'detail' => $archiveFeed['detail'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $taxAgencyPortals
     * @return array{status: string, status_label: string, detail: string}
     */
    protected function agencyPortalFeed(?array $taxAgencyPortals): array
    {
        if ($taxAgencyPortals === null) {
            return [
                'status' => 'connector_needed',
                'status_label' => 'Connector needed',
                'detail' => 'Transcript, notice, and portal-message automation needs an explicit IRS/Oregon connector with owner-approved authentication.',
            ];
        }

        $status = $taxAgencyPortals['status'] ?? 'needs_connections';
        $activeCount = (int) ($taxAgencyPortals['active_count'] ?? 0);
        $connectedCount = (int) ($taxAgencyPortals['connected_count'] ?? 0);

        return match ($status) {
            'connected' => [
                'status' => 'connected',
                'status_label' => 'Connected',
                'detail' => "{$connectedCount} portal connection(s) have pulled balances, notices, transcripts, or payment history into Tax Office.",
            ],
            'syncing' => [
                'status' => 'connected',
                'status_label' => 'Syncing',
                'detail' => 'IRS/Oregon portal automation is running in the background and updating Tax Office evidence.',
            ],
            'partial' => [
                'status' => 'needs_refresh',
                'status_label' => 'Partial',
                'detail' => "{$activeCount} portal connection(s) are active. Finish the missing agency connection so evidence coverage stays complete.",
            ],
            'needs_auth' => [
                'status' => 'needs_refresh',
                'status_label' => 'Needs auth',
                'detail' => 'Portal connections exist, but authenticated bridge access still needs to be refreshed before automatic pulls can run.',
            ],
            default => [
                'status' => 'connector_needed',
                'status_label' => 'Connector needed',
                'detail' => 'Add IRS and Oregon portal connections so notices, balances, payment history, and transcripts can be pulled automatically.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>|null  $financialArchive
     * @return array{status: string, status_label: string, detail: string}
     */
    protected function financialArchiveFeed(?array $financialArchive): array
    {
        if ($financialArchive === null || ! ($financialArchive['is_available'] ?? false)) {
            return [
                'status' => 'needs_input',
                'status_label' => 'Needs input',
                'detail' => (string) ($financialArchive['next_action'] ?? 'Upload prior returns, bank statements, and payroll packets into Documents so Tax Office can use them as historical evidence.'),
            ];
        }

        $coverageStatus = $financialArchive['coverage_status'] ?? 'missing';
        $businessYears = $financialArchive['prior_business_return_years'] ?? [];
        $personalYears = $financialArchive['prior_personal_return_years'] ?? [];
        $payrollDocumentCount = (int) ($financialArchive['payroll_document_count'] ?? 0);
        $currentYearDocumentCount = (int) ($financialArchive['current_year_document_count'] ?? 0);
        $currentYearCoverageStatus = (string) ($financialArchive['current_year_coverage_status'] ?? 'missing');
        $currentYearLabel = $currentYearCoverageStatus === 'current' ? 'current-year archive coverage' : 'current-year archive gap';

        return match ($coverageStatus) {
            'current' => [
                'status' => 'connected',
                'status_label' => 'Current',
                'detail' => "Prior returns, bank statements, and payroll filings are inventoried from the Financials archive and available as supporting evidence. {$currentYearDocumentCount} {$currentYearLabel} file(s) detected.",
            ],
            'partial' => [
                'status' => 'needs_refresh',
                'status_label' => 'Partial',
                'detail' => sprintf(
                    'Financial archive inventory found business returns for %s, personal returns for %s, %d payroll document(s), and %d %s file(s). %s',
                    $this->yearSummary($businessYears),
                    $this->yearSummary($personalYears),
                    $payrollDocumentCount,
                    $currentYearDocumentCount,
                    $currentYearLabel,
                    (string) ($financialArchive['next_action'] ?? 'Fill the remaining archive gaps.'),
                ),
            ],
            default => [
                'status' => 'needs_input',
                'status_label' => 'Needs input',
                'detail' => (string) ($financialArchive['next_action'] ?? 'Financial archive evidence has not been inventoried yet.'),
            ],
        };
    }

    /**
     * @param  array<int, int>  $years
     */
    protected function yearSummary(array $years): string
    {
        if ($years === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (int $year): string => (string) $year, $years));
    }

    protected function liveTaxPositionWorkstream(?array $annualProjection, bool $hasProfile, bool $hasSourceData, bool $hasLiveProjection): array
    {
        return [
            'code' => 'live_tax_position',
            'title' => 'Live Tax Position',
            'status' => $hasLiveProjection ? 'ready' : 'needs_inputs',
            'status_label' => $hasLiveProjection ? 'Running' : 'Needs inputs',
            'blocks_live_position' => ! $hasLiveProjection,
            'blocks_filing_prep' => ! $hasProfile || ! $hasSourceData,
            'intent' => 'Keep liability, safe harbor, quarterly payments, and cash impact current without waiting for filed-return evidence.',
            'next_action' => $hasLiveProjection
                ? 'Use the live projection to drive estimated payments and current-year planning.'
                : $this->primaryNextStep($hasProfile, $hasSourceData, false),
            'outputs' => $hasLiveProjection ? [
                ['label' => 'YTD revenue', 'value' => $this->currency((float) ($annualProjection['ytd_revenue'] ?? 0))],
                ['label' => 'Projected annual revenue', 'value' => $this->currency((float) ($annualProjection['projected_annual_revenue'] ?? 0))],
                ['label' => 'Projected tax liability', 'value' => $this->currency((float) ($annualProjection['total_tax_liability'] ?? 0))],
                ['label' => 'Remaining due', 'value' => $this->currency((float) ($annualProjection['remaining_tax_due'] ?? 0))],
            ] : [],
        ];
    }

    protected function filingPrepWorkstream(?array $annualProjection, bool $hasProfile, bool $hasSourceData, bool $hasLiveProjection): array
    {
        $ready = $hasProfile && $hasSourceData && $hasLiveProjection;

        return [
            'code' => 'filing_prep',
            'title' => 'Filing Prep and Form Assembly',
            'status' => $ready ? 'ready_to_prepare' : 'needs_inputs',
            'status_label' => $ready ? 'Ready to prepare' : 'Needs inputs',
            'blocks_live_position' => false,
            'blocks_filing_prep' => ! $ready,
            'intent' => 'Map source facts into draft forms, workpapers, and owner sign-off packets before any external filing or payment.',
            'next_action' => $ready
                ? 'Generate the next estimated-tax vouchers, then attach source explanations and owner approval.'
                : 'Complete profile, books, bank feeds, and live projection before preparing form packages.',
            'outputs' => $ready ? [
                ['label' => 'Current package', 'value' => '1040-ES and OR-40-V vouchers'],
                ['label' => 'Form basis', 'value' => $this->currency((float) ($annualProjection['remaining_tax_due'] ?? 0)).' remaining due'],
            ] : [],
        ];
    }

    protected function strategyWorkstream(int $activeStrategyCount, bool $hasLiveProjection): array
    {
        return [
            'code' => 'strategy_optimization',
            'title' => 'Strategy and Optimization',
            'status' => $hasLiveProjection ? 'running' : 'waiting_on_projection',
            'status_label' => $hasLiveProjection ? 'Running' : 'Waiting on projection',
            'blocks_live_position' => false,
            'blocks_filing_prep' => false,
            'intent' => 'Find favorable, source-defensible positions before year-end choices become irreversible.',
            'next_action' => $activeStrategyCount > 0
                ? 'Review active strategies and convert approved actions into filing assumptions.'
                : 'Run strategy discovery after the live projection is current.',
            'outputs' => [
                ['label' => 'Active strategies', 'value' => (string) $activeStrategyCount],
            ],
        ];
    }

    protected function filedEvidenceWorkstream(?array $taxConfidenceReview, int $taxDocumentCount, int $openDeadlineCount): array
    {
        $confidencePercent = (int) ($taxConfidenceReview['confidence_percent'] ?? 0);

        return [
            'code' => 'filed_return_evidence',
            'title' => 'Filed Return Evidence',
            'status' => $confidencePercent === 100 ? 'complete' : 'evidence_gap',
            'status_label' => $confidencePercent === 100 ? 'Complete' : 'Evidence gap',
            'blocks_live_position' => false,
            'blocks_filing_prep' => false,
            'intent' => 'Preserve proof after filing and close prior-year questions without blocking current-year calculations.',
            'next_action' => $taxConfidenceReview['current_gate']['action']
                ?? 'Attach return packets, payment proof, acceptance proof, transcripts, and issue-clearance records as they become available.',
            'outputs' => [
                ['label' => 'Evidence confidence', 'value' => "{$confidencePercent}%"],
                ['label' => 'Evidence documents', 'value' => (string) $taxDocumentCount],
                ['label' => 'Open deadlines', 'value' => (string) $openDeadlineCount],
            ],
        ];
    }

    protected function estimatedTaxPackage(?array $annualProjection, bool $hasProfile, bool $hasLiveProjection): array
    {
        $ready = $hasProfile && $hasLiveProjection;

        return [
            'code' => 'estimated_tax_package',
            'title' => 'Quarterly estimated tax package',
            'status' => $ready ? 'ready_to_generate' : 'needs_inputs',
            'status_label' => $ready ? 'Ready to generate' : 'Needs inputs',
            'forms' => ['1040-ES', 'OR-40-V'],
            'source_basis' => ['Tax profile', 'Live annual projection', 'Estimated payments', 'Oregon residency'],
            'owner_signoff_required' => true,
            'next_action' => $ready
                ? 'Generate vouchers and review the source-to-field explanation before payment.'
                : 'Complete tax profile and live projection first.',
            'amount_basis' => $this->currency((float) ($annualProjection['remaining_tax_due'] ?? 0)),
        ];
    }

    protected function annualReturnPackage(bool $hasProfile, bool $hasSourceData, ?array $taxReturnPrep): array
    {
        $readyForMapping = $hasProfile && $hasSourceData;
        $hasPrepPacket = $taxReturnPrep !== null;
        $status = $readyForMapping ? 'mapper_next' : 'needs_inputs';
        $statusLabel = $readyForMapping ? 'Mapper next' : 'Needs inputs';
        $nextAction = $readyForMapping
            ? 'Build deterministic field mappers and source-linked workpapers for annual return assembly.'
            : 'Connect profile, books, and bank feeds before annual return mapping.';

        if ($hasPrepPacket) {
            $status = $taxReturnPrep['status'];
            $statusLabel = $taxReturnPrep['status_label'];
            $nextAction = $taxReturnPrep['next_action'];
        }

        return [
            'code' => 'annual_return_package',
            'title' => 'Annual return package',
            'status' => $status,
            'status_label' => $statusLabel,
            'forms' => ['1040', '1120-S/K-1 bridge', 'Oregon OR-40', 'local tax workpapers'],
            'source_basis' => ['Books-to-bank tie-out', 'Payroll and reasonable compensation', 'Owner distributions', 'Prior-year safe harbor'],
            'owner_signoff_required' => true,
            'next_action' => $nextAction,
            'readiness_percent' => $taxReturnPrep['readiness_percent'] ?? 0,
            'mapped_field_count' => $taxReturnPrep['mapped_field_count'] ?? 0,
            'total_field_count' => $taxReturnPrep['total_field_count'] ?? 0,
        ];
    }

    protected function currency(float $amount): string
    {
        return '$'.number_format($amount, 0);
    }
}
