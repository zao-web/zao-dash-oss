<?php

namespace App\Services\X;

use App\Models\XCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * X (Twitter) API Service
 *
 * This service uses the X API (api.twitter.com) and is governed by the
 * X Developer Agreement: https://developer.x.com/en/developer-terms/agreement
 *
 * IMPORTANT: This is SEPARATE from the Grok API (api.x.ai) which is used for
 * trend analysis. Grok is xAI's AI service with different terms.
 *
 * COMPLIANCE REQUIREMENTS:
 * - Section III.A: No surveillance, tracking users, or building user profiles
 * - Section III.D: Respect rate limits (this service handles token refresh)
 * - Section IV.B: Delete content within 24h if removed from X
 * - Section XIV.B: No intelligence gathering on individuals
 *
 * SAFE USES:
 * - Posting our own content (with human approval)
 * - Reading our own timeline/metrics
 * - Searching public topics for content ideas (not user tracking)
 *
 * PROHIBITED USES:
 * - Building prospect lists from followers/following
 * - Monitoring competitors' engagement patterns
 * - Tracking individual users' activity
 * - Any form of surveillance or intelligence gathering
 *
 * @see docs/SOCIAL_MEDIA_COMPLIANCE.md
 */
class XService
{
    protected string $baseUrl = 'https://api.twitter.com/2';

    protected string $oauthUrl = 'https://twitter.com/i/oauth2';

    public function getAuthUrl(string $redirectUri, array $scopes = [], bool $includeBookmarks = false): string
    {
        $defaultScopes = ['tweet.read', 'tweet.write', 'users.read', 'offline.access'];

        // Add bookmark scope if requested (requires re-auth for existing users)
        if ($includeBookmarks) {
            $defaultScopes[] = 'bookmark.read';
        }

        $scopes = array_merge($defaultScopes, $scopes);

        // X uses PKCE
        $codeVerifier = Str::random(128);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Store code verifier in session for later
        session(['x_code_verifier' => $codeVerifier]);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.x.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => csrf_token(),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return "{$this->oauthUrl}/authorize?{$params}";
    }

    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $codeVerifier = session('x_code_verifier');
        session()->forget('x_code_verifier');

        $response = Http::withBasicAuth(
            config('services.x.client_id'),
            config('services.x.client_secret')
        )->asForm()->post("{$this->baseUrl}/oauth2/token", [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for token: '.$response->body());
        }

        return $response->json();
    }

    public function refreshToken(XCredential $credential): array
    {
        $response = Http::withBasicAuth(
            config('services.x.client_id'),
            config('services.x.client_secret')
        )->asForm()->post("{$this->baseUrl}/oauth2/token", [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credential->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data;
    }

    public function getMe(XCredential $credential): array
    {
        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->get("{$this->baseUrl}/users/me", [
                'user.fields' => 'id,name,username,profile_image_url,description,verified,public_metrics',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get user: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    public function createTweet(XCredential $credential, string $text, ?array $options = []): array
    {
        $this->ensureValidToken($credential);

        $payload = ['text' => $text];

        // Add reply settings if specified
        if (! empty($options['reply_to'])) {
            $payload['reply'] = ['in_reply_to_tweet_id' => $options['reply_to']];
        }

        // Add quote tweet if specified
        if (! empty($options['quote_tweet_id'])) {
            $payload['quote_tweet_id'] = $options['quote_tweet_id'];
        }

        // Add poll if specified
        if (! empty($options['poll'])) {
            $payload['poll'] = [
                'options' => $options['poll']['options'],
                'duration_minutes' => $options['poll']['duration_minutes'] ?? 1440,
            ];
        }

        $response = Http::withToken($credential->access_token)
            ->post("{$this->baseUrl}/tweets", $payload);

        if (! $response->successful()) {
            Log::error('X tweet failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to create tweet: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    public function createThread(XCredential $credential, array $tweets): array
    {
        $results = [];
        $lastTweetId = null;

        foreach ($tweets as $tweetText) {
            $options = $lastTweetId ? ['reply_to' => $lastTweetId] : [];
            $result = $this->createTweet($credential, $tweetText, $options);
            $results[] = $result;
            $lastTweetId = $result['id'] ?? null;

            // Small delay to avoid rate limits
            usleep(500000);
        }

        return $results;
    }

    public function deleteTweet(XCredential $credential, string $tweetId): bool
    {
        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->delete("{$this->baseUrl}/tweets/{$tweetId}");

        return $response->successful();
    }

    public function getUserTimeline(XCredential $credential, int $maxResults = 10): array
    {
        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->get("{$this->baseUrl}/users/{$credential->x_user_id}/tweets", [
                'max_results' => min($maxResults, 100),
                'tweet.fields' => 'created_at,public_metrics,entities',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get timeline: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Search recent tweets.
     *
     * COMPLIANCE WARNING: This method must only be used for:
     * - Topic/trend research for content ideas
     * - General industry conversations
     *
     * PROHIBITED uses (X Developer Agreement Section XIV.B):
     * - Searching for specific users' tweets to track them
     * - Building profiles based on users' posting patterns
     * - Competitive surveillance of individuals
     * - Lead generation based on users' social activity
     *
     * @param  string  $query  Search query - should be TOPIC-based, not USER-based
     */
    public function searchTweets(XCredential $credential, string $query, int $maxResults = 10): array
    {
        // Compliance: Log searches for audit trail
        Log::info('X API search executed', [
            'query' => $query,
            'max_results' => $maxResults,
            'user_id' => $credential->user_id,
        ]);

        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->get("{$this->baseUrl}/tweets/search/recent", [
                'query' => $query,
                'max_results' => min($maxResults, 100),
                'tweet.fields' => 'created_at,public_metrics,author_id',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to search tweets: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Get user's bookmarks.
     *
     * Requires the bookmark.read scope.
     * Rate limit: 180 requests per 15-min window (Free tier: 1/day)
     * Returns up to 800 most recent bookmarks.
     *
     * @param  int  $maxResults  Max results per page (10-100)
     * @param  string|null  $paginationToken  For pagination
     * @return array{data: array, meta: array}
     */
    public function getBookmarks(XCredential $credential, int $maxResults = 100, ?string $paginationToken = null): array
    {
        $this->ensureValidToken($credential);

        $params = [
            'max_results' => min(max($maxResults, 10), 100),
            'tweet.fields' => 'created_at,public_metrics,entities,author_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
        ];

        if ($paginationToken) {
            $params['pagination_token'] = $paginationToken;
        }

        Log::debug('Fetching X bookmarks', [
            'credential_id' => $credential->id,
            'username' => $credential->username,
            'max_results' => $params['max_results'],
            'has_pagination_token' => ! empty($paginationToken),
        ]);

        $response = Http::withToken($credential->access_token)
            ->get("{$this->baseUrl}/users/{$credential->x_user_id}/bookmarks", $params);

        // Log rate limit headers for monitoring
        $rateLimitRemaining = $response->header('x-rate-limit-remaining');
        $rateLimitReset = $response->header('x-rate-limit-reset');
        if ($rateLimitRemaining !== null) {
            Log::info('X API rate limit status', [
                'endpoint' => 'bookmarks',
                'remaining' => $rateLimitRemaining,
                'reset_at' => $rateLimitReset ? date('Y-m-d H:i:s', (int) $rateLimitReset) : null,
            ]);
        }

        if (! $response->successful()) {
            $error = $response->json();

            Log::error('X bookmarks API error', [
                'credential_id' => $credential->id,
                'status' => $response->status(),
                'error' => $error,
            ]);

            // Handle specific errors
            if ($response->status() === 403) {
                throw new \Exception('Bookmark access denied. Please reconnect your X account with bookmark permissions.');
            }

            if ($response->status() === 429) {
                // Store rate limit reset time for future checks
                $resetTimestamp = $response->header('x-rate-limit-reset');
                if ($resetTimestamp) {
                    $credential->update([
                        'rate_limit_reset_at' => \Carbon\Carbon::createFromTimestamp((int) $resetTimestamp),
                    ]);
                }
                throw new \Exception('X API rate limit exceeded. Try again later.');
            }

            throw new \Exception('Failed to get bookmarks: '.($error['detail'] ?? $response->body()));
        }

        $json = $response->json();

        // Map author data to tweets for easier access
        $users = collect($json['includes']['users'] ?? [])->keyBy('id');
        $tweets = collect($json['data'] ?? [])->map(function ($tweet) use ($users) {
            $author = $users->get($tweet['author_id']);
            if ($author) {
                $tweet['author_username'] = $author['username'] ?? null;
                $tweet['author_name'] = $author['name'] ?? null;
                $tweet['author_profile_image'] = $author['profile_image_url'] ?? null;
            }

            return $tweet;
        })->all();

        $meta = $json['meta'] ?? [];

        Log::debug('X bookmarks response processed', [
            'credential_id' => $credential->id,
            'tweets_count' => count($tweets),
            'users_count' => $users->count(),
            'result_count' => $meta['result_count'] ?? null,
            'has_next_token' => ! empty($meta['next_token']),
        ]);

        return [
            'data' => $tweets,
            'meta' => $meta,
        ];
    }

    /**
     * Get all bookmarks with automatic pagination.
     * Use sparingly - this can consume your entire daily quota on free tier.
     *
     * @param  int  $limit  Maximum total bookmarks to fetch (0 = all, up to 800)
     */
    public function getAllBookmarks(XCredential $credential, int $limit = 0): array
    {
        $allBookmarks = [];
        $paginationToken = null;
        $maxLimit = $limit > 0 ? $limit : 800; // API max is 800
        $pageCount = 0;

        Log::info('Starting full bookmarks fetch', [
            'credential_id' => $credential->id,
            'username' => $credential->username,
            'limit' => $maxLimit,
        ]);

        do {
            $pageCount++;
            $result = $this->getBookmarks($credential, 100, $paginationToken);
            $pageBookmarks = $result['data'];
            $allBookmarks = array_merge($allBookmarks, $pageBookmarks);

            $paginationToken = $result['meta']['next_token'] ?? null;
            $resultCount = $result['meta']['result_count'] ?? count($pageBookmarks);

            Log::debug('Fetched bookmarks page', [
                'credential_id' => $credential->id,
                'page' => $pageCount,
                'page_count' => $resultCount,
                'total_so_far' => count($allBookmarks),
                'has_more' => ! empty($paginationToken),
            ]);

            // Respect limit
            if (count($allBookmarks) >= $maxLimit) {
                Log::info('Reached bookmark limit, truncating', [
                    'credential_id' => $credential->id,
                    'fetched' => count($allBookmarks),
                    'limit' => $maxLimit,
                ]);
                $allBookmarks = array_slice($allBookmarks, 0, $maxLimit);
                break;
            }

            // Small delay to be nice to API
            if ($paginationToken) {
                usleep(100000); // 100ms
            }
        } while ($paginationToken);

        Log::info('Completed full bookmarks fetch', [
            'credential_id' => $credential->id,
            'username' => $credential->username,
            'total_bookmarks' => count($allBookmarks),
            'pages_fetched' => $pageCount,
        ]);

        return $allBookmarks;
    }

    /**
     * Check if credential has bookmark.read scope.
     */
    public function hasBookmarkScope(XCredential $credential): bool
    {
        $scopes = $credential->scopes ?? [];

        return in_array('bookmark.read', $scopes);
    }

    protected function ensureValidToken(XCredential $credential): void
    {
        if ($credential->isTokenExpired()) {
            $this->refreshToken($credential);
            $credential->refresh();
        }
    }
}
