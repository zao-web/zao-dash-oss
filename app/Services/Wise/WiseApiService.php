<?php

namespace App\Services\Wise;

use App\Models\WiseConnection;
use App\Models\WiseTransfer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WiseApiService
{
    protected string $environment;

    public function __construct()
    {
        $this->environment = config('services.wise.environment', 'production');
    }

    protected function getBaseUrl(): string
    {
        return $this->environment === 'sandbox'
            ? 'https://api.sandbox.transferwise.tech'
            : 'https://api.wise.com';
    }

    protected function client(WiseConnection $connection): PendingRequest
    {
        return Http::withToken($connection->api_token)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->baseUrl($this->getBaseUrl());
    }

    // =========================================================================
    // PROFILES
    // =========================================================================

    /**
     * Get all profiles for the authenticated user.
     */
    public function getProfiles(WiseConnection $connection): array
    {
        $response = $this->client($connection)->get('/v1/profiles');

        if (! $response->successful()) {
            throw new \Exception('Failed to get Wise profiles: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a specific profile.
     */
    public function getProfile(WiseConnection $connection): array
    {
        $response = $this->client($connection)->get("/v1/profiles/{$connection->profile_id}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get Wise profile: '.$response->body());
        }

        return $response->json();
    }

    // =========================================================================
    // BALANCES
    // =========================================================================

    /**
     * Get all balances for the profile.
     */
    public function getBalances(WiseConnection $connection): array
    {
        $response = $this->client($connection)->get("/v4/profiles/{$connection->profile_id}/balances", [
            'types' => 'STANDARD',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get Wise balances: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get balance for a specific currency.
     */
    public function getBalance(WiseConnection $connection, string $currency): ?array
    {
        $balances = $this->getBalances($connection);

        foreach ($balances as $balance) {
            if ($balance['currency'] === strtoupper($currency)) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * Get balance statement (transactions) for a period.
     */
    public function getBalanceStatement(
        WiseConnection $connection,
        int $balanceId,
        string $startDate,
        string $endDate,
        string $currency
    ): array {
        $response = $this->client($connection)->get(
            "/v1/profiles/{$connection->profile_id}/balance-statements/{$balanceId}/statement.json",
            [
                'currency' => $currency,
                'intervalStart' => $startDate.'T00:00:00.000Z',
                'intervalEnd' => $endDate.'T23:59:59.999Z',
                'type' => 'COMPACT',
            ]
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get balance statement: '.$response->body());
        }

        return $response->json();
    }

    // =========================================================================
    // RECIPIENTS
    // =========================================================================

    /**
     * List all recipients for the profile.
     */
    public function listRecipients(WiseConnection $connection, ?string $currency = null): array
    {
        $params = [];
        if ($currency) {
            $params['currency'] = strtoupper($currency);
        }

        $response = $this->client($connection)->get('/v1/accounts', [
            'profile' => $connection->profile_id,
            ...$params,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to list recipients: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a specific recipient.
     */
    public function getRecipient(WiseConnection $connection, string $recipientId): array
    {
        $response = $this->client($connection)->get("/v1/accounts/{$recipientId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get recipient: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a new recipient.
     * Different account types require different details structure.
     */
    public function createRecipient(WiseConnection $connection, array $data): array
    {
        $payload = [
            'profile' => $connection->profile_id,
            'accountHolderName' => $data['account_holder_name'],
            'currency' => strtoupper($data['currency']),
            'type' => $data['type'], // e.g., 'aba', 'swift_code', 'iban', 'email'
            'details' => $data['details'],
        ];

        // Optional: legal type for business recipients
        if (isset($data['legal_type'])) {
            $payload['legalType'] = $data['legal_type']; // PRIVATE or BUSINESS
        }

        $response = $this->client($connection)->post('/v1/accounts', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create recipient: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Delete a recipient.
     */
    public function deleteRecipient(WiseConnection $connection, string $recipientId): bool
    {
        $response = $this->client($connection)->delete("/v1/accounts/{$recipientId}");

        return $response->successful();
    }

    /**
     * Get required fields for creating a recipient in a specific currency.
     */
    public function getRecipientRequirements(WiseConnection $connection, string $sourceCurrency, string $targetCurrency): array
    {
        $response = $this->client($connection)->get('/v1/account-requirements', [
            'source' => strtoupper($sourceCurrency),
            'target' => strtoupper($targetCurrency),
            'sourceAmount' => 1000, // Sample amount
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get recipient requirements: '.$response->body());
        }

        return $response->json();
    }

    // =========================================================================
    // QUOTES
    // =========================================================================

    /**
     * Create a quote for a transfer.
     */
    public function createQuote(WiseConnection $connection, array $data): array
    {
        $payload = [
            'profile' => $connection->profile_id,
            'sourceCurrency' => strtoupper($data['source_currency']),
            'targetCurrency' => strtoupper($data['target_currency']),
            'payOut' => $data['pay_out'] ?? 'BALANCE', // BALANCE or BANK_TRANSFER
        ];

        // Either sourceAmount or targetAmount, not both
        if (isset($data['source_amount'])) {
            $payload['sourceAmount'] = $data['source_amount'];
        } else {
            $payload['targetAmount'] = $data['target_amount'];
        }

        $response = $this->client($connection)->post('/v3/profiles/'.$connection->profile_id.'/quotes', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create quote: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get an existing quote.
     */
    public function getQuote(WiseConnection $connection, string $quoteId): array
    {
        $response = $this->client($connection)->get("/v3/profiles/{$connection->profile_id}/quotes/{$quoteId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get quote: '.$response->body());
        }

        return $response->json();
    }

    // =========================================================================
    // TRANSFERS
    // =========================================================================

    /**
     * Create a transfer using a quote.
     */
    public function createTransfer(WiseConnection $connection, array $data): array
    {
        $payload = [
            'targetAccount' => $data['recipient_id'],
            'quoteUuid' => $data['quote_id'],
            'customerTransactionId' => $data['transaction_id'] ?? uniqid('tf_'),
        ];

        // Optional: reference shown to recipient
        if (isset($data['reference'])) {
            $payload['details'] = [
                'reference' => $data['reference'],
            ];
        }

        $response = $this->client($connection)->post('/v1/transfers', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create transfer: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Fund a transfer from the balance.
     */
    public function fundTransfer(WiseConnection $connection, string $transferId): array
    {
        $response = $this->client($connection)->post(
            "/v3/profiles/{$connection->profile_id}/transfers/{$transferId}/payments",
            ['type' => 'BALANCE']
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to fund transfer: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get transfer status.
     */
    public function getTransfer(WiseConnection $connection, string $transferId): array
    {
        $response = $this->client($connection)->get("/v1/transfers/{$transferId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get transfer: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get transfer status string.
     */
    public function getTransferStatus(WiseConnection $connection, string $transferId): string
    {
        $transfer = $this->getTransfer($connection, $transferId);

        return $transfer['status'] ?? 'unknown';
    }

    /**
     * List transfers for the profile.
     */
    public function listTransfers(
        WiseConnection $connection,
        ?string $status = null,
        ?int $limit = 100,
        ?int $offset = 0
    ): array {
        $params = [
            'profile' => $connection->profile_id,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($status) {
            $params['status'] = $status;
        }

        $response = $this->client($connection)->get('/v1/transfers', $params);

        if (! $response->successful()) {
            throw new \Exception('Failed to list transfers: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Cancel a transfer (only possible before it's funded).
     */
    public function cancelTransfer(WiseConnection $connection, string $transferId): array
    {
        $response = $this->client($connection)->put("/v1/transfers/{$transferId}/cancel");

        if (! $response->successful()) {
            throw new \Exception('Failed to cancel transfer: '.$response->body());
        }

        return $response->json();
    }

    // =========================================================================
    // EXCHANGE RATES
    // =========================================================================

    /**
     * Get current exchange rate.
     */
    public function getExchangeRate(WiseConnection $connection, string $source, string $target): array
    {
        $response = $this->client($connection)->get('/v1/rates', [
            'source' => strtoupper($source),
            'target' => strtoupper($target),
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get exchange rate: '.$response->body());
        }

        $rates = $response->json();

        return $rates[0] ?? [];
    }

    // =========================================================================
    // WEBHOOKS
    // =========================================================================

    /**
     * Subscribe to webhook events.
     */
    public function createWebhookSubscription(WiseConnection $connection, string $callbackUrl): array
    {
        $payload = [
            'name' => 'ZaoDash Transfer Updates',
            'delivery' => [
                'version' => '2.0.0',
                'url' => $callbackUrl,
            ],
            'trigger_on' => 'transfers#state-change',
            'scope' => [
                'domain' => 'profile',
                'id' => $connection->profile_id,
            ],
        ];

        $response = $this->client($connection)->post('/v3/profiles/'.$connection->profile_id.'/subscriptions', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create webhook subscription: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $webhookSecret): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    /**
     * Create a complete transfer workflow: quote → transfer → fund.
     * Returns the funded transfer or throws on any failure.
     */
    public function initiateFullTransfer(
        WiseConnection $connection,
        string $recipientId,
        float $amount,
        string $sourceCurrency,
        string $targetCurrency,
        ?string $reference = null
    ): array {
        // 1. Create quote
        $quote = $this->createQuote($connection, [
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $amount,
        ]);

        // 2. Create transfer
        $transfer = $this->createTransfer($connection, [
            'recipient_id' => $recipientId,
            'quote_id' => $quote['id'],
            'reference' => $reference,
            'transaction_id' => 'zd_'.uniqid(),
        ]);

        // 3. Fund the transfer (automatically deducts from balance)
        $this->fundTransfer($connection, $transfer['id']);

        // 4. Return final transfer state
        return $this->getTransfer($connection, $transfer['id']);
    }

    /**
     * Sync local WiseTransfer record with Wise API status.
     */
    public function syncTransferStatus(WiseConnection $connection, WiseTransfer $transfer): WiseTransfer
    {
        if (! $transfer->wise_transfer_id) {
            return $transfer;
        }

        $wiseTransfer = $this->getTransfer($connection, $transfer->wise_transfer_id);

        $statusMap = [
            'incoming_payment_waiting' => WiseTransfer::STATUS_PENDING,
            'incoming_payment_initiated' => WiseTransfer::STATUS_PROCESSING,
            'processing' => WiseTransfer::STATUS_PROCESSING,
            'funds_converted' => WiseTransfer::STATUS_FUNDS_CONVERTED,
            'outgoing_payment_sent' => WiseTransfer::STATUS_COMPLETED,
            'bounced_back' => WiseTransfer::STATUS_FAILED,
            'cancelled' => WiseTransfer::STATUS_CANCELLED,
            'funds_refunded' => WiseTransfer::STATUS_FAILED,
        ];

        $newStatus = $statusMap[$wiseTransfer['status']] ?? $transfer->status;

        if ($transfer->status !== $newStatus) {
            $transfer->updateStatus($newStatus);
        }

        return $transfer;
    }

    /**
     * Get a summary of account state for agent consumption.
     */
    public function getAccountSummary(WiseConnection $connection): array
    {
        $balances = $this->getBalances($connection);
        $pendingTransfers = $this->listTransfers($connection, 'processing', 50);

        $totalUsd = 0;
        $balanceSummary = [];

        foreach ($balances as $balance) {
            $balanceSummary[] = [
                'currency' => $balance['currency'],
                'amount' => $balance['amount']['value'],
            ];

            // Rough USD equivalent (would need real rates in production)
            if ($balance['currency'] === 'USD') {
                $totalUsd += $balance['amount']['value'];
            }
        }

        $pendingAmount = 0;
        foreach ($pendingTransfers as $transfer) {
            $pendingAmount += $transfer['sourceValue'] ?? 0;
        }

        return [
            'balances' => $balanceSummary,
            'total_usd_equivalent' => $totalUsd,
            'pending_transfers_count' => count($pendingTransfers),
            'pending_transfers_amount' => $pendingAmount,
            'last_synced' => now()->toIso8601String(),
        ];
    }
}
