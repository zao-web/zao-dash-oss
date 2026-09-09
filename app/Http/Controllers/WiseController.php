<?php

namespace App\Http\Controllers;

use App\Models\WiseConnection;
use App\Models\WiseTransfer;
use App\Services\Wise\WiseApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WiseController extends Controller
{
    public function __construct(protected WiseApiService $wiseService) {}

    /**
     * Show Wise connection status and management page.
     */
    public function index()
    {
        $connection = WiseConnection::where('user_id', Auth::id())->first();

        $transfers = null;
        $balances = null;

        if ($connection) {
            try {
                $balances = $this->wiseService->getBalances($connection);
            } catch (\Exception $e) {
                // Connection may be invalid
            }

            $transfers = WiseTransfer::where('wise_connection_id', $connection->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($tr) => [
                    'id' => $tr->id,
                    'recipient_name' => $tr->recipient_name,
                    'source_amount' => $tr->source_amount,
                    'source_currency' => $tr->source_currency,
                    'target_amount' => $tr->target_amount,
                    'target_currency' => $tr->target_currency,
                    'fee' => $tr->fee,
                    'status' => $tr->status,
                    'payment_type' => $tr->payment_type,
                    'created_at' => $tr->created_at->format('M d, Y H:i'),
                ]);
        }

        return Inertia::render('Settings/Wise', [
            'connection' => $connection ? [
                'id' => $connection->id,
                'profile_id' => $connection->profile_id,
                'profile_type' => $connection->profile_type,
                'is_active' => $connection->is_active,
                'last_synced_at' => $connection->last_synced_at?->diffForHumans(),
            ] : null,
            'balances' => $balances,
            'recentTransfers' => $transfers,
        ]);
    }

    /**
     * Store API token manually (Wise uses API tokens, not OAuth).
     * Users generate tokens from their Wise account settings.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'api_token' => 'required|string',
            'profile_id' => 'required|string',
            'profile_type' => 'required|in:personal,business',
        ]);

        // Test the token by fetching profiles
        $testConnection = new WiseConnection([
            'api_token' => $validated['api_token'],
            'profile_id' => $validated['profile_id'],
        ]);

        try {
            $profiles = $this->wiseService->getProfiles($testConnection);

            // Verify the profile_id exists in the returned profiles
            $profileExists = collect($profiles)->contains('id', (int) $validated['profile_id']);

            if (! $profileExists) {
                return redirect()->back()
                    ->with('error', 'The profile ID does not match any profiles for this API token.');
            }
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Invalid API token: '.$e->getMessage());
        }

        // Create or update connection
        WiseConnection::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'profile_id' => $validated['profile_id'],
                'profile_type' => $validated['profile_type'],
                'api_token' => $validated['api_token'],
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        return redirect()->back()
            ->with('success', 'Wise account connected successfully.');
    }

    /**
     * Disconnect Wise account.
     */
    public function destroy()
    {
        WiseConnection::where('user_id', Auth::id())->delete();

        return redirect()->back()
            ->with('success', 'Wise account disconnected.');
    }

    /**
     * Get current balances (API endpoint for AJAX).
     */
    public function balances()
    {
        $connection = WiseConnection::where('user_id', Auth::id())->active()->first();

        if (! $connection) {
            return response()->json(['error' => 'No active Wise connection'], 404);
        }

        try {
            $balances = $this->wiseService->getBalances($connection);
            $connection->markSynced();

            return response()->json([
                'balances' => $balances,
                'synced_at' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get recipients list.
     */
    public function recipients(Request $request)
    {
        $connection = WiseConnection::where('user_id', Auth::id())->active()->first();

        if (! $connection) {
            return response()->json(['error' => 'No active Wise connection'], 404);
        }

        try {
            $recipients = $this->wiseService->listRecipients($connection, $request->get('currency'));

            return response()->json([
                'recipients' => array_map(fn ($r) => [
                    'id' => $r['id'],
                    'name' => $r['accountHolderName'],
                    'currency' => $r['currency'],
                    'type' => $r['type'],
                    'active' => $r['active'],
                ], $recipients),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a quote for a transfer.
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'source_currency' => 'required|string|size:3',
            'target_currency' => 'required|string|size:3',
            'amount' => 'required|numeric|min:1',
            'amount_type' => 'required|in:source,target',
        ]);

        $connection = WiseConnection::where('user_id', Auth::id())->active()->first();

        if (! $connection) {
            return response()->json(['error' => 'No active Wise connection'], 404);
        }

        try {
            $quoteData = [
                'source_currency' => $validated['source_currency'],
                'target_currency' => $validated['target_currency'],
            ];

            if ($validated['amount_type'] === 'source') {
                $quoteData['source_amount'] = $validated['amount'];
            } else {
                $quoteData['target_amount'] = $validated['amount'];
            }

            $quote = $this->wiseService->createQuote($connection, $quoteData);

            return response()->json([
                'quote_id' => $quote['id'],
                'source_amount' => $quote['sourceAmount'],
                'target_amount' => $quote['targetAmount'],
                'rate' => $quote['rate'],
                'fee' => $quote['fee']['total'] ?? 0,
                'estimated_delivery' => $quote['estimatedDelivery'] ?? null,
                'expires_at' => $quote['expirationTime'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transfer status.
     */
    public function transferStatus(WiseTransfer $transfer)
    {
        $connection = $transfer->wiseConnection;

        if (! $connection || $connection->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $transfer->wise_transfer_id) {
            return response()->json([
                'status' => $transfer->status,
                'message' => 'Transfer not yet submitted to Wise',
            ]);
        }

        try {
            $wiseTransfer = $this->wiseService->getTransfer($connection, $transfer->wise_transfer_id);

            // Update local status
            $this->wiseService->syncTransferStatus($connection, $transfer);

            return response()->json([
                'status' => $transfer->fresh()->status,
                'wise_status' => $wiseTransfer['status'],
                'created' => $wiseTransfer['created'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle Wise webhooks.
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('X-Signature-SHA256');
        $payload = $request->getContent();

        // Find connection by profile in webhook
        $data = json_decode($payload, true);
        $profileId = $data['data']['resource']['profile_id'] ?? null;

        if (! $profileId) {
            return response()->json(['error' => 'Missing profile'], 400);
        }

        $connection = WiseConnection::where('profile_id', $profileId)->first();

        if (! $connection) {
            return response()->json(['error' => 'Unknown profile'], 404);
        }

        // Verify signature
        if ($connection->webhook_secret) {
            if (! $this->wiseService->verifyWebhookSignature($payload, $signature, $connection->webhook_secret)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        // Process webhook based on event type
        $eventType = $data['event_type'] ?? '';

        if (str_starts_with($eventType, 'transfers#')) {
            $this->handleTransferWebhook($data);
        }

        return response()->json(['received' => true]);
    }

    protected function handleTransferWebhook(array $data): void
    {
        $transferId = $data['data']['resource']['id'] ?? null;

        if (! $transferId) {
            return;
        }

        $transfer = WiseTransfer::where('wise_transfer_id', $transferId)->first();

        if ($transfer) {
            $statusMap = [
                'incoming_payment_waiting' => WiseTransfer::STATUS_PENDING,
                'processing' => WiseTransfer::STATUS_PROCESSING,
                'funds_converted' => WiseTransfer::STATUS_FUNDS_CONVERTED,
                'outgoing_payment_sent' => WiseTransfer::STATUS_COMPLETED,
                'cancelled' => WiseTransfer::STATUS_CANCELLED,
                'bounced_back' => WiseTransfer::STATUS_FAILED,
                'funds_refunded' => WiseTransfer::STATUS_FAILED,
            ];

            $newStatus = $statusMap[$data['data']['current_state']] ?? null;

            if ($newStatus) {
                $transfer->updateStatus($newStatus);
            }
        }
    }
}
