<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Services\Symphony\Orchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process pending agent tasks.
 *
 * This job picks up tasks assigned by the Business Strategist
 * and dispatches them to the appropriate agents for execution.
 */
class ProcessAgentTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected ?Orchestrator $orchestrator = null
    ) {}

    public function handle(): void
    {
        if (! $this->orchestrator) {
            $this->orchestrator = app(Orchestrator::class);
        }

        $this->orchestrator->runTick();

        // Legacy strategist tasks (not attached to Kanban tasks).
        $tasks = AgentTask::ready()
            ->whereNull('task_id')
            ->byPriority()
            ->with('agent')
            ->limit(10) // Process 10 at a time
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        Log::info("Processing {$tasks->count()} agent task(s)");

        foreach ($tasks as $task) {
            $this->processTask($task);
        }
    }

    protected function processTask(AgentTask $task): void
    {
        $agent = $task->agent;

        // Skip if agent not active
        if ($agent->status !== 'active') {
            Log::warning("Skipping task {$task->id}: Agent {$agent->slug} is not active");
            $task->fail("Agent is not active (status: {$agent->status})");

            return;
        }

        // Skip if agent has circuit breaker
        if ($agent->circuit_broken_at) {
            Log::warning("Skipping task {$task->id}: Agent {$agent->slug} circuit breaker is active");

            return; // Don't fail - will retry later
        }

        // Mark as running
        $task->start();

        // Build config for the agent run
        $config = [
            'prompt' => $task->task_description,
            'context' => $task->getExecutionContext(),
            'task_id' => $task->task_id,
            'agent_task_id' => $task->id,
        ];

        // Dispatch the agent execution
        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_CHAINED, // Triggered by another agent
            invokedBy: 'strategist',
            triggerMetadata: [
                'task_id' => $task->task_id,
                'agent_task_id' => $task->id,
                'priority' => $task->priority,
                'assigned_at' => $task->created_at->toIso8601String(),
            ],
        );

        Log::info("Dispatched agent task {$task->id} to {$agent->slug}");
    }
}
