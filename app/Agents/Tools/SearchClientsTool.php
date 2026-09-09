<?php

namespace App\Agents\Tools;

use App\Models\Client;

/**
 * Search for clients.
 */
class SearchClientsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Clients';
    }

    public function description(): string
    {
        return 'Search for clients by name or other criteria. Returns matching clients.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to find in client name or description',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive', 'prospect'],
                    'description' => 'Filter by status',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 10)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive,prospect',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function execute(array $params): array
    {
        $query = Client::query();

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $limit = $params['limit'] ?? 10;
        $clients = $query->orderBy('name')->limit($limit)->get();

        return [
            'count' => $clients->count(),
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'status' => $c->status,
                'email' => $c->email,
                'projects_count' => $c->projects()->count(),
            ])->toArray(),
        ];
    }
}
