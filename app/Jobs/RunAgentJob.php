<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Execute a pre-created agent run.
 *
 * Use this when:
 * - Run is created externally (MCP trigger, API)
 * - Need to return run ID immediately before execution
 * - Async execution of existing run records
 */
class RunAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Maximum seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    public function __construct(
        public AgentRun $run
    ) {
        $this->onQueue('agents');
    }

    /**
     * Rate limit and prevent overlapping executions.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('anthropic-agents'),
            (new WithoutOverlapping($this->run->agent_id))->releaseAfter(300)->expireAfter(1800),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(AgentExecutor $executor): void
    {
        $this->run = $this->run->fresh();

        if (! $this->run) {
            return;
        }

        if ($this->run->status === AgentRun::STATUS_CANCELLED) {
            Log::info('RunAgentJob skipped cancelled run', [
                'run_id' => $this->run->id,
            ]);

            return;
        }

        Log::info('RunAgentJob starting', [
            'run_id' => $this->run->id,
            'agent_id' => $this->run->agent_id,
            'status' => $this->run->status,
        ]);

        $executor->executePendingRun($this->run);
    }

    /**
     * Unique job identifier.
     */
    public function uniqueId(): string
    {
        return 'run-'.$this->run->id;
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
            'source:'.($this->run->invocation_source ?? 'unknown'),
        ];
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RunAgentJob failed', [
            'run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        if (in_array($this->run->status, ['pending', AgentRun::STATUS_RUNNING], true)) {
            $this->run->update([
                'status' => AgentRun::STATUS_FAILED,
                'output' => ['error' => 'Job failed: '.$exception->getMessage()],
                'completed_at' => now(),
            ]);
        }
    }
}
