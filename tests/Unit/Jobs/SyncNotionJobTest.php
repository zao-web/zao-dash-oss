<?php

use App\Jobs\SyncNotionJob;
use App\Models\NotionConnection;
use App\Models\NotionPage;
use App\Services\Notion\NotionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncNotionJob::dispatch();

    Queue::assertPushed(SyncNotionJob::class);
});

test('job can be dispatched for specific connection', function () {
    Queue::fake();

    SyncNotionJob::dispatch(connectionId: 123);

    Queue::assertPushed(SyncNotionJob::class, function ($job) {
        return $job->connectionId === 123;
    });
});

test('handle syncs all active connections', function () {
    $conn1 = NotionConnection::factory()->create(['is_active' => true]);
    $conn2 = NotionConnection::factory()->create(['is_active' => true]);
    $inactive = NotionConnection::factory()->create(['is_active' => false]);

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->twice()->andReturn([]);
    $notionService->shouldReceive('searchDatabases')->twice()->andReturn([]);

    $job = new SyncNotionJob;
    $job->handle($notionService);
});

test('handle syncs specific connection when connectionId provided', function () {
    $target = NotionConnection::factory()->create(['is_active' => true]);
    $other = NotionConnection::factory()->create(['is_active' => true]);

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $target->id))
        ->andReturn([]);
    $notionService->shouldReceive('searchDatabases')->once()->andReturn([]);

    $job = new SyncNotionJob(connectionId: $target->id);
    $job->handle($notionService);
});

test('handle syncs pages', function () {
    $connection = NotionConnection::factory()->create(['is_active' => true]);

    $pages = [
        [
            'id' => 'page-1',
            'url' => 'https://notion.so/page-1',
            'properties' => [
                'title' => [
                    'type' => 'title',
                    'title' => [['plain_text' => 'Test Page']],
                ],
            ],
        ],
    ];

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn($pages);
    $notionService->shouldReceive('searchDatabases')->andReturn([]);

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);

    expect(NotionPage::count())->toBe(1);
    expect(NotionPage::first()->notion_id)->toBe('page-1');
});

test('handle syncs page content when configured', function () {
    $connection = NotionConnection::factory()->create([
        'is_active' => true,
        'sync_content' => true,
    ]);

    $pages = [
        [
            'id' => 'page-1',
            'properties' => [
                'title' => ['type' => 'title', 'title' => [['plain_text' => 'Test']]],
            ],
        ],
    ];

    $blocks = [
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Content']]]],
    ];

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn($pages);
    $notionService->shouldReceive('searchDatabases')->andReturn([]);
    $notionService->shouldReceive('getPageContent')->once()->andReturn($blocks);

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);

    $page = NotionPage::first();
    expect($page->content)->not->toBeNull();
});

test('handle skips page content when not configured', function () {
    $connection = NotionConnection::factory()->create([
        'is_active' => true,
        'sync_content' => false,
    ]);

    $pages = [
        [
            'id' => 'page-1',
            'properties' => [
                'title' => ['type' => 'title', 'title' => [['plain_text' => 'Test']]],
            ],
        ],
    ];

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn($pages);
    $notionService->shouldReceive('searchDatabases')->andReturn([]);
    $notionService->shouldNotReceive('getPageContent');

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);
});

test('handle syncs databases', function () {
    $connection = NotionConnection::factory()->create(['is_active' => true]);

    $databases = [
        [
            'id' => 'db-1',
            'title' => [['plain_text' => 'Test DB']],
            'url' => 'https://notion.so/db-1',
            'properties' => [],
        ],
    ];

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn([]);
    $notionService->shouldReceive('searchDatabases')->andReturn($databases);

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);

    $db = $connection->databases()->first();
    expect($db)->not->toBeNull()
        ->and($db->notion_id)->toBe('db-1');
});

test('handle syncs database items when configured', function () {
    $connection = NotionConnection::factory()->create([
        'is_active' => true,
        'sync_database_items' => true,
    ]);

    $databases = [
        ['id' => 'db-1', 'title' => [['plain_text' => 'DB']], 'properties' => []],
    ];

    $items = [
        ['id' => 'item-1', 'properties' => [], 'archived' => false],
    ];

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn([]);
    $notionService->shouldReceive('searchDatabases')->andReturn($databases);
    $notionService->shouldReceive('queryDatabase')->once()->andReturn($items);

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);

    $db = $connection->databases()->first();
    expect($db->items()->count())->toBe(1);
});

test('handle updates connection last_synced_at', function () {
    $connection = NotionConnection::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')->andReturn([]);
    $notionService->shouldReceive('searchDatabases')->andReturn([]);

    $job = new SyncNotionJob(connectionId: $connection->id);
    $job->handle($notionService);

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
});

test('handle logs errors and continues', function () {
    Log::spy();

    $conn1 = NotionConnection::factory()->create(['is_active' => true]);
    $conn2 = NotionConnection::factory()->create(['is_active' => true]);

    $notionService = Mockery::mock(NotionService::class);
    $notionService->shouldReceive('searchPages')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });
    $notionService->shouldReceive('searchDatabases')->once()->andReturn([]);

    $job = new SyncNotionJob;
    $job->handle($notionService);

    Log::shouldHaveReceived('error')
        ->with('Notion sync failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncNotionJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncNotionJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
