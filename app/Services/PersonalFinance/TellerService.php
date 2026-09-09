<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TellerService
{
    private const BASE_URL = 'https://api.teller.io';

    public function isConfigured(): bool
    {
        return ! empty(config('services.teller.application_id'));
    }

    /**
     * Make an authenticated API request with mTLS + access token.
     *
     * @return array<string, mixed>
     */
    protected function apiRequest(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        $options = [];

        // Add client certificate — supports file paths OR base64-encoded env vars
        $certPath = $this->resolveCertificate('certificate');
        $keyPath = $this->resolveCertificate('private_key');

        if ($certPath && $keyPath) {
            $options['cert'] = $certPath;
            $options['ssl_key'] = $keyPath;
        }

        $response = Http::withBasicAuth($accessToken, '')
            ->withOptions($options)
            ->{$method}(self::BASE_URL.$endpoint, $data);

        if (! $response->successful()) {
            Log::error('[Teller] API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException("Teller API error ({$response->status()}): ".$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Store enrollment after Teller Connect completes.
     * Creates PersonalAccount records for each account.
     *
     * @param  array<string, mixed>  $enrollment
     */
    public function handleEnrollment(int $userId, array $enrollment): void
    {
        $accessToken = $enrollment['accessToken'] ?? $enrollment['access_token'] ?? '';
        $institutionName = $enrollment['enrollment']['institution']['name']
            ?? $enrollment['institution']['name']
            ?? 'Unknown';
        $enrollmentId = $enrollment['enrollment']['id']
            ?? $enrollment['enrollmentId']
            ?? '';

        Log::info('[Teller] Processing enrollment', [
            'user_id' => $userId,
            'institution' => $institutionName,
        ]);

        // Fetch accounts from Teller
        $accounts = $this->apiRequest('get', '/accounts', $accessToken);

        foreach ($accounts as $account) {
            // Teller accounts have institution info directly
            $instName = $account['institution']['name'] ?? $institutionName;

            $accountType = $this->mapAccountType($account['type'] ?? '', $account['subtype'] ?? '');

            $personalAccount = PersonalAccount::updateOrCreate(
                ['plaid_account_id' => 'teller_'.$account['id']],
                [
                    'user_id' => $userId,
                    'name' => $account['name'] ?? 'Account',
                    'institution_name' => $instName,
                    'account_type' => $accountType,
                    'account_subtype' => $account['subtype'] ?? null,
                    'current_balance' => 0,
                    'plaid_item_id' => $enrollmentId,
                    'last_synced_at' => now(),
                    'metadata' => [
                        'teller_access_token' => encrypt($accessToken),
                        'teller_account_id' => $account['id'],
                        'teller_enrollment_id' => $enrollmentId,
                        'provider' => 'teller',
                    ],
                ]
            );

            // Auto-create a Debt record for credit cards and loans
            if (in_array($accountType, ['credit_card', 'loan'])) {
                $debtType = $accountType === 'loan' ? 'personal_loan' : $accountType;
                \App\Models\Debt::firstOrCreate(
                    ['personal_account_id' => $personalAccount->id, 'user_id' => $userId],
                    [
                        'name' => ($account['name'] ?? 'Account').' - '.$instName,
                        'debt_type' => $debtType,
                        'creditor_name' => $instName,
                        'original_amount' => 0, // Will be updated on balance sync
                        'current_balance' => 0, // Will be updated on balance sync
                        'interest_rate' => $accountType === 'credit_card' ? 24.99 : 0, // Default, user can update
                        'minimum_payment' => 0,
                        'status' => 'active',
                        'priority' => $accountType === 'credit_card' ? 'high' : 'medium',
                    ]
                );
            }
        }

        Log::info('[Teller] Enrollment complete, dispatching sync job', [
            'user_id' => $userId,
            'accounts_created' => count($accounts),
        ]);

        // Sync transactions in the background — don't block the request
        \App\Jobs\SyncTellerTransactionsJob::dispatch($userId);
    }

    /**
     * Sync transactions for all Teller-connected accounts.
     *
     * @return array{synced: int, accounts: int}
     */
    public function syncTransactions(int $userId, ?string $accessToken = null, bool $forceBalance = false, ?\Closure $onProgress = null): array
    {
        $stats = ['synced' => 0, 'accounts' => 0];

        $accounts = PersonalAccount::where('user_id', $userId)
            ->whereJsonContains('metadata->provider', 'teller')
            ->get()
            ->reject(fn (PersonalAccount $account): bool => $account->isPlaidMapped());

        $totalAccounts = $accounts->count();
        $currentIndex = 0;

        foreach ($accounts as $account) {
            $currentIndex++;
            if ($onProgress) {
                $onProgress('syncing_account', "Syncing {$account->name}...", $currentIndex, $totalAccounts, $account->name);
            }
            $token = $accessToken ?? decrypt($account->metadata['teller_access_token'] ?? '');
            $tellerId = $account->metadata['teller_account_id'] ?? null;

            if (! $token || ! $tellerId) {
                continue;
            }

            try {
                // Fetch transactions
                $transactions = $this->apiRequest('get', "/accounts/{$tellerId}/transactions", $token);

                foreach ($transactions as $tx) {
                    // Parse date — Teller returns YYYY-MM-DD format
                    $txDate = null;
                    try {
                        $txDate = \Carbon\Carbon::parse($tx['date'] ?? now())->toDateString();
                    } catch (\Exception $e) {
                        $txDate = now()->toDateString();
                    }

                    PersonalTransaction::updateOrCreate(
                        ['plaid_transaction_id' => 'teller_'.$tx['id']],
                        [
                            'personal_account_id' => $account->id,
                            'transaction_date' => $txDate,
                            'amount' => (float) ($tx['amount'] ?? 0),
                            'description' => $tx['description'] ?? 'Unknown',
                            'original_description' => $tx['description'] ?? null,
                            'merchant_name' => $tx['details']['counterparty']['name'] ?? $tx['merchant_name'] ?? null,
                            'import_source' => 'teller',
                            'is_recurring' => false,
                        ]
                    );
                    $stats['synced']++;
                }

                // Update balance (cache this - costs $0.10/call)
                // Always fetch on first sync (balance is 0) or if last sync was > 4 hours ago
                $needsBalance = $forceBalance
                    || (float) $account->current_balance === 0.0
                    || ! $account->last_synced_at
                    || $account->last_synced_at->lt(now()->subHours(4));
                if ($needsBalance) {
                    try {
                        $balances = $this->apiRequest('get', "/accounts/{$tellerId}/balances", $token);

                        // Credit cards: use ledger (amount owed), not available (remaining credit)
                        $isDebtAccount = in_array($account->account_type, ['credit_card', 'loan', 'collections', 'tax_debt']);
                        $balance = $isDebtAccount
                            ? (float) ($balances['ledger'] ?? $balances['available'] ?? 0)
                            : (float) ($balances['available'] ?? $balances['ledger'] ?? 0);

                        $account->update([
                            'current_balance' => $balance,
                            'available_balance' => isset($balances['available']) ? (float) $balances['available'] : null,
                        ]);

                        // Keep linked Debt balance in sync
                        $linkedDebt = \App\Models\Debt::where('personal_account_id', $account->id)->first();
                        if ($linkedDebt) {
                            $absBalance = abs($balance);
                            $linkedDebt->update([
                                'current_balance' => $absBalance,
                                'original_amount' => max((float) $linkedDebt->original_amount, $absBalance),
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('[Teller] Balance fetch failed, using transaction-based balance', [
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Auto-create Debt record for credit card/loan accounts that don't have one
                if (in_array($account->account_type, ['credit_card', 'loan']) && ! \App\Models\Debt::where('personal_account_id', $account->id)->exists()) {
                    $debtType = $account->account_type === 'loan' ? 'personal_loan' : $account->account_type;
                    \App\Models\Debt::create([
                        'user_id' => $account->user_id,
                        'name' => $account->name.' - '.$account->institution_name,
                        'debt_type' => $debtType,
                        'creditor_name' => $account->institution_name ?? 'Unknown',
                        'original_amount' => abs((float) $account->current_balance),
                        'current_balance' => abs((float) $account->current_balance),
                        'interest_rate' => 0, // User should set actual APR from Debts page
                        'minimum_payment' => 0,
                        'status' => 'active',
                        'priority' => $account->account_type === 'credit_card' ? 'high' : 'medium',
                        'personal_account_id' => $account->id,
                    ]);
                    Log::info('[Teller] Auto-created Debt for existing account', ['account_id' => $account->id, 'type' => $account->account_type]);
                }

                $account->update(['last_synced_at' => now()]);
                $stats['accounts']++;
            } catch (\Exception $e) {
                Log::error('[Teller] Transaction sync failed for account', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Sync a single account and return debug data from Teller API responses.
     *
     * @return array{account: array, balances: array, transactions_synced: int, debt_updated: bool}
     */
    public function syncSingleAccount(PersonalAccount $account): array
    {
        if ($account->isPlaidMapped()) {
            throw new \RuntimeException('This account is mapped to Plaid and cannot sync from Teller.');
        }

        $token = decrypt($account->metadata['teller_access_token'] ?? '');
        $tellerId = $account->metadata['teller_account_id'] ?? null;

        if (! $token || ! $tellerId) {
            throw new \RuntimeException('Missing Teller credentials for this account.');
        }

        $debug = ['account' => [], 'balances' => [], 'transactions_synced' => 0, 'debt_updated' => false];

        // Fetch account details
        try {
            $accountData = $this->apiRequest('get', "/accounts/{$tellerId}", $token);
            $debug['account'] = $accountData;
        } catch (\Exception $e) {
            $debug['account'] = ['error' => $e->getMessage()];
        }

        // Fetch balances
        try {
            $balances = $this->apiRequest('get', "/accounts/{$tellerId}/balances", $token);
            $debug['balances'] = $balances;

            // Credit cards: use ledger (amount owed), not available (remaining credit)
            $isDebtAccount = in_array($account->account_type, ['credit_card', 'loan', 'collections', 'tax_debt']);
            $balance = $isDebtAccount
                ? (float) ($balances['ledger'] ?? $balances['available'] ?? 0)
                : (float) ($balances['available'] ?? $balances['ledger'] ?? 0);
            $account->update([
                'current_balance' => $balance,
                'available_balance' => isset($balances['available']) ? (float) $balances['available'] : null,
            ]);

            // Keep linked Debt balance in sync
            $linkedDebt = \App\Models\Debt::where('personal_account_id', $account->id)->first();
            if ($linkedDebt) {
                $absBalance = abs($balance);
                $linkedDebt->update([
                    'current_balance' => $absBalance,
                    'original_amount' => max((float) $linkedDebt->original_amount, $absBalance),
                ]);
                $debug['debt_updated'] = true;
            }
        } catch (\Exception $e) {
            $debug['balances'] = ['error' => $e->getMessage()];
        }

        // Fetch and sync transactions
        try {
            $transactions = $this->apiRequest('get', "/accounts/{$tellerId}/transactions", $token);

            foreach ($transactions as $tx) {
                $txDate = null;
                try {
                    $txDate = \Carbon\Carbon::parse($tx['date'] ?? now())->toDateString();
                } catch (\Exception $e) {
                    $txDate = now()->toDateString();
                }

                PersonalTransaction::updateOrCreate(
                    ['plaid_transaction_id' => 'teller_'.$tx['id']],
                    [
                        'personal_account_id' => $account->id,
                        'transaction_date' => $txDate,
                        'amount' => (float) ($tx['amount'] ?? 0),
                        'description' => $tx['description'] ?? 'Unknown',
                        'original_description' => $tx['description'] ?? null,
                        'merchant_name' => $tx['details']['counterparty']['name'] ?? $tx['merchant_name'] ?? null,
                        'import_source' => 'teller',
                        'is_recurring' => false,
                    ]
                );
                $debug['transactions_synced']++;
            }
        } catch (\Exception $e) {
            $debug['transactions_error'] = $e->getMessage();
        }

        $account->update(['last_synced_at' => now()]);

        return $debug;
    }

    /**
     * Disconnect a Teller account.
     */
    public function disconnect(PersonalAccount $account): void
    {
        $token = decrypt($account->metadata['teller_access_token'] ?? '');
        $tellerId = $account->metadata['teller_account_id'] ?? null;

        if ($token && $tellerId) {
            try {
                $this->apiRequest('delete', "/accounts/{$tellerId}", $token);
            } catch (\Exception $e) {
                Log::warning('[Teller] Disconnect API call failed', ['error' => $e->getMessage()]);
            }
        }

        $account->delete();
    }

    /**
     * Resolve certificate path — supports file paths OR base64-encoded env vars.
     * If the config value is base64, decode it to a temp file and return the path.
     */
    protected function resolveCertificate(string $type): ?string
    {
        $pathConfig = config("services.teller.{$type}_path");
        $base64Config = config("services.teller.{$type}_base64");

        // If a file path is set and exists, use it directly
        if ($pathConfig && file_exists($pathConfig)) {
            return $pathConfig;
        }

        // If base64 content is set, decode to a temp file
        if ($base64Config) {
            $tmpPath = storage_path("app/teller_{$type}.pem");

            if (! file_exists($tmpPath)) {
                $decoded = base64_decode($base64Config);
                if ($decoded === false) {
                    Log::error("[Teller] Failed to decode base64 {$type}");

                    return null;
                }
                file_put_contents($tmpPath, $decoded);
                chmod($tmpPath, 0600);
            }

            return $tmpPath;
        }

        // Fallback: check if the path config IS the base64 content (no separate var)
        if ($pathConfig && ! file_exists($pathConfig) && strlen($pathConfig) > 100) {
            $tmpPath = storage_path("app/teller_{$type}.pem");

            if (! file_exists($tmpPath)) {
                $decoded = base64_decode($pathConfig);
                if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
                    file_put_contents($tmpPath, $decoded);
                    chmod($tmpPath, 0600);

                    return $tmpPath;
                }
            } else {
                return $tmpPath;
            }
        }

        return null;
    }

    protected function mapAccountType(string $type, string $subtype): string
    {
        return match ($type) {
            'depository' => match ($subtype) {
                'savings', 'money_market' => 'savings',
                default => 'checking',
            },
            'credit' => 'credit_card',
            'loan' => 'loan',
            default => 'other',
        };
    }
}
