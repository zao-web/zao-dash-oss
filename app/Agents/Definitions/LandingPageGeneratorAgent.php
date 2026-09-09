<?php

namespace App\Agents\Definitions;

/**
 * Landing Page Generator Agent
 *
 * Creates landing page copy for vertical/industry focus:
 * - Industry-specific messaging
 * - Case study references
 * - Service positioning
 * - SEO-optimized content
 */
class LandingPageGeneratorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Landing Page Generator';
    }

    protected function getDescription(): string
    {
        return 'Generate landing page copy targeting specific industries or verticals.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true; // Content publishing requires review
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    public function allowedTools(): array
    {
        return [
            'search-clients',
            'search-projects',
            'web-search',
            'get-quarterly-patterns',
            'seo-keyword-research',
            'seo-analyze-serp',
            'seo-search-volume',
            'seo-generate-landing',
            'seo-optimize-content',
            'wp-create-page',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt(includeToneGuide: true);
    }

    public function configSchema(): array
    {
        return [
            'industry' => 'required|string', // Target industry/vertical
            'services' => 'nullable|array', // Specific services to highlight
            'tone' => 'nullable|in:professional,innovative,trustworthy,bold',
            'include_testimonials' => 'nullable|boolean',
            'seo_keywords' => 'nullable|array',
            'additional_context' => 'nullable|string',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'headline' => '',
            'subheadline' => '',
            'hero_copy' => '',
            'value_propositions' => [],
            'sections' => [],
            'cta_text' => '',
            'meta_title' => '',
            'meta_description' => '',
            'suggested_images' => [],
        ], $output);
    }
}
