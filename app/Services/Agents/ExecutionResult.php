<?php

namespace App\Services\Agents;

/**
 * Immutable result of an agent execution.
 */
readonly class ExecutionResult
{
    public function __construct(
        public bool $success,
        public array $output,
        public string $rawOutput,
        public string $errorOutput,
        public int $exitCode,
        public float $durationSeconds,
        public int $tokensUsed,
        public float $costUsd,
        public ?string $workspace,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $durationMs = null,
    ) {}

    /**
     * Check if execution was successful.
     */
    public function succeeded(): bool
    {
        return $this->success && $this->exitCode === 0;
    }

    /**
     * Check if execution failed.
     */
    public function failed(): bool
    {
        return ! $this->succeeded();
    }

    /**
     * Get error message if failed.
     */
    public function errorMessage(): ?string
    {
        if ($this->succeeded()) {
            return null;
        }

        return $this->output['error'] ?? $this->errorOutput ?: 'Unknown error';
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'exit_code' => $this->exitCode,
            'duration_seconds' => $this->durationSeconds,
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
