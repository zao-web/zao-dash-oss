<?php

namespace App\Mcp\Tools;

use App\Models\WebsiteProject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListWebsiteProjectsTool extends Tool
{
    protected string $name = 'list-website-projects';

    protected string $title = 'List Website Projects';

    protected string $description = 'List all website builder projects with optional filtering by status or project type.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $projectType = $request->get('project_type');
        $limit = $request->get('limit', 50);

        $query = WebsiteProject::with('user');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($projectType && $projectType !== 'all') {
            $query->where('project_type', $projectType);
        }

        $projects = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'project_type' => $project->project_type,
                'domain' => $project->domain,
                'overall_progress' => $project->getProgressPercentage(),
                'staging_url' => $project->staging_url,
                'production_url' => $project->production_url,
                'user' => $project->user?->name,
                'started_at' => $project->started_at?->toIso8601String(),
                'completed_at' => $project->completed_at?->toIso8601String(),
                'last_error' => $project->last_error,
            ]);

        return Response::structured([
            'website_projects' => $projects,
            'total' => $projects->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['created', 'analyzing', 'designing', 'building', 'reviewing', 'deploying', 'complete', 'failed', 'all'])
                ->description('Filter website projects by status'),
            'project_type' => $schema->string()
                ->enum(['autonomous', 'guided', 'migration', 'redesign', 'all'])
                ->description('Filter by project type'),
            'limit' => $schema->integer()->description('Maximum projects to return (default: 50)'),
        ];
    }
}
