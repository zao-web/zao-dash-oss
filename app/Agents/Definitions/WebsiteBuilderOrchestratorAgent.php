<?php

namespace App\Agents\Definitions;

/**
 * Unified orchestrator for all website building operations.
 *
 * Coordinates autonomous, guided, migration, and redesign workflows
 * for complete WordPress site creation and deployment.
 */
class WebsiteBuilderOrchestratorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Website Builder Orchestrator';
    }

    protected function getDescription(): string
    {
        return 'Unified orchestrator for autonomous, guided, migration, and redesign website building workflows. Coordinates research, content creation, WordPress setup, and deployment based on project type.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Sonnet is fast and capable enough for orchestration
    }

    protected function getMaxBudget(): float
    {
        return 30.00; // Higher budget for comprehensive site building
    }

    protected function requiresApproval(): bool
    {
        return false; // Disabled - website builder runs autonomously without pre-approval
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'project_type' => 'required|in:autonomous,guided,migration,redesign',
            'mode' => 'nullable|in:auto,interactive,batch',
            'skip_research' => 'nullable|boolean',
            'skip_design' => 'nullable|boolean',
            'skip_content' => 'nullable|boolean',
            'skip_deployment' => 'nullable|boolean',
            'approval_gates' => 'nullable|array',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Project management
            'website-builder-project-status',
            'website-builder-update-progress',
            'website-builder-broadcast-message',

            // Brief parsing & analysis
            'website-builder-parse-brief',
            'website-builder-analyze-site',
            'website-builder-analyze-repo',
            'website-builder-analyze-pages',

            // Social proof research (NEW)
            'website-builder-social-proof-aggregator',
            'website-builder-social-media-analyzer',
            'website-builder-build-testimonials-page',
            'google-places-reviews',
            'facebook-page-reviews',
            'yelp-reviews',

            // Design system
            'website-builder-generate-theme',
            'website-builder-list-patterns',

            // Content & patterns
            'website-builder-compose-page',
            'website-builder-update-template-part',

            // Design & styling
            'website-builder-update-global-styles',
            'website-builder-upload-theme-file',

            // Media & content ingestion
            'website-builder-upload-media',
            'website-builder-extract-content',
            'website-builder-download-assets',

            // Deployment
            'website-builder-deploy',
            'spinup-wp-provision-site',

            // Agent coordination
            'web-search',
            'trigger-agent',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge($output, [
            'project_id' => $output['project_id'] ?? null,
            'final_status' => $output['final_status'] ?? 'completed',
            'staging_url' => $output['staging_url'] ?? null,
            'production_url' => $output['production_url'] ?? null,
            'next_steps' => $output['next_steps'] ?? [],
            'client_notification_sent' => $output['client_notification_sent'] ?? false,
        ]);
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt('website-builder-orchestrator/SKILL.md');
    }

    /**
     * Use CLI mode to leverage OAuth token (Claude Max subscription).
     *
     * CLI mode avoids the API rate limits (30k tokens/min) that SDK mode hits.
     * If Claude CLI is not available, AgentExecutor will fall back to SDK mode.
     */
    public function executionMode(): ?string
    {
        // CLI mode uses OAuth token from Claude Max subscription
        // - No per-minute token rate limits
        // - No API costs (uses subscription)
        // - Falls back to SDK automatically if CLI unavailable
        return 'cli';
    }
}
