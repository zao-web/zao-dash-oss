<?php

namespace App\Agents\Tools;

use App\Agents\ToolRegistry;
use App\Services\Seo\SeoResearchService;

class SeoGenerateBlogTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-generate-blog';
    }

    public function name(): string
    {
        return 'Generate SEO Blog Post';
    }

    public function description(): string
    {
        return 'Generate a high-value, conversion-focused SEO blog post targeting our ICP. CRITICAL: Content must NOT sound like AI - no em dashes, no "delve/leverage/unlock", no corporate buzzwords. Write like a senior dev explaining to a peer. Include strong CTAs encouraging contact.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Target keyword from GSC/GA data analysis, aligned with ICP interests',
                ],
                'topic_angle' => [
                    'type' => 'string',
                    'description' => 'Specific angle for the post (e.g., "beginner guide", "case study", "comparison", "how-to")',
                ],
                'target_icp' => [
                    'type' => 'string',
                    'description' => 'Target ICP slug to tailor content for (e.g., "saas-companies", "healthcare-orgs")',
                ],
                'conversion_goal' => [
                    'type' => 'string',
                    'enum' => ['consultation', 'contact', 'newsletter', 'download'],
                    'description' => 'Primary conversion goal for the post CTA (default: contact)',
                    'default' => 'contact',
                ],
                'word_count_target' => [
                    'type' => 'integer',
                    'description' => 'Target word count for the post (default 1500, aim for comprehensive value)',
                ],
                'include_case_study' => [
                    'type' => 'boolean',
                    'description' => 'Include relevant client case study/example if available',
                    'default' => true,
                ],
            ],
            'required' => ['keyword', 'topic_angle'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        // Automatically fetch voice analysis to ensure brand consistency
        $voiceAnalysis = $this->getVoiceAnalysis();

        $result = $service->generateBlogPost(
            keyword: $params['keyword'],
            topicAngle: $params['topic_angle'],
            wordCountTarget: $params['word_count_target'] ?? 1500,
            voiceGuide: $voiceAnalysis
        );

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'voice_analysis_used' => ! empty($voiceAnalysis),
        ];
    }

    /**
     * Get cached voice analysis for brand consistency.
     */
    protected function getVoiceAnalysis(): ?string
    {
        try {
            $registry = app(ToolRegistry::class);
            $result = $registry->execute('analyze-blog-voice', [
                'num_posts' => 10,
                'refresh' => false, // Use cache
            ]);

            if ($result['success'] && isset($result['result']['style_guide'])) {
                return $result['result']['style_guide'];
            }
        } catch (\Exception $e) {
            // Voice analysis is optional enhancement
        }

        return null;
    }
}
