<?php

namespace App\Agents\Definitions;

/**
 * Content Synchronization Agent for pushing content to WordPress sites.
 *
 * Handles the content pipeline from Zao Dash agents to client WordPress sites,
 * ensuring content flows seamlessly and maintains formatting.
 */
class ContentSyncAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Content Synchronization Agent';
    }

    protected function getDescription(): string
    {
        return 'Manages content flow from Zao Dash agents to WordPress sites. Creates drafts, uploads media, syncs categories, and handles content updates across multiple sites.';
    }

    protected function getTrigger(): string
    {
        return 'chained'; // Triggered by content-generating agents
    }

    protected function getChainFrom(): ?string
    {
        return 'content-creator'; // Can be chained from various content agents
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Content processing and formatting
    }

    protected function getMaxBudget(): float
    {
        return 5.00; // Content operations are typically quick
    }

    protected function requiresApproval(): bool
    {
        return false; // Content sync to drafts is low-risk
    }

    public function configSchema(): array
    {
        return [
            'source_agent_run_id' => 'required|integer|exists:agent_runs,id',
            'target_sites' => 'required|array|min:1',
            'target_sites.*' => 'integer|exists:wordpress_sites,id',
            'content_type' => 'required|in:post,page,case_study,blog_post,landing_page',
            'publish_immediately' => 'boolean',
            'categories' => 'nullable|array',
            'tags' => 'nullable|array',
            'featured_image' => 'nullable|url',
            'seo_meta' => 'nullable|array',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Content creation
            'wordpress_draft_create',
            'wordpress_content_format',
            'wordpress_media_upload',

            // Taxonomy management
            'wordpress_category_sync',
            'wordpress_tag_sync',

            // SEO and metadata
            'wordpress_seo_meta_set',
            'wordpress_featured_image_set',

            // Multi-site operations
            'wordpress_bulk_publish',
            'wordpress_content_update',

            // Quality assurance
            'content_validation_check',
            'wordpress_link_check',
        ];
    }

    public function processOutput(array $output): array
    {
        // Track sync results and provide client notifications
        $results = $output['sync_results'] ?? [];

        $successful = count(array_filter($results, fn ($r) => $r['success'] ?? false));
        $total = count($results);

        return array_merge($output, [
            'sync_summary' => [
                'successful' => $successful,
                'total' => $total,
                'success_rate' => $total > 0 ? round(($successful / $total) * 100, 1) : 0,
            ],
            'client_notification' => $successful > 0,
            'wordpress_links' => array_filter(array_column($results, 'edit_url')),
        ]);
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
