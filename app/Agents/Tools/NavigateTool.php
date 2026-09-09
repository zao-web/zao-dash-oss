<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\Project;

/**
 * Provide navigation suggestions.
 *
 * This is a read-only tool that helps AI suggest navigation.
 */
class NavigateTool extends BaseTool
{
    public function category(): string
    {
        return 'navigation';
    }

    public function name(): string
    {
        return 'Navigate';
    }

    public function description(): string
    {
        return 'Get navigation URL for a specific page or entity. Use this when user wants to go somewhere.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'destination' => [
                    'type' => 'string',
                    'enum' => ['dashboard', 'projects', 'tasks', 'clients', 'pipeline', 'team', 'agents', 'approvals', 'vault', 'costs'],
                    'description' => 'Page to navigate to',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'enum' => ['project', 'client', 'task'],
                    'description' => 'Type of entity to view',
                ],
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'ID of entity',
                ],
                'entity_name' => [
                    'type' => 'string',
                    'description' => 'Name to search for (alternative to ID)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'destination' => 'nullable|in:dashboard,projects,tasks,clients,pipeline,team,agents,approvals,vault,costs',
            'entity_type' => 'nullable|in:project,client,task',
            'entity_id' => 'nullable|integer',
            'entity_name' => 'nullable|string',
        ];
    }

    public function execute(array $params): array
    {
        // Direct page navigation
        if (! empty($params['destination'])) {
            $routes = [
                'dashboard' => '/',
                'projects' => '/projects',
                'tasks' => '/tasks',
                'clients' => '/clients',
                'pipeline' => '/leads',
                'team' => '/team',
                'agents' => '/agents',
                'approvals' => '/approvals',
                'vault' => '/vault',
                'costs' => '/costs',
            ];

            return [
                'type' => 'navigation',
                'url' => $routes[$params['destination']] ?? '/dashboard',
                'label' => ucfirst($params['destination']),
            ];
        }

        // Entity navigation
        if (! empty($params['entity_type'])) {
            $entity = null;
            $url = null;

            switch ($params['entity_type']) {
                case 'project':
                    if (! empty($params['entity_id'])) {
                        $entity = Project::find($params['entity_id']);
                    } elseif (! empty($params['entity_name'])) {
                        $entity = Project::where('name', 'like', "%{$params['entity_name']}%")->first();
                    }
                    if ($entity) {
                        $url = "/projects/{$entity->slug}";
                    }
                    break;

                case 'client':
                    if (! empty($params['entity_id'])) {
                        $entity = Client::find($params['entity_id']);
                    } elseif (! empty($params['entity_name'])) {
                        $entity = Client::where('name', 'like', "%{$params['entity_name']}%")->first();
                    }
                    if ($entity) {
                        $url = "/clients/{$entity->slug}";
                    }
                    break;
            }

            if ($entity && $url) {
                return [
                    'type' => 'navigation',
                    'url' => $url,
                    'label' => $entity->name,
                    'entity' => [
                        'type' => $params['entity_type'],
                        'id' => $entity->id,
                        'name' => $entity->name,
                    ],
                ];
            }

            return [
                'type' => 'not_found',
                'message' => "Could not find {$params['entity_type']} matching your request.",
            ];
        }

        return [
            'type' => 'error',
            'message' => 'Please specify a destination or entity to navigate to.',
        ];
    }
}
