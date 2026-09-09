<?php

namespace App\Jobs;

use App\Events\AgentRunStatusChanged;
use App\Events\InteractionRequestCreated;
use App\Models\AgentRun;
use App\Models\InteractionRequest;
use App\Services\Agents\InteractiveClaudeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Execute an agent interactively with checkpoint-resume support.
 *
 * This job handles both initial execution and resumption after user input.
 * When an AskUserQuestion tool is detected, the job creates a checkpoint,
 * broadcasts the interaction, and exits - freeing the worker.
 *
 * A new job is dispatched when the user responds.
 */
class RunInteractiveAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - checkpoints handle resume

    /**
     * Maximum seconds the job can run.
     */
    public int $timeout = 3600; // 60 minutes

    /**
     * @param  string|null  $resumeResponse  Response from user if resuming from checkpoint
     */
    public function __construct(
        public AgentRun $run,
        public array $config = [],
        public ?string $resumeResponse = null
    ) {
        $this->onQueue('interactive-agents');
    }

    /**
     * Execute the job.
     */
    public function handle(InteractiveClaudeRunner $runner): void
    {
        $this->run = $this->run->fresh();

        if (! $this->run) {
            return;
        }

        if ($this->run->status === AgentRun::STATUS_CANCELLED) {
            Log::info('RunInteractiveAgentJob skipped cancelled run', [
                'run_id' => $this->run->id,
            ]);

            return;
        }

        Log::info('RunInteractiveAgentJob starting', [
            'run_id' => $this->run->id,
            'agent_id' => $this->run->agent_id,
            'is_resume' => $this->resumeResponse !== null,
        ]);

        $agent = $this->run->agent;
        $previousStatus = $this->run->status;

        // Mark as running
        $this->run->update([
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => $this->run->started_at ?? now(),
        ]);

        AgentRunStatusChanged::dispatch($this->run, $previousStatus);

        try {
            // Execute or resume
            if ($this->resumeResponse !== null) {
                $result = $runner->resume($this->run, $this->resumeResponse);
            } else {
                $result = $runner->execute($agent, $this->run, $this->config);
            }

            if ($result->needsInteraction && $result->interaction) {
                $this->handleInteractionNeeded($result->interaction, $result->checkpoint);
            } elseif ($result->succeeded()) {
                $this->handleCompleted($result);
            } else {
                $this->handleFailed($result->errorMessage ?? 'Unknown error');
            }

        } catch (\Throwable $e) {
            Log::error('RunInteractiveAgentJob failed', [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailed($e->getMessage());
        }
    }

    /**
     * Handle when interaction is needed - checkpoint and broadcast.
     */
    protected function handleInteractionNeeded(InteractionRequest $interaction, ?array $checkpoint): void
    {
        $this->run->refresh();

        if ($this->run->status === AgentRun::STATUS_CANCELLED) {
            Log::info('Interactive agent interaction ignored because run was cancelled', [
                'run_id' => $this->run->id,
                'interaction_id' => $interaction->id,
            ]);

            return;
        }

        $previousStatus = $this->run->status;

        $this->run->update([
            'status' => AgentRun::STATUS_AWAITING_INPUT,
            'checkpoint' => $checkpoint,
        ]);

        AgentRunStatusChanged::dispatch($this->run, $previousStatus);

        // Determine the user to notify
        $userId = $this->determineUserToNotify();

        if ($userId) {
            InteractionRequestCreated::dispatch($interaction, $userId);
        }

        Log::info('Interactive agent awaiting input', [
            'run_id' => $this->run->id,
            'interaction_id' => $interaction->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Handle successful completion.
     */
    protected function handleCompleted($result): void
    {
        $this->run->refresh();

        if ($this->run->status === AgentRun::STATUS_CANCELLED) {
            Log::info('Interactive agent completion ignored because run was cancelled', [
                'run_id' => $this->run->id,
            ]);

            return;
        }

        $previousStatus = $this->run->status;

        $this->run->update([
            'status' => AgentRun::STATUS_COMPLETED,
            'output' => $result->output,
            'checkpoint' => null, // Clear checkpoint on completion
            'completed_at' => now(),
        ]);

        AgentRunStatusChanged::dispatch($this->run, $previousStatus);

        Log::info('Interactive agent completed', [
            'run_id' => $this->run->id,
            'duration_seconds' => $result->durationSeconds,
        ]);
    }

    /**
     * Handle failure.
     */
    protected function handleFailed(string $errorMessage): void
    {
        $this->run->refresh();

        if ($this->run->status === AgentRun::STATUS_CANCELLED) {
            Log::info('Interactive agent failure ignored because run was cancelled', [
                'run_id' => $this->run->id,
            ]);

            return;
        }

        $previousStatus = $this->run->status;

        $this->run->update([
            'status' => AgentRun::STATUS_FAILED,
            'error_message' => $errorMessage,
            'checkpoint' => null,
            'completed_at' => now(),
        ]);

        AgentRunStatusChanged::dispatch($this->run, $previousStatus);

        Log::warning('Interactive agent failed', [
            'run_id' => $this->run->id,
            'error' => $errorMessage,
        ]);
    }

    /**
     * Determine which user should be notified of interactions.
     */
    protected function determineUserToNotify(): ?int
    {
        // Try to get from context
        $context = $this->run->context ?? [];

        // Direct user_id in context
        if (isset($context['user_id'])) {
            return (int) $context['user_id'];
        }

        // From Slack context - look up Slack user
        if (isset($context['slack_user_id'])) {
            $slackUser = \App\Models\SlackUser::where('slack_id', $context['slack_user_id'])->first();
            if ($slackUser && $slackUser->user_id) {
                return $slackUser->user_id;
            }
        }

        // From trigger metadata
        $triggerMeta = $this->run->trigger_metadata ?? [];
        if (isset($triggerMeta['user_id'])) {
            return (int) $triggerMeta['user_id'];
        }

        // Fallback: get first admin user
        $admin = \App\Models\User::where('role', 'admin')->first();

        return $admin?->id;
    }

    /**
     * Job tags for Horizon.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'agent:'.$this->run->agent?->slug,
            'run:'.$this->run->id,
            'interactive',
            $this->resumeResponse ? 'resume' : 'start',
        ];
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->run = $this->run->fresh();

        if (! $this->run || $this->run->status === AgentRun::STATUS_CANCELLED) {
            return;
        }

        Log::error('RunInteractiveAgentJob failed completely', [
            'run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        $this->handleFailed('Job failed: '.$exception->getMessage());
    }
}
