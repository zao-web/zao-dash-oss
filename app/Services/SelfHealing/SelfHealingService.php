<?php

namespace App\Services\SelfHealing;

use App\DTOs\NightwatchError;
use App\Models\SelfHealingAttempt;
use App\Services\Slack\SlackApiService;
use Illuminate\Support\Facades\Log;

class SelfHealingService
{
    public function __construct(
        protected SlackApiService $slack
    ) {}

    /**
     * Check if self-healing is enabled.
     */
    public function isEnabled(): bool
    {
        return config('self-healing.enabled', false);
    }

    /**
     * Determine if we should attempt to fix this error.
     */
    public function shouldAttemptFix(NightwatchError $error): bool
    {
        if (! $this->isEnabled()) {
            Log::debug('Self-healing disabled');

            return false;
        }

        // Check if error type should be skipped
        if ($error->shouldSkip()) {
            Log::info('Self-healing: Skipping infrastructure error', [
                'signature' => $error->getSignature(),
            ]);

            return false;
        }

        // Check deduplication
        if ($this->isAlreadyBeingFixed($error)) {
            Log::info('Self-healing: Already being fixed', [
                'signature' => $error->getSignature(),
            ]);

            return false;
        }

        if ($this->wasRecentlyFixed($error)) {
            Log::info('Self-healing: Recently fixed', [
                'signature' => $error->getSignature(),
            ]);

            return false;
        }

        // Check rate limits
        if ($this->hasExceededRateLimit()) {
            Log::warning('Self-healing: Rate limit exceeded');

            return false;
        }

        // Check circuit breaker
        if ($this->isCircuitBreakerOpen()) {
            Log::warning('Self-healing: Circuit breaker open');

            return false;
        }

        return true;
    }

    /**
     * Check if this error is currently being fixed.
     */
    public function isAlreadyBeingFixed(NightwatchError $error): bool
    {
        if (! config('self-healing.deduplication.block_concurrent', true)) {
            return false;
        }

        return SelfHealingAttempt::query()
            ->where('error_signature', $error->getSignature())
            ->whereIn('status', [
                SelfHealingAttempt::STATUS_PENDING,
                SelfHealingAttempt::STATUS_IN_PROGRESS,
            ])
            ->exists();
    }

    /**
     * Check if this error was recently fixed successfully.
     */
    public function wasRecentlyFixed(NightwatchError $error): bool
    {
        $successCooldown = config('self-healing.deduplication.success_cooldown_hours', 24);

        $recentSuccess = SelfHealingAttempt::query()
            ->where('error_signature', $error->getSignature())
            ->where('status', SelfHealingAttempt::STATUS_SUCCESS)
            ->where('created_at', '>', now()->subHours($successCooldown))
            ->exists();

        if ($recentSuccess) {
            return true;
        }

        // Also check for recent failures (give them time to cool down)
        $failureCooldown = config('self-healing.deduplication.failure_cooldown_hours', 6);

        return SelfHealingAttempt::query()
            ->where('error_signature', $error->getSignature())
            ->where('status', SelfHealingAttempt::STATUS_FAILED)
            ->where('created_at', '>', now()->subHours($failureCooldown))
            ->exists();
    }

    /**
     * Check if rate limits have been exceeded.
     */
    public function hasExceededRateLimit(): bool
    {
        $hourlyLimit = config('self-healing.rate_limits.per_hour', 5);
        $dailyLimit = config('self-healing.rate_limits.per_day', 15);

        $hourlyCount = SelfHealingAttempt::query()
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($hourlyCount >= $hourlyLimit) {
            return true;
        }

        $dailyCount = SelfHealingAttempt::query()
            ->where('created_at', '>', now()->subDay())
            ->count();

        return $dailyCount >= $dailyLimit;
    }

    /**
     * Check if circuit breaker is open (too many failures).
     */
    public function isCircuitBreakerOpen(): bool
    {
        $threshold = config('self-healing.circuit_breaker.failure_threshold', 3);
        $cooldown = config('self-healing.circuit_breaker.cooldown_minutes', 60);

        $recentAttempts = SelfHealingAttempt::query()
            ->where('created_at', '>', now()->subMinutes($cooldown))
            ->latest()
            ->take($threshold)
            ->get();

        if ($recentAttempts->count() < $threshold) {
            return false;
        }

        // Check if all recent attempts failed
        return $recentAttempts->every(
            fn ($attempt) => $attempt->status === SelfHealingAttempt::STATUS_FAILED
        );
    }

    /**
     * Create a new healing attempt record.
     */
    public function createAttempt(NightwatchError $error): SelfHealingAttempt
    {
        return SelfHealingAttempt::create([
            'error_signature' => $error->getSignature(),
            'exception_class' => $error->exceptionClass,
            'error_message' => $error->message,
            'source_job' => $error->sourceJob,
            'source_file' => $error->file,
            'source_line' => $error->line,
            'environment' => $error->environment,
            'occurrence_count' => $error->occurrenceCount,
            'nightwatch_url' => $error->nightwatchUrl,
            'slack_message_ts' => $error->slackMessageTs,
            'slack_channel_id' => $error->slackChannelId,
            'status' => SelfHealingAttempt::STATUS_PENDING,
            'branch' => config('self-healing.default_branch', 'main'),
        ]);
    }

    /**
     * Get current rate limit usage.
     */
    public function getRateLimitStatus(): array
    {
        $hourlyLimit = config('self-healing.rate_limits.per_hour', 5);
        $dailyLimit = config('self-healing.rate_limits.per_day', 15);

        $hourlyCount = SelfHealingAttempt::where('created_at', '>', now()->subHour())->count();
        $dailyCount = SelfHealingAttempt::where('created_at', '>', now()->subDay())->count();

        return [
            'hourly' => [
                'used' => $hourlyCount,
                'limit' => $hourlyLimit,
                'remaining' => max(0, $hourlyLimit - $hourlyCount),
            ],
            'daily' => [
                'used' => $dailyCount,
                'limit' => $dailyLimit,
                'remaining' => max(0, $dailyLimit - $dailyCount),
            ],
        ];
    }

    /**
     * Get recent statistics.
     */
    public function getStats(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        return [
            'total' => SelfHealingAttempt::where('created_at', '>', $since)->count(),
            'success' => SelfHealingAttempt::where('created_at', '>', $since)
                ->where('status', SelfHealingAttempt::STATUS_SUCCESS)->count(),
            'failed' => SelfHealingAttempt::where('created_at', '>', $since)
                ->where('status', SelfHealingAttempt::STATUS_FAILED)->count(),
            'escalated' => SelfHealingAttempt::where('created_at', '>', $since)
                ->where('status', SelfHealingAttempt::STATUS_ESCALATED)->count(),
            'in_progress' => SelfHealingAttempt::where('status', SelfHealingAttempt::STATUS_IN_PROGRESS)->count(),
            'circuit_breaker_open' => $this->isCircuitBreakerOpen(),
            'rate_limit' => $this->getRateLimitStatus(),
        ];
    }

    /**
     * Post a status update as a thread reply to the original Nightwatch message.
     */
    public function postSlackUpdate(SelfHealingAttempt $attempt, string $message): void
    {
        if (! config('self-healing.notifications.slack_thread_updates', true)) {
            return;
        }

        try {
            $this->slack->postMessageDirect(
                channel: $attempt->slack_channel_id,
                text: $message,
                threadTs: $attempt->slack_message_ts,
            );
        } catch (\Exception $e) {
            Log::warning('Failed to post self-healing Slack update', [
                'error' => $e->getMessage(),
                'attempt_id' => $attempt->id,
            ]);
        }
    }

    /**
     * Notify about circuit breaker opening.
     */
    public function notifyCircuitBreakerOpen(): void
    {
        $alertChannel = config('self-healing.notifications.alert_channel', '#eng-alerts');

        try {
            $this->slack->postMessageDirect(
                channel: $alertChannel,
                text: "⚠️ *Self-Healing Circuit Breaker Open*\n\n".
                    'Too many consecutive failures. Self-healing is paused for '.
                    config('self-healing.circuit_breaker.cooldown_minutes', 60)." minutes.\n\n".
                    'Recent failures need manual review.',
            );
        } catch (\Exception $e) {
            Log::error('Failed to notify circuit breaker open', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Escalate an error to humans.
     */
    public function escalate(SelfHealingAttempt $attempt, string $reason, ?string $agentOutput = null): void
    {
        $attempt->markEscalated($reason, $agentOutput);

        $mentions = config('self-healing.notifications.escalation_mentions', '');
        $mentionText = $mentions ? "{$mentions} " : '';

        $this->postSlackUpdate($attempt, sprintf(
            "⚠️ %s*Self-healing couldn't fix this automatically*\n\n".
            "*Reason:* %s\n\n".
            'Manual intervention required.',
            $mentionText,
            $reason
        ));

        Log::info('Self-healing escalated to human', [
            'attempt_id' => $attempt->id,
            'reason' => $reason,
        ]);
    }
}
