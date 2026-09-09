<?php

use App\Models\Client;
use App\Models\ClientSheetSync;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Google\SheetsService;
use App\Services\GoogleSheets\SheetTaskSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * In-memory fake for the Google Sheets API. Captures writes so tests can
 * assert what we'd have sent to Google, and serves whatever 2D values the
 * test seeded.
 */
class FakeSheetsService extends SheetsService
{
    /** @var array<string, array<int, array<int, mixed>>> */
    public array $valuesByRange = [];

    /** @var array<int, array{range:string, values:array<int,array<int,mixed>>}> */
    public array $batchUpdates = [];

    /** @var array<int, array{range:string, value:string}> */
    public array $cellUpdates = [];

    public function __construct() {}

    public function getMetadata(User $user, string $spreadsheetId): array
    {
        return [
            'spreadsheetId' => $spreadsheetId,
            'properties' => ['title' => 'Fake'],
            'sheets' => [[
                'properties' => ['sheetId' => 0, 'title' => 'Sheet1', 'index' => 0],
            ]],
        ];
    }

    /** @var array<string, array<int, array<int, string>>> */
    public array $formulasByRange = [];

    public function getValues(User $user, string $spreadsheetId, string $range, string $valueRenderOption = 'UNFORMATTED_VALUE'): array
    {
        if ($valueRenderOption === 'FORMULA') {
            return $this->formulasByRange[$range] ?? $this->valuesByRange[$range] ?? [];
        }

        return $this->valuesByRange[$range] ?? [];
    }

    public function getValuesAndFormulas(User $user, string $spreadsheetId, string $range): array
    {
        return [
            'values' => $this->valuesByRange[$range] ?? [],
            'formulas' => $this->formulasByRange[$range] ?? $this->valuesByRange[$range] ?? [],
        ];
    }

    public function updateCell(User $user, string $spreadsheetId, string $range, string $value): void
    {
        $this->cellUpdates[] = ['range' => $range, 'value' => $value];
        $this->valuesByRange[$range] = [[$value]];
    }

    public function updateValues(User $user, string $spreadsheetId, string $range, array $values): void
    {
        $this->valuesByRange[$range] = $values;
    }

    public function batchUpdateValues(User $user, string $spreadsheetId, array $updates): void
    {
        foreach ($updates as $u) {
            $this->batchUpdates[] = $u;
            $this->valuesByRange[$u['range']] = $u['values'];
        }
    }

    public function batchUpdate(User $user, string $spreadsheetId, array $requests): array
    {
        return [];
    }
}

beforeEach(function () {
    User::factory()->create();
    $this->user = User::first();
    $this->client = Client::factory()->create();
    Project::factory()->create(['client_id' => $this->client->id, 'status' => 'active']);

    $this->fake = new FakeSheetsService;
    app()->instance(SheetsService::class, $this->fake);

    $this->config = ClientSheetSync::create([
        'client_id' => $this->client->id,
        'spreadsheet_id' => 'sheet-abc',
        'sheet_title' => 'Sheet1',
        'sheet_gid' => 0,
        'header_row' => 1,
        'column_map' => ClientSheetSync::defaultColumnMap(),
        'active' => true,
    ]);

    // Header row (no Zao ID column yet — service should append it)
    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status'],
    ];
});

it('appends a Zao ID column on first run and creates tasks from pending rows', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Fix cache headers', 'Agency', 'Bug', 'Agency', 'High', 'Cache stuck on inventory', 'https://example.com', '', 'Pending'],
        ['Polish typography', 'Home Page', 'Design', '', 'Low', 'Headings cramped', '', '', 'Pending'],
    ];

    $stats = app(SheetTaskSyncService::class)->sync($this->config, $this->user);

    expect($stats['rows_seen'])->toBe(2)
        ->and($stats['tasks_created'])->toBe(2)
        ->and($stats['ids_assigned'])->toBe(2);

    // The Zao ID column header was added as column J (10th).
    expect($this->fake->cellUpdates[0]['range'])->toBe('Sheet1!J1')
        ->and($this->fake->cellUpdates[0]['value'])->toBe('Zao ID');

    expect(Task::where('source', 'activity-feed')->count())->toBe(2);
});

it('does not duplicate tasks on a second run', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Fix cache headers', 'Agency', 'Bug', 'Agency', 'High', 'note', '', '', 'Pending'],
    ];

    $service = app(SheetTaskSyncService::class);

    $first = $service->sync($this->config, $this->user);
    expect($first['tasks_created'])->toBe(1);

    // Mirror the UUID assignment back into the input data the way the real
    // sheet would after the first sync wrote it.
    $assignedId = $this->fake->batchUpdates[0]['values'][0][0];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Fix cache headers', 'Agency', 'Bug', 'Agency', 'High', 'note', '', '', 'Pending', $assignedId],
    ];
    // Header now has Zao ID at position 10
    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status', 'Zao ID'],
    ];

    $second = $service->sync($this->config, $this->user);

    expect($second['tasks_created'])->toBe(0)
        ->and(Task::where('source', 'activity-feed')->count())->toBe(1);
});

it('updates task status when the sheet flips Pending → Done', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Pending'],
    ];

    $service = app(SheetTaskSyncService::class);
    $service->sync($this->config, $this->user);

    $task = Task::first();
    expect($task->status)->toBe('pending');

    $assignedId = $this->fake->batchUpdates[0]['values'][0][0];
    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status', 'Zao ID'],
    ];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Done', $assignedId],
    ];

    $service->sync($this->config, $this->user);

    expect($task->fresh()->status)->toBe('completed');
});

it('writes Underway to the sheet when a task moves to in_progress', function () {
    // Fake the queue so the TaskObserver's writeback job doesn't race with
    // the reconciliation pass we're testing here.
    Queue::fake();

    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Pending'],
    ];

    $service = app(SheetTaskSyncService::class);
    $service->sync($this->config, $this->user);
    $assignedId = $this->fake->batchUpdates[0]['values'][0][0];

    $task = Task::first();
    $task->update(['status' => 'in_progress']);

    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status', 'Zao ID'],
    ];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Pending', $assignedId],
    ];

    $service->sync($this->config, $this->user);

    $writeRanges = array_column($this->fake->batchUpdates, 'range');
    expect($writeRanges)->toContain('Sheet1!I2');
    $statusWrite = collect($this->fake->batchUpdates)->firstWhere('range', 'Sheet1!I2');
    expect($statusWrite['values'][0][0])->toBe('Underway');
});

it('captures unmapped columns and surfaces them in the task description', function () {
    // Header now includes a ClickUp Link column that is NOT in the column map.
    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status'],
    ];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Vendor reg broken', 'Agency', 'Bug', 'Agency', 'High', 'Cannot complete signup', '', 'CU Link', 'Pending'],
    ];
    // ClickUp Link is a hyperlink — formula version surfaces the URL.
    $this->fake->formulasByRange['Sheet1!A2:J'] = [
        ['Vendor reg broken', 'Agency', 'Bug', 'Agency', 'High', 'Cannot complete signup', '', '=HYPERLINK("https://app.clickup.com/t/abc123", "CU Link")', 'Pending'],
    ];

    app(SheetTaskSyncService::class)->sync($this->config, $this->user);

    $task = \App\Models\Task::first();
    expect($task->description)->toContain('Cannot complete signup')
        ->and($task->description)->toContain('ClickUp Link: CU Link')
        ->and($task->description)->toContain('https://app.clickup.com/t/abc123');
});

it('deeplinks the external_url to the specific row', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Row two issue', 'Agency', 'Bug', 'Agency', 'High', 'note', '', '', 'Pending'],
        ['Row three issue', 'Agency', 'Bug', 'Agency', 'High', 'note', '', '', 'Pending'],
    ];

    app(SheetTaskSyncService::class)->sync($this->config, $this->user);

    $rowThree = \App\Models\Task::where('title', 'Row three issue')->first();
    $mapping = $rowThree->externalMappings->first();
    expect($mapping->external_url)->toContain('range=A3')
        ->and($mapping->external_url)->toContain('gid=0');
});

it('refreshes task description on subsequent syncs when sheet content changes', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Original title', 'Asset A', 'Bug', 'Agency', 'High', 'first version', '', '', 'Pending'],
    ];

    $service = app(SheetTaskSyncService::class);
    $service->sync($this->config, $this->user);
    $assignedId = $this->fake->batchUpdates[0]['values'][0][0];

    // Cory updates the row — adds richer notes
    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status', 'Zao ID'],
    ];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Original title', 'Asset A', 'Bug', 'Agency', 'High', 'updated notes with more detail', '', '', 'Pending', $assignedId],
    ];

    $service->sync($this->config, $this->user);

    expect(\App\Models\Task::first()->description)->toContain('updated notes with more detail');
});

it('never writes Done back to the sheet, even if internal status hits completed', function () {
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Pending'],
    ];

    $service = app(SheetTaskSyncService::class);
    $service->sync($this->config, $this->user);
    $assignedId = $this->fake->batchUpdates[0]['values'][0][0];
    $this->fake->batchUpdates = []; // clear baseline writes

    Task::first()->update(['status' => 'completed']);

    $this->fake->valuesByRange['Sheet1!1:1'] = [
        ['Task / Issue Description', 'Asset', 'Task Type', 'User Type', 'Priority', 'Notes', 'Website Link', 'ClickUp Link', 'Status', 'Zao ID'],
    ];
    $this->fake->valuesByRange['Sheet1!A2:J'] = [
        ['Bug A', 'Agency', 'Bug', 'Agency', 'High', '', '', '', 'Pending', $assignedId],
    ];

    $service->sync($this->config, $this->user);

    // No status-cell writeback should have happened from sync. (Any other
    // writes would be ID assignments to *new* rows, which there aren't here.)
    $statusWrites = collect($this->fake->batchUpdates)->where('range', 'Sheet1!I2');
    expect($statusWrites)->toBeEmpty();
});
