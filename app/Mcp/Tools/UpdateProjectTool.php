<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateProjectTool extends Tool
{
    protected string $name = 'update-project';

    protected string $title = 'Update Project';

    protected string $description = 'Update an existing project\'s information or status.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($request->get('id'));

        $updateData = collect($request->all())
            ->only(['name', 'description', 'status', 'type', 'budget', 'github_repo'])
            ->filter(fn ($value) => ! is_null($value))
            ->toArray();

        if (empty($updateData)) {
            return Response::text('No fields to update provided.');
        }

        $project->update($updateData);

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'updated_fields' => array_keys($updateData),
            'message' => "Project '{$project->name}' updated successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Project ID to update'),
            'name' => $schema->string()->description('New project name'),
            'description' => $schema->string()->description('New description'),
            'status' => $schema->string()->enum(['active', 'on_hold', 'completed', 'archived'])->description('New status'),
            'type' => $schema->string()->enum(['retainer', 'fixed', 'hourly', 'milestone'])->description('Project type'),
            'budget' => $schema->number()->description('New budget'),
            'github_repo' => $schema->string()->description('GitHub repository'),
        ];
    }
}
