<?php

use App\Enums\SeoPageStatus;
use App\Jobs\SyncSeoPerformanceJob;
use App\Models\GoogleCredential;
use App\Models\SeoPage;
use App\Models\SeoPerformanceHistory;
use App\Models\User;
use App\Services\Google\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('google-api-sync');
    Cache::flush();
});

describe('SyncSeoPerformanceJob', function () {
    test('syncs Search Console data for active pages', function () {
        $user = User::factory()->create();

        // Create a Google credential for the user using factory
        GoogleCredential::factory()->create(['user_id' => $user->id]);

        $page = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_url' => 'https://example.com/laravel-development',
            'impressions_30d' => 0,
            'clicks_30d' => 0,
        ]);

        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldReceive('getPagePerformance')
            ->once()
            ->andReturn([
                'total_impressions' => 1500,
                'total_clicks' => 75,
                'avg_position' => 8.5,
            ]);

        $mockService->shouldReceive('getAnalyticsPageViews')
            ->andReturn([]);

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob($user->id);
        $job->handle($mockService);

        $page->refresh();

        expect($page->impressions_30d)->toBe(1500);
        expect($page->clicks_30d)->toBe(75);
        expect((float) $page->avg_position_30d)->toBe(8.5);
        expect((float) $page->ctr_30d)->toBe(5.0); // 75/1500 * 100
    });

    test('handles API failure with exponential backoff', function () {
        $user = User::factory()->create();

        GoogleCredential::factory()->create(['user_id' => $user->id]);

        $page = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_url' => 'https://example.com/test-page',
            'impressions_30d' => 100,
            'clicks_30d' => 10,
        ]);

        $callCount = 0;
        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldReceive('getPagePerformance')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 3) {
                    throw new \Exception('429 Too Many Requests');
                }

                return [
                    'total_impressions' => 500,
                    'total_clicks' => 25,
                    'avg_position' => 5.0,
                ];
            });

        $mockService->shouldReceive('getAnalyticsPageViews')
            ->andReturn([]);

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob($user->id);
        $job->handle($mockService);

        $page->refresh();

        // Should have retried and eventually succeeded
        expect($callCount)->toBeGreaterThanOrEqual(3);
        expect($page->impressions_30d)->toBe(500);
    });

    test('stops after max consecutive failures', function () {
        $user = User::factory()->create();

        GoogleCredential::factory()->create(['user_id' => $user->id]);

        // Create 10 pages
        $pages = SeoPage::factory()->count(10)->create([
            'status' => SeoPageStatus::Published,
        ]);

        $callCount = 0;
        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldReceive('getPagePerformance')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                throw new \Exception('Service unavailable');
            });

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob($user->id);
        $job->handle($mockService);

        // Should stop after 5 consecutive failures (each with up to 4 retries)
        // So max calls = 5 pages * 4 attempts = 20, but we stop after 5 consecutive failures
        expect($callCount)->toBeLessThanOrEqual(20);
    });

    test('creates weekly snapshot on Monday', function () {
        // Travel to a Monday
        $this->travelTo(now()->startOfWeek());

        $user = User::factory()->create();

        GoogleCredential::factory()->create(['user_id' => $user->id]);

        $page = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_url' => 'https://example.com/monday-test',
        ]);

        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldReceive('getPagePerformance')
            ->once()
            ->andReturn([
                'total_impressions' => 1000,
                'total_clicks' => 50,
                'avg_position' => 10.0,
            ]);

        $mockService->shouldReceive('getAnalyticsPageViews')
            ->andReturn([]);

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob($user->id);
        $job->handle($mockService);

        expect(SeoPerformanceHistory::count())->toBe(1);

        $history = SeoPerformanceHistory::first();
        expect($history->seo_page_id)->toBe($page->id);
        expect($history->impressions)->toBe(1000);
        expect($history->clicks)->toBe(50);
    });

    test('does not create snapshot on non-Monday', function () {
        // Travel to a Tuesday
        $this->travelTo(now()->startOfWeek()->addDay());

        $user = User::factory()->create();

        GoogleCredential::factory()->create(['user_id' => $user->id]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
        ]);

        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldReceive('getPagePerformance')
            ->once()
            ->andReturn([
                'total_impressions' => 1000,
                'total_clicks' => 50,
                'avg_position' => 10.0,
            ]);

        $mockService->shouldReceive('getAnalyticsPageViews')
            ->andReturn([]);

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob($user->id);
        $job->handle($mockService);

        expect(SeoPerformanceHistory::count())->toBe(0);
    });

    test('warns when no user with Google credentials found', function () {
        // No user with Google credentials
        User::factory()->create();

        $mockService = Mockery::mock(SearchConsoleService::class);
        $mockService->shouldNotReceive('getPagePerformance');

        app()->instance(SearchConsoleService::class, $mockService);

        $job = new SyncSeoPerformanceJob;
        $job->handle($mockService);

        // Job should complete without errors
        expect(true)->toBeTrue();
    });

    test('SeoPerformanceHistory updateOrCreate prevents duplicates', function () {
        // This tests the core updateOrCreate behavior directly
        $this->travelTo(now()->startOfWeek()); // Monday
        $snapshotDate = now()->startOfDay(); // Use Carbon object for consistency

        $page = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
        ]);

        // Create an existing snapshot for today with old data
        $existingHistory = SeoPerformanceHistory::create([
            'seo_page_id' => $page->id,
            'snapshot_date' => $snapshotDate,
            'impressions' => 500,
            'clicks' => 25,
            'avg_position' => 15.0,
            'ctr' => 5.0,
            'sessions' => 0,
            'conversions' => 0,
            'bounce_rate' => 0,
            'avg_session_duration' => 0,
        ]);

        // Simulate what the job does with updateOrCreate - use same date variable
        SeoPerformanceHistory::updateOrCreate(
            [
                'seo_page_id' => $page->id,
                'snapshot_date' => $snapshotDate,
            ],
            [
                'impressions' => 1000,
                'clicks' => 50,
                'avg_position' => 10.0,
                'ctr' => 5.0,
                'sessions' => 100,
                'conversions' => 0,
                'bounce_rate' => 0,
                'avg_session_duration' => 0,
            ]
        );

        // Should still only have 1 record, not 2 (updateOrCreate worked)
        expect(SeoPerformanceHistory::count())->toBe(1);

        // Should have updated values - refresh the same record
        $existingHistory->refresh();
        expect($existingHistory->impressions)->toBe(1000);
        expect($existingHistory->clicks)->toBe(50);
    });
});
