<?php

namespace App\Agents\Tools\SiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\SiteBuilderProject;
use Illuminate\Support\Facades\Auth;

class SiteBuilderProjectCreateTool extends BaseTool
{
    public function id(): string
    {
        return 'site_builder_project_create';
    }

    public function name(): string
    {
        return 'Site Builder Project Create';
    }

    public function description(): string
    {
        return 'Create a new site builder project record to track the building process.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The target domain name for the website',
                ],
                'brief' => [
                    'type' => 'string',
                    'description' => 'Client brief describing the website requirements',
                ],
                'company_type' => [
                    'type' => 'string',
                    'enum' => ['active', 'defunct', 'startup', 'enterprise'],
                    'description' => 'Type of company for research strategy',
                ],
                'target_hosting' => [
                    'type' => 'string',
                    'enum' => ['wordpress_com', 'self_hosted', 'existing_site'],
                    'description' => 'Where the site will be hosted',
                ],
                'environment' => [
                    'type' => 'string',
                    'enum' => ['staging', 'production'],
                    'description' => 'Target environment for deployment',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Optional custom project name',
                ],
                'budget_limit' => [
                    'type' => 'number',
                    'description' => 'Maximum budget for the project in USD',
                ],
            ],
            'required' => ['domain', 'brief', 'company_type', 'target_hosting', 'environment'],
        ];
    }

    public function execute(array $params): array
    {
        // Create the project record
        $project = SiteBuilderProject::create([
            'domain' => $params['domain'],
            'project_name' => $params['project_name'] ?? $this->generateProjectName($params['domain']),
            'brief' => $params['brief'],
            'company_type' => $params['company_type'],
            'status' => SiteBuilderProject::STATUS_CREATED,
            'environment' => $params['environment'],
            'target_hosting' => $params['target_hosting'],
            'user_id' => Auth::id(),
            'budget_allocated' => $params['budget_limit'] ?? null,
            'started_at' => now(),
            'estimated_completion' => $this->calculateEstimatedCompletion($params),
        ]);

        return [
            'project_id' => $project->id,
            'project_name' => $project->project_name,
            'status' => 'created',
            'estimated_completion' => $project->estimated_completion->toISOString(),
            'budget_allocated' => $project->budget_allocated,
        ];
    }

    private function generateProjectName(string $domain): string
    {
        // Convert domain to readable project name
        $name = explode('.', $domain)[0];
        $name = str_replace(['-', '_'], ' ', $name);

        return ucwords($name).' Website';
    }

    private function calculateEstimatedCompletion(array $params): \Carbon\Carbon
    {
        $baseMinutes = 40;

        // Adjust based on company type
        $multipliers = [
            'defunct' => 1.5,  // More research needed
            'startup' => 0.8,  // Less content to work with
            'enterprise' => 1.8, // More complex requirements
            'active' => 1.0,   // Standard case
        ];

        $multiplier = $multipliers[$params['company_type']] ?? 1.0;
        $minutes = (int) ($baseMinutes * $multiplier);

        return now()->addMinutes($minutes);
    }
}
