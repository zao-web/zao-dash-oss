<?php

namespace App\Services\PersonalFinance;

use App\Jobs\SyncPlaidTransactionsJob;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\PlaidConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaidService
{
    public const TRANSACTION_ID_PREFIX = 'plaid_';

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = match (config('services.plaid.environment')) {
            'production' => 'https://production.plaid.com',
            default => 'https://sandbox.plaid.com', // development also uses sandbox URL with development API keys
        };
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.plaid.client_id')) && ! empty(config('services.plaid.secret'));
    }

    /**
     * Create a Link token for the Plaid Link widget.
     *
     * @return array{link_token: string}|array{error: string, error_code: ?string, error_type: ?string, error_message: ?string}
     */
    public function createLinkToken(int $userId): array
    {
        $response = Http::post("{$this->baseUrl}/link/token/create", [
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'user' => ['client_user_id' => (string) $userId],
            'client_name' => 'Zao Dash',
            'products' => ['transactions'],
            'country_codes' => ['US'],
            'language' => 'en',
            'webhook' => config('services.plaid.webhook_url'),
        ]);

        if (! $response->successful()) {
            $error = $response->json();
            Log::error('[Plaid] Failed to create link token', ['error' => $error]);

            return [
                'error' => 'Failed to create Plaid link token.',
                ...$this->safePlaidClientErrorFields(is_array($error) ? $error : []),
            ];
        }

        return ['link_token' => $response->json('link_token')];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{error_code: ?string, error_type: ?string, error_message: ?string}
     */
    protected function safePlaidClientErrorFields(array $body): array
    {
        return [
            'error_code' => is_string($body['error_code'] ?? null) ? $body['error_code'] : null,
            'error_type' => is_string($body['error_type'] ?? null) ? $body['error_type'] : null,
            'error_message' => is_string($body['error_message'] ?? null) ? $body['error_message'] : null,
        ];
    }

    /**
     * Exchange a public token for an access token after Link completes.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function exchangePublicToken(string $publicToken, int $userId, array $metadata): PlaidConnection
    {
        $response = Http::post("{$this->baseUrl}/item/public_token/exchange", [
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'public_token' => $publicToken,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Plaid token exchange failed: '.$response->body());
        }

        $data = $response->json();
        $institutionName = $metadata['institution']['name'] ?? 'Unknown';
        $institutionId = $metadata['institution']['institution_id']
            ?? $metadata['institution']['id']
            ?? '';

        $accounts = $this->resolveLinkedAccounts($data['access_token'], $metadata);

        $connection = PlaidConnection::create([
            'user_id' => $userId,
            'institution_name' => $institutionName,
            'institution_id' => $institutionId,
            'access_token' => $data['access_token'],
            'item_id' => $data['item_id'],
            'status' => 'active',
            'products' => ['transactions'],
        ]);

        $this->mapLinkedAccounts(
            $userId,
            $data['item_id'],
            $institutionName,
            $accounts,
        );

        SyncPlaidTransactionsJob::dispatch($connection->id);

        return $connection;
    }

    /**
     * Sync transactions using cursor-based incremental sync.
     *
     * @return array{added: int, modified: int, removed: int}
     */
    public function syncTransactions(PlaidConnection $connection): array
    {
        $stats = ['added' => 0, 'modified' => 0, 'removed' => 0];
        $cursor = $connection->cursor;
        $hasMore = true;

        while ($hasMore) {
            $payload = [
                'client_id' => config('services.plaid.client_id'),
                'secret' => config('services.plaid.secret'),
                'access_token' => $connection->access_token,
            ];

            if ($cursor) {
                $payload['cursor'] = $cursor;
            }

            $response = Http::post("{$this->baseUrl}/transactions/sync", $payload);

            if (! $response->successful()) {
                $error = $response->json('error_code') ?? $response->body();
                Log::error('[Plaid] Transaction sync failed', ['error' => $error, 'connection_id' => $connection->id]);

                if ($response->json('error_code') === 'ITEM_LOGIN_REQUIRED') {
                    $connection->update(['status' => 'error', 'error_code' => 'ITEM_LOGIN_REQUIRED', 'error_message' => 'Bank login required']);
                }

                throw new \RuntimeException("Plaid sync failed: {$error}");
            }

            $data = $response->json();

            foreach ($data['added'] ?? [] as $tx) {
                $account = PersonalAccount::where('plaid_account_id', $tx['account_id'])->first();
                if (! $account) {
                    continue;
                }

                $this->upsertPlaidTransaction($account, $tx);
                $stats['added']++;
            }

            foreach ($data['modified'] ?? [] as $tx) {
                $transaction = PersonalTransaction::where('plaid_transaction_id', $this->prefixedTransactionId($tx['transaction_id']))->first();
                if (! $transaction) {
                    continue;
                }

                $account = $transaction->personalAccount;
                if (! $account) {
                    continue;
                }

                PersonalTransaction::query()
                    ->whereKey($transaction->id)
                    ->update($this->plaidTransactionAttributes($account, $tx));
                $stats['modified']++;
            }

            foreach ($data['removed'] ?? [] as $tx) {
                $transaction = PersonalTransaction::where('plaid_transaction_id', $this->prefixedTransactionId($tx['transaction_id']))->first();
                if (! $transaction) {
                    continue;
                }

                $this->tombstoneTransaction($transaction);
                $stats['removed']++;
            }

            $cursor = $data['next_cursor'] ?? null;
            $hasMore = $data['has_more'] ?? false;
        }

        $connection->update([
            'cursor' => $cursor,
            'last_synced_at' => now(),
            'status' => 'active',
            'error_code' => null,
            'error_message' => null,
        ]);

        PersonalAccount::query()
            ->where('user_id', $connection->user_id)
            ->where('plaid_item_id', $connection->item_id)
            ->update(['last_synced_at' => now()]);

        $this->refreshBalances($connection);

        return $stats;
    }

    /**
     * Get real-time balances for all accounts in a connection.
     */
    public function refreshBalances(PlaidConnection $connection): void
    {
        $response = Http::post("{$this->baseUrl}/accounts/balance/get", [
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'access_token' => $connection->access_token,
        ]);

        if (! $response->successful()) {
            Log::warning('[Plaid] Balance refresh failed', ['connection_id' => $connection->id]);

            return;
        }

        $plaidAccounts = $response->json('accounts') ?? [];
        $this->syncPlaidAccountPresence($connection, $plaidAccounts);

        foreach ($plaidAccounts as $account) {
            PersonalAccount::where('plaid_account_id', $account['account_id'])->update([
                'current_balance' => $account['balances']['current'] ?? 0,
                'available_balance' => $account['balances']['available'] ?? null,
                'credit_limit' => $account['balances']['limit'] ?? null,
                'last_synced_at' => now(),
                'inactive_at' => null,
            ]);
        }
    }

    /**
     * Revoke access and delete connection.
     */
    public function revokeAccess(PlaidConnection $connection): void
    {
        Http::post("{$this->baseUrl}/item/remove", [
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'access_token' => $connection->access_token,
        ]);

        $connection->delete();
    }

    /**
     * Handle Plaid webhook events.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $webhookType = $payload['webhook_type'] ?? '';
        $webhookCode = $payload['webhook_code'] ?? '';
        $itemId = $payload['item_id'] ?? '';

        Log::info('[Plaid] Webhook received', ['type' => $webhookType, 'code' => $webhookCode, 'item_id' => $itemId]);

        $connection = PlaidConnection::where('item_id', $itemId)->first();
        if (! $connection) {
            Log::warning('[Plaid] Webhook for unknown item', ['item_id' => $itemId]);

            return;
        }

        match ($webhookType) {
            'TRANSACTIONS' => match ($webhookCode) {
                'SYNC_UPDATES_AVAILABLE' => SyncPlaidTransactionsJob::dispatch($connection->id),
                'INITIAL_UPDATE', 'HISTORICAL_UPDATE' => SyncPlaidTransactionsJob::dispatch($connection->id),
                default => null,
            },
            'ITEM' => match ($webhookCode) {
                'ERROR' => $connection->update([
                    'status' => 'error',
                    'error_code' => $payload['error']['error_code'] ?? null,
                    'error_message' => $payload['error']['error_message'] ?? null,
                ]),
                'PENDING_EXPIRATION' => $connection->update(['status' => 'error', 'error_message' => 'Connection expiring soon']),
                default => null,
            },
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $plaidAccounts
     */
    protected function mapLinkedAccounts(int $userId, string $itemId, string $institutionName, array $plaidAccounts): void
    {
        $claimedAccountIds = [];

        foreach ($plaidAccounts as $plaidAccount) {
            $plaidAccountId = $plaidAccount['account_id'] ?? $plaidAccount['id'] ?? null;
            if (! is_string($plaidAccountId) || $plaidAccountId === '') {
                continue;
            }

            $account = $this->mapLinkedAccount($userId, $itemId, $institutionName, $plaidAccount, $claimedAccountIds);
            $claimedAccountIds[] = $account->id;
        }
    }

    /**
     * Map one Plaid Link account onto an existing Life ledger row when the same bank/type already exists.
     * Production rows 1, 2, 3, 4, 5, 7 must be reused for unique institution+type matches.
     * Extra products that cannot be scored onto a sibling row create a new PersonalAccount.
     *
     * @param  array<string, mixed>  $plaidAccount
     * @param  array<int, int>  $claimedAccountIds
     */
    protected function mapLinkedAccount(int $userId, string $itemId, string $institutionName, array $plaidAccount, array $claimedAccountIds): PersonalAccount
    {
        $plaidAccountId = (string) ($plaidAccount['account_id'] ?? $plaidAccount['id']);
        $accountType = $this->mapPlaidAccountType($plaidAccount['type'] ?? '', $plaidAccount['subtype'] ?? '');
        $mask = isset($plaidAccount['mask']) ? (string) $plaidAccount['mask'] : null;

        $exact = PersonalAccount::query()
            ->where('user_id', $userId)
            ->where('plaid_account_id', $plaidAccountId)
            ->first();

        if ($exact) {
            return $this->stampPlaidMapping($exact, $plaidAccountId, $itemId, $mask);
        }

        $institutionMatches = PersonalAccount::query()
            ->where('user_id', $userId)
            ->where('account_type', $accountType)
            ->get()
            ->filter(fn (PersonalAccount $account): bool => $this->institutionsMatch($account->institution_name, $institutionName));

        $candidates = $institutionMatches
            ->whereNotIn('id', $claimedAccountIds)
            ->filter(fn (PersonalAccount $account): bool => $this->accountCanAcceptPlaidMapping($account, $plaidAccountId, $itemId))
            ->values();

        $match = $this->pickExistingAccount($candidates, $plaidAccount, $institutionMatches->count() > 1);
        if ($match) {
            Log::info('[Plaid] Mapped Link account onto existing personal account', [
                'personal_account_id' => $match->id,
                'plaid_account_id' => $plaidAccountId,
                'institution' => $institutionName,
                'account_type' => $accountType,
            ]);

            return $this->stampPlaidMapping($match, $plaidAccountId, $itemId, $mask);
        }

        return PersonalAccount::create([
            'user_id' => $userId,
            'name' => $plaidAccount['name'] ?? $plaidAccount['official_name'] ?? 'Account',
            'institution_name' => $institutionName,
            'account_type' => $accountType,
            'account_subtype' => $plaidAccount['subtype'] ?? null,
            'plaid_account_id' => $plaidAccountId,
            'plaid_item_id' => $itemId,
            'current_balance' => 0,
            'metadata' => [
                'provider' => 'plaid',
                'mask' => $mask,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, array<string, mixed>>
     */
    protected function resolveLinkedAccounts(string $accessToken, array $metadata): array
    {
        $linkAccounts = $metadata['accounts'] ?? [];
        $linkSpecifiedAccounts = is_array($linkAccounts) && $linkAccounts !== [];
        $fromMetadata = $this->accountsFromLinkMetadata($metadata);
        $selectedIds = collect($fromMetadata)
            ->map(fn (array $account): ?string => $account['account_id'] ?? $account['id'] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();

        $fromApi = $this->fetchPlaidAccounts($accessToken);
        if ($fromApi === null) {
            return $this->requireMappableLinkedAccounts($fromMetadata, $linkSpecifiedAccounts);
        }

        if ($fromApi === []) {
            return $this->requireMappableLinkedAccounts($fromMetadata, $linkSpecifiedAccounts);
        }

        if ($selectedIds->isEmpty()) {
            if ($linkSpecifiedAccounts) {
                throw new \RuntimeException('Plaid Link completed but selected accounts could not be mapped');
            }

            return $fromApi;
        }

        $intersected = collect($fromApi)
            ->filter(fn (array $account): bool => $selectedIds->contains($account['account_id'] ?? $account['id'] ?? null))
            ->values()
            ->all();

        if ($intersected !== []) {
            return $intersected;
        }

        if ($fromMetadata !== []) {
            Log::warning('[Plaid] Link selected account ids did not intersect /accounts/get; falling back to Link metadata', [
                'selected_ids' => $selectedIds->all(),
                'api_account_ids' => collect($fromApi)
                    ->map(fn (array $account): ?string => $account['account_id'] ?? $account['id'] ?? null)
                    ->all(),
            ]);

            return $fromMetadata;
        }

        throw new \RuntimeException('Plaid Link completed but selected accounts could not be mapped');
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<int, array<string, mixed>>
     */
    protected function requireMappableLinkedAccounts(array $accounts, bool $linkSpecifiedAccounts): array
    {
        if ($accounts !== []) {
            return $accounts;
        }

        if ($linkSpecifiedAccounts) {
            throw new \RuntimeException('Plaid Link completed but selected accounts could not be mapped');
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, array<string, mixed>>
     */
    protected function accountsFromLinkMetadata(array $metadata): array
    {
        return collect($metadata['accounts'] ?? [])
            ->map(fn (array $account): array => [
                'account_id' => $account['id'] ?? $account['account_id'] ?? null,
                'name' => $account['name'] ?? 'Account',
                'official_name' => $account['official_name'] ?? $account['name'] ?? null,
                'type' => $account['type'] ?? '',
                'subtype' => $account['subtype'] ?? '',
                'mask' => $account['mask'] ?? null,
            ])
            ->filter(fn (array $account): bool => is_string($account['account_id']) && $account['account_id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchPlaidAccounts(string $accessToken): ?array
    {
        $response = Http::post("{$this->baseUrl}/accounts/get", [
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            Log::warning('[Plaid] Failed to fetch accounts after link', ['error' => $response->json()]);

            return null;
        }

        return $response->json('accounts') ?? [];
    }

    protected function stampPlaidMapping(PersonalAccount $account, string $plaidAccountId, string $itemId, ?string $mask): PersonalAccount
    {
        $metadata = $account->metadata ?? [];
        $metadata['provider'] = 'plaid';
        $metadata['plaid_account_id'] = $plaidAccountId;
        if ($mask) {
            $metadata['mask'] = $mask;
        }

        $account->update([
            'plaid_account_id' => $plaidAccountId,
            'plaid_item_id' => $itemId,
            'inactive_at' => null,
            'metadata' => $metadata,
        ]);

        return $account;
    }

    protected function accountCanAcceptPlaidMapping(PersonalAccount $account, string $plaidAccountId, string $itemId): bool
    {
        $existingId = $account->plaid_account_id;

        if ($existingId === null || $existingId === '' || str_starts_with($existingId, 'teller_')) {
            return true;
        }

        if ($existingId === $plaidAccountId) {
            return true;
        }

        $existingItemId = $account->plaid_item_id;
        if (! is_string($existingItemId) || $existingItemId === '') {
            return true;
        }

        if ($existingItemId === $itemId) {
            return false;
        }

        return ! PlaidConnection::query()
            ->where('user_id', $account->user_id)
            ->where('item_id', $existingItemId)
            ->active()
            ->exists();
    }

    /**
     * @param  Collection<int, PersonalAccount>  $candidates
     * @param  array<string, mixed>  $plaidAccount
     */
    protected function pickExistingAccount(Collection $candidates, array $plaidAccount, bool $requirePositiveScore = false): ?PersonalAccount
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1 && ! $requirePositiveScore) {
            return $candidates->first();
        }

        $mask = (string) ($plaidAccount['mask'] ?? '');
        $name = strtolower((string) ($plaidAccount['name'] ?? $plaidAccount['official_name'] ?? ''));

        $scored = $candidates->map(function (PersonalAccount $account) use ($mask, $name): array {
            $score = 0;
            $accountName = strtolower((string) $account->name);
            $storedMask = (string) ($account->metadata['mask'] ?? '');

            if ($mask !== '' && (str_contains($accountName, $mask) || ($storedMask !== '' && $storedMask === $mask))) {
                $score += 50;
            }

            if ($name !== '' && ($accountName === $name || str_contains($accountName, $name) || str_contains($name, $accountName))) {
                $score += 20;
            }

            return ['account' => $account, 'score' => $score];
        });

        $bestScore = (int) $scored->max('score');
        if ($bestScore <= 0) {
            return null;
        }

        $best = $scored
            ->filter(fn (array $row): bool => $row['score'] === $bestScore)
            ->sortBy(fn (array $row): int => $row['account']->id)
            ->first();

        return $best['account'] ?? null;
    }

    protected function institutionsMatch(?string $left, ?string $right): bool
    {
        $normalizedLeft = $this->normalizeInstitution($left);
        $normalizedRight = $this->normalizeInstitution($right);

        if ($normalizedLeft === '' || $normalizedRight === '') {
            return false;
        }

        return $normalizedLeft === $normalizedRight
            || str_contains($normalizedLeft, $normalizedRight)
            || str_contains($normalizedRight, $normalizedLeft);
    }

    protected function normalizeInstitution(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));
        $normalized = str_replace(['&', ',', '.', "'"], ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9\s]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(bank|banks|n a|na|inc|incorporated|corp|corporation|the|credit union)\b/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * @param  array<string, mixed>  $tx
     */
    protected function upsertPlaidTransaction(PersonalAccount $account, array $tx): PersonalTransaction
    {
        $prefixedId = $this->prefixedTransactionId((string) $tx['transaction_id']);
        $attributes = $this->plaidTransactionAttributes($account, $tx);

        $existing = PersonalTransaction::where('plaid_transaction_id', $prefixedId)->first();
        if ($existing) {
            PersonalTransaction::query()->whereKey($existing->id)->update($attributes);

            return $existing->fresh();
        }

        return PersonalTransaction::create(array_merge($attributes, [
            'plaid_transaction_id' => $prefixedId,
            'is_recurring' => ! empty($tx['personal_finance_category']['detailed']) && str_contains($tx['personal_finance_category']['detailed'] ?? '', 'SUBSCRIPTION'),
        ]));
    }

    /**
     * Bank-feed fields only. Never include category_id, tax-review columns, notes, tags, or is_business_expense.
     *
     * @param  array<string, mixed>  $tx
     * @return array<string, mixed>
     */
    protected function plaidTransactionAttributes(PersonalAccount $account, array $tx): array
    {
        return [
            'personal_account_id' => $account->id,
            'transaction_date' => $tx['date'],
            'amount' => $this->ledgerAmount($account, (float) $tx['amount']),
            'description' => $tx['name'] ?? $tx['merchant_name'] ?? 'Unknown',
            'original_description' => $tx['original_description'] ?? $tx['name'] ?? null,
            'merchant_name' => $tx['merchant_name'] ?? null,
            'import_source' => 'plaid',
            'inactive_at' => null,
        ];
    }

    /**
     * Plaid positive = money out. Life checking/savings spend is negative. Card charges stay positive.
     */
    protected function ledgerAmount(PersonalAccount $account, float $plaidAmount): float
    {
        if (in_array($account->account_type, ['checking', 'savings'], true)) {
            return $plaidAmount * -1;
        }

        return $plaidAmount;
    }

    public function prefixedTransactionId(string $plaidTransactionId): string
    {
        if (str_starts_with($plaidTransactionId, self::TRANSACTION_ID_PREFIX)) {
            return $plaidTransactionId;
        }

        return self::TRANSACTION_ID_PREFIX.$plaidTransactionId;
    }

    protected function mapPlaidAccountType(string $type, string $subtype): string
    {
        return match ($type) {
            'depository' => match ($subtype) {
                'savings', 'money market', 'hsa' => 'savings',
                default => 'checking',
            },
            'credit' => 'credit_card',
            'loan' => 'loan',
            'investment' => 'investment',
            default => 'other',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $plaidAccounts
     */
    protected function syncPlaidAccountPresence(PlaidConnection $connection, array $plaidAccounts): void
    {
        $seenIds = collect($plaidAccounts)
            ->map(fn (array $account): ?string => $account['account_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();

        if ($seenIds->isEmpty()) {
            return;
        }

        $mapped = PersonalAccount::query()
            ->where('user_id', $connection->user_id)
            ->where('plaid_item_id', $connection->item_id)
            ->get()
            ->filter(fn (PersonalAccount $account): bool => $account->isPlaidMapped());

        foreach ($mapped as $account) {
            if ($seenIds->contains($account->plaid_account_id)) {
                continue;
            }

            $this->tombstoneAccount($account);
        }
    }

    protected function tombstoneTransaction(PersonalTransaction $transaction): void
    {
        if ($transaction->inactive_at !== null) {
            return;
        }

        $transaction->update(['inactive_at' => now()]);

        Log::info('[Plaid] Tombstoned removed transaction', [
            'personal_transaction_id' => $transaction->id,
            'plaid_transaction_id' => $transaction->plaid_transaction_id,
        ]);
    }

    protected function tombstoneAccount(PersonalAccount $account): void
    {
        if ($account->inactive_at === null) {
            $account->update(['inactive_at' => now()]);
        }

        PersonalTransaction::query()
            ->where('personal_account_id', $account->id)
            ->where('plaid_transaction_id', 'like', self::TRANSACTION_ID_PREFIX.'%')
            ->whereNull('inactive_at')
            ->update(['inactive_at' => now()]);

        Log::info('[Plaid] Tombstoned removed account', [
            'personal_account_id' => $account->id,
            'plaid_account_id' => $account->plaid_account_id,
        ]);
    }
}
