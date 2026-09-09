<?php

namespace App\Services\Tax\Agency;

use App\Models\FinancialDocument;
use App\Models\TaxAgencyAccountState;
use App\Models\TaxAgencyConnection;
use App\Services\Tax\Agency\Contracts\TaxAgencyPortalConnector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TaxAgencySyncService
{
    public function __construct(
        protected IrsOnlineAccountConnector $irsConnector,
        protected OregonRevenueOnlineConnector $oregonConnector,
        protected TaxAgencyCredentialVaultService $taxAgencyCredentialVaultService,
    ) {}

    /**
     * @return array{
     *     balance_count: int,
     *     document_count: int,
     *     latest_balance_amount: float|null,
     *     open_notice_count: int,
     *     transcript_count: int,
     * }
     */
    public function syncConnection(TaxAgencyConnection $connection, int $year): array
    {
        $result = $this->connectorFor($connection)->pull($connection, $year);
        $summaryPayload = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $balanceCount = $this->persistBalances($connection, $year, $result['balances'] ?? []);
        $documentCount = $this->persistDocuments($connection, $year, $result['documents'] ?? []);
        $summary = $this->connectionSummary($connection, $year);

        $authPayload = $connection->auth_payload ?? [];
        if (is_array($summaryPayload['session_bundle'] ?? null)) {
            $authPayload = $this->taxAgencyCredentialVaultService->persistSessionBundleForConnection(
                connection: $connection,
                sessionBundle: $summaryPayload['session_bundle'],
                authPayload: $authPayload,
            );
        }

        $connection->forceFill([
            'auth_payload' => $authPayload,
            'last_synced_at' => now(),
            'latest_balance_amount' => $summary['latest_balance_amount'],
            'latest_balance_status' => $summary['latest_balance_status'],
            'latest_notice_at' => $summary['latest_notice_at'],
            'latest_transcript_at' => $summary['latest_transcript_at'],
        ])->save();

        return [
            'balance_count' => $balanceCount,
            'document_count' => $documentCount,
            'latest_balance_amount' => $summary['latest_balance_amount'],
            'open_notice_count' => $summary['open_notice_count'],
            'transcript_count' => $summary['transcript_count'],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     next_action: string,
     *     connected_count: int,
     *     active_count: int,
     *     can_sync: bool,
     *     connections: array<int, array<string, mixed>>,
     * }
     */
    public function summarizeUserPortals(int $userId, int $year): array
    {
        $user = \App\Models\User::findOrFail($userId);
        $connections = TaxAgencyConnection::where('user_id', $userId)
            ->orderByRaw("CASE agency_code WHEN 'irs' THEN 0 WHEN 'oregon_dor' THEN 1 ELSE 2 END")
            ->get();
        $connectionsByAgency = $connections->keyBy('agency_code');
        $supportedAgencies = collect($this->taxAgencyCredentialVaultService->supportedAgencies());

        $activeCount = $connections
            ->filter(fn (TaxAgencyConnection $connection): bool => $connection->status === TaxAgencyConnection::STATUS_ACTIVE)
            ->count();
        $connectedCount = $connections
            ->filter(fn (TaxAgencyConnection $connection): bool => $connection->status === TaxAgencyConnection::STATUS_ACTIVE && $connection->last_synced_at !== null)
            ->count();
        $syncingCount = $connections
            ->filter(fn (TaxAgencyConnection $connection): bool => $connection->sync_status === 'syncing')
            ->count();

        $status = match (true) {
            $connections->isEmpty() => 'needs_connections',
            $syncingCount > 0 => 'syncing',
            $connectedCount === $supportedAgencies->count() && $activeCount === $supportedAgencies->count() => 'connected',
            $activeCount > 0 => 'partial',
            default => 'needs_auth',
        };

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'syncing' => 'Syncing',
                'connected' => 'Connected',
                'partial' => 'Partial coverage',
                'needs_auth' => 'Needs auth',
                default => 'Connections needed',
            },
            'next_action' => match ($status) {
                'syncing' => 'Background portal sync is running. Balances, notices, and transcripts will land automatically when the bridge finishes.',
                'connected' => 'Keep IRS and Oregon connections healthy so balances, notices, transcripts, and payment history stay current in the background.',
                'partial' => 'At least one tax agency connection is active. Finish the missing agency connection so portal evidence stays complete.',
                'needs_auth' => 'Configure authenticated IRS and Oregon portal bridge access before the system can pull balances, notices, or transcripts automatically.',
                default => 'Add IRS Individual Online Account and Oregon Revenue Online connections so portal sync can run automatically.',
            },
            'connected_count' => $connectedCount,
            'active_count' => $activeCount,
            'can_sync' => $connections->contains(fn (TaxAgencyConnection $connection): bool => $connection->status === TaxAgencyConnection::STATUS_ACTIVE && $connection->sync_enabled),
            'connections' => $supportedAgencies
                ->map(function (array $agency) use ($connectionsByAgency, $user, $year): array {
                    $connection = $connectionsByAgency->get($agency['agency_code']);

                    return $connection
                        ? $this->connectionCard($connection, $year)
                        : $this->missingConnectionCard($user, $agency);
                })
                ->values()
                ->all(),
        ];
    }

    protected function connectorFor(TaxAgencyConnection $connection): TaxAgencyPortalConnector
    {
        return match ($connection->agency_code) {
            TaxAgencyConnection::AGENCY_IRS => $this->irsConnector,
            TaxAgencyConnection::AGENCY_OREGON_DOR => $this->oregonConnector,
            default => throw new InvalidArgumentException("Unsupported tax agency [{$connection->agency_code}]."),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $balances
     */
    protected function persistBalances(TaxAgencyConnection $connection, int $year, array $balances): int
    {
        foreach ($balances as $balance) {
            TaxAgencyAccountState::updateOrCreate(
                [
                    'tax_agency_connection_id' => $connection->id,
                    'record_type' => (string) ($balance['record_type'] ?? 'balance'),
                    'record_key' => (string) ($balance['record_key'] ?? uniqid($connection->agency_code.'-', true)),
                ],
                [
                    'user_id' => $connection->user_id,
                    'agency_code' => $connection->agency_code,
                    'tax_year' => $balance['tax_year'] ?? $year,
                    'label' => (string) ($balance['label'] ?? 'Agency account state'),
                    'status' => (string) ($balance['status'] ?? 'available'),
                    'amount' => isset($balance['amount']) ? (float) $balance['amount'] : null,
                    'effective_date' => $balance['effective_date'] ?? null,
                    'due_date' => $balance['due_date'] ?? null,
                    'payload' => is_array($balance['payload'] ?? null) ? $balance['payload'] : [],
                ],
            );
        }

        return count($balances);
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    protected function persistDocuments(TaxAgencyConnection $connection, int $year, array $documents): int
    {
        foreach ($documents as $document) {
            $mimeType = $this->normalizeMimeType($document);
            $storagePath = $this->documentStoragePath($connection, $document, $mimeType);
            $this->writeDocumentContent($storagePath, $document['content'] ?? null, $mimeType);

            FinancialDocument::updateOrCreate(
                [
                    'user_id' => $connection->user_id,
                    'file_path' => $storagePath,
                ],
                [
                    'document_type' => $this->normalizeDocumentType($connection, $document),
                    'file_name' => (string) ($document['file_name'] ?? basename($storagePath)),
                    'file_size' => Storage::size($storagePath),
                    'mime_type' => $mimeType,
                    'processing_status' => 'completed',
                    'processing_notes' => "Fetched from {$connection->portal_name} portal sync.",
                    'extracted_data' => array_merge(
                        is_array($document['extracted_data'] ?? null) ? $document['extracted_data'] : [],
                        [
                            'agency_code' => $connection->agency_code,
                            'document_key' => $document['document_key'] ?? basename($storagePath),
                            'tax_year' => $document['tax_year'] ?? $year,
                            'fetched_via' => 'tax_agency_portal_sync',
                        ],
                    ),
                    'extraction_confidence' => 1.0,
                    'needs_review' => (bool) ($document['needs_review'] ?? true),
                    'effective_date' => $document['effective_date'] ?? $document['issued_at'] ?? now()->toDateString(),
                    'response_deadline' => $document['response_deadline'] ?? $document['due_date'] ?? null,
                    'irs_notice_type' => $document['irs_notice_type'] ?? $document['notice_type'] ?? null,
                ],
            );
        }

        return count($documents);
    }

    /**
     * @return array{
     *     latest_balance_amount: float|null,
     *     latest_balance_status: string|null,
     *     latest_notice_at: \Illuminate\Support\Carbon|null,
     *     latest_transcript_at: \Illuminate\Support\Carbon|null,
     *     open_notice_count: int,
     *     transcript_count: int,
     * }
     */
    protected function connectionSummary(TaxAgencyConnection $connection, int $year): array
    {
        $states = TaxAgencyAccountState::where('tax_agency_connection_id', $connection->id)
            ->where(function ($query) use ($year): void {
                $query->whereNull('tax_year')
                    ->orWhere('tax_year', $year);
            })
            ->get();

        $documents = $this->portalDocuments($connection);
        $latestBalanceState = $states
            ->filter(fn (TaxAgencyAccountState $state): bool => $state->record_type === 'balance' && $state->amount !== null)
            ->sortByDesc(fn (TaxAgencyAccountState $state): string => optional($state->due_date)->toDateString() ?? optional($state->effective_date)->toDateString() ?? '')
            ->first();

        return [
            'latest_balance_amount' => $latestBalanceState?->amount !== null ? (float) $latestBalanceState->amount : null,
            'latest_balance_status' => $latestBalanceState?->status,
            'latest_notice_at' => $documents
                ->whereIn('document_type', ['irs_notice', 'state_tax_notice'])
                ->max('effective_date'),
            'latest_transcript_at' => $documents
                ->whereIn('document_type', ['tax_transcript', 'account_transcript'])
                ->max('effective_date'),
            'open_notice_count' => $documents
                ->whereIn('document_type', ['irs_notice', 'state_tax_notice'])
                ->where('needs_review', true)
                ->count(),
            'transcript_count' => $documents
                ->whereIn('document_type', ['tax_transcript', 'account_transcript'])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionCard(TaxAgencyConnection $connection, int $year): array
    {
        $secretState = $this->taxAgencyCredentialVaultService->connectionSecretState(
            user: $connection->user,
            agencyCode: $connection->agency_code,
            authPayload: $connection->auth_payload,
        );
        $states = TaxAgencyAccountState::where('tax_agency_connection_id', $connection->id)
            ->where(function ($query) use ($year): void {
                $query->whereNull('tax_year')
                    ->orWhere('tax_year', $year);
            })
            ->get();
        $documents = $this->portalDocuments($connection);

        return [
            'connection_id' => $connection->id,
            'agency_code' => $connection->agency_code,
            'agency_name' => $connection->agency_name,
            'portal_name' => $connection->portal_name,
            'status' => $connection->status,
            'status_label' => $connection->status_label,
            'sync_status' => (string) $connection->sync_status,
            'sync_status_label' => $connection->sync_status_label,
            'sync_progress' => (int) ($connection->sync_progress ?? 0),
            'last_synced_at' => $connection->last_synced_at?->diffForHumans(),
            'latest_balance_amount' => $connection->latest_balance_amount !== null ? (float) $connection->latest_balance_amount : null,
            'latest_balance_status' => $connection->latest_balance_status,
            'open_notice_count' => $documents
                ->whereIn('document_type', ['irs_notice', 'state_tax_notice'])
                ->where('needs_review', true)
                ->count(),
            'transcript_count' => $documents
                ->whereIn('document_type', ['tax_transcript', 'account_transcript'])
                ->count(),
            'account_state_count' => $states->count(),
            'capabilities' => $connection->capabilities ?? [],
            'sync_enabled' => (bool) $connection->sync_enabled,
            'vault_environment' => $secretState['vault_environment'],
            'credentials_vault_key' => $secretState['credentials_vault_key'],
            'session_vault_key' => $secretState['session_vault_key'],
            'has_credentials' => $secretState['has_credentials'],
            'has_session' => $secretState['has_session'],
            'is_configured' => $secretState['has_credentials'],
            'can_sync' => $connection->status === TaxAgencyConnection::STATUS_ACTIVE && $connection->sync_enabled,
            'next_action' => $this->connectionNextAction($connection, $documents),
        ];
    }

    /**
     * @param  array{
     *     agency_code: string,
     *     agency_name: string,
     *     portal_name: string,
     *     credentials_vault_key: string,
     *     session_vault_key: string,
     *     capabilities: array<int, string>,
     * }  $agency
     * @return array<string, mixed>
     */
    protected function missingConnectionCard(\App\Models\User $user, array $agency): array
    {
        $secretState = $this->taxAgencyCredentialVaultService->connectionSecretState($user, $agency['agency_code']);

        return [
            'connection_id' => null,
            'agency_code' => $agency['agency_code'],
            'agency_name' => $agency['agency_name'],
            'portal_name' => $agency['portal_name'],
            'status' => 'needs_configuration',
            'status_label' => 'Needs setup',
            'sync_status' => 'pending',
            'sync_status_label' => 'Waiting',
            'sync_progress' => 0,
            'last_synced_at' => null,
            'latest_balance_amount' => null,
            'latest_balance_status' => null,
            'open_notice_count' => 0,
            'transcript_count' => 0,
            'account_state_count' => 0,
            'capabilities' => $agency['capabilities'],
            'sync_enabled' => false,
            'vault_environment' => $secretState['vault_environment'],
            'credentials_vault_key' => $secretState['credentials_vault_key'],
            'session_vault_key' => $secretState['session_vault_key'],
            'has_credentials' => $secretState['has_credentials'],
            'has_session' => $secretState['has_session'],
            'is_configured' => false,
            'can_sync' => false,
            'next_action' => "Store {$agency['portal_name']} credentials and enable sync so Tax Office can pull live agency evidence automatically.",
        ];
    }

    protected function connectionNextAction(TaxAgencyConnection $connection, Collection $documents): string
    {
        if ($connection->status === TaxAgencyConnection::STATUS_NEEDS_AUTH) {
            return "Authenticate {$connection->portal_name} through the browser-session bridge before automatic pulls can run.";
        }

        if ($connection->sync_status === 'syncing') {
            return 'Portal sync is running in the background.';
        }

        if ($connection->sync_status === 'failed') {
            return $connection->sync_error ?: 'Portal sync failed. Review bridge access and retry.';
        }

        if ($documents->isEmpty()) {
            return 'Run the first sync to pull balances, payment history, notices, and transcripts into Tax Office.';
        }

        return 'Keep this connection healthy so agency evidence continues landing automatically.';
    }

    protected function portalDocuments(TaxAgencyConnection $connection): Collection
    {
        return FinancialDocument::where('user_id', $connection->user_id)
            ->where('file_path', 'like', "tax-agency/{$connection->user_id}/{$connection->agency_code}/%")
            ->get();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function documentStoragePath(TaxAgencyConnection $connection, array $document, string $mimeType): string
    {
        $documentKey = (string) ($document['document_key'] ?? uniqid($connection->agency_code.'-', true));
        $extension = match ($mimeType) {
            'application/pdf' => 'pdf',
            'text/html' => 'html',
            'text/plain' => 'txt',
            default => 'json',
        };

        return "tax-agency/{$connection->user_id}/{$connection->agency_code}/{$documentKey}.{$extension}";
    }

    protected function writeDocumentContent(string $storagePath, mixed $content, string $mimeType): void
    {
        if (is_array($content)) {
            Storage::put($storagePath, (string) json_encode($content, JSON_PRETTY_PRINT));

            return;
        }

        if (is_string($content) && $content !== '') {
            Storage::put($storagePath, $this->normalizeDocumentContent($content, $mimeType));

            return;
        }

        if ($mimeType === 'text/html') {
            Storage::put($storagePath, '<html><body><p>Portal sync returned metadata only.</p></body></html>');

            return;
        }

        Storage::put($storagePath, (string) json_encode(['message' => 'Portal sync returned metadata only.'], JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function normalizeMimeType(array $document): string
    {
        $mimeType = $document['mime_type'] ?? null;
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        $fileName = strtolower((string) ($document['file_name'] ?? ''));

        return match (true) {
            str_ends_with($fileName, '.pdf') => 'application/pdf',
            str_ends_with($fileName, '.html'), str_ends_with($fileName, '.htm') => 'text/html',
            str_ends_with($fileName, '.txt') => 'text/plain',
            default => 'application/json',
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function normalizeDocumentType(TaxAgencyConnection $connection, array $document): string
    {
        $type = strtolower((string) ($document['document_type'] ?? $document['type'] ?? ''));

        return match ($type) {
            'notice', 'tax_notice', 'notice_letter', 'balance_notice', 'collection_notice', 'state_notice', 'oregon_notice', 'irs_notice' => $connection->agency_code === TaxAgencyConnection::AGENCY_OREGON_DOR
                ? 'state_tax_notice'
                : 'irs_notice',
            'state_tax_notice' => 'state_tax_notice',
            'transcript', 'tax_transcript', 'return_transcript', 'record_of_account', 'wage_and_income_transcript' => 'tax_transcript',
            'account_transcript' => 'account_transcript',
            'payment', 'payment_receipt', 'payment_history', 'payment_confirmation' => 'payment_confirmation',
            'acceptance', 'acknowledgement', 'acknowledgment', 'filing_acceptance', 'efile_acceptance' => $connection->agency_code === TaxAgencyConnection::AGENCY_IRS
                ? 'efile_acceptance'
                : 'filing_acceptance',
            'tax_return', 'return_packet', 'return_copy' => 'tax_return',
            default => $type !== '' ? $type : 'other',
        };
    }

    protected function normalizeDocumentContent(string $content, string $mimeType): string
    {
        if ($mimeType !== 'application/pdf') {
            return $content;
        }

        if (str_starts_with($content, 'data:application/pdf;base64,')) {
            $content = substr($content, strlen('data:application/pdf;base64,'));
        }

        $normalized = preg_replace('/\s+/', '', $content);
        if (! is_string($normalized) || $normalized === '') {
            return $content;
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return $content;
        }

        if (base64_encode($decoded) !== $normalized) {
            return $content;
        }

        return $decoded;
    }
}
