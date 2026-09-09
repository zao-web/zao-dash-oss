<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetProjectTool extends Tool
{
    protected string $name = 'get-project';

    protected string $title = 'Get Project Details';

    protected string $description = 'Get detailed information about a specific project including tasks and milestones.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        if ($request->get('id')) {
            $project = Project::with(['client', 'tasks.assignee', 'milestones'])->findOrFail($request->get('id'));
        } else {
            // Slugs are only unique per client, so we need client context
            $query = Project::with(['client', 'tasks.assignee', 'milestones'])->where('slug', $request->get('slug'));

            if ($request->get('client_id')) {
                $query->where('client_id', $request->get('client_id'));
            }

            $project = $query->firstOrFail();
        }

        $tasksByStatus = $project->tasks->groupBy('status')->map->count();

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->status,
            'type' => $project->type,
            'budget' => $project->budget,
            'github_repo' => $project->github_repo,
            'client' => [
                'id' => $project->client->id,
                'name' => $project->client->name,
                'slug' => $project->client->slug,
            ],
            'task_summary' => [
                'total' => $project->tasks->count(),
                'pending' => $tasksByStatus->get('pending', 0),
                'in_progress' => $tasksByStatus->get('in_progress', 0),
                'review' => $tasksByStatus->get('review', 0),
                'completed' => $tasksByStatus->get('completed', 0),
            ],
            'tasks' => $project->tasks->take(20)->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'assignee' => $t->assignee?->name,
                'due_date' => $t->due_date?->format('Y-m-d'),
            ]),
            'milestones' => $project->milestones->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'status' => $m->status,
                'due_date' => $m->due_date?->format('Y-m-d'),
            ]),
            'created_at' => $project->created_at->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Project ID'),
            'slug' => $schema->string()->description('Project slug (alternative to ID). Note: slugs are only unique per client, so provide client_id when using slug.'),
            'client_id' => $schema->integer()->description('Client ID (recommended when using slug lookup, since slugs are only unique per client)'),
        ];
    }
}
