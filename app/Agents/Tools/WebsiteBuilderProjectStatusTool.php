<?php

namespace App\Agents\Tools;

use App\Models\WebsiteProject;

/**
 * Get the current status and state of a Website Builder project.
 */
class WebsiteBuilderProjectStatusTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Get Project Status';
    }

    public function description(): string
    {
        return 'Retrieve the current status, progress, and configuration of a Website Builder project. Use this to understand the current state before making decisions.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The Website Project ID to get status for',
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
        $project = WebsiteProject::with(['user', 'wordpressSite'])->findOrFail($params['project_id']);

        return [
            'project_id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'project_type' => $project->project_type,
            'source_type' => $project->source_type,
            'source_data' => $project->source_data,
            'status' => $project->status,
            'overall_progress' => $project->overall_progress ?? $project->getProgressPercentage(),
            'phase_progress' => $project->phase_progress ?? [],
            'is_in_progress' => $project->isInProgress(),
            'is_complete' => $project->isComplete(),
            'has_failed' => $project->hasFailed(),
            'can_deploy' => $project->canDeploy(),
            'domain' => $project->domain,
            'hosting_type' => $project->hosting_type,
            'environment' => $project->environment,
            'staging_url' => $project->staging_url,
            'production_url' => $project->production_url,
            'live_url' => $project->getLiveUrl(),
            'design_config' => $project->design_config,
            'brand_assets' => $project->brand_assets,
            'pages' => $project->pages,
            'patterns_selected' => $project->patterns_selected,
            'site_analysis' => $project->site_analysis,
            'repo_analysis' => $project->repo_analysis,
            'extracted_content' => $project->extracted_content,
            'budget_allocated' => $project->budget_allocated,
            'cost_incurred' => $project->cost_incurred,
            'remaining_budget' => $project->getRemainingBudget(),
            'is_over_budget' => $project->isOverBudget(),
            'started_at' => $project->started_at?->toIso8601String(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'estimated_completion' => $project->estimated_completion?->toIso8601String(),
            'last_error' => $project->last_error,
            'retry_count' => $project->retry_count,
            'wordpress_site' => $project->wordpressSite ? [
                'id' => $project->wordpressSite->id,
                'name' => $project->wordpressSite->name,
                'url' => $project->wordpressSite->url,
            ] : null,
        ];
    }
}
