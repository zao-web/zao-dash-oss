<?php

namespace App\Agents\Tools;

use App\Models\WordPressPost;
use App\Models\WordPressSite;
use App\Services\AI\MultiModelConsortium;
use App\Services\WordPress\WordPressMcpService;
use Illuminate\Support\Facades\Cache;

/**
 * Analyze existing blog posts to extract brand voice and writing style.
 *
 * Reads recent published posts and generates a style guide that can be
 * used to ensure new content matches the established tone.
 */
class AnalyzeBlogVoiceTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'analyze-blog-voice';
    }

    public function name(): string
    {
        return 'Analyze Blog Voice';
    }

    public function description(): string
    {
        return 'Analyze existing blog posts to extract brand voice, tone, and writing style patterns. Returns a style guide that should be followed when creating new content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'num_posts' => [
                    'type' => 'integer',
                    'description' => 'Number of recent posts to analyze (default: 10, max: 20)',
                    'default' => 10,
                ],
                'focus_areas' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific aspects to analyze: tone, vocabulary, structure, cta_style, headline_patterns',
                ],
                'refresh' => [
                    'type' => 'boolean',
                    'description' => 'Force refresh the analysis (bypasses 24h cache)',
                    'default' => false,
                ],
            ],
            'required' => [],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // Read-only analysis
    }

    public function execute(array $params): array
    {
        $site = WordPressSite::where('is_primary', true)->first()
            ?? WordPressSite::first();

        if (! $site) {
            return [
                'success' => false,
                'error' => 'No WordPress site configured.',
            ];
        }

        $numPosts = min($params['num_posts'] ?? 10, 20);
        $refresh = $params['refresh'] ?? false;
        $cacheKey = "blog_voice_analysis_{$site->id}";

        // Check cache (24h)
        if (! $refresh && $cached = Cache::get($cacheKey)) {
            return [
                'success' => true,
                'from_cache' => true,
                'analyzed_at' => $cached['analyzed_at'],
                'style_guide' => $cached['style_guide'],
                'patterns' => $cached['patterns'],
            ];
        }

        // Get recent posts
        $posts = $this->getRecentPosts($site, $numPosts);

        if (empty($posts)) {
            return [
                'success' => false,
                'error' => 'No published posts found to analyze.',
            ];
        }

        try {
            // Analyze with AI
            $analysis = $this->analyzeWithAI($posts, $params['focus_areas'] ?? []);

            // Cache for 24 hours
            $result = [
                'analyzed_at' => now()->toIso8601String(),
                'posts_analyzed' => count($posts),
                'style_guide' => $analysis['style_guide'],
                'patterns' => $analysis['patterns'],
            ];

            Cache::put($cacheKey, $result, 86400);

            return [
                'success' => true,
                'from_cache' => false,
                ...$result,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getRecentPosts(WordPressSite $site, int $limit): array
    {
        // Try local database first
        $localPosts = WordPressPost::where('wordpress_site_id', $site->id)
            ->where('status', 'publish')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        if ($localPosts->count() >= 5) {
            return $localPosts->map(fn ($p) => [
                'title' => $p->title,
                'excerpt' => $p->excerpt,
                'content_preview' => $p->content_preview,
            ])->toArray();
        }

        // Fetch from WordPress if not enough local posts
        try {
            $wpService = app(WordPressMcpService::class);
            $posts = $wpService->getPosts($site, [
                'per_page' => $limit,
                'status' => 'publish',
                'orderby' => 'date',
                'order' => 'desc',
            ]);

            return array_map(fn ($p) => [
                'title' => $p['title']['rendered'] ?? '',
                'excerpt' => strip_tags($p['excerpt']['rendered'] ?? ''),
                'content_preview' => substr(strip_tags($p['content']['rendered'] ?? ''), 0, 1500),
            ], $posts);
        } catch (\Exception $e) {
            return $localPosts->map(fn ($p) => [
                'title' => $p->title,
                'excerpt' => $p->excerpt,
                'content_preview' => $p->content_preview,
            ])->toArray();
        }
    }

    protected function analyzeWithAI(array $posts, array $focusAreas): array
    {
        $consortium = app(MultiModelConsortium::class);

        // Prepare samples
        $samples = collect($posts)->map(fn ($p, $i) => 'POST '.($i + 1).":\nTitle: {$p['title']}\nExcerpt: {$p['excerpt']}\nContent: {$p['content_preview']}\n"
        )->join("\n---\n");

        $prompt = "Analyze these blog posts and extract the brand's writing voice and style patterns.

{$samples}

Provide a comprehensive analysis with:

1. **TONE PROFILE**
   - Primary tone (e.g., authoritative, conversational, technical, friendly)
   - Secondary tone characteristics
   - Emotional register (formal/informal scale 1-10)

2. **VOCABULARY PATTERNS**
   - Common phrases and expressions used
   - Technical jargon level (1-10)
   - Industry-specific terminology
   - Words/phrases to USE (match their style)
   - Words/phrases to AVOID (not in their style)

3. **STRUCTURE PATTERNS**
   - Typical sentence length (short/medium/long)
   - Paragraph structure
   - Use of lists and bullet points
   - Heading hierarchy patterns
   - Introduction/conclusion styles

4. **HEADLINE PATTERNS**
   - Common headline structures
   - Use of numbers
   - Question vs statement headlines
   - Power words used

5. **CTA STYLE**
   - How they encourage action
   - Placement patterns
   - Urgency/softness level

6. **UNIQUE VOICE MARKERS**
   - Distinctive phrases or expressions
   - Cultural references
   - Humor usage
   - First person vs third person

Return as JSON with keys: style_guide (narrative summary, 200-300 words) and patterns (structured data for each category above).";

        $response = $consortium->chat([
            ['role' => 'user', 'content' => $prompt],
        ], null, 'gpt-4o');

        $content = $response['content'] ?? '';

        // Extract JSON
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed) {
                return $parsed;
            }
        }

        // Fallback structure
        return [
            'style_guide' => $content,
            'patterns' => [
                'analysis_raw' => $content,
            ],
        ];
    }
}
