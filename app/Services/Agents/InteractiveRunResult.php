<?php

namespace App\Services\Agents;

use App\Models\InteractionRequest;

/**
 * Result of an interactive agent execution step.
 *
 * An interactive run can either:
 * - Complete successfully (no more input needed)
 * - Require user interaction (paused for input)
 * - Fail with an error
 */
readonly class InteractiveRunResult
{
    private function __construct(
        public bool $completed,
        public bool $needsInteraction,
        public ?InteractionRequest $interaction,
        public ?array $checkpoint,
        public ?array $output,
        public string $rawOutput,
        public ?string $errorMessage,
        public float $durationSeconds,
        public int $tokensUsed,
        public float $costUsd,
    ) {}

    /**
     * Create a result for a completed run.
     */
    public static function completed(
        array $output,
        string $rawOutput,
        float $durationSeconds = 0,
        int $tokensUsed = 0,
        float $costUsd = 0.0,
    ): self {
        return new self(
            completed: true,
            needsInteraction: false,
            interaction: null,
            checkpoint: null,
            output: $output,
            rawOutput: $rawOutput,
            errorMessage: null,
            durationSeconds: $durationSeconds,
            tokensUsed: $tokensUsed,
            costUsd: $costUsd,
        );
    }

    /**
     * Create a result indicating user interaction is needed.
     */
    public static function needsInteraction(
        InteractionRequest $interaction,
        array $checkpoint,
        string $rawOutput = '',
        float $durationSeconds = 0,
    ): self {
        return new self(
            completed: false,
            needsInteraction: true,
            interaction: $interaction,
            checkpoint: $checkpoint,
            output: null,
            rawOutput: $rawOutput,
            errorMessage: null,
            durationSeconds: $durationSeconds,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * Create a result for a failed run.
     */
    public static function failed(
        string $errorMessage,
        string $rawOutput = '',
        float $durationSeconds = 0,
    ): self {
        return new self(
            completed: true,
            needsInteraction: false,
            interaction: null,
            checkpoint: null,
            output: ['error' => $errorMessage],
            rawOutput: $rawOutput,
            errorMessage: $errorMessage,
            durationSeconds: $durationSeconds,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * Check if this run succeeded (completed without error).
     */
    public function succeeded(): bool
    {
        return $this->completed && $this->errorMessage === null;
    }

    /**
     * Check if this run failed.
     */
    public function failed(): bool
    {
        return $this->errorMessage !== null;
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'needs_interaction' => $this->needsInteraction,
            'interaction_id' => $this->interaction?->id,
            'checkpoint' => $this->checkpoint,
            'output' => $this->output,
            'error_message' => $this->errorMessage,
            'duration_seconds' => $this->durationSeconds,
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
        ];
    }
}
