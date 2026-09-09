<?php

namespace App\Agents\Tools;

use App\Models\Contractor;
use App\Models\WiseConnection;
use App\Models\WiseTransfer;
use App\Services\Wise\WiseApiService;

/**
 * Initiate a wire transfer via Wise.
 *
 * CRITICAL: This tool requires owner approval before execution.
 * It creates a transfer request that must be approved in the approval queue.
 */
class WiseInitiateTransferTool extends BaseTool
{
    protected WiseApiService $wiseService;

    public function __construct(WiseApiService $wiseService)
    {
        $this->wiseService = $wiseService;
    }

    public function category(): string
    {
        return 'wise';
    }

    public function name(): string
    {
        return 'Initiate Wise Transfer';
    }

    public function description(): string
    {
        return 'Initiate a wire transfer to pay a contractor or recipient via Wise. IMPORTANT: This creates a transfer request that requires owner approval before funds are sent. Never auto-executes payments.';
    }

    public function requiresApproval(): bool
    {
        return true; // CRITICAL: Always requires approval
    }

    public function riskLevel(): string
    {
        return 'critical';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipient_id' => [
                    'type' => 'string',
                    'description' => 'Wise recipient ID. Get from wise-get-recipients or use contractor\'s wise_recipient_id.',
                ],
                'contractor_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Our contractor ID to link this payment.',
                ],
                'amount' => [
                    'type' => 'number',
                    'description' => 'Amount to send in source currency.',
                ],
                'source_currency' => [
                    'type' => 'string',
                    'description' => 'Source currency code (e.g., USD). Default: USD.',
                    'default' => 'USD',
                ],
                'target_currency' => [
                    'type' => 'string',
                    'description' => 'Target currency code. If different from source, exchange will apply.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Payment reference (shows on recipient\'s bank statement). Max 10 chars for some countries.',
                ],
                'payment_type' => [
                    'type' => 'string',
                    'enum' => ['recurring', 'invoice', 'bonus', 'reimbursement'],
                    'description' => 'Type of payment for categorization.',
                    'default' => 'invoice',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Reason for the payment (for audit trail).',
                ],
            ],
            'required' => ['recipient_id', 'amount', 'reason'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'recipient_id' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'source_currency' => 'string|size:3',
            'target_currency' => 'string|size:3',
            'reason' => 'required|string|min:5',
        ];
    }

    public function execute(array $params): array
    {
        $connection = WiseConnection::active()->first();

        if (! $connection) {
            return [
                'success' => false,
                'error' => 'No active Wise connection found.',
            ];
        }

        $sourceCurrency = strtoupper($params['source_currency'] ?? 'USD');
        $targetCurrency = strtoupper($params['target_currency'] ?? $sourceCurrency);

        // Check balance first
        $balance = $this->wiseService->getBalance($connection, $sourceCurrency);
        if (! $balance || ($balance['amount']['value'] ?? 0) < $params['amount']) {
            $available = $balance['amount']['value'] ?? 0;

            return [
                'success' => false,
                'error' => "Insufficient {$sourceCurrency} balance. Available: {$available}, Required: {$params['amount']}",
            ];
        }

        try {
            // Get quote for the transfer
            $quote = $this->wiseService->createQuote($connection, [
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'source_amount' => $params['amount'],
            ]);

            // Get recipient name
            $recipient = $this->wiseService->getRecipient($connection, $params['recipient_id']);
            $recipientName = $recipient['accountHolderName'] ?? 'Unknown';

            // Find contractor if ID provided
            $contractor = null;
            if (isset($params['contractor_id'])) {
                $contractor = Contractor::find($params['contractor_id']);
            }

            // Create transfer record (PENDING - awaits approval)
            $transfer = WiseTransfer::create([
                'wise_connection_id' => $connection->id,
                'contractor_id' => $contractor?->id,
                'wise_quote_id' => $quote['id'],
                'source_amount' => $quote['sourceAmount'],
                'source_currency' => $quote['sourceCurrency'],
                'target_amount' => $quote['targetAmount'],
                'target_currency' => $quote['targetCurrency'],
                'exchange_rate' => $quote['rate'] ?? 1,
                'fee' => $quote['fee']['total'] ?? 0,
                'recipient_id' => $params['recipient_id'],
                'recipient_name' => $recipientName,
                'reference' => $params['reference'] ?? null,
                'notes' => $params['reason'],
                'payment_type' => $params['payment_type'] ?? 'invoice',
                'status' => WiseTransfer::STATUS_PENDING,
                // initiated_by will be set by the approval system
            ]);

            return [
                'success' => true,
                'transfer_id' => $transfer->id,
                'status' => 'pending_approval',
                'message' => 'Transfer request created. Requires owner approval to execute.',
                'details' => [
                    'recipient' => $recipientName,
                    'send_amount' => $quote['sourceAmount'].' '.$sourceCurrency,
                    'receive_amount' => $quote['targetAmount'].' '.$targetCurrency,
                    'fee' => ($quote['fee']['total'] ?? 0).' '.$sourceCurrency,
                    'rate' => $quote['rate'] ?? 1,
                    'total_cost' => ($quote['sourceAmount'] + ($quote['fee']['total'] ?? 0)).' '.$sourceCurrency,
                ],
                'approval_required' => true,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to initiate transfer: '.$e->getMessage(),
            ];
        }
    }
}
