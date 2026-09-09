<?php

use App\Jobs\SyncHarvestJob;
use App\Models\HarvestCredential;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\HarvestTaskCategory;
use App\Models\TimeEntry;
use App\Services\Harvest\HarvestService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncHarvestJob::dispatch();

    Queue::assertPushed(SyncHarvestJob::class);
});

test('job can be dispatched with all parameters', function () {
    Queue::fake();

    SyncHarvestJob::dispatch(
        credentialId: 1,
        syncProjects: false,
        syncTimeEntries: true,
        syncInvoices: false,
        syncTaskCategories: true,
        fromDate: '2024-01-01'
    );

    Queue::assertPushed(SyncHarvestJob::class, function ($job) {
        return $job->credentialId === 1
            && $job->syncProjects === false
            && $job->syncTimeEntries === true
            && $job->syncInvoices === false
            && $job->syncTaskCategories === true
            && $job->fromDate === '2024-01-01';
    });
});

test('handle syncs all active credentials', function () {
    $cred1 = HarvestCredential::factory()->create(['is_active' => true]);
    $cred2 = HarvestCredential::factory()->create(['is_active' => true]);
    $inactive = HarvestCredential::factory()->create(['is_active' => false]);

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->twice()->andReturn([]);
    $harvestService->shouldReceive('listProjects')->twice()->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->twice()->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->twice()->andReturn([]);

    $job = new SyncHarvestJob;
    $job->handle($harvestService);
});

test('handle syncs task categories', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);

    $tasks = [
        [
            'id' => 1,
            'name' => 'Development',
            'is_active' => true,
            'is_default' => false,
            'default_hourly_rate' => 150,
            'billable_by_default' => true,
        ],
    ];

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->once()->andReturn($tasks);
    $harvestService->shouldReceive('listProjects')->once()->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->once()->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->once()->andReturn([]);

    $job = new SyncHarvestJob(credentialId: $credential->id);
    $job->handle($harvestService);

    expect(HarvestTaskCategory::count())->toBe(1);
    expect(HarvestTaskCategory::first()->name)->toBe('Development');
});

test('handle syncs projects', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);

    $projects = [
        [
            'id' => 1,
            'name' => 'Client Project',
            'code' => 'CP01',
            'client' => ['name' => 'Acme Corp', 'id' => 100],
            'is_active' => true,
            'is_billable' => true,
            'budget' => 10000,
            'hourly_rate' => 150,
        ],
    ];

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->andReturn([]);
    $harvestService->shouldReceive('listProjects')->once()->andReturn($projects);
    $harvestService->shouldReceive('listTimeEntries')->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->andReturn([]);

    $job = new SyncHarvestJob(credentialId: $credential->id);
    $job->handle($harvestService);

    expect(HarvestProject::count())->toBe(1);
    $project = HarvestProject::first();
    expect($project->name)->toBe('Client Project')
        ->and($project->client_name)->toBe('Acme Corp');
});

test('handle syncs time entries', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);
    $project = HarvestProject::factory()->create(['harvest_id' => 1]);
    $taskCategory = HarvestTaskCategory::factory()->create(['harvest_id' => 2]);

    $entries = [
        [
            'id' => 100,
            'project' => ['id' => 1],
            'task' => ['id' => 2],
            'user' => ['id' => 50, 'name' => 'John Doe'],
            'spent_date' => '2024-01-15',
            'hours' => 8.5,
            'notes' => 'Worked on feature',
            'billable' => true,
        ],
    ];

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->andReturn([]);
    $harvestService->shouldReceive('listProjects')->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->once()->andReturn($entries);
    $harvestService->shouldReceive('listInvoices')->andReturn([]);

    $job = new SyncHarvestJob(credentialId: $credential->id);
    $job->handle($harvestService);

    expect(TimeEntry::count())->toBe(1);
    $entry = TimeEntry::first();
    expect($entry->hours)->toBe(8.5)
        ->and($entry->harvest_project_id)->toBe($project->id);
});

test('handle syncs invoices', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);

    $invoices = [
        [
            'id' => 1000,
            'client' => ['name' => 'Test Client', 'id' => 200],
            'number' => 'INV-001',
            'amount' => 5000,
            'due_amount' => 2500,
            'state' => 'sent',
            'issue_date' => '2024-01-01',
            'due_date' => '2024-01-31',
        ],
    ];

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->andReturn([]);
    $harvestService->shouldReceive('listProjects')->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->once()->andReturn($invoices);

    $job = new SyncHarvestJob(credentialId: $credential->id);
    $job->handle($harvestService);

    expect(HarvestInvoice::count())->toBe(1);
    $invoice = HarvestInvoice::first();
    expect($invoice->number)->toBe('INV-001')
        ->and($invoice->amount)->toBe(5000);
});

test('handle respects sync flags', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldNotReceive('listTasks');
    $harvestService->shouldNotReceive('listProjects');
    $harvestService->shouldReceive('listTimeEntries')->once()->andReturn([]);
    $harvestService->shouldNotReceive('listInvoices');

    $job = new SyncHarvestJob(
        credentialId: $credential->id,
        syncProjects: false,
        syncTimeEntries: true,
        syncInvoices: false,
        syncTaskCategories: false
    );
    $job->handle($harvestService);
});

test('handle updates credential last_synced_at', function () {
    $credential = HarvestCredential::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->andReturn([]);
    $harvestService->shouldReceive('listProjects')->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->andReturn([]);

    $job = new SyncHarvestJob(credentialId: $credential->id);
    $job->handle($harvestService);

    $credential->refresh();
    expect($credential->last_synced_at)->not->toBeNull();
});

test('handle uses fromDate parameter', function () {
    $credential = HarvestCredential::factory()->create(['is_active' => true]);

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')->andReturn([]);
    $harvestService->shouldReceive('listProjects')->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')
        ->once()
        ->with(Mockery::any(), Mockery::on(fn ($date) => $date->isSameDay('2024-01-01')))
        ->andReturn([]);
    $harvestService->shouldReceive('listInvoices')
        ->once()
        ->with(Mockery::any(), Mockery::on(fn ($date) => $date->isSameDay('2024-01-01')))
        ->andReturn([]);

    $job = new SyncHarvestJob(credentialId: $credential->id, fromDate: '2024-01-01');
    $job->handle($harvestService);
});

test('handle logs errors and continues', function () {
    Log::spy();

    $cred1 = HarvestCredential::factory()->create(['is_active' => true]);
    $cred2 = HarvestCredential::factory()->create(['is_active' => true]);

    $harvestService = Mockery::mock(HarvestService::class);
    $harvestService->shouldReceive('listTasks')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });
    $harvestService->shouldReceive('listProjects')->once()->andReturn([]);
    $harvestService->shouldReceive('listTimeEntries')->once()->andReturn([]);
    $harvestService->shouldReceive('listInvoices')->once()->andReturn([]);

    $job = new SyncHarvestJob;
    $job->handle($harvestService);

    Log::shouldHaveReceived('error')
        ->with('Harvest sync failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncHarvestJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncHarvestJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
