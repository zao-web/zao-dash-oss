<?php

use App\Jobs\SyncWordPressJob;
use App\Models\WordPressPost;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncWordPressJob::dispatch();

    Queue::assertPushed(SyncWordPressJob::class);
});

test('job can be dispatched with parameters', function () {
    Queue::fake();

    SyncWordPressJob::dispatch(
        siteId: 1,
        syncPosts: false,
        syncPages: true,
        syncCategories: false,
        syncTags: true
    );

    Queue::assertPushed(SyncWordPressJob::class, function ($job) {
        return $job->siteId === 1
            && $job->syncPosts === false
            && $job->syncPages === true
            && $job->syncCategories === false
            && $job->syncTags === true;
    });
});

test('handle syncs all active sites', function () {
    $site1 = WordPressSite::factory()->create(['is_active' => true]);
    $site2 = WordPressSite::factory()->create(['is_active' => true]);
    $inactive = WordPressSite::factory()->create(['is_active' => false]);

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->twice()->andReturn([]);
    $wpService->shouldReceive('getTags')->twice()->andReturn([]);
    $wpService->shouldReceive('getPosts')->times(4)->andReturn([]); // posts + pages for each

    $job = new SyncWordPressJob;
    $job->handle($wpService);
});

test('handle syncs posts', function () {
    $site = WordPressSite::factory()->create(['is_active' => true]);

    $posts = [
        [
            'id' => 1,
            'slug' => 'test-post',
            'title' => ['rendered' => 'Test Post'],
            'content' => ['rendered' => '<p>Content</p>'],
            'excerpt' => ['rendered' => 'Excerpt'],
            'status' => 'publish',
            'type' => 'post',
            'link' => 'https://example.com/test-post',
            'author' => 1,
            'date' => '2024-01-01T00:00:00',
            'modified' => '2024-01-02T00:00:00',
        ],
    ];

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->andReturn([]);
    $wpService->shouldReceive('getTags')->andReturn([]);
    $wpService->shouldReceive('getPosts')->once()->andReturn($posts);
    $wpService->shouldReceive('getPosts')->once()->andReturn([]); // pages

    $job = new SyncWordPressJob(siteId: $site->id);
    $job->handle($wpService);

    expect(WordPressPost::count())->toBe(1);
    $post = WordPressPost::first();
    expect($post->title)->toBe('Test Post')
        ->and($post->post_type)->toBe('post');
});

test('handle syncs pages', function () {
    $site = WordPressSite::factory()->create(['is_active' => true]);

    $pages = [
        [
            'id' => 2,
            'slug' => 'about',
            'title' => ['rendered' => 'About Us'],
            'content' => ['rendered' => '<p>About content</p>'],
            'excerpt' => ['rendered' => ''],
            'status' => 'publish',
            'type' => 'page',
            'link' => 'https://example.com/about',
        ],
    ];

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->andReturn([]);
    $wpService->shouldReceive('getTags')->andReturn([]);
    $wpService->shouldReceive('getPosts')->with(Mockery::any(), ['type' => 'post', 'per_page' => 100, 'status' => 'any'])->andReturn([]);
    $wpService->shouldReceive('getPosts')->with(Mockery::any(), ['type' => 'page', 'per_page' => 100, 'status' => 'any'])->andReturn($pages);

    $job = new SyncWordPressJob(siteId: $site->id);
    $job->handle($wpService);

    expect(WordPressPost::where('post_type', 'page')->count())->toBe(1);
});

test('handle syncs categories', function () {
    $site = WordPressSite::factory()->create(['is_active' => true]);

    $categories = [
        ['id' => 1, 'name' => 'News', 'slug' => 'news', 'count' => 5],
        ['id' => 2, 'name' => 'Updates', 'slug' => 'updates', 'count' => 3],
    ];

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->once()->andReturn($categories);
    $wpService->shouldReceive('getTags')->andReturn([]);
    $wpService->shouldReceive('getPosts')->twice()->andReturn([]);

    $job = new SyncWordPressJob(siteId: $site->id);
    $job->handle($wpService);

    $site->refresh();
    expect($site->categories)->toHaveCount(2)
        ->and($site->categories[0]['name'])->toBe('News');
});

test('handle syncs tags', function () {
    $site = WordPressSite::factory()->create(['is_active' => true]);

    $tags = [
        ['id' => 10, 'name' => 'PHP', 'slug' => 'php', 'count' => 12],
        ['id' => 11, 'name' => 'Laravel', 'slug' => 'laravel', 'count' => 8],
    ];

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->andReturn([]);
    $wpService->shouldReceive('getTags')->once()->andReturn($tags);
    $wpService->shouldReceive('getPosts')->twice()->andReturn([]);

    $job = new SyncWordPressJob(siteId: $site->id);
    $job->handle($wpService);

    $site->refresh();
    expect($site->tags)->toHaveCount(2)
        ->and($site->tags[1]['name'])->toBe('Laravel');
});

test('handle respects sync flags', function () {
    $site = WordPressSite::factory()->create(['is_active' => true]);

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldNotReceive('getCategories');
    $wpService->shouldReceive('getTags')->once()->andReturn([]);
    $wpService->shouldReceive('getPosts')->once()->andReturn([]); // only posts, not pages

    $job = new SyncWordPressJob(
        siteId: $site->id,
        syncPosts: true,
        syncPages: false,
        syncCategories: false,
        syncTags: true
    );
    $job->handle($wpService);
});

test('handle updates site last_synced_at', function () {
    $site = WordPressSite::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')->andReturn([]);
    $wpService->shouldReceive('getTags')->andReturn([]);
    $wpService->shouldReceive('getPosts')->twice()->andReturn([]);

    $job = new SyncWordPressJob(siteId: $site->id);
    $job->handle($wpService);

    $site->refresh();
    expect($site->last_synced_at)->not->toBeNull();
});

test('handle logs errors and continues', function () {
    Log::spy();

    $site1 = WordPressSite::factory()->create(['is_active' => true]);
    $site2 = WordPressSite::factory()->create(['is_active' => true]);

    $wpService = Mockery::mock(WordPressService::class);
    $wpService->shouldReceive('getCategories')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });
    $wpService->shouldReceive('getTags')->once()->andReturn([]);
    $wpService->shouldReceive('getPosts')->twice()->andReturn([]);

    $job = new SyncWordPressJob;
    $job->handle($wpService);

    Log::shouldHaveReceived('error')
        ->with('WordPress sync failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncWordPressJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncWordPressJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
