<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateProjectTool extends Tool
{
    protected string $name = 'create-project';

    protected string $title = 'Create Project';

    protected string $description = 'Create a new project for a client.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
            'description' => 'nullable|string',
            'type' => 'nullable|in:retainer,fixed,hourly,milestone',
            'budget' => 'nullable|numeric|min:0',
            'github_repo' => 'nullable|string',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['status'] = 'active';

        $baseSlug = $validated['slug'];
        $counter = 1;
        while (Project::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug.'-'.$counter++;
        }

        $project = Project::create($validated);
        $client = Client::find($validated['client_id']);

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'client' => $client->name,
            'status' => $project->status,
            'message' => "Project '{$project->name}' created for client '{$client->name}'.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Project name'),
            'client_id' => $schema->integer()->required()->description('Client ID this project belongs to'),
            'description' => $schema->string()->description('Project description'),
            'type' => $schema->string()->enum(['retainer', 'fixed', 'hourly', 'milestone'])->description('Project billing type'),
            'budget' => $schema->number()->description('Project budget'),
            'github_repo' => $schema->string()->description('GitHub repository name (owner/repo)'),
        ];
    }
}
