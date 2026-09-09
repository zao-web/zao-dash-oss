<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Create a new project.
 */
class CreateProjectTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Project';
    }

    public function description(): string
    {
        return 'Create a new project with name, description, and optional client. Returns the created project.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Project name (required)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Project description',
                ],
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the client for this project',
                ],
                'client_name' => [
                    'type' => 'string',
                    'description' => 'Name of client (alternative to client_id)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['planning', 'active', 'on_hold', 'completed'],
                    'description' => 'Project status (default: planning)',
                ],
                'budget' => [
                    'type' => 'number',
                    'description' => 'Project budget in dollars',
                ],
            ],
            'required' => ['name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_name' => 'nullable|string',
            'status' => 'nullable|in:planning,active,on_hold,completed',
            'budget' => 'nullable|numeric|min:0',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        // Resolve client by name if needed
        $clientId = $params['client_id'] ?? null;
        if (! $clientId && ! empty($params['client_name'])) {
            $client = Client::where('name', 'like', "%{$params['client_name']}%")->first();
            $clientId = $client?->id;
        }

        // Check for existing project with same slug under the same client
        $slug = Str::slug($params['name']);
        $existingQuery = Project::where('slug', $slug);
        if ($clientId) {
            $existingQuery->where('client_id', $clientId);
        }
        $existing = $existingQuery->first();

        if ($existing) {
            return [
                'created' => false,
                'existing' => true,
                'message' => "A project named \"{$existing->name}\" already exists".($existing->client ? " for {$existing->client->name}" : '').'.',
                'project' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'slug' => $existing->slug,
                    'status' => $existing->status,
                    'client' => $existing->client?->name,
                ],
            ];
        }

        $project = Project::create([
            'name' => $params['name'],
            'slug' => $slug,
            'description' => $params['description'] ?? null,
            'client_id' => $clientId,
            'status' => $params['status'] ?? 'planning',
            'budget' => $params['budget'] ?? null,
        ]);

        return [
            'created' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'client' => $project->client?->name,
            ],
        ];
    }
}
