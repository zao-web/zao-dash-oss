<?php

namespace App\Mcp\Tools;

use App\Jobs\RunAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerAgentTool extends Tool
{
    protected string $name = 'trigger-agent';

    protected string $title = 'Trigger Agent';

    protected string $description = 'Trigger an agent to run with optional context/task description. The agent will run asynchronously.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $agent = $request->get('id')
            ? Agent::findOrFail($request->get('id'))
            : Agent::where('slug', $request->get('slug'))->firstOrFail();

        if ($agent->status !== 'active') {
            return Response::structured([
                'success' => false,
                'message' => "Agent '{$agent->name}' is not active (status: {$agent->status}).",
            ]);
        }

        if ($agent->circuit_broken_at) {
            return Response::structured([
                'success' => false,
                'message' => "Agent '{$agent->name}' is currently circuit-broken. Check the agent for errors.",
            ]);
        }

        $task = $request->get('task', 'Triggered via MCP');
        $context = $request->get('context', []);
        $projectId = $request->get('project_id');
        $taskId = $request->get('task_id');
        $timeoutSeconds = $request->get('timeout_seconds');

        // Auto-populate project_id from task if not provided
        if ($taskId && ! $projectId) {
            $taskModel = \App\Models\Task::find($taskId);
            if ($taskModel?->project_id) {
                $projectId = $taskModel->project_id;
            }
        }

        // Ensure context is an array and add timeout if specified
        $contextArray = is_array($context) ? $context : ['input' => $context];
        if ($timeoutSeconds) {
            $contextArray['timeout_seconds'] = (int) $timeoutSeconds;
        }

        // Add project context for GitHub token resolution
        if ($projectId) {
            $project = \App\Models\Project::find($projectId);
            if ($project) {
                $contextArray['project_id'] = $projectId;
                $contextArray['project'] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'github_repo' => $project->github_repo,
                ];
            }
        }

        $run = $agent->runs()->create([
            'session_id' => Str::uuid(),
            'status' => 'running',
            'task' => $task,
            'context' => $contextArray,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'invocation_source' => AgentRun::SOURCE_API,
            'invoked_by' => 'mcp',
            'started_at' => now(),
        ]);

        RunAgentJob::dispatch($run);

        return Response::structured([
            'success' => true,
            'run_id' => $run->id,
            'agent_name' => $agent->name,
            'agent_slug' => $agent->slug,
            'status' => 'running',
            'message' => "Agent '{$agent->name}' triggered. Run ID: {$run->id}. Check run status for results.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Agent ID'),
            'slug' => $schema->string()->description('Agent slug (alternative to ID)'),
            'task' => $schema->string()->description('Task description or instruction for the agent'),
            'context' => $schema->object()->description('Additional context data for the agent run'),
            'project_id' => $schema->integer()->description('Optional project ID to associate the run with'),
            'task_id' => $schema->integer()->description('Optional task ID to associate the run with (will create task activity)'),
            'timeout_seconds' => $schema->integer()->description('Execution timeout in seconds (default: 600, max: 1800)'),
        ];
    }
}
