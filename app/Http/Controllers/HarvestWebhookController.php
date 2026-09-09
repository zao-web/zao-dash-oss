<?php

namespace App\Http\Controllers;

use App\Models\HarvestInvoice;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HarvestWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event = $payload['event'] ?? null;

        Log::info('Harvest webhook received', ['event' => $event]);

        return match ($event) {
            'time_entry.created', 'time_entry.updated' => $this->handleTimeEntry($payload),
            'time_entry.deleted' => $this->handleTimeEntryDeleted($payload),
            'invoice.created', 'invoice.updated' => $this->handleInvoice($payload),
            'invoice.deleted' => $this->handleInvoiceDeleted($payload),
            default => response()->json(['message' => 'Event not handled']),
        };
    }

    protected function handleTimeEntry(array $payload): JsonResponse
    {
        $data = $payload['time_entry'] ?? [];

        if (empty($data['id'])) {
            return response()->json(['error' => 'Missing time entry ID'], 400);
        }

        $entry = TimeEntry::where('harvest_id', $data['id'])->first();

        if ($entry) {
            $entry->update([
                'hours' => $data['hours'] ?? $entry->hours,
                'notes' => $data['notes'] ?? $entry->notes,
                'is_running' => $data['is_running'] ?? $entry->is_running,
                'is_billed' => $data['is_billed'] ?? $entry->is_billed,
            ]);
        }

        return response()->json(['message' => 'Time entry processed']);
    }

    protected function handleTimeEntryDeleted(array $payload): JsonResponse
    {
        $harvestId = $payload['time_entry']['id'] ?? null;

        if ($harvestId) {
            TimeEntry::where('harvest_id', $harvestId)->delete();
        }

        return response()->json(['message' => 'Time entry deleted']);
    }

    protected function handleInvoice(array $payload): JsonResponse
    {
        $data = $payload['invoice'] ?? [];

        if (empty($data['id'])) {
            return response()->json(['error' => 'Missing invoice ID'], 400);
        }

        $invoice = HarvestInvoice::where('harvest_id', $data['id'])->first();

        if ($invoice) {
            $invoice->update([
                'state' => $data['state'] ?? $invoice->state,
                'amount' => $data['amount'] ?? $invoice->amount,
                'due_amount' => $data['due_amount'] ?? $invoice->due_amount,
                'sent_at' => $data['sent_at'] ?? $invoice->sent_at,
                'paid_at' => $data['paid_at'] ?? $invoice->paid_at,
            ]);
        }

        return response()->json(['message' => 'Invoice processed']);
    }

    protected function handleInvoiceDeleted(array $payload): JsonResponse
    {
        $harvestId = $payload['invoice']['id'] ?? null;

        if ($harvestId) {
            HarvestInvoice::where('harvest_id', $harvestId)->delete();
        }

        return response()->json(['message' => 'Invoice deleted']);
    }
}
