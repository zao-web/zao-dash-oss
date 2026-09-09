<?php

namespace App\Jobs;

use App\Events\AgentRunCompleted;
use App\Events\NotificationCreated;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes agent run results and handles chaining.
 *
 * Responsibilities:
 * 1. Update agent run status
 * 2. Process output (create tasks, approvals, etc.)
 * 3. Chain to next agent if configured
 * 4. Handle circuit breaker logic
 * 5. Send notifications
 */
class ProcessAgentResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public AgentRun $agentRun,
        public array $result
    ) {}

    public function handle(): void
    {
        Log::info('Processing agent result', [
            'run_id' => $this->agentRun->id,
            'agent_id' => $this->agentRun->agent_id,
            'status' => $this->result['status'] ?? 'unknown',
        ]);

        try {
            // Update run with results
            $this->updateAgentRun();

            // Process based on status
            if ($this->result['status'] === 'completed') {
                $this->handleSuccess();
            } elseif ($this->result['status'] === 'requires_approval') {
                $this->handleApprovalRequired();
            } else {
                $this->handleFailure();
            }

            // Dispatch completion event
            event(new AgentRunCompleted($this->agentRun));

        } catch (\Exception $e) {
            Log::error('Error processing agent result', [
                'run_id' => $this->agentRun->id,
                'error' => $e->getMessage(),
            ]);

            $this->agentRun->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Update the agent run with results.
     */
    protected function updateAgentRun(): void
    {
        $this->agentRun->update([
            'status' => $this->result['status'] ?? 'completed',
            'output' => $this->result['output'] ?? [],
            'tokens_used' => $this->result['tokens_used'] ?? 0,
            'cost_usd' => $this->result['cost_usd'] ?? 0,
            'completed_at' => now(),
            'error_message' => $this->result['error'] ?? null,
        ]);
    }

    /**
     * Handle successful completion.
     */
    protected function handleSuccess(): void
    {
        // Process any tasks created by the agent
        $this->processCreatedTasks();

        // Reset circuit breaker on success
        $this->resetCircuitBreaker();

        // Check for chaining
        $this->triggerChainedAgents();

        // Send success notification
        $this->sendNotification('completed', 'Agent completed successfully');
    }

    /**
     * Handle approval required status.
     */
    protected function handleApprovalRequired(): void
    {
        $approvals = $this->result['approvals'] ?? [];

        foreach ($approvals as $approval) {
            ApprovalRequest::create([
                'agent_run_id' => $this->agentRun->id,
                'agent_id' => $this->agentRun->agent_id,
                'category' => $approval['category'] ?? 'general',
                'title' => $approval['title'] ?? 'Approval Required',
                'description' => $approval['description'] ?? null,
                'payload' => $approval['payload'] ?? [],
                'risk_level' => $approval['risk_level'] ?? 'medium',
                'status' => 'pending',
                'expires_at' => now()->addHours(24),
            ]);
        }

        $this->sendNotification(
            'requires_approval',
            count($approvals).' approval(s) needed'
        );
    }

    /**
     * Handle failed run.
     */
    protected function handleFailure(): void
    {
        // Increment circuit breaker
        $this->incrementCircuitBreaker();

        // Send failure notification
        $this->sendNotification(
            'failed',
            $this->result['error'] ?? 'Agent run failed'
        );
    }

    /**
     * Process tasks created by the agent.
     */
    protected function processCreatedTasks(): void
    {
        $tasks = $this->result['tasks'] ?? [];

        foreach ($tasks as $taskData) {
            AgentTask::create([
                'agent_id' => $this->agentRun->agent_id,
                'agent_run_id' => $this->agentRun->id,
                'assigned_by' => 'agent',
                'task_description' => $taskData['description'] ?? '',
                'context' => $taskData['context'] ?? [],
                'priority' => $taskData['priority'] ?? 'medium',
                'status' => 'pending',
                'scheduled_for' => $taskData['scheduled_for'] ?? null,
            ]);
        }
    }

    /**
     * Trigger chained agents.
     */
    protected function triggerChainedAgents(): void
    {
        $chains = config('agents.chains', []);
        $currentAgentId = $this->agentRun->agent_id;

        if (! isset($chains[$currentAgentId])) {
            return;
        }

        $chainedAgents = $chains[$currentAgentId];

        foreach ($chainedAgents as $chainedAgentId) {
            $agent = Agent::where('slug', $chainedAgentId)->first();

            if (! $agent || ! $agent->is_active) {
                Log::info('Skipping chained agent (inactive or not found)', [
                    'agent_id' => $chainedAgentId,
                ]);

                continue;
            }

            // Check if agent circuit is broken
            if ($agent->circuit_broken_at) {
                Log::info('Skipping chained agent (circuit broken)', [
                    'agent_id' => $chainedAgentId,
                ]);

                continue;
            }

            Log::info('Dispatching chained agent', [
                'from_agent' => $currentAgentId,
                'to_agent' => $chainedAgentId,
                'from_run' => $this->agentRun->id,
            ]);

            // Dispatch chained agent with context from this run
            ExecuteAgentJob::dispatch($agent, [
                'chained_from' => $currentAgentId,
                'chained_from_run_id' => $this->agentRun->id,
                'parent_output' => $this->result['output'] ?? [],
            ]);
        }
    }

    /**
     * Reset circuit breaker on success.
     */
    protected function resetCircuitBreaker(): void
    {
        $agent = Agent::where('slug', $this->agentRun->agent_id)->first();

        if ($agent && $agent->circuit_broken_at) {
            $agent->update([
                'circuit_broken_at' => null,
                'consecutive_failures' => 0,
            ]);

            Log::info('Circuit breaker reset', [
                'agent_id' => $this->agentRun->agent_id,
            ]);
        }
    }

    /**
     * Increment circuit breaker on failure.
     */
    protected function incrementCircuitBreaker(): void
    {
        $agent = Agent::where('slug', $this->agentRun->agent_id)->first();

        if (! $agent) {
            return;
        }

        $failures = ($agent->consecutive_failures ?? 0) + 1;
        $threshold = config('agents.circuit_breaker.failure_threshold', 3);

        $updateData = ['consecutive_failures' => $failures];

        if ($failures >= $threshold) {
            $updateData['circuit_broken_at'] = now();

            Log::warning('Circuit breaker triggered', [
                'agent_id' => $this->agentRun->agent_id,
                'consecutive_failures' => $failures,
            ]);
        }

        $agent->update($updateData);
    }

    /**
     * Send notification about run status.
     */
    protected function sendNotification(string $type, string $message): void
    {
        event(new NotificationCreated([
            'type' => "agent.{$type}",
            'title' => "Agent Run {$type}",
            'message' => $message,
            'data' => [
                'agent_id' => $this->agentRun->agent_id,
                'run_id' => $this->agentRun->id,
            ],
        ]));
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAgentResultJob failed', [
            'run_id' => $this->agentRun->id,
            'error' => $exception->getMessage(),
        ]);

        $this->agentRun->update([
            'status' => 'failed',
            'error_message' => 'Result processing failed: '.$exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
