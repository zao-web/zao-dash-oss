<?php

namespace App\Jobs;

use App\Models\SeoPage;
use App\Models\SeoPerformanceHistory;
use App\Models\User;
use App\Services\Google\SearchConsoleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SyncSeoPerformanceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum number of consecutive failures before stopping.
     */
    private const MAX_CONSECUTIVE_FAILURES = 5;

    /**
     * Base delay for exponential backoff in milliseconds.
     */
    private const BASE_DELAY_MS = 1000;

    public function __construct(
        private ?int $userId = null
    ) {}

    public function handle(SearchConsoleService $searchConsoleService): void
    {
        // Get the user who owns the Google credentials
        // In the future, this could sync for multiple users
        $user = $this->userId
            ? User::find($this->userId)
            : User::whereHas('googleCredential')->first();

        if (! $user) {
            Log::warning('SyncSeoPerformanceJob: No user with Google credentials found');

            return;
        }

        // Get site URL from config (could be dynamic per user in the future)
        $siteUrl = config('services.google.search_console_site_url', 'sc-domain:example.com');
        $ga4PropertyId = config('services.google.ga4_property_id');

        // Get rate limit from config (default: 60 requests per minute)
        $rateLimit = config('services.google.api_rate_limit', 60);

        Log::info('SyncSeoPerformanceJob: Starting sync for user '.$user->id);

        $syncedCount = 0;
        $errorCount = 0;
        $consecutiveFailures = 0;

        // Use cursor() for memory-efficient iteration - only one model in memory at a time
        foreach (SeoPage::active()->cursor() as $page) {
            // Check if we've hit too many consecutive failures (likely quota exhausted)
            if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                Log::error('SyncSeoPerformanceJob: Too many consecutive failures, stopping sync to avoid quota exhaustion');
                break;
            }

            // Rate limit API calls using Laravel's RateLimiter
            $executed = RateLimiter::attempt(
                key: 'google-api-sync',
                maxAttempts: $rateLimit,
                callback: fn () => $this->syncPage(
                    $page,
                    $searchConsoleService,
                    $user,
                    $siteUrl,
                    $ga4PropertyId,
                    $consecutiveFailures
                ),
                decaySeconds: 60
            );

            if (! $executed) {
                // Rate limit hit - wait and retry
                Log::info('SyncSeoPerformanceJob: Rate limit reached, waiting 60 seconds');
                sleep(60);

                // Retry the page after waiting
                $result = $this->syncPage(
                    $page,
                    $searchConsoleService,
                    $user,
                    $siteUrl,
                    $ga4PropertyId,
                    $consecutiveFailures
                );

                if ($result['success']) {
                    $syncedCount++;
                    $consecutiveFailures = 0;
                } else {
                    $errorCount++;
                    $consecutiveFailures++;
                }
            } else {
                // Rate limiter executed successfully
                $result = Cache::get('seo-sync-last-result-'.$page->id, ['success' => true]);
                if ($result['success']) {
                    $syncedCount++;
                    $consecutiveFailures = 0;
                } else {
                    $errorCount++;
                    $consecutiveFailures++;
                }
            }

            // Small delay between pages to be nice to the API
            usleep(100000); // 100ms
        }

        Log::info("SyncSeoPerformanceJob completed: {$syncedCount} pages synced, {$errorCount} errors");
    }

    /**
     * Sync a single page with exponential backoff on failure.
     */
    private function syncPage(
        SeoPage $page,
        SearchConsoleService $searchConsoleService,
        User $user,
        string $siteUrl,
        ?string $ga4PropertyId,
        int $attemptNumber = 0
    ): array {
        $maxRetries = 3;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                // Get Search Console data (last 30 days)
                $scData = $searchConsoleService->getPagePerformance(
                    $user,
                    $siteUrl,
                    $page->page_url,
                    30
                );

                // Calculate CTR
                $ctr = $scData['total_impressions'] > 0
                    ? round(($scData['total_clicks'] / $scData['total_impressions']) * 100, 2)
                    : 0;

                // Get GA4 data if property ID is configured
                $sessions = 0;
                $bounceRate = 0;
                $avgDuration = 0;

                if ($ga4PropertyId) {
                    try {
                        $gaData = $searchConsoleService->getAnalyticsPageViews($user, $ga4PropertyId, [
                            'start_date' => '28daysAgo',
                            'end_date' => 'yesterday',
                            'page_filter' => parse_url($page->page_url, PHP_URL_PATH),
                        ]);

                        if (! empty($gaData)) {
                            $sessions = array_sum(array_column($gaData, 'sessions'));
                            $bounceRate = ! empty($gaData) ? array_sum(array_column($gaData, 'bounce_rate')) / count($gaData) : 0;
                            $avgDuration = ! empty($gaData) ? array_sum(array_column($gaData, 'avg_duration')) / count($gaData) : 0;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to fetch GA4 data for {$page->page_url}: ".$e->getMessage());
                    }
                }

                // Update page metrics
                $page->update([
                    'impressions_30d' => $scData['total_impressions'],
                    'clicks_30d' => $scData['total_clicks'],
                    'avg_position_30d' => $scData['avg_position'],
                    'ctr_30d' => $ctr,
                ]);

                // Create weekly snapshot (Mondays only)
                if (now()->isMonday()) {
                    SeoPerformanceHistory::updateOrCreate(
                        [
                            'seo_page_id' => $page->id,
                            'snapshot_date' => now()->startOfDay(),
                        ],
                        [
                            'impressions' => $scData['total_impressions'],
                            'clicks' => $scData['total_clicks'],
                            'avg_position' => $scData['avg_position'],
                            'ctr' => $ctr,
                            'sessions' => $sessions,
                            'conversions' => 0, // Will be tracked through form submissions
                            'bounce_rate' => round($bounceRate, 2),
                            'avg_session_duration' => round($avgDuration, 2),
                        ]
                    );
                }

                Log::info("Synced SEO data for {$page->page_url}: {$scData['total_impressions']} impressions, {$scData['total_clicks']} clicks");

                $result = ['success' => true];
                Cache::put('seo-sync-last-result-'.$page->id, $result, 300);

                return $result;
            } catch (\Exception $e) {
                $isQuotaError = $this->isQuotaError($e);
                $isRateLimitError = $this->isRateLimitError($e);

                if ($retry < $maxRetries && ($isQuotaError || $isRateLimitError)) {
                    // Calculate exponential backoff delay: 1s, 2s, 4s
                    $delayMs = self::BASE_DELAY_MS * pow(2, $retry);

                    Log::warning("Retry {$retry}/{$maxRetries} for {$page->page_url} after {$delayMs}ms: ".$e->getMessage());

                    usleep($delayMs * 1000);

                    continue;
                }

                Log::error("Failed to sync SEO data for {$page->page_url}: ".$e->getMessage());

                $result = ['success' => false, 'error' => $e->getMessage()];
                Cache::put('seo-sync-last-result-'.$page->id, $result, 300);

                return $result;
            }
        }

        return ['success' => false, 'error' => 'Max retries exceeded'];
    }

    /**
     * Check if the exception is a quota exhaustion error.
     */
    private function isQuotaError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'quota')
            || str_contains($message, 'rate limit')
            || str_contains($message, '429')
            || str_contains($message, 'too many requests');
    }

    /**
     * Check if the exception is a rate limit error.
     */
    private function isRateLimitError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate')
            || str_contains($message, '429')
            || $e->getCode() === 429;
    }
}
