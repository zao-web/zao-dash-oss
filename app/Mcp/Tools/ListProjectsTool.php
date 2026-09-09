<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListProjectsTool extends Tool
{
    protected string $name = 'list-projects';

    protected string $title = 'List Projects';

    protected string $description = 'List all projects with optional filtering by status or client.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $clientId = $request->get('client_id');
        $limit = $request->get('limit', 50);

        $query = Project::with('client')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed')]);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        } else {
            $query->whereNotIn('status', ['archived']);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $projects = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'type' => $project->type,
                'budget' => $project->budget,
                'client' => $project->client?->name,
                'client_id' => $project->client_id,
                'client_slug' => $project->client?->slug,
                'tasks_count' => $project->tasks_count,
                'completed_tasks_count' => $project->completed_tasks_count,
                'completion_pct' => $project->tasks_count > 0
                    ? round(($project->completed_tasks_count / $project->tasks_count) * 100)
                    : 0,
            ]);

        return Response::structured([
            'projects' => $projects,
            'total' => $projects->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['active', 'completed', 'on_hold', 'archived', 'all'])
                ->description('Filter projects by status'),
            'client_id' => $schema->integer()->description('Filter by client ID'),
            'limit' => $schema->integer()->description('Maximum projects to return (default: 50)'),
        ];
    }
}
