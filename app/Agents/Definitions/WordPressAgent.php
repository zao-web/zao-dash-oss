<?php

namespace App\Agents\Definitions;

class WordPressAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'WordPress Publisher';
    }

    protected function getDescription(): string
    {
        return 'Publishes blog posts, case studies, and landing pages to the WordPress agency website. Handles formatting, images, and SEO.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'content-creator'; // Runs after ContentCreator produces content
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Publishing requires approval
    }

    public function allowedTools(): array
    {
        return [
            'wp-create-post',
            'generate-featured-image',
            'analyze-blog-voice',
            'seo-optimize-content',
            'search-content',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
