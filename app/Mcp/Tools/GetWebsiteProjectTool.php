<?php

namespace App\Mcp\Tools;

use App\Models\WebsiteProject;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetWebsiteProjectTool extends Tool
{
    protected string $name = 'get-website-project';

    protected string $title = 'Get Website Project Details';

    protected string $description = 'Get detailed information about a specific website builder project including progress, URLs, and configuration.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $project = $request->get('id')
            ? WebsiteProject::with(['user', 'agentRuns'])->findOrFail($request->get('id'))
            : WebsiteProject::with(['user', 'agentRuns'])->where('slug', $request->get('slug'))->firstOrFail();

        $assets = app(WebsiteProjectAssetService::class)->getAssetsForAgent($project);

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'project_type' => $project->project_type,
            'source_type' => $project->source_type,
            'source_data' => $project->source_data,
            'domain' => $project->domain,
            'hosting_type' => $project->hosting_type,
            'environment' => $project->environment,
            'overall_progress' => $project->getProgressPercentage(),
            'phase_progress' => $project->phase_progress,
            'staging_url' => $project->staging_url,
            'production_url' => $project->production_url,
            'download_url' => $project->download_url,
            'design_config' => $project->design_config,
            'pages' => $project->pages,
            'site_analysis' => $project->site_analysis,
            'last_error' => $project->last_error,
            'retry_count' => $project->retry_count,
            'budget_allocated' => $project->budget_allocated,
            'cost_incurred' => $project->cost_incurred,
            'remaining_budget' => $project->getRemainingBudget(),
            'is_over_budget' => $project->isOverBudget(),
            'user' => [
                'id' => $project->user?->id,
                'name' => $project->user?->name,
            ],
            'recent_agent_runs' => $project->agentRuns->take(5)->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'task' => $run->task,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ]),
            'started_at' => $project->started_at?->toIso8601String(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'estimated_completion' => $project->estimated_completion?->toIso8601String(),
            'created_at' => $project->created_at->toIso8601String(),
            'assets' => $assets,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Website project ID'),
            'slug' => $schema->string()->description('Website project slug (alternative to ID)'),
        ];
    }
}
