<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;

/**
 * Get current status and details of a website building project.
 *
 * Works across all project types (autonomous, guided, migration, redesign).
 */
class WebsiteBuilderProjectStatusTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Get Website Project Status';
    }

    public function description(): string
    {
        return 'Get comprehensive status and details for a website building project including progress, current phase, URLs, errors, and configuration.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID',
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::with(['user', 'wordpressSite'])
            ->find($params['project_id']);

        if (! $project) {
            return [
                'success' => false,
                'error' => 'Project not found',
            ];
        }

        return [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->project_type,
                'source_type' => $project->source_type,
                'domain' => $project->domain,
                'hosting_type' => $project->hosting_type,
                'environment' => $project->environment,

                // Status & Progress
                'status' => $project->status,
                'overall_progress' => $project->overall_progress,
                'phase_progress' => $project->phase_progress,
                'last_error' => $project->last_error,
                'retry_count' => $project->retry_count,

                // URLs
                'staging_url' => $project->staging_url,
                'production_url' => $project->production_url,
                'download_url' => $project->download_url,
                'live_url' => $project->getLiveUrl(),

                // Configuration
                'design_config' => $project->design_config,
                'brand_assets' => $project->brand_assets,
                'pages' => $project->pages,
                'patterns_selected' => $project->patterns_selected,
                'custom_blocks' => $project->custom_blocks,

                // Analysis Data (for migrations/redesigns)
                'site_analysis' => $project->site_analysis,
                'repo_analysis' => $project->repo_analysis,
                'extracted_content' => $project->extracted_content,

                // Budget & Cost
                'budget_allocated' => $project->budget_allocated,
                'cost_incurred' => $project->cost_incurred,
                'is_over_budget' => $project->isOverBudget(),
                'remaining_budget' => $project->getRemainingBudget(),

                // Timestamps
                'started_at' => $project->started_at?->toIso8601String(),
                'completed_at' => $project->completed_at?->toIso8601String(),
                'estimated_completion' => $project->estimated_completion?->toIso8601String(),
                'created_at' => $project->created_at->toIso8601String(),
                'updated_at' => $project->updated_at->toIso8601String(),

                // Computed Properties
                'is_complete' => $project->isComplete(),
                'has_failed' => $project->hasFailed(),
                'is_in_progress' => $project->isInProgress(),
                'can_deploy' => $project->canDeploy(),
                'progress_percentage' => $project->getProgressPercentage(),

                // WordPress Site (if provisioned)
                'wordpress_site_id' => $project->wordpress_site_id,
                'wordpress_site' => $project->wordpressSite ? [
                    'id' => $project->wordpressSite->id,
                    'url' => $project->wordpressSite->url,
                    'status' => $project->wordpressSite->status,
                ] : null,

                // User
                'user' => [
                    'id' => $project->user->id,
                    'name' => $project->user->name,
                    'email' => $project->user->email,
                ],
            ],
        ];
    }
}
