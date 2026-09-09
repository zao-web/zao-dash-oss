<?php

namespace App\Services\Grok;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * xAI Grok API Service with Real-Time Search Tools
 *
 * Uses the /responses endpoint with x_search and web_search tools
 * for actual real-time X/Twitter data.
 *
 * IMPORTANT: This is SEPARATE from the X API (api.twitter.com).
 * - Grok API: api.x.ai (xAI's AI service with search tools)
 * - X API: api.twitter.com (X Corp's Twitter API)
 *
 * @see https://docs.x.ai/docs/guides/tools/search-tools
 * @see docs/SOCIAL_MEDIA_COMPLIANCE.md
 */
class GrokService
{
    protected string $baseUrl = 'https://api.x.ai/v1';

    protected string $model = 'grok-4-1-fast';

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('services.grok.api_key');
    }

    /**
     * Check if Grok is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Analyze current X/Twitter trends for a topic using real-time x_search.
     */
    public function analyzeTrends(string $topic, array $options = []): array
    {
        $prompt = "Analyze current X/Twitter trends for: {$topic}

Search X for recent posts about this topic and provide:
1. Top 5 trending conversations/angles
2. Hashtags being used (with engagement levels)
3. Content types performing best (threads, single tweets, media)
4. Key accounts driving the conversation
5. Sentiment breakdown
6. Opportunities for a brand to join authentically
7. Topics/angles to AVOID";

        $response = $this->query($prompt, [
            'tools' => ['x_search'],
            'from_date' => $options['from_date'] ?? now()->subDays(7)->toDateString(),
        ]);

        return [
            'topic' => $topic,
            'analysis' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'model' => $this->model,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get trending hashtags for an industry using real-time x_search.
     */
    public function getTrendingHashtags(string $industry): array
    {
        $cacheKey = "grok_hashtags_{$industry}_".now()->format('Y-m-d-H');

        return Cache::remember($cacheKey, 3600, function () use ($industry) {
            $response = $this->query(
                "Search X for trending hashtags in the {$industry} industry from the past 24 hours. Return as JSON array with keys: hashtag, engagement (high/medium/low), trend_direction (rising/stable/falling), context. Only return the JSON array, no other text.",
                ['tools' => ['x_search'], 'from_date' => now()->subDay()->toDateString()]
            );

            $content = $response['content'] ?? '[]';

            // Extract JSON from response
            if (preg_match('/\[[\s\S]*\]/', $content, $matches)) {
                $hashtags = json_decode($matches[0], true) ?? [];
            } else {
                $hashtags = [];
            }

            return [
                'industry' => $industry,
                'hashtags' => $hashtags,
                'citations' => $response['citations'] ?? [],
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Analyze what content formats are performing well using x_search.
     */
    public function analyzeContentFormats(string $niche): array
    {
        $response = $this->query(
            "Search X for high-performing posts in the {$niche} niche from the past week. Analyze what content formats work best: thread length, media usage, posting times, engagement hooks, tone. Provide specific examples of post structures that got high engagement.",
            ['tools' => ['x_search'], 'from_date' => now()->subWeek()->toDateString()]
        );

        return [
            'niche' => $niche,
            'analysis' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get real-time sentiment around a topic using x_search.
     */
    public function getTopicSentiment(string $topic): array
    {
        $response = $this->query(
            "Search X for recent conversations about '{$topic}'. Analyze: overall sentiment (positive/negative/neutral with percentages), key talking points, controversies to avoid, opportunities to join the conversation authentically.",
            ['tools' => ['x_search'], 'from_date' => now()->subDays(3)->toDateString()]
        );

        return [
            'topic' => $topic,
            'sentiment' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate post suggestions based on current trends using x_search.
     */
    public function suggestPosts(string $brand, string $industry, array $recentWork = []): array
    {
        $workContext = ! empty($recentWork)
            ? 'Recent work/projects to potentially reference: '.implode(', ', $recentWork)
            : '';

        $response = $this->query(
            "Search X for what's trending RIGHT NOW in {$industry}. Then suggest 3 post ideas for {$brand} (a {$industry} company). {$workContext}

For each post provide:
1. The hook (first line that stops the scroll)
2. Full post content (under 280 chars or indicate if thread)
3. Suggested hashtags (2-4 max)
4. Best time to post today
5. Why this will perform well based on current trends

Focus on trends that align with expertise, not forced trend-jacking.",
            ['tools' => ['x_search'], 'from_date' => now()->subDays(2)->toDateString()]
        );

        return [
            'brand' => $brand,
            'industry' => $industry,
            'suggestions' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Optimize a draft post using x_search for current best practices.
     */
    public function optimizePost(string $draft, string $goal = 'engagement'): array
    {
        $response = $this->query(
            "Search X for high-performing posts similar to this draft to understand current best practices. Then optimize this post for {$goal}:

\"{$draft}\"

Provide:
1. Score (1-10) for current version
2. Specific improvements based on what's working on X right now
3. Optimized version of the post
4. Hashtag suggestions
5. Best posting time",
            ['tools' => ['x_search', 'web_search']]
        );

        return [
            'original' => $draft,
            'goal' => $goal,
            'optimization' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Web search for general research (competitors, industry news, etc).
     */
    public function webSearch(string $query, array $options = []): array
    {
        $response = $this->query($query, [
            'tools' => ['web_search'],
            'excluded_domains' => $options['excluded_domains'] ?? [],
        ]);

        return [
            'query' => $query,
            'result' => $response['content'] ?? '',
            'citations' => $response['citations'] ?? [],
            'searched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Core query method using /responses endpoint with tools.
     */
    public function query(string $prompt, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Grok API key not configured. Set GROK_API_KEY in environment.');
        }

        $tools = [];
        foreach ($options['tools'] ?? [] as $toolType) {
            $tool = ['type' => $toolType];

            // Add x_search specific options
            if ($toolType === 'x_search') {
                if (! empty($options['from_date'])) {
                    $tool['from_date'] = $options['from_date'];
                }
                if (! empty($options['to_date'])) {
                    $tool['to_date'] = $options['to_date'];
                }
                if (! empty($options['allowed_x_handles'])) {
                    $tool['allowed_x_handles'] = $options['allowed_x_handles'];
                }
            }

            // Add web_search specific options
            if ($toolType === 'web_search') {
                if (! empty($options['excluded_domains'])) {
                    $tool['excluded_domains'] = array_slice($options['excluded_domains'], 0, 5);
                }
                if (! empty($options['allowed_domains'])) {
                    $tool['allowed_domains'] = $options['allowed_domains'];
                }
            }

            $tools[] = $tool;
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'input' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/responses", $payload);

        if (! $response->successful()) {
            Log::error('Grok API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Grok API error: {$response->status()} - {$response->body()}");
        }

        $data = $response->json();

        return $this->parseResponse($data);
    }

    /**
     * Parse the /responses API response format.
     */
    protected function parseResponse(array $data): array
    {
        $content = '';
        $citations = [];

        // The response structure from /responses endpoint
        // may have output array with different item types
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $content .= $block['text'] ?? '';
                    }
                    // Extract inline citations if present
                    if (! empty($block['citations'])) {
                        $citations = array_merge($citations, $block['citations']);
                    }
                }
            }
        }

        // Also check for citations at top level
        if (! empty($data['citations'])) {
            $citations = array_merge($citations, $data['citations']);
        }

        return [
            'content' => $content,
            'citations' => $citations,
            'usage' => $data['usage'] ?? [],
            'model' => $data['model'] ?? $this->model,
        ];
    }
}
