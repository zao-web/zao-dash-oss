<?php

use App\Models\User;
use App\Models\XBookmark;
use App\Models\XCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('command syncs bookmarks for active credentials with bookmark scope', function () {
    $user = User::factory()->create();

    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['tweet.read', 'bookmark.read', 'offline.access'],
        'is_active' => true,
    ]);

    // Mock the X API response
    Http::fake([
        'api.twitter.com/2/users/*/bookmarks*' => Http::response([
            'data' => [
                [
                    'id' => '1234567890',
                    'text' => 'Test bookmark content',
                    'author_id' => '98765',
                    'created_at' => '2024-01-10T12:00:00.000Z',
                    'public_metrics' => [
                        'like_count' => 42,
                        'retweet_count' => 10,
                        'reply_count' => 5,
                        'quote_count' => 2,
                    ],
                    'entities' => [
                        'urls' => [
                            [
                                'url' => 'https://t.co/short',
                                'expanded_url' => 'https://example.com/article',
                                'title' => 'Example Article',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => '9876543210',
                    'text' => 'Another bookmark',
                    'author_id' => '98765',
                    'created_at' => '2024-01-09T12:00:00.000Z',
                    'public_metrics' => [
                        'like_count' => 15,
                        'retweet_count' => 3,
                        'reply_count' => 1,
                        'quote_count' => 0,
                    ],
                ],
            ],
            'meta' => [
                'result_count' => 2,
            ],
            'includes' => [
                'users' => [
                    [
                        'id' => '98765',
                        'username' => 'author1',
                        'name' => 'Author One',
                        'profile_image_url' => 'https://example.com/image.jpg',
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('x:sync-bookmarks --sync')
        ->assertExitCode(0);

    // Verify bookmarks were created
    expect(XBookmark::count())->toBe(2);

    $bookmark = XBookmark::where('tweet_id', '1234567890')->first();
    expect($bookmark)->not->toBeNull();
    expect($bookmark->text)->toBe('Test bookmark content');
    expect($bookmark->author_id)->toBe('98765');
    expect($bookmark->author_username)->toBe('author1');
    expect($bookmark->like_count)->toBe(42);
    expect($bookmark->urls)->toBeArray();
    expect($bookmark->urls[0]['url'])->toBe('https://example.com/article');

    // Verify last_synced_at was updated
    $credential->refresh();
    expect($credential->last_synced_at)->not->toBeNull();
    expect($credential->last_synced_at->isToday())->toBeTrue();
});

test('command skips credentials without bookmark.read scope', function () {
    $user = User::factory()->create();

    // Create credential WITHOUT bookmark.read scope
    XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['tweet.read', 'tweet.write'], // No bookmark.read
        'is_active' => true,
    ]);

    $this->artisan('x:sync-bookmarks --sync')
        ->expectsOutput('No X credentials found with bookmark.read scope.')
        ->assertExitCode(0);

    expect(XBookmark::count())->toBe(0);
});

test('command respects 24 hour rate limit', function () {
    $user = User::factory()->create();

    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['bookmark.read'],
        'is_active' => true,
        'last_synced_at' => now()->subHours(12), // Synced 12 hours ago
    ]);

    // Mock should NOT be called
    Http::fake();

    $this->artisan('x:sync-bookmarks --sync')
        ->assertExitCode(0);

    // Verify no API calls were made
    Http::assertNothingSent();

    // Verify no bookmarks were created
    expect(XBookmark::count())->toBe(0);
});

test('command with --force flag bypasses rate limit', function () {
    $user = User::factory()->create();

    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['bookmark.read'],
        'is_active' => true,
        'last_synced_at' => now()->subHours(12), // Synced 12 hours ago
    ]);

    Http::fake([
        'api.twitter.com/2/users/*/bookmarks*' => Http::response([
            'data' => [
                [
                    'id' => '999',
                    'text' => 'Forced sync bookmark',
                    'author_id' => '123',
                    'created_at' => now()->toISOString(),
                    'public_metrics' => [
                        'like_count' => 1,
                        'retweet_count' => 0,
                        'reply_count' => 0,
                        'quote_count' => 0,
                    ],
                ],
            ],
            'meta' => ['result_count' => 1],
            'includes' => ['users' => []],
        ], 200),
    ]);

    $this->artisan('x:sync-bookmarks --force --sync')
        ->assertExitCode(0);

    // Verify API was called despite cache
    Http::assertSentCount(1);

    // Verify bookmark was created
    expect(XBookmark::count())->toBe(1);
});

test('job handles rate limit errors gracefully without retrying', function () {
    $user = User::factory()->create();

    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['bookmark.read'],
        'is_active' => true,
    ]);

    // Mock rate limit error response
    Http::fake([
        'api.twitter.com/2/users/*/bookmarks*' => Http::response([
            'detail' => 'Too Many Requests',
            'title' => 'Too Many Requests',
            'type' => 'about:blank',
        ], 429),
    ]);

    // Should not throw exception - gracefully handles rate limit
    $this->artisan('x:sync-bookmarks --sync')
        ->assertExitCode(0);

    // Verify only 1 API call was made (no retries)
    Http::assertSentCount(1);

    // Verify no bookmarks were created
    expect(XBookmark::count())->toBe(0);
});

test('job allows sync after 24 hours from last sync', function () {
    $user = User::factory()->create();

    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => '12345',
        'username' => 'testuser',
        'account_type' => 'personal',
        'name' => 'Test User',
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'token_expires_at' => now()->addHours(2),
        'scopes' => ['bookmark.read'],
        'is_active' => true,
        'last_synced_at' => now()->subHours(25), // Synced 25 hours ago
    ]);

    Http::fake([
        'api.twitter.com/2/users/*/bookmarks*' => Http::response([
            'data' => [
                [
                    'id' => '111',
                    'text' => 'New bookmark after 24h',
                    'author_id' => '123',
                    'created_at' => now()->toISOString(),
                    'public_metrics' => [
                        'like_count' => 5,
                        'retweet_count' => 0,
                        'reply_count' => 0,
                        'quote_count' => 0,
                    ],
                ],
            ],
            'meta' => ['result_count' => 1],
            'includes' => ['users' => []],
        ], 200),
    ]);

    $this->artisan('x:sync-bookmarks --sync')
        ->assertExitCode(0);

    // Verify API was called
    Http::assertSentCount(1);

    // Verify bookmark was created
    expect(XBookmark::count())->toBe(1);

    // Verify last_synced_at was updated
    $credential->refresh();
    expect($credential->last_synced_at->isToday())->toBeTrue();
});
