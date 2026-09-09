<?php

namespace App\Agents\Tools;

use App\Services\Grok\GrokService;

/**
 * Get real-time X/Twitter trends via Grok's x_search tool.
 *
 * Uses the xAI /responses API with x_search tool to get actual
 * real-time data from X, not just Grok's training data.
 *
 * @see https://docs.x.ai/docs/guides/tools/search-tools
 */
class GetXTrendsTool extends BaseTool
{
    public function category(): string
    {
        return 'social';
    }

    public function id(): string
    {
        return 'get-x-trends';
    }

    public function name(): string
    {
        return 'Get X Trends';
    }

    public function description(): string
    {
        return 'Searches X/Twitter in real-time using Grok x_search tool. Returns live trends, hashtags, content formats, and posting strategies with citations to actual posts. Use before drafting social content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'analysis_type' => [
                    'type' => 'string',
                    'enum' => ['trends', 'hashtags', 'content_formats', 'sentiment', 'suggestions', 'optimize'],
                    'description' => 'Type of analysis: trends (general topic trends), hashtags (trending tags), content_formats (what\'s working), sentiment (topic sentiment), suggestions (post ideas), optimize (improve a draft)',
                ],
                'topic' => [
                    'type' => 'string',
                    'description' => 'Topic, industry, or niche to analyze (e.g., "WordPress development", "SaaS", "web agencies")',
                ],
                'draft' => [
                    'type' => 'string',
                    'description' => 'Draft post to optimize (only for analysis_type=optimize)',
                ],
                'recent_work' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Recent projects/work to potentially reference in suggestions',
                ],
            ],
            'required' => ['analysis_type', 'topic'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // Read-only analysis
    }

    public function execute(array $params): array
    {
        $grok = app(GrokService::class);

        if (! $grok->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Grok API not configured. Set GROK_API_KEY in environment.',
            ];
        }

        $analysisType = $params['analysis_type'];
        $topic = $params['topic'];

        try {
            $result = match ($analysisType) {
                'trends' => $grok->analyzeTrends($topic),
                'hashtags' => $grok->getTrendingHashtags($topic),
                'content_formats' => $grok->analyzeContentFormats($topic),
                'sentiment' => $grok->getTopicSentiment($topic),
                'suggestions' => $grok->suggestPosts(
                    brand: 'Zao',
                    industry: $topic,
                    recentWork: $params['recent_work'] ?? []
                ),
                'optimize' => $grok->optimizePost(
                    draft: $params['draft'] ?? '',
                    goal: 'engagement'
                ),
                default => throw new \InvalidArgumentException("Unknown analysis type: {$analysisType}"),
            };

            return [
                'success' => true,
                'analysis_type' => $analysisType,
                'topic' => $topic,
                'data' => $result,
                'citations' => $result['citations'] ?? [],
                'note' => 'Data sourced from real-time X search via Grok x_search tool.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
