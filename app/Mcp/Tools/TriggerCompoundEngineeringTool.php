<?php

namespace App\Mcp\Tools;

use App\Agents\Definitions\CompoundEngineeringAgent;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerCompoundEngineeringTool extends Tool
{
    protected string $name = 'trigger-compound-engineering';

    protected string $title = 'Trigger Compound Engineering';

    protected string $description = 'Trigger a Compound Engineering skill to run interactively. Supports /workflows:plan, /workflows:work, /workflows:review, /workflows:compound, and /lfg.';

    public function __construct(
        protected CompoundEngineeringAgent $agentDefinition,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'skill' => 'required|string',
        ]);

        $skill = $request->get('skill');
        $args = $request->get('args');
        $projectId = $request->get('project_id');

        // Validate the skill
        $availableSkills = ['workflows:plan', 'workflows:work', 'workflows:review', 'workflows:compound', 'lfg'];
        if (! in_array($skill, $availableSkills, true)) {
            return Response::structured([
                'success' => false,
                'message' => "Unknown skill: {$skill}. Available skills: ".implode(', ', $availableSkills),
            ]);
        }

        // Get or create the Compound Engineering agent
        $agent = Agent::where('slug', 'compound-engineering')->where('status', 'active')->first();

        if (! $agent) {
            return Response::structured([
                'success' => false,
                'message' => 'Compound Engineering agent is not configured or not active. Create an agent with slug "compound-engineering" first.',
            ]);
        }

        // Build context
        $context = [
            'skill' => $skill,
            'args' => $args,
            'invocation_source' => 'mcp',
        ];

        if ($projectId) {
            $project = \App\Models\Project::find($projectId);
            if ($project) {
                $context['project_id'] = $projectId;
                $context['project'] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'github_repo' => $project->github_repo,
                    'description' => $project->description,
                ];
            }
        }

        // Create the run
        $run = $agent->runs()->create([
            'session_id' => Str::uuid(),
            'status' => 'running',
            'task' => "/{$skill}".($args ? " {$args}" : ''),
            'context' => $context,
            'project_id' => $projectId,
            'invocation_source' => AgentRun::SOURCE_API,
            'invoked_by' => 'mcp',
            'started_at' => now(),
        ]);

        // Dispatch interactive job
        RunInteractiveAgentJob::dispatch($run);

        return Response::structured([
            'success' => true,
            'run_id' => $run->id,
            'skill' => $skill,
            'status' => 'running',
            'message' => "Started /{$skill}".($args ? " with: {$args}" : '')." (Run #{$run->id}). This is an interactive session - questions will be posted for human response.",
            'note' => 'Use get-agent-run to check progress, or list-interaction-requests to see pending questions.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()
                ->enum(['workflows:plan', 'workflows:work', 'workflows:review', 'workflows:compound', 'lfg'])
                ->description('The Compound Engineering skill to execute'),
            'args' => $schema->string()->description('Arguments/instructions for the skill (e.g., "Add user authentication feature")'),
            'project_id' => $schema->integer()->description('Optional project ID to associate the run with (provides GitHub repo context)'),
        ];
    }
}
