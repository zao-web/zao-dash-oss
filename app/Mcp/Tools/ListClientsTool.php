<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListClientsTool extends Tool
{
    protected string $name = 'list-clients';

    protected string $title = 'List Clients';

    protected string $description = 'List all clients with optional filtering by status. Returns client names, health scores, and project counts.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $search = $request->get('search');
        $limit = $request->get('limit', 50);

        $query = Client::with('contacts')
            ->withCount(['projects', 'projects as active_projects_count' => fn ($q) => $q->where('status', 'active')]);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $clients = $query->orderBy('health_score', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'status' => $client->status,
                'health_score' => $client->health_score,
                'website' => $client->website,
                'slack_channel' => $client->slack_channel,
                'projects_count' => $client->projects_count,
                'active_projects_count' => $client->active_projects_count,
                'primary_contact' => $client->contacts->firstWhere('is_primary', true)?->name,
            ]);

        return Response::structured([
            'clients' => $clients,
            'total' => $clients->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['active', 'inactive', 'prospect', 'churned', 'archived', 'all'])
                ->description('Filter clients by status. Defaults to all.'),
            'search' => $schema->string()
                ->description('Search clients by name or description'),
            'limit' => $schema->integer()
                ->description('Maximum number of clients to return (default: 50)'),
        ];
    }
}
