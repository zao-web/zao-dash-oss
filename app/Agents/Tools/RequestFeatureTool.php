<?php

namespace App\Agents\Tools;

use App\Jobs\ImplementFeatureJob;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;

/**
 * Request a Feature for Zao Dashboard itself.
 *
 * Enables self-development: request features, bug fixes, or enhancements
 * that get created as tasks and optionally trigger the Dev Agent.
 */
class RequestFeatureTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Request Feature';
    }

    public function description(): string
    {
        return 'Request a new feature, bug fix, or enhancement for Zao Dashboard. Creates a task in the self-development project and optionally triggers the Dev Agent to implement it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short, clear title for the feature/fix (required)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Detailed description of what should be built or fixed. Be specific about expected behavior, affected areas, and acceptance criteria.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['feature', 'bug', 'enhancement', 'refactor'],
                    'description' => 'Type of request (default: feature)',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'urgent'],
                    'description' => 'Priority level (default: from config)',
                ],
                'trigger_dev_agent' => [
                    'type' => 'boolean',
                    'description' => 'Whether to automatically trigger the Dev Agent to implement this. Defaults to config setting.',
                ],
                'file_scope' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: specific files or directories the feature affects (helps Dev Agent)',
                ],
            ],
            'required' => ['title', 'description'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:20',
            'type' => 'nullable|in:feature,bug,enhancement,refactor',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'trigger_dev_agent' => 'nullable|boolean',
            'file_scope' => 'nullable|array',
            'file_scope.*' => 'string',
        ];
    }

    public function requiresApproval(): bool
    {
        return true; // Creating tasks and potentially running agents
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        $config = config('services.self_development');

        if (! $config['enabled']) {
            return [
                'success' => false,
                'error' => 'Self-development feature is disabled in configuration.',
            ];
        }

        // Find the self-development project
        $project = $this->getSelfProject($config);

        if (! $project) {
            return [
                'success' => false,
                'error' => 'Self-development project not found. Configure SELF_PROJECT_SLUG or SELF_PROJECT_ID in .env',
            ];
        }

        // Determine request type and priority
        $type = $params['type'] ?? 'feature';
        $priority = $params['priority'] ?? $config['default_priority'] ?? 'medium';

        // Build task title with type prefix
        $prefix = match ($type) {
            'bug' => '[Bug]',
            'enhancement' => '[Enhancement]',
            'refactor' => '[Refactor]',
            default => '[Feature]',
        };
        $title = "{$prefix} {$params['title']}";

        // Build description with metadata
        $description = $this->buildDescription($params, $type);

        // Create the task
        $task = Task::create([
            'title' => $title,
            'description' => $description,
            'project_id' => $project->id,
            'priority' => $priority,
            'status' => 'pending',
            'source' => 'self_development',
            'metadata' => [
                'type' => $type,
                'file_scope' => $params['file_scope'] ?? [],
                'requested_via' => 'command_palette',
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        $result = [
            'success' => true,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $type,
                'priority' => $task->priority,
                'status' => $task->status,
                'project' => $project->name,
            ],
            'message' => "Created {$type} request: {$params['title']}",
        ];

        // Determine if we should trigger Dev Agent
        $triggerAgent = $params['trigger_dev_agent'] ?? $config['auto_trigger_agent'] ?? false;

        if ($triggerAgent) {
            $agentResult = $this->triggerDevAgent($task, $params, $config);
            $result['agent'] = $agentResult;
        }

        return $result;
    }

    /**
     * Get the self-development project.
     */
    protected function getSelfProject(array $config): ?Project
    {
        // Try by slug first
        if (! empty($config['project_slug'])) {
            $project = Project::where('slug', $config['project_slug'])->first();
            if ($project) {
                return $project;
            }
        }

        // Fallback to ID
        if (! empty($config['project_id'])) {
            return Project::find($config['project_id']);
        }

        // Final fallback: find any project with "zao" or "dash" in name
        return Project::where('name', 'like', '%zao%')
            ->orWhere('name', 'like', '%dash%')
            ->orWhere('slug', 'like', '%zao%')
            ->first();
    }

    /**
     * Build rich description with context.
     */
    protected function buildDescription(array $params, string $type): string
    {
        $description = $params['description'];

        $sections = ["## Description\n{$description}"];

        if (! empty($params['file_scope'])) {
            $files = implode("\n- ", $params['file_scope']);
            $sections[] = "## Affected Files\n- {$files}";
        }

        // Add type-specific sections
        match ($type) {
            'bug' => $sections[] = "## Steps to Reproduce\n_To be documented_\n\n## Expected Behavior\n_To be documented_",
            'feature' => $sections[] = "## Acceptance Criteria\n_To be verified_",
            default => null,
        };

        $sections[] = '_Requested via Command Palette on '.now()->format('M j, Y g:i A').'_';

        return implode("\n\n", $sections);
    }

    /**
     * Trigger the Dev Agent to implement this feature.
     */
    protected function triggerDevAgent(Task $task, array $params, array $config): array
    {
        // Find the Dev Agent
        $devAgent = Agent::where('slug', 'dev-agent')->first();

        if (! $devAgent) {
            return [
                'triggered' => false,
                'reason' => 'Dev Agent not found in system',
            ];
        }

        // Dispatch the implementation job
        ImplementFeatureJob::dispatch(
            task: $task,
            agent: $devAgent,
            fileScope: $params['file_scope'] ?? [],
            githubRepo: $config['github_repo'] ?? null,
        );

        return [
            'triggered' => true,
            'agent' => $devAgent->name,
            'message' => 'Dev Agent queued to implement this feature',
        ];
    }
}
