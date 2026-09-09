<?php

namespace App\Services\GoogleSheets;

use App\Models\ClientSheetSync;
use App\Models\ExternalTaskMapping;
use App\Models\ExternalTaskSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Google\SheetsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bidirectional sync between a client's Google Sheet task tracker and the
 * internal Task table. Asymmetric writeback: we push only Underway and
 * Reviewing back to the sheet; the client owns Pending (initial state)
 * and Done (final verdict).
 *
 * Conflict resolution is last-writer-wins, compared against the snapshot
 * we recorded in the ExternalTaskMapping on the previous sync.
 */
class SheetTaskSyncService
{
    /** Sheet status (what Cory sees) → internal Task.status enum. */
    public const SHEET_TO_TASK_STATUS = [
        'pending' => 'pending',
        'underway' => 'in_progress',
        'reviewing' => 'review',
        'done' => 'completed',
    ];

    /**
     * Internal Task.status → Sheet status to write. NULL means we do NOT
     * push this status; the sheet keeps whatever it has. We never push
     * "Done" (Cory marks Done after verifying) or overwrite "Pending"
     * with our own pending.
     */
    public const TASK_TO_SHEET_STATUS = [
        'pending' => null,
        'in_progress' => 'Underway',
        'review' => 'Reviewing',
        'completed' => null,
    ];

    public function __construct(
        protected SheetsService $sheets,
    ) {}

    /**
     * Run a single sync pass for one ClientSheetSync configuration.
     *
     * @return array{rows_seen:int, ids_assigned:int, tasks_created:int, tasks_updated:int, sheet_writes:int, skipped_conflict:int}
     */
    public function sync(ClientSheetSync $config, User $user): array
    {
        $stats = [
            'rows_seen' => 0,
            'ids_assigned' => 0,
            'tasks_created' => 0,
            'tasks_updated' => 0,
            'sheet_writes' => 0,
            'skipped_conflict' => 0,
        ];

        $sheetTitle = $this->resolveSheetTitle($config, $user);
        $headers = $this->readHeaderRow($config, $user, $sheetTitle);

        $headerMap = $this->resolveHeaderMap($config, $headers);

        // Auto-add Zao ID column if it doesn't exist in the sheet yet.
        if (! isset($headerMap['zao_id'])) {
            $headerMap['zao_id'] = $this->appendZaoIdColumn($config, $user, $sheetTitle, count($headers));
            $config->update(['zao_id_column_letter' => SheetsService::columnLetter($headerMap['zao_id']['column'] + 1)]);
        } elseif (! $config->zao_id_column_letter) {
            $config->update(['zao_id_column_letter' => SheetsService::columnLetter($headerMap['zao_id']['column'] + 1)]);
        }

        if (! $config->status_column_letter && isset($headerMap['status'])) {
            $config->update(['status_column_letter' => SheetsService::columnLetter($headerMap['status']['column'] + 1)]);
        }

        // Read every data row across the FULL width of the header row so we
        // capture columns that aren't in column_map (ClickUp Link, Image,
        // anything Cory adds later). Second pass with FORMULA value-render
        // gives us back the HYPERLINK() URLs that UNFORMATTED_VALUE strips.
        $totalColumnCount = max(count($headers), max(array_column($headerMap, 'column')) + 1);
        $lastColLetter = SheetsService::columnLetter($totalColumnCount);
        $dataRange = "{$sheetTitle}!A".($config->header_row + 1).":{$lastColLetter}";
        $bothPasses = $this->sheets->getValuesAndFormulas($user, $config->spreadsheet_id, $dataRange);
        $rows = $bothPasses['values'];
        $formulaRows = $bothPasses['formulas'];

        $idAssignments = [];
        $statusWritebacks = [];
        $source = $this->resolveSource($config);

        foreach ($rows as $offset => $row) {
            $rowNumber = $config->header_row + 1 + $offset;
            $formulaRow = $formulaRows[$offset] ?? [];
            $rowData = $this->mapRow($row, $headerMap);
            $allFields = $this->extractAllFields($headers, $row, $formulaRow);

            if ($this->isEffectivelyEmpty($rowData)) {
                continue;
            }

            $stats['rows_seen']++;

            // Ensure every real row carries a Zao ID. Queue writes in a batch
            // so we don't burn a quota call per row.
            $zaoId = trim((string) ($rowData['zao_id'] ?? ''));
            if ($zaoId === '') {
                $zaoId = SheetsService::generateRowId();
                $rowData['zao_id'] = $zaoId;
                $idAssignments[] = [
                    'range' => sprintf('%s!%s%d', $sheetTitle, $config->zao_id_column_letter, $rowNumber),
                    'values' => [[$zaoId]],
                ];
                $stats['ids_assigned']++;
            }

            $externalId = sprintf('gs:%s:%s', $config->spreadsheet_id, $zaoId);
            $mapping = ExternalTaskMapping::query()
                ->where('external_task_source_id', $source->id)
                ->where('external_id', $externalId)
                ->with('task')
                ->first();

            $sheetStatusNow = $this->normalizeSheetStatus($rowData['status'] ?? null);

            if (! $mapping) {
                $task = Task::create([
                    'title' => $rowData['title'] ?? 'Untitled',
                    'description' => $this->buildDescription($rowData, $allFields),
                    'status' => self::SHEET_TO_TASK_STATUS[$sheetStatusNow] ?? 'pending',
                    'priority' => $this->mapPriority($rowData['priority'] ?? null),
                    'source' => 'activity-feed',
                    'project_id' => $this->primaryProjectIdFor($config),
                ]);

                ExternalTaskMapping::create([
                    'external_task_source_id' => $source->id,
                    'external_id' => $externalId,
                    'task_id' => $task->id,
                    'external_url' => $this->buildRowUrl($config, $rowNumber),
                    'external_data' => [
                        'row_number' => $rowNumber,
                        'row_snapshot' => $rowData,
                        'all_fields' => $allFields,
                        'sheet_status_at_last_sync' => $sheetStatusNow,
                        'task_status_at_last_sync' => $task->status,
                        'client_id' => $config->client_id,
                    ],
                    'sync_status' => ExternalTaskMapping::STATUS_SYNCED,
                    'sync_direction' => ExternalTaskMapping::DIRECTION_BIDIRECTIONAL,
                    'last_synced_at' => now(),
                ]);

                $stats['tasks_created']++;

                continue;
            }

            $task = $mapping->task;
            if (! $task) {
                continue;
            }

            $sheetStatusAtLastSync = $mapping->external_data['sheet_status_at_last_sync'] ?? null;
            $taskStatusAtLastSync = $mapping->external_data['task_status_at_last_sync'] ?? null;
            $taskStatusNow = $task->status;

            $sheetChanged = $sheetStatusNow !== $sheetStatusAtLastSync;
            $taskChanged = $taskStatusNow !== $taskStatusAtLastSync;

            if ($sheetChanged && $taskChanged) {
                // Both sides moved since last sync — last writer wins. Without
                // a per-cell timestamp from Sheets we can't tell who's more
                // recent; default to trusting the sheet (Cory's intent) and
                // log so we can revisit if this ever bites.
                Log::info('SheetTaskSyncService: both sides changed since last sync — trusting sheet', [
                    'config_id' => $config->id,
                    'task_id' => $task->id,
                    'sheet_status_now' => $sheetStatusNow,
                    'task_status_now' => $taskStatusNow,
                ]);
                $stats['skipped_conflict']++;
                $task->update(['status' => self::SHEET_TO_TASK_STATUS[$sheetStatusNow] ?? $task->status]);
            } elseif ($sheetChanged) {
                $task->update(['status' => self::SHEET_TO_TASK_STATUS[$sheetStatusNow] ?? $task->status]);
                $stats['tasks_updated']++;
            } elseif ($taskChanged) {
                $sheetTarget = self::TASK_TO_SHEET_STATUS[$taskStatusNow] ?? null;
                if ($sheetTarget !== null && isset($config->status_column_letter)) {
                    $statusWritebacks[] = [
                        'range' => sprintf('%s!%s%d', $sheetTitle, $config->status_column_letter, $rowNumber),
                        'values' => [[$sheetTarget]],
                    ];
                    $sheetStatusNow = strtolower($sheetTarget);
                    $stats['sheet_writes']++;
                }
            }

            // Always refresh the row snapshot + status checkpoints so next
            // sync's diffs work correctly. Also keep the description in
            // sync with whatever Cory's currently put in the row.
            $task->update(['description' => $this->buildDescription($rowData, $allFields)]);
            $mapping->update([
                'external_url' => $this->buildRowUrl($config, $rowNumber),
                'external_data' => array_merge($mapping->external_data ?? [], [
                    'row_number' => $rowNumber,
                    'row_snapshot' => $rowData,
                    'all_fields' => $allFields,
                    'sheet_status_at_last_sync' => $sheetStatusNow,
                    'task_status_at_last_sync' => $task->fresh()->status,
                    'client_id' => $config->client_id,
                ]),
                'last_synced_at' => now(),
            ]);
        }

        if (! empty($idAssignments)) {
            $this->sheets->batchUpdateValues($user, $config->spreadsheet_id, $idAssignments);
        }
        if (! empty($statusWritebacks)) {
            $this->sheets->batchUpdateValues($user, $config->spreadsheet_id, $statusWritebacks);
        }

        $config->update(['last_synced_at' => now()]);

        return $stats;
    }

    protected function resolveSheetTitle(ClientSheetSync $config, User $user): string
    {
        if ($config->sheet_title) {
            return $config->sheet_title;
        }

        $meta = $this->sheets->getMetadata($user, $config->spreadsheet_id);
        $firstSheet = $meta['sheets'][0]['properties'] ?? [];
        $title = $firstSheet['title'] ?? 'Sheet1';
        $config->update(['sheet_title' => $title, 'sheet_gid' => $firstSheet['sheetId'] ?? null]);

        return $title;
    }

    /**
     * @return array<int, string>
     */
    protected function readHeaderRow(ClientSheetSync $config, User $user, string $sheetTitle): array
    {
        $range = "{$sheetTitle}!{$config->header_row}:{$config->header_row}";
        $rows = $this->sheets->getValues($user, $config->spreadsheet_id, $range);

        return $rows[0] ?? [];
    }

    /**
     * Map each logical key in column_map to {column: int, header: string}.
     * Column index is zero-based.
     *
     * @param  array<int, string>  $headers
     * @return array<string, array{column:int, header:string}>
     */
    protected function resolveHeaderMap(ClientSheetSync $config, array $headers): array
    {
        $lookup = [];
        foreach ($headers as $i => $label) {
            $lookup[strtolower(trim((string) $label))] = ['column' => $i, 'header' => $label];
        }

        $result = [];
        foreach ($config->column_map as $logical => $target) {
            $key = strtolower(trim((string) $target));
            if (isset($lookup[$key])) {
                $result[$logical] = $lookup[$key];
            }
        }

        return $result;
    }

    /**
     * @return array{column:int, header:string}
     */
    protected function appendZaoIdColumn(ClientSheetSync $config, User $user, string $sheetTitle, int $existingCount): array
    {
        $newColIndex = $existingCount; // zero-based — append after the last existing column
        $cell = sprintf('%s!%s%d', $sheetTitle, SheetsService::columnLetter($newColIndex + 1), $config->header_row);
        $this->sheets->updateCell($user, $config->spreadsheet_id, $cell, 'Zao ID');

        return ['column' => $newColIndex, 'header' => 'Zao ID'];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, array{column:int, header:string}>  $headerMap
     * @return array<string, string>
     */
    protected function mapRow(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $logical => $meta) {
            $out[$logical] = trim((string) ($row[$meta['column']] ?? ''));
        }

        return $out;
    }

    protected function isEffectivelyEmpty(array $rowData): bool
    {
        $candidates = ['title', 'notes', 'asset'];
        foreach ($candidates as $key) {
            if (! empty($rowData[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeSheetStatus(?string $raw): string
    {
        $lower = strtolower(trim((string) $raw));

        return match ($lower) {
            'pending', 'underway', 'reviewing', 'done' => $lower,
            'in progress', 'in-progress' => 'underway',
            'review' => 'reviewing',
            'complete', 'completed', 'fixed' => 'done',
            default => 'pending',
        };
    }

    /**
     * Build a Markdown-friendly description that includes:
     *  - Cory's notes (primary)
     *  - Mapped metadata (asset, task type, user type, website link, etc.)
     *  - "Additional context" block listing any sheet columns we don't
     *    map explicitly — so things like ClickUp Link, Image, or new
     *    columns Cory adds later don't get silently dropped.
     */
    protected function buildDescription(array $rowData, array $allFields = []): string
    {
        $parts = [];
        if (! empty($rowData['notes'])) {
            $parts[] = $rowData['notes'];
        }

        $primary = [];
        foreach (['asset', 'task_type', 'user_type', 'priority', 'website_link'] as $key) {
            if (! empty($rowData[$key])) {
                $primary[] = ucwords(str_replace('_', ' ', $key)).': '.$rowData[$key];
            }
        }
        if ($primary) {
            $parts[] = "\n\n".implode("\n", $primary);
        }

        // Unmapped columns: anything in allFields whose header doesn't
        // correspond to a mapped logical field. Keep them so URLs from
        // hyperlink-only columns (ClickUp Link, etc.) survive.
        $mappedHeaderLabels = array_map('strtolower', array_values(array_filter([
            'task / issue description', 'asset', 'task type', 'user type',
            'priority', 'notes', 'website link', 'status', 'zao id',
        ])));

        $extras = [];
        foreach ($allFields as $header => $cell) {
            if (in_array(strtolower(trim($header)), $mappedHeaderLabels, true)) {
                continue;
            }
            $display = $cell['display'] ?? '';
            $url = $cell['url'] ?? null;
            if ($display === '' && ! $url) {
                continue;
            }

            if ($url && $display && $url !== $display) {
                $extras[] = "- {$header}: {$display} ({$url})";
            } elseif ($url) {
                $extras[] = "- {$header}: {$url}";
            } else {
                $extras[] = "- {$header}: {$display}";
            }
        }

        if ($extras) {
            $parts[] = "\n\n**Additional context from sheet:**\n".implode("\n", $extras);
        }

        return trim(implode('', $parts));
    }

    /**
     * Pull every column in the header row into a header-keyed map of
     * `{display, url}`. Used to capture data that isn't covered by
     * column_map so it can flow into the description.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $valueRow
     * @param  array<int, string>  $formulaRow
     * @return array<string, array{display:string, url:?string}>
     */
    protected function extractAllFields(array $headers, array $valueRow, array $formulaRow): array
    {
        $out = [];
        foreach ($headers as $i => $header) {
            $label = trim((string) $header);
            if ($label === '') {
                continue;
            }
            $display = trim((string) ($valueRow[$i] ?? ''));
            $url = SheetsService::extractHyperlinkUrl((string) ($formulaRow[$i] ?? ''));
            $out[$label] = ['display' => $display, 'url' => $url];
        }

        return $out;
    }

    protected function buildRowUrl(ClientSheetSync $config, int $rowNumber): string
    {
        return sprintf(
            'https://docs.google.com/spreadsheets/d/%s/edit?gid=%s&range=A%d',
            $config->spreadsheet_id,
            $config->sheet_gid ?? 0,
            $rowNumber,
        );
    }

    protected function mapPriority(?string $raw): string
    {
        return match (strtolower(trim((string) $raw))) {
            'urgent' => 'urgent',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    protected function primaryProjectIdFor(ClientSheetSync $config): ?int
    {
        return Project::query()
            ->where('client_id', $config->client_id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->value('id');
    }

    /**
     * Find-or-create the ExternalTaskSource row for this client's sheet so
     * mappings have a parent to belong to.
     */
    protected function resolveSource(ClientSheetSync $config): ExternalTaskSource
    {
        return DB::transaction(function () use ($config) {
            $source = ExternalTaskSource::query()
                ->where('client_id', $config->client_id)
                ->where('type', ExternalTaskSource::TYPE_GOOGLE_SHEET)
                ->where('external_id', 'sheet:'.$config->spreadsheet_id)
                ->whereNull('pm_connection_id')
                ->first();

            if ($source) {
                return $source;
            }

            return ExternalTaskSource::create([
                'pm_connection_id' => null,
                'client_id' => $config->client_id,
                'external_id' => 'sheet:'.$config->spreadsheet_id,
                'name' => sprintf('Sheet %s for client %d', $config->spreadsheet_id, $config->client_id),
                'type' => ExternalTaskSource::TYPE_GOOGLE_SHEET,
                'auto_import' => true,
                'sync_back' => true,
            ]);
        });
    }
}
