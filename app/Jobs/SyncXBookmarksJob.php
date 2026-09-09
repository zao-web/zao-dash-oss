<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\XBookmark;
use App\Models\XCredential;
use App\Services\X\XService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sync X bookmarks for a user.
 *
 * Sync Strategy:
 * - Initial sync: Paginate through ALL bookmarks until we reach the end
 * - Subsequent syncs: Fetch newest bookmarks until we hit already-synced ones
 *
 * This ensures we get your full bookmark history on first sync, then only
 * fetch new bookmarks on subsequent syncs.
 *
 * Rate limits: X API allows ~15 requests/15min for bookmarks endpoint.
 */
class SyncXBookmarksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public int $tries = 3;

    public array $backoff = [60, 120, 300];

    public function __construct(
        public int $credentialId,
        public bool $forceSync = false,
        public bool $fullSync = false
    ) {}

    public function handle(XService $xService): void
    {
        $credential = XCredential::find($this->credentialId);

        if (! $credential) {
            Log::warning('XBookmark sync: credential not found', ['id' => $this->credentialId]);

            return;
        }

        if (! $credential->is_active) {
            Log::info('XBookmark sync: credential inactive', ['id' => $this->credentialId]);

            return;
        }

        // Check if we have bookmark scope
        if (! $xService->hasBookmarkScope($credential)) {
            Log::warning('XBookmark sync: missing bookmark.read scope', [
                'credential_id' => $credential->id,
                'username' => $credential->username,
                'scopes' => $credential->scopes,
            ]);

            return;
        }

        // Check if we're still rate limited from a previous attempt
        if (! $this->forceSync && $credential->rate_limit_reset_at && $credential->rate_limit_reset_at->isFuture()) {
            Log::info('XBookmark sync: still rate limited by X API', [
                'credential_id' => $credential->id,
                'rate_limit_reset_at' => $credential->rate_limit_reset_at->toISOString(),
                'minutes_remaining' => now()->diffInMinutes($credential->rate_limit_reset_at),
            ]);

            return;
        }

        // Also check our own 1-hour throttle for successful syncs
        if (! $this->forceSync && ! $this->fullSync) {
            if ($credential->last_synced_at && $credential->last_synced_at->isAfter(now()->subHours(1))) {
                $nextSync = $credential->last_synced_at->addHours(1);
                Log::info('XBookmark sync: rate limited (1h internal throttle)', [
                    'credential_id' => $credential->id,
                    'last_sync' => $credential->last_synced_at->toISOString(),
                    'next_sync' => $nextSync->toISOString(),
                ]);

                return;
            }
        }

        $this->initSyncTracking($credential);

        try {
            $isInitialSync = ! $credential->initial_sync_complete;
            $shouldPaginate = $isInitialSync || $this->fullSync;

            Log::info('Starting X bookmarks sync', [
                'credential_id' => $credential->id,
                'username' => $credential->username,
                'is_initial_sync' => $isInitialSync,
                'full_sync' => $this->fullSync,
                'will_paginate' => $shouldPaginate,
            ]);

            $this->updateSyncProgress(10, 'fetching bookmarks');

            $allStats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
            $paginationToken = $credential->sync_pagination_token;
            $pageCount = 0;
            $hitExistingBookmarks = false;

            do {
                $pageCount++;
                $response = $xService->getBookmarks($credential, 100, $paginationToken);
                $bookmarks = $response['data'];
                $paginationToken = $response['meta']['next_token'] ?? null;

                Log::info('X API bookmarks page received', [
                    'credential_id' => $credential->id,
                    'page' => $pageCount,
                    'count' => count($bookmarks),
                    'has_more' => ! empty($paginationToken),
                ]);

                $stats = $this->processBookmarks($credential, $bookmarks);
                $allStats['total'] += $stats['total'];
                $allStats['created'] += $stats['created'];
                $allStats['updated'] += $stats['updated'];
                $allStats['skipped'] += $stats['skipped'];

                if (! $shouldPaginate && $stats['updated'] > 0 && $stats['created'] === 0) {
                    Log::info('Hit existing bookmarks, stopping incremental sync', [
                        'credential_id' => $credential->id,
                        'page' => $pageCount,
                    ]);
                    $hitExistingBookmarks = true;
                    break;
                }

                $credential->update(['sync_pagination_token' => $paginationToken]);

                $progress = min(80, 10 + ($pageCount * 10));
                $this->updateSyncProgress($progress, "fetching page {$pageCount}");

                if ($paginationToken) {
                    usleep(200000);
                }

            } while ($paginationToken && $shouldPaginate && $pageCount < 10);

            $this->updateSyncProgress(90, 'finalizing');

            $credential->update([
                'last_synced_at' => now(),
                'rate_limit_reset_at' => null, // Clear rate limit on success
                'initial_sync_complete' => true,
                'sync_pagination_token' => null,
            ]);

            $this->completeSyncTracking();

            Log::info('X bookmarks sync completed', array_merge(
                [
                    'credential_id' => $credential->id,
                    'pages_fetched' => $pageCount,
                    'hit_existing' => $hitExistingBookmarks,
                ],
                $allStats
            ));

            if ($allStats['created'] > 0) {
                EnrichXBookmarksJob::dispatch($credential->id);
            }

        } catch (\Exception $e) {
            $this->failSyncTracking($e);

            // Check if this is a rate limit error
            $isRateLimitError = str_contains($e->getMessage(), 'Too Many Requests') ||
                               str_contains($e->getMessage(), 'Rate limit exceeded');

            Log::error('X bookmarks sync failed', [
                'credential_id' => $credential->id,
                'error' => $e->getMessage(),
                'is_rate_limit' => $isRateLimitError,
                'last_synced_at' => $credential->last_synced_at?->toISOString(),
                'help' => $isRateLimitError ? 'X API free tier allows 1 request per 24 hours. Wait 24h from last successful sync.' : null,
            ]);

            // Don't retry rate limit errors - they'll fail for 24 hours
            if ($isRateLimitError) {
                return; // Soft fail - don't throw exception
            }

            throw $e;
        }
    }

    protected function processBookmarks(XCredential $credential, array $bookmarks): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        Log::debug('Processing bookmarks batch', [
            'credential_id' => $credential->id,
            'username' => $credential->username,
            'batch_count' => count($bookmarks),
        ]);

        foreach ($bookmarks as $index => $tweet) {
            $tweetId = $tweet['id'] ?? null;
            if (! $tweetId) {
                Log::warning('Skipping bookmark with missing tweet ID', [
                    'credential_id' => $credential->id,
                    'index' => $index,
                    'tweet_data' => array_keys($tweet),
                ]);
                $skipped++;

                continue;
            }

            $entities = $tweet['entities'] ?? [];
            $metrics = $tweet['public_metrics'] ?? [];

            $data = [
                'author_id' => $tweet['author_id'] ?? '',
                'author_username' => $tweet['author_username'] ?? null,
                'author_name' => $tweet['author_name'] ?? null,
                'text' => $tweet['text'] ?? '',
                'tweet_created_at' => isset($tweet['created_at'])
                    ? Carbon::parse($tweet['created_at'])
                    : null,
                'urls' => $this->extractUrls($entities),
                'mentions' => $entities['mentions'] ?? null,
                'hashtags' => $entities['hashtags'] ?? null,
                'media' => $tweet['attachments']['media_keys'] ?? null,
                'like_count' => $metrics['like_count'] ?? 0,
                'retweet_count' => $metrics['retweet_count'] ?? 0,
                'reply_count' => $metrics['reply_count'] ?? 0,
                'quote_count' => $metrics['quote_count'] ?? 0,
            ];

            $bookmark = XBookmark::where('x_credential_id', $credential->id)
                ->where('tweet_id', $tweetId)
                ->first();

            if ($bookmark) {
                // Only update metrics, don't reset analysis
                $bookmark->update([
                    'like_count' => $data['like_count'],
                    'retweet_count' => $data['retweet_count'],
                    'reply_count' => $data['reply_count'],
                    'quote_count' => $data['quote_count'],
                ]);
                $updated++;

                Log::debug('Updated existing bookmark metrics', [
                    'bookmark_id' => $bookmark->id,
                    'tweet_id' => $tweetId,
                    'author' => $data['author_username'],
                    'likes' => $data['like_count'],
                ]);
            } else {
                $newBookmark = XBookmark::create(array_merge(
                    ['x_credential_id' => $credential->id, 'tweet_id' => $tweetId],
                    $data
                ));
                $created++;

                Log::info('Created new bookmark', [
                    'bookmark_id' => $newBookmark->id,
                    'tweet_id' => $tweetId,
                    'author' => $data['author_username'],
                    'text_preview' => Str::limit($data['text'], 100),
                    'url_count' => count($data['urls'] ?? []),
                ]);
            }
        }

        Log::info('Bookmark processing complete', [
            'credential_id' => $credential->id,
            'total' => count($bookmarks),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [
            'total' => count($bookmarks),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    protected function extractUrls(array $entities): ?array
    {
        $urls = $entities['urls'] ?? [];
        if (empty($urls)) {
            return null;
        }

        // Extract expanded URLs and titles
        return collect($urls)->map(fn ($url) => [
            'url' => $url['expanded_url'] ?? $url['url'] ?? null,
            'title' => $url['title'] ?? null,
            'description' => $url['description'] ?? null,
        ])->filter(fn ($url) => $url['url'])->values()->all();
    }
}
