<?php

use App\Jobs\PushTaskStatusToSheetJob;
use App\Models\Client;
use App\Models\ClientSheetSync;
use App\Models\ExternalTaskMapping;
use App\Models\ExternalTaskSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Google\SheetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

class JobFakeSheetsService extends SheetsService
{
    /** @var array<int, array{range:string, value:string}> */
    public array $cellUpdates = [];

    public function __construct() {}

    public function updateCell(User $user, string $spreadsheetId, string $range, string $value): void
    {
        $this->cellUpdates[] = ['range' => $range, 'value' => $value];
    }
}

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'owner']);
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create(['client_id' => $this->client->id]);

    $this->fake = new JobFakeSheetsService;
    app()->instance(SheetsService::class, $this->fake);

    $this->config = ClientSheetSync::create([
        'client_id' => $this->client->id,
        'spreadsheet_id' => 'sheet-abc',
        'sheet_title' => 'Sheet1',
        'sheet_gid' => 0,
        'header_row' => 1,
        'column_map' => ClientSheetSync::defaultColumnMap(),
        'status_column_letter' => 'I',
        'zao_id_column_letter' => 'J',
        'active' => true,
    ]);

    $this->source = ExternalTaskSource::create([
        'pm_connection_id' => null,
        'client_id' => $this->client->id,
        'external_id' => 'sheet:sheet-abc',
        'name' => 'Locumpedia tracker',
        'type' => ExternalTaskSource::TYPE_GOOGLE_SHEET,
        'auto_import' => true,
        'sync_back' => true,
    ]);

    $this->task = Task::factory()->create([
        'project_id' => $this->project->id,
        'source' => 'activity-feed',
        'status' => 'pending',
        'title' => 'Vendor registration broken',
    ]);

    $this->mapping = ExternalTaskMapping::create([
        'external_task_source_id' => $this->source->id,
        'external_id' => 'gs:sheet-abc:zao_abc',
        'task_id' => $this->task->id,
        'external_url' => 'https://example.com',
        'external_data' => [
            'row_number' => 5,
            'sheet_status_at_last_sync' => 'pending',
            'task_status_at_last_sync' => 'pending',
            'client_id' => $this->client->id,
        ],
        'sync_status' => ExternalTaskMapping::STATUS_SYNCED,
        'sync_direction' => ExternalTaskMapping::DIRECTION_BIDIRECTIONAL,
        'last_synced_at' => now(),
    ]);
});

it('dispatches a writeback job when an activity-feed task moves to in_progress', function () {
    Queue::fake();

    $this->task->update(['status' => 'in_progress']);

    Queue::assertPushed(PushTaskStatusToSheetJob::class, function ($job) {
        return $job->mappingId === $this->mapping->id && $job->sheetStatusToWrite === 'Underway';
    });
});

it('dispatches a writeback job when an activity-feed task moves to review', function () {
    Queue::fake();

    $this->task->update(['status' => 'review']);

    Queue::assertPushed(PushTaskStatusToSheetJob::class, function ($job) {
        return $job->sheetStatusToWrite === 'Reviewing';
    });
});

it('does NOT dispatch a writeback job when a task moves to completed (client owns Done)', function () {
    Queue::fake();

    $this->task->update(['status' => 'completed']);

    Queue::assertNotPushed(PushTaskStatusToSheetJob::class);
});

it('does NOT dispatch a writeback job when a task moves back to pending', function () {
    $this->task->update(['status' => 'in_progress']);

    Queue::fake();
    $this->task->update(['status' => 'pending']);

    Queue::assertNotPushed(PushTaskStatusToSheetJob::class);
});

it('actually writes the correct cell when the job runs', function () {
    $job = new PushTaskStatusToSheetJob($this->mapping->id, 'Underway');
    $job->handle($this->fake);

    expect($this->fake->cellUpdates)->toHaveCount(1)
        ->and($this->fake->cellUpdates[0]['range'])->toBe('Sheet1!I5')
        ->and($this->fake->cellUpdates[0]['value'])->toBe('Underway');

    $this->mapping->refresh();
    expect($this->mapping->external_data['sheet_status_at_last_sync'])->toBe('underway');
});
