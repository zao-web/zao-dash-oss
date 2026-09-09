<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchTool extends Tool
{
    protected string $name = 'search';

    protected string $title = 'Search Everything';

    protected string $description = 'Search across clients, projects, tasks, leads, and agents. Returns matching results from all categories.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $request->get('query');
        $types = $request->get('types', ['clients', 'projects', 'tasks', 'leads', 'agents']);
        $limit = $request->get('limit', 10);

        $results = [];

        if (in_array('clients', $types)) {
            $results['clients'] = Client::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit($limit)
                ->get(['id', 'name', 'slug', 'status', 'health_score'])
                ->toArray();
        }

        if (in_array('projects', $types)) {
            $results['projects'] = Project::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit($limit)
                ->get(['id', 'name', 'slug', 'status', 'client_id'])
                ->toArray();
        }

        if (in_array('tasks', $types)) {
            $results['tasks'] = Task::where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit($limit)
                ->get(['id', 'title', 'status', 'priority', 'project_id'])
                ->toArray();
        }

        if (in_array('leads', $types)) {
            $results['leads'] = Lead::where('company_name', 'like', "%{$query}%")
                ->orWhere('contact_name', 'like', "%{$query}%")
                ->orWhere('contact_email', 'like', "%{$query}%")
                ->limit($limit)
                ->get(['id', 'company_name', 'contact_name', 'stage', 'deal_value'])
                ->toArray();
        }

        if (in_array('agents', $types)) {
            $results['agents'] = Agent::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit($limit)
                ->get(['id', 'name', 'slug', 'status', 'description'])
                ->toArray();
        }

        $totalResults = collect($results)->flatten(1)->count();

        return Response::structured([
            'query' => $query,
            'total_results' => $totalResults,
            'results' => $results,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Search query (min 2 characters)'),
            'types' => $schema->array()
                ->items($schema->string()->enum(['clients', 'projects', 'tasks', 'leads', 'agents']))
                ->description('Types to search (default: all)'),
            'limit' => $schema->integer()->description('Max results per type (default: 10)'),
        ];
    }
}
