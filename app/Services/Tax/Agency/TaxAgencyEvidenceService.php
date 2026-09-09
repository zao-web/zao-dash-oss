<?php

namespace App\Services\Tax\Agency;

use App\Models\EstimatedTaxPayment;
use App\Models\FinancialDocument;
use App\Models\TaxAgencyAccountState;
use App\Models\TaxAgencyConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaxAgencyEvidenceService
{
    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     has_required_connections: bool,
     *     has_synced_coverage: bool,
     *     active_connection_count: int,
     *     synced_connection_count: int,
     *     open_notice_count: int,
     *     transcript_count: int,
     *     acceptance_count: int,
     *     portal_payment_totals: array{federal: float, state_or: float, total: float},
     *     internal_payment_totals: array{federal: float, state_or: float, local_portland: float, local_multnomah: float, total: float},
     *     payment_reconciliation: array<string, mixed>,
     *     agencies: array<string, array<string, mixed>>,
     * }
     */
    public function summarize(int $userId, int $year): array
    {
        $connections = TaxAgencyConnection::where('user_id', $userId)
            ->get()
            ->keyBy('agency_code');
        $states = TaxAgencyAccountState::where('user_id', $userId)
            ->where(function ($query) use ($year): void {
                $query->whereNull('tax_year')
                    ->orWhere('tax_year', $year);
            })
            ->get();
        $documents = FinancialDocument::where('user_id', $userId)
            ->where('file_path', 'like', "tax-agency/{$userId}/%")
            ->get()
            ->filter(function (FinancialDocument $document) use ($year): bool {
                $documentYear = data_get($document->extracted_data, 'tax_year');

                return $documentYear === null || (int) $documentYear === $year;
            })
            ->values();

        $requiredAgencies = [
            TaxAgencyConnection::AGENCY_IRS,
            TaxAgencyConnection::AGENCY_OREGON_DOR,
        ];

        $agencies = collect($requiredAgencies)
            ->mapWithKeys(fn (string $agencyCode): array => [
                $agencyCode => $this->agencySummary(
                    $connections->get($agencyCode),
                    $agencyCode,
                    $states->where('agency_code', $agencyCode)->values(),
                    $documents->filter(
                        fn (FinancialDocument $document): bool => data_get($document->extracted_data, 'agency_code') === $agencyCode
                            || Str::contains($document->file_path, "/{$agencyCode}/")
                    )->values(),
                ),
            ])
            ->all();

        $internalPaymentTotals = EstimatedTaxPayment::ytdPaymentsByJurisdiction($userId, $year);
        $portalPaymentTotals = [
            'federal' => (float) ($agencies[TaxAgencyConnection::AGENCY_IRS]['payment_total'] ?? 0),
            'state_or' => (float) ($agencies[TaxAgencyConnection::AGENCY_OREGON_DOR]['payment_total'] ?? 0),
        ];
        $portalPaymentTotals['total'] = round($portalPaymentTotals['federal'] + $portalPaymentTotals['state_or'], 2);

        $paymentReconciliation = $this->paymentReconciliation($internalPaymentTotals, $portalPaymentTotals, $agencies);
        $activeConnectionCount = collect($agencies)->where('is_active', true)->count();
        $syncedConnectionCount = collect($agencies)->where('is_synced', true)->count();
        $hasRequiredConnections = collect($requiredAgencies)->every(
            fn (string $agencyCode): bool => (bool) ($agencies[$agencyCode]['is_active'] ?? false)
        );
        $hasSyncedCoverage = collect($requiredAgencies)->every(
            fn (string $agencyCode): bool => (bool) ($agencies[$agencyCode]['is_synced'] ?? false)
        );
        $openNoticeCount = (int) collect($agencies)->sum('open_notice_count');
        $transcriptCount = (int) collect($agencies)->sum('transcript_count');
        $acceptanceCount = (int) collect($agencies)->sum('acceptance_count');

        $status = match (true) {
            $hasSyncedCoverage => 'current',
            $activeConnectionCount > 0 => 'partial',
            default => 'missing',
        };

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'current' => 'Agency evidence current',
                'partial' => 'Agency evidence partial',
                default => 'Agency evidence missing',
            },
            'has_required_connections' => $hasRequiredConnections,
            'has_synced_coverage' => $hasSyncedCoverage,
            'active_connection_count' => $activeConnectionCount,
            'synced_connection_count' => $syncedConnectionCount,
            'open_notice_count' => $openNoticeCount,
            'transcript_count' => $transcriptCount,
            'acceptance_count' => $acceptanceCount,
            'portal_payment_totals' => $portalPaymentTotals,
            'internal_payment_totals' => $internalPaymentTotals,
            'payment_reconciliation' => $paymentReconciliation,
            'agencies' => $agencies,
        ];
    }

    /**
     * @param  Collection<int, TaxAgencyAccountState>  $states
     * @param  Collection<int, FinancialDocument>  $documents
     * @return array<string, mixed>
     */
    protected function agencySummary(
        ?TaxAgencyConnection $connection,
        string $agencyCode,
        Collection $states,
        Collection $documents,
    ): array {
        $latestBalanceState = $states
            ->filter(fn (TaxAgencyAccountState $state): bool => $state->record_type === 'balance' && $state->amount !== null)
            ->sortByDesc(fn (TaxAgencyAccountState $state): string => optional($state->due_date)->toDateString() ?? optional($state->effective_date)->toDateString() ?? '')
            ->first();
        $paymentStates = $states
            ->filter(fn (TaxAgencyAccountState $state): bool => Str::contains($state->record_type, 'payment'))
            ->values();
        $openNoticeCount = $documents
            ->whereIn('document_type', ['irs_notice', 'state_tax_notice'])
            ->where('needs_review', true)
            ->count();
        $transcriptCount = $documents
            ->whereIn('document_type', ['tax_transcript', 'account_transcript'])
            ->count();
        $acceptanceCount = $documents
            ->whereIn('document_type', ['efile_acceptance', 'filing_acceptance', 'extension_acceptance'])
            ->count();

        return [
            'agency_code' => $agencyCode,
            'agency_name' => $connection?->agency_name,
            'portal_name' => $connection?->portal_name,
            'is_active' => $connection?->status === TaxAgencyConnection::STATUS_ACTIVE,
            'is_synced' => $connection?->status === TaxAgencyConnection::STATUS_ACTIVE && $connection?->last_synced_at !== null,
            'latest_balance_amount' => $latestBalanceState?->amount !== null ? round((float) $latestBalanceState->amount, 2) : null,
            'latest_balance_status' => $latestBalanceState?->status,
            'payment_total' => round(
                $paymentStates->reduce(
                    fn (float $total, TaxAgencyAccountState $state): float => $total + abs((float) ($state->amount ?? 0)),
                    0.0,
                ),
                2,
            ),
            'payment_count' => $paymentStates->count(),
            'has_payment_history' => $paymentStates->isNotEmpty(),
            'open_notice_count' => $openNoticeCount,
            'transcript_count' => $transcriptCount,
            'acceptance_count' => $acceptanceCount,
        ];
    }

    /**
     * @param  array{federal: float, state_or: float, local_portland: float, local_multnomah: float, total: float}  $internalPaymentTotals
     * @param  array{federal: float, state_or: float, total: float}  $portalPaymentTotals
     * @param  array<string, array<string, mixed>>  $agencies
     * @return array<string, mixed>
     */
    protected function paymentReconciliation(array $internalPaymentTotals, array $portalPaymentTotals, array $agencies): array
    {
        $federal = $this->paymentComparison(
            $internalPaymentTotals['federal'],
            $portalPaymentTotals['federal'],
            (bool) ($agencies[TaxAgencyConnection::AGENCY_IRS]['has_payment_history'] ?? false),
        );
        $oregon = $this->paymentComparison(
            $internalPaymentTotals['state_or'],
            $portalPaymentTotals['state_or'],
            (bool) ($agencies[TaxAgencyConnection::AGENCY_OREGON_DOR]['has_payment_history'] ?? false),
        );
        $hasPortalHistory = $federal['has_portal_history'] || $oregon['has_portal_history'];
        $blocking = $federal['matches'] === false || $oregon['matches'] === false;
        $expectedHistoriesPresent = collect([
            TaxAgencyConnection::AGENCY_IRS,
            TaxAgencyConnection::AGENCY_OREGON_DOR,
        ])->every(function (string $agencyCode) use ($agencies): bool {
            $agency = $agencies[$agencyCode] ?? [];

            if (($agency['is_synced'] ?? false) !== true) {
                return true;
            }

            return ($agency['has_payment_history'] ?? false) === true;
        });

        return [
            'status' => match (true) {
                ! $hasPortalHistory => 'needs_review',
                $blocking => 'needs_evidence',
                ! $expectedHistoriesPresent => 'needs_review',
                default => 'passed',
            },
            'status_label' => match (true) {
                ! $hasPortalHistory => 'Review needed',
                $blocking => 'Blocking',
                ! $expectedHistoriesPresent => 'Review needed',
                default => 'Passed',
            },
            'blocking' => $blocking,
            'federal' => $federal,
            'state_or' => $oregon,
        ];
    }

    /**
     * @return array{
     *     has_portal_history: bool,
     *     internal: float,
     *     portal: float|null,
     *     difference: float|null,
     *     tolerance: float|null,
     *     matches: bool|null
     * }
     */
    protected function paymentComparison(float $internalAmount, float $portalAmount, bool $hasPortalHistory): array
    {
        if (! $hasPortalHistory) {
            return [
                'has_portal_history' => false,
                'internal' => round($internalAmount, 2),
                'portal' => null,
                'difference' => null,
                'tolerance' => null,
                'matches' => null,
            ];
        }

        $difference = round(abs($internalAmount - $portalAmount), 2);
        $tolerance = round(max(25.0, max(abs($internalAmount), abs($portalAmount)) * 0.01), 2);

        return [
            'has_portal_history' => true,
            'internal' => round($internalAmount, 2),
            'portal' => round($portalAmount, 2),
            'difference' => $difference,
            'tolerance' => $tolerance,
            'matches' => $difference <= $tolerance,
        ];
    }
}
