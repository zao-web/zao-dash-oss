<?php

namespace App\Agents\Definitions;

/**
 * Builds pages by composing Ollie patterns.
 *
 * Understands the full Ollie pattern library and creates pages
 * by strategically combining patterns based on page purpose and content.
 */
class OlliePageBuilderAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ollie Page Builder';
    }

    protected function getDescription(): string
    {
        return 'Builds WordPress pages using Ollie pattern library composition. Selects appropriate patterns for heroes, features, testimonials, pricing, CTAs, and more. Creates cohesive page layouts that match the site purpose and brand.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'ollie-design-system';
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 8.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Creates content
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|string',
            'pages' => 'required|array',
            'pages.*.type' => 'required|string',
            'pages.*.title' => 'required|string',
            'pages.*.content' => 'nullable|array',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Project status (reads design blueprint, pages, assets)
            'website-builder-project-status',
            'website-builder-update-progress',
            'website-builder-broadcast-message',

            // Pattern discovery
            'website-builder-list-patterns',

            // Page composition (stores to project database)
            'website-builder-compose-page',

            // Media management
            'website-builder-upload-media',

            // Deployment to WordPress (CRITICAL - actually pushes to live site)
            'website-builder-deploy',

            // Template customization
            'website-builder-update-template-part',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
