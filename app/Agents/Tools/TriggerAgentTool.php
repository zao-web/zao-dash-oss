<?php

namespace App\Agents\Tools;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;

/**
 * Trigger an agent execution.
 */
class TriggerAgentTool extends BaseTool
{
    public function __construct(
        protected AgentExecutor $executor
    ) {}

    public function category(): string
    {
        return 'agent';
    }

    public function name(): string
    {
        return 'Trigger Agent';
    }

    public function description(): string
    {
        return 'Trigger an AI agent to run. The agent may require approval before execution.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agent_slug' => [
                    'type' => 'string',
                    'description' => 'The slug/ID of the agent to trigger',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Optional prompt/instructions for the agent',
                ],
                'context' => [
                    'type' => 'object',
                    'description' => 'Additional context data to pass to the agent',
                ],
            ],
            'required' => ['agent_slug'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'agent_slug' => 'required|string|max:100',
            'prompt' => 'nullable|string|max:5000',
            'context' => 'nullable|array',
        ];
    }

    public function requiresApproval(): bool
    {
        return true; // Agent execution can have side effects
    }

    public function riskLevel(): string
    {
        return 'high';
    }

    public function execute(array $params): array
    {
        $agent = Agent::where('slug', $params['agent_slug'])->first();

        if (! $agent) {
            // List available agents to help the AI
            $available = Agent::pluck('slug')->toArray();

            return [
                'success' => false,
                'error' => "Agent '{$params['agent_slug']}' not found",
                'available_agents' => $available,
                'hint' => 'Use one of the available agent slugs, or create the agent first',
            ];
        }

        $run = $this->executor->execute(
            agent: $agent,
            config: [
                'prompt' => $params['prompt'] ?? '',
                'context' => $params['context'] ?? [],
            ],
            invocationSource: AgentRun::SOURCE_API,
            invokedBy: 'tool:trigger-agent',
        );

        return [
            'triggered' => true,
            'agent' => $agent->name,
            'run_id' => $run->id,
            'status' => $run->status,
            'requires_approval' => $run->status === 'pending_approval',
        ];
    }
}
