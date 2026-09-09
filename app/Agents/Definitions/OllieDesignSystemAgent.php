<?php

namespace App\Agents\Definitions;

/**
 * Creates theme.json and style variations from brand guidelines.
 *
 * Maps brand colors, typography, and spacing to Ollie's design token system.
 */
class OllieDesignSystemAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ollie Design System';
    }

    protected function getDescription(): string
    {
        return 'Maps brand guidelines to Ollie theme.json design tokens. Generates custom style variations, configures typography using Mona Sans variants, and ensures WCAG accessibility compliance for color contrast.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'ollie-brief-analyzer';
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    protected function requiresApproval(): bool
    {
        return false; // Design tokens don't have side effects
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|string',
            'brand_colors' => 'required|array',
            'brand_colors.primary' => 'required|string',
            'typography_preferences' => 'nullable|array',
            'base_style' => 'nullable|in:default,agency,creator,startup,studio',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Project context
            'website-builder-project-status',

            // Theme generation and deployment
            'website-builder-generate-theme',
            'website-builder-update-global-styles',
            'website-builder-upload-theme-file',
            'website-builder-update-template-part',

            // Progress and communication
            'website-builder-update-progress',
            'website-builder-broadcast-message',

            // Deployment to WordPress
            'website-builder-deploy',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
