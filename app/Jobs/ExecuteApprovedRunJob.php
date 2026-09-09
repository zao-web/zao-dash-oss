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
 * Executes an agent run that has been approved.
 *
 * This runs asynchronously so the approval HTTP request
 * returns immediately without waiting for agent completion.
 */
class ExecuteApprovedRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * Seconds to wait before retrying (exponential backoff for rate limits).
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120, 300, 600];

    /**
     * Maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    public function __construct(
        public AgentRun $run,
        public array $config = [],
    ) {}

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('anthropic-agents'),
            (new WithoutOverlapping('approved-run-'.$this->run->agent_id))
                ->releaseAfter(300)
                ->expireAfter(1800),
        ];
    }

    /**
     * Determine if the job should be retried when a rate limit exception occurs.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /**
     * Execute the job.
     */
    public function handle(AgentExecutor $executor): void
    {
        Log::info('ExecuteApprovedRunJob starting', [
            'run_id' => $this->run->id,
            'agent_id' => $this->run->agent_id,
        ]);

        try {
            $executor->executeApproved($this->run, $this->config);
        } catch (\Throwable $e) {
            Log::error('ExecuteApprovedRunJob failed', [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'agent-run:'.$this->run->id,
            'agent:'.$this->run->agent?->slug,
            'approved-execution',
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteApprovedRunJob failed permanently', [
            'run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        $this->run->update([
            'status' => 'failed',
            'output' => ['error' => 'Job execution failed: '.$exception->getMessage()],
            'completed_at' => now(),
        ]);
    }
}
