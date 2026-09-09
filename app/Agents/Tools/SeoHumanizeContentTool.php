<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

/**
 * Humanize AI-generated content by removing AI patterns.
 *
 * Detects and removes 24 common AI writing patterns including:
 * - Banned words (delve, leverage, seamlessly, etc.)
 * - Em dashes
 * - Excessive hedging
 * - Chatbot artifacts
 * - Vague attributions
 */
class SeoHumanizeContentTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-humanize-content';
    }

    public function name(): string
    {
        return 'Humanize SEO Content';
    }

    public function description(): string
    {
        return 'Detect and remove AI writing patterns from content to make it sound more human and natural. Returns a score (0-100) and the humanized version of the content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to humanize (HTML or plain text)',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // Content analysis doesn't need approval
    }

    public function riskLevel(): string
    {
        return 'low';
    }

    public function execute(array $params): array
    {
        $seoService = app(SeoResearchService::class);

        try {
            $result = $seoService->humanizeContent($params['content']);

            $patternsDetected = count($result['patterns_detected']);
            $patternsRemaining = count($result['patterns_remaining']);

            return [
                'success' => true,
                'score' => $result['score'],
                'patterns_detected' => $patternsDetected,
                'patterns_remaining' => $patternsRemaining,
                'patterns_fixed' => $patternsDetected - $patternsRemaining,
                'humanized_content' => $result['humanized'],
                'issues' => $result['patterns_detected'],
                'remaining_issues' => $result['patterns_remaining'],
                'is_human_quality' => $patternsRemaining === 0,
                'message' => $this->getMessage($result['score'], $patternsRemaining),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getMessage(int $score, int $patternsRemaining): string
    {
        if ($patternsRemaining === 0) {
            return "Content is human-quality with no AI patterns detected. Score: {$score}/100";
        }

        if ($score >= 80) {
            return "Content is good but has {$patternsRemaining} minor AI patterns. Score: {$score}/100";
        }

        if ($score >= 60) {
            return "Content needs improvement. Found {$patternsRemaining} AI patterns. Score: {$score}/100";
        }

        return "Content has significant AI patterns ({$patternsRemaining} found). Regenerate recommended. Score: {$score}/100";
    }
}
