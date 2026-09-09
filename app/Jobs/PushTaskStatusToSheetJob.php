<?php

namespace App\Jobs;

use App\Models\ClientSheetSync;
use App\Models\ExternalTaskMapping;
use App\Models\User;
use App\Services\Google\SheetsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Push a single Task status change back to its Google Sheet cell.
 * Fire-and-forget; runs on the queue so request handlers don't wait on
 * Google's API.
 */
class PushTaskStatusToSheetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $mappingId,
        public string $sheetStatusToWrite,
    ) {}

    public function handle(SheetsService $sheets): void
    {
        $mapping = ExternalTaskMapping::with('source')->find($this->mappingId);
        if (! $mapping || ! $mapping->source) {
            return;
        }

        $clientId = $mapping->source->client_id;
        if (! $clientId) {
            return;
        }

        // sheet_id is encoded in source.external_id as "sheet:<spreadsheet_id>"
        $spreadsheetId = str_starts_with((string) $mapping->source->external_id, 'sheet:')
            ? substr($mapping->source->external_id, 6)
            : null;
        if (! $spreadsheetId) {
            Log::warning('PushTaskStatusToSheetJob: source missing spreadsheet id', [
                'mapping_id' => $mapping->id,
            ]);

            return;
        }

        $config = ClientSheetSync::query()
            ->where('client_id', $clientId)
            ->where('spreadsheet_id', $spreadsheetId)
            ->where('active', true)
            ->first();
        if (! $config || ! $config->sheet_title || ! $config->status_column_letter) {
            return;
        }

        $rowNumber = $mapping->external_data['row_number'] ?? null;
        if (! $rowNumber) {
            return;
        }

        $user = User::query()->where('role', 'owner')->orderBy('id')->first();
        if (! $user) {
            return;
        }

        $range = sprintf('%s!%s%d', $config->sheet_title, $config->status_column_letter, $rowNumber);

        try {
            $sheets->updateCell($user, $spreadsheetId, $range, $this->sheetStatusToWrite);

            $mapping->update([
                'external_data' => array_merge($mapping->external_data ?? [], [
                    'sheet_status_at_last_sync' => strtolower($this->sheetStatusToWrite),
                    'task_status_at_last_sync' => $mapping->task?->status,
                ]),
                'last_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PushTaskStatusToSheetJob: write failed', [
                'mapping_id' => $mapping->id,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
