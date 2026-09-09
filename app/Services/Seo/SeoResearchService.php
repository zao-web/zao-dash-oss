<?php

namespace App\Services\Seo;

use App\Services\Grok\GrokService;
use Illuminate\Support\Facades\Cache;

class SeoResearchService
{
    public function __construct(
        protected ?GrokService $grok = null
    ) {
        $this->grok = $grok ?? app(GrokService::class);
    }

    /**
     * Research keywords for a given topic.
     */
    public function keywordResearch(string $topic, array $seedKeywords = [], ?string $intentFilter = null): array
    {
        $cacheKey = 'seo_keywords_'.md5($topic.implode(',', $seedKeywords).$intentFilter);

        return Cache::remember($cacheKey, 3600, function () use ($topic, $seedKeywords, $intentFilter) {
            // Use Grok for keyword ideation (has real-time search knowledge)
            if ($this->grok->isConfigured()) {
                return $this->researchWithGrok($topic, $seedKeywords, $intentFilter);
            }

            // Fallback to basic keyword generation
            return $this->generateBasicKeywords($topic, $seedKeywords, $intentFilter);
        });
    }

    /**
     * Analyze SERP for a keyword.
     */
    public function analyzeSerpForKeyword(string $keyword): array
    {
        $cacheKey = 'seo_serp_'.md5($keyword);

        return Cache::remember($cacheKey, 7200, function () use ($keyword) {
            if ($this->grok->isConfigured()) {
                return $this->analyzeSerpWithGrok($keyword);
            }

            return [
                'keyword' => $keyword,
                'analysis' => 'SERP analysis requires Grok API configuration',
                'top_results' => [],
                'content_patterns' => [],
            ];
        });
    }

    /**
     * Find keyword gaps vs competitors.
     */
    public function findCompetitorGaps(array $competitorUrls, array $ourKeywords = []): array
    {
        if ($this->grok->isConfigured()) {
            $response = $this->grok->chat([
                [
                    'role' => 'system',
                    'content' => 'You are an SEO competitor analyst. Analyze competitors and identify keyword gaps.',
                ],
                [
                    'role' => 'user',
                    'content' => "Analyze these competitor websites and identify keyword opportunities they're likely ranking for that a WordPress/Laravel agency should target:

Competitors: ".implode(', ', $competitorUrls).'

Our current focus keywords: '.implode(', ', $ourKeywords).'

Provide:
1. Keywords they likely rank for (based on their services/content)
2. Content gaps we could fill
3. Long-tail opportunities
4. Recommended priority keywords

Format as JSON with keys: competitor_keywords, content_gaps, long_tail, priority_keywords',
                ],
            ]);

            return [
                'competitors' => $competitorUrls,
                'analysis' => $response['content'] ?? '',
                'analyzed_at' => now()->toIso8601String(),
            ];
        }

        return [
            'competitors' => $competitorUrls,
            'analysis' => 'Competitor analysis requires Grok API configuration',
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get search volume estimates for keywords.
     */
    public function getSearchVolume(array $keywords): array
    {
        // In production, this would integrate with SEMrush, Ahrefs, or similar APIs
        // For now, use Grok to estimate based on its knowledge

        if ($this->grok->isConfigured()) {
            $response = $this->grok->chat([
                [
                    'role' => 'system',
                    'content' => 'You are an SEO data analyst. Estimate search volumes and provide keyword metrics.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Estimate monthly search volume and SEO metrics for these keywords:

'.implode("\n", $keywords).'

For each keyword provide:
- estimated_monthly_volume (number)
- difficulty (1-100)
- intent (informational/commercial/transactional/navigational)
- trend (rising/stable/declining)
- cpc_estimate (USD)

Return as JSON array.',
                ],
            ]);

            return [
                'keywords' => $keywords,
                'estimates' => $response['content'] ?? '',
                'source' => 'grok_estimate',
                'disclaimer' => 'Estimates based on AI analysis. Use SEMrush/Ahrefs for precise data.',
            ];
        }

        return [
            'keywords' => $keywords,
            'estimates' => [],
            'source' => 'none',
            'disclaimer' => 'Search volume data requires API configuration',
        ];
    }

    /**
     * Generate SEO-optimized landing page content.
     */
    public function generateLandingPage(string $keyword, string $pageType, string $templateStyle = 'professional'): array
    {
        if (! $this->grok->isConfigured()) {
            return ['error' => 'Content generation requires Grok API'];
        }

        $response = $this->grok->chat([
            [
                'role' => 'system',
                'content' => "You are an expert SEO copywriter for Zao, a WordPress/Laravel development agency. Create high-converting, SEO-optimized landing pages that rank well and generate leads.

Style: {$templateStyle}
Brand voice: Professional, technical expertise, approachable",
            ],
            [
                'role' => 'user',
                'content' => "Create a complete SEO-optimized landing page for the keyword: \"{$keyword}\"

Page Type: {$pageType}

Include:
1. SEO Title (under 60 chars, keyword front-loaded)
2. Meta Description (under 160 chars, compelling)
3. URL Slug
4. H1 Headline (different from title, includes keyword)
5. Hero Section (headline, subheadline, CTA)
6. Problem Section (pain points we solve)
7. Solution Section (how we help)
8. Benefits Section (3-5 key benefits)
9. Social Proof Section (placeholder for testimonials)
10. FAQ Section (5 common questions with SEO-rich answers)
11. CTA Section (final call to action)
12. Schema Markup (LocalBusiness or Service JSON-LD)

Format the content in clean HTML with proper heading hierarchy.
Include internal link suggestions to related services.",
            ],
        ]);

        return [
            'keyword' => $keyword,
            'page_type' => $pageType,
            'content' => $response['content'] ?? '',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate SEO-optimized blog post.
     *
     * @param  string  $keyword  Target SEO keyword
     * @param  string  $topicAngle  Content angle (how-to, guide, comparison, etc)
     * @param  int  $wordCountTarget  Target word count
     * @param  string|null  $voiceGuide  Brand voice analysis from existing posts
     */
    public function generateBlogPost(string $keyword, string $topicAngle, int $wordCountTarget = 1500, ?string $voiceGuide = null): array
    {
        if (! $this->grok->isConfigured()) {
            return ['error' => 'Content generation requires Grok API'];
        }

        // Build voice instructions from analysis or use defaults
        $voiceInstructions = $voiceGuide
            ? "## Brand Voice (from analysis of existing posts)\n\n{$voiceGuide}\n\nMatch this tone exactly."
            : 'Brand voice: Expert, helpful, practical with real examples';

        $response = $this->grok->chat([
            [
                'role' => 'system',
                'content' => "You are an expert technical content writer for Zao, a WordPress/Laravel agency. Write informative, SEO-optimized blog posts that demonstrate expertise and attract qualified leads.

Target word count: {$wordCountTarget}

{$voiceInstructions}

## CRITICAL: Anti-AI-Slop Rules
NEVER use these patterns:
- Em dashes (—) - use commas or periods
- \"Delve\", \"dive into\", \"leverage\", \"unlock\", \"elevate\"
- \"Seamlessly\", \"effortlessly\", \"cutting-edge\", \"game-changer\"
- \"In today's fast-paced world\", \"It's important to note\"
- \"Furthermore\", \"Moreover\" - use \"Also\" or nothing

Write like a senior dev explaining to a peer. Short sentences. Specific examples. No buzzwords.",
            ],
            [
                'role' => 'user',
                'content' => "Write a comprehensive SEO-optimized blog post for the keyword: \"{$keyword}\"

Topic Angle: {$topicAngle}

Include:
1. SEO Title (under 60 chars)
2. Meta Description (under 160 chars)
3. URL Slug
4. Introduction (hook + keyword in first 100 words)
5. Main Content with proper H2/H3 structure
6. Practical examples and code snippets where relevant
7. Key takeaways section
8. Conclusion with CTA
9. FAQ section (3-5 questions for featured snippets)
10. Schema markup (Article JSON-LD)

Ensure:
- Keyword appears naturally 3-5 times
- Include related keywords (LSI)
- Use bullet points and numbered lists
- Short paragraphs (2-3 sentences max)
- Include suggestions for internal/external links",
            ],
        ]);

        return [
            'keyword' => $keyword,
            'angle' => $topicAngle,
            'word_count_target' => $wordCountTarget,
            'content' => $response['content'] ?? '',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Optimize existing content for a keyword.
     */
    public function optimizeContent(string $currentContent, string $targetKeyword): array
    {
        if (! $this->grok->isConfigured()) {
            return ['error' => 'Content optimization requires Grok API'];
        }

        $response = $this->grok->chat([
            [
                'role' => 'system',
                'content' => 'You are an SEO optimization expert. Analyze and improve existing content for better search rankings while maintaining quality and readability.',
            ],
            [
                'role' => 'user',
                'content' => "Optimize this content for the target keyword: \"{$targetKeyword}\"

Current Content:
{$currentContent}

Provide:
1. SEO Score (1-100) of current content
2. Issues Found (list of SEO problems)
3. Optimized Version (full rewritten content)
4. Changes Made (bullet list of improvements)
5. New Meta Title
6. New Meta Description
7. Additional Keywords to Include
8. Internal Link Suggestions",
            ],
        ]);

        return [
            'target_keyword' => $targetKeyword,
            'optimization' => $response['content'] ?? '',
            'optimized_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Research keywords using Grok.
     */
    protected function researchWithGrok(string $topic, array $seedKeywords, ?string $intentFilter): array
    {
        $intentInstruction = $intentFilter
            ? "Focus on {$intentFilter} intent keywords."
            : 'Include all intent types.';

        $seedContext = ! empty($seedKeywords)
            ? 'Seed keywords to expand from: '.implode(', ', $seedKeywords)
            : '';

        $response = $this->grok->chat([
            [
                'role' => 'system',
                'content' => 'You are an SEO keyword research expert. Generate comprehensive keyword lists with search intent classification.',
            ],
            [
                'role' => 'user',
                'content' => "Research keywords for a WordPress/Laravel development agency targeting: {$topic}

{$seedContext}

{$intentInstruction}

Generate 20-30 keywords including:
- Primary keywords (high volume, core topic)
- Long-tail keywords (specific, lower competition)
- Question keywords (how, what, why, when)
- Comparison keywords (vs, alternative, best)
- Commercial keywords (services, agency, company)

For each keyword provide:
- keyword
- estimated_volume (low/medium/high)
- difficulty (easy/medium/hard)
- intent (informational/commercial/transactional)
- priority (1-5, 5 being highest)

Format as JSON array.",
            ],
        ]);

        return [
            'topic' => $topic,
            'seed_keywords' => $seedKeywords,
            'intent_filter' => $intentFilter,
            'keywords' => $response['content'] ?? '',
            'source' => 'grok',
            'researched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Analyze SERP using Grok.
     */
    protected function analyzeSerpWithGrok(string $keyword): array
    {
        $response = $this->grok->chat([
            [
                'role' => 'system',
                'content' => 'You are an SEO SERP analyst. Analyze search results and identify patterns for ranking.',
            ],
            [
                'role' => 'user',
                'content' => "Analyze the search results page for: \"{$keyword}\"

Provide:
1. Search Intent (what users want)
2. SERP Features Present (featured snippets, PAA, local pack, etc.)
3. Top Ranking Content Types (blog, service page, comparison, etc.)
4. Common Content Patterns:
   - Average word count
   - Heading structure
   - Topics covered
   - Media usage
5. Content Gaps (what's missing from current results)
6. Ranking Opportunity Score (1-10)
7. Recommended Content Strategy

Be specific and actionable.",
            ],
        ]);

        return [
            'keyword' => $keyword,
            'analysis' => $response['content'] ?? '',
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate basic keywords without API.
     */
    protected function generateBasicKeywords(string $topic, array $seedKeywords, ?string $intentFilter): array
    {
        $baseKeywords = array_merge($seedKeywords, [
            $topic,
            "{$topic} services",
            "{$topic} agency",
            "{$topic} company",
            "{$topic} development",
            "best {$topic}",
            "{$topic} experts",
            "hire {$topic}",
            "{$topic} consulting",
            "professional {$topic}",
        ]);

        return [
            'topic' => $topic,
            'seed_keywords' => $seedKeywords,
            'keywords' => $baseKeywords,
            'source' => 'basic_generation',
            'note' => 'Configure Grok API for comprehensive keyword research',
        ];
    }

    /**
     * Get content templates for programmatic SEO.
     */
    public function getContentTemplates(): array
    {
        $path = storage_path('app/seo/content-templates.json');

        if (! file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true);
    }

    /**
     * Humanize AI-generated content by removing AI patterns.
     */
    public function humanizeContent(string $content): array
    {
        $patterns = $this->detectAIPatterns($content);

        // If patterns detected and Grok is available, regenerate with fixes
        if (count($patterns) > 0 && $this->grok->isConfigured()) {
            $fixInstructions = implode("\n", array_map(
                fn ($p) => "- Fix: {$p['type']} → {$p['suggestion']}",
                $patterns
            ));

            $response = $this->grok->chat([
                [
                    'role' => 'system',
                    'content' => 'You are a content humanizer. Remove AI patterns and make text sound natural.',
                ],
                [
                    'role' => 'user',
                    'content' => "Rewrite this content to fix these AI patterns:\n\n{$fixInstructions}\n\nOriginal content:\n{$content}",
                ],
            ]);

            $humanized = $response['content'] ?? $content;

            // Re-check for patterns
            $remainingPatterns = $this->detectAIPatterns($humanized);

            return [
                'original' => $content,
                'humanized' => $humanized,
                'patterns_detected' => $patterns,
                'patterns_remaining' => $remainingPatterns,
                'score' => count($remainingPatterns) === 0 ? 100 : max(0, 100 - (count($remainingPatterns) * 10)),
            ];
        }

        return [
            'original' => $content,
            'humanized' => $content,
            'patterns_detected' => $patterns,
            'patterns_remaining' => $patterns,
            'score' => count($patterns) === 0 ? 100 : max(0, 100 - (count($patterns) * 10)),
        ];
    }

    /**
     * Detect AI patterns in content.
     */
    public function detectAIPatterns(string $content): array
    {
        $patterns = [];

        // Banned words
        $bannedWords = ['delve', 'dive into', 'leverage', 'unlock', 'seamlessly', 'revolutionize',
            'paradigm', 'cutting-edge', 'game-changer', 'elevate'];

        foreach ($bannedWords as $word) {
            if (str_contains(strtolower($content), strtolower($word))) {
                $patterns[] = [
                    'type' => 'banned_word',
                    'word' => $word,
                    'suggestion' => 'Use simpler, more natural language',
                ];
            }
        }

        // Em dashes
        if (str_contains($content, '—')) {
            $patterns[] = [
                'type' => 'em_dash',
                'suggestion' => 'Replace em dashes with commas or periods',
            ];
        }

        // Excessive hedging
        $hedgingWords = ['might', 'could', 'possibly', 'perhaps'];
        $hedgingCount = 0;
        foreach ($hedgingWords as $word) {
            $hedgingCount += substr_count(strtolower($content), ' '.$word.' ');
        }

        if ($hedgingCount > 3) {
            $patterns[] = [
                'type' => 'excessive_hedging',
                'count' => $hedgingCount,
                'suggestion' => 'Be more direct and confident',
            ];
        }

        // Chatbot artifacts
        $chatbotPhrases = ['i hope this helps', 'let me know if', 'feel free to', 'happy to help'];
        foreach ($chatbotPhrases as $phrase) {
            if (str_contains(strtolower($content), $phrase)) {
                $patterns[] = [
                    'type' => 'chatbot_artifact',
                    'phrase' => $phrase,
                    'suggestion' => 'Remove conversational AI phrases',
                ];
            }
        }

        // Vague attributions
        $vagueAttributions = ['research shows', 'studies indicate', 'experts say', 'it is important to note'];
        foreach ($vagueAttributions as $phrase) {
            if (str_contains(strtolower($content), $phrase)) {
                $patterns[] = [
                    'type' => 'vague_attribution',
                    'phrase' => $phrase,
                    'suggestion' => 'Use specific sources or remove',
                ];
            }
        }

        return $patterns;
    }

    /**
     * Check if generated page passes quality gates.
     */
    public function passesQualityGates(array $page): bool
    {
        $checks = [
            'has_title' => ! empty($page['title']),
            'has_content' => ! empty($page['content']) && strlen($page['content']) > 500,
            'has_meta_title' => ! empty($page['meta_title']) && strlen($page['meta_title']) < 60,
            'has_meta_description' => ! empty($page['meta_description']) && strlen($page['meta_description']) < 160,
            'has_target_keyword' => ! empty($page['target_keyword']),
            'keyword_in_first_100' => ! empty($page['content']) &&
                str_contains(strtolower(substr($page['content'], 0, 100)), strtolower($page['target_keyword'] ?? '')),
        ];

        return ! in_array(false, $checks, true);
    }
}
