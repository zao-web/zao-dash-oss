<?php

namespace App\Agents\Definitions;

class SeoContentWriterAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'SEO Content Writer';
    }

    protected function getDescription(): string
    {
        return 'Generates individual SEO content pieces based on orchestrator strategy. Worker agent that creates ONE high-quality page at a time using proprietary data.';
    }

    protected function getTrigger(): string
    {
        return 'chained'; // Triggered by GenerateSeoContentJob
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 5.00; // $5 per content piece
    }

    protected function requiresApproval(): bool
    {
        return false; // Auto-execute when dispatched by orchestrator
    }

    public function executionMode(): ?string
    {
        return 'cli'; // Use CLI for content generation
    }

    public function allowedTools(): array
    {
        return [
            // Database queries
            'list-clients',
            'list-projects',
            'get-client',
            'get-project',
            'search',

            // WordPress operations
            'wp-create-page',
            'wp-create-post',
            'wp-update-page',
            'wp-get-site',

            // SEO tools
            'seo-humanize-content',
            'generate-seo-featured-image',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt('seo-content-writer/SKILL.md', false);
    }
}
