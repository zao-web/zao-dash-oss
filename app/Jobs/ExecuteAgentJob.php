<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Queued agent execution job.
 *
 * Use this for:
 * - Scheduled agent triggers
 * - Webhook-initiated executions
 * - Long-running agents
 * - Batch processing
 */
class ExecuteAgentJob implements ShouldQueue
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
        public Agent $agent,
        public array $config = [],
        public string $invocationSource = AgentRun::SOURCE_MANUAL,
        public ?string $invokedBy = null,
        public array $triggerMetadata = [],
    ) {}

    /**
     * Get the middleware the job should pass through.
     *
     * Rate limit Anthropic API calls to prevent 429 errors.
     * Only one agent of the same type can run at a time (WithoutOverlapping).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('anthropic-agents'),
            (new WithoutOverlapping($this->agent->id))->releaseAfter(300)->expireAfter(1800),
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
        $executor->execute(
            agent: $this->agent,
            config: $this->config,
            invocationSource: $this->invocationSource,
            invokedBy: $this->invokedBy,
            triggerMetadata: $this->triggerMetadata,
        );
    }

    /**
     * Determine the unique ID of the job.
     */
    public function uniqueId(): string
    {
        // Allow only one queued execution per agent at a time
        // (can be overridden by using different queue names)
        return 'agent-'.$this->agent->id;
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'agent:'.$this->agent->slug,
            'source:'.$this->invocationSource,
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Log is already handled by AgentExecutor
        // Additional failure handling could go here
        // (e.g., notifications, circuit breaker updates)
    }
}
