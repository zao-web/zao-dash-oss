<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracks execution of an agent chain.
 * Each step completion triggers evaluation of the next step.
 */
class AgentChainRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'step_results' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_RUNNING = 'running';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    public function chain(): BelongsTo
    {
        return $this->belongsTo(AgentChain::class, 'agent_chain_id');
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'chain_run_id');
    }

    /**
     * Get the current step being executed.
     */
    public function getCurrentStep(): int
    {
        return $this->current_step ?? 0;
    }

    /**
     * Get result from a specific step.
     */
    public function getStepResult(int $stepIndex): ?array
    {
        $results = $this->step_results ?? [];

        return $results[$stepIndex] ?? null;
    }

    /**
     * Get output from the previous step.
     */
    public function getPreviousOutput(): ?string
    {
        $currentStep = $this->getCurrentStep();
        if ($currentStep === 0) {
            return $this->initial_input;
        }

        $prevResult = $this->getStepResult($currentStep - 1);

        return $prevResult['output'] ?? null;
    }

    /**
     * Was the previous step successful?
     */
    public function wasPreviousStepSuccessful(): bool
    {
        $currentStep = $this->getCurrentStep();
        if ($currentStep === 0) {
            return true; // First step always proceeds
        }

        $prevResult = $this->getStepResult($currentStep - 1);

        return ($prevResult['status'] ?? '') === 'completed';
    }

    /**
     * Record step completion.
     */
    public function recordStepResult(int $stepIndex, AgentRun $run): void
    {
        $results = $this->step_results ?? [];
        $results[$stepIndex] = [
            'agent_run_id' => $run->id,
            'status' => $run->status,
            'output' => $run->output,
            'cost_usd' => $run->cost_usd,
            'completed_at' => now()->toISOString(),
        ];

        $this->update([
            'step_results' => $results,
            'current_step' => $stepIndex + 1,
            'total_cost_usd' => collect($results)->sum('cost_usd'),
        ]);
    }

    /**
     * Mark the chain run as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the chain run as failed.
     */
    public function markFailed(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $reason,
        ]);
    }

    /**
     * Check if chain should continue to next step.
     */
    public function shouldContinue(): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        $chain = $this->chain;
        if (! $chain) {
            return false;
        }

        $currentStep = $this->getCurrentStep();

        // Check if we've completed all steps
        if ($currentStep >= $chain->getStepCount()) {
            return false;
        }

        // Check condition for next step
        return $chain->shouldExecuteStep(
            $currentStep,
            $this->getPreviousOutput(),
            $this->wasPreviousStepSuccessful()
        );
    }

    /**
     * Get transformed input for the current step.
     */
    public function getTransformedInput(): string
    {
        $chain = $this->chain;
        $currentStep = $this->getCurrentStep();

        if ($currentStep === 0) {
            return $this->initial_input ?? '';
        }

        $previousOutput = $this->getPreviousOutput() ?? '';

        // Apply transform from previous step
        return $chain->transformOutput($currentStep - 1, $previousOutput);
    }

    /**
     * Get summary of chain execution.
     */
    public function getSummary(): array
    {
        $results = $this->step_results ?? [];
        $chain = $this->chain;

        return [
            'chain_name' => $chain?->name,
            'status' => $this->status,
            'steps_completed' => count($results),
            'total_steps' => $chain?->getStepCount() ?? 0,
            'total_cost' => $this->total_cost_usd,
            'duration_seconds' => $this->started_at && $this->completed_at
                ? $this->completed_at->diffInSeconds($this->started_at)
                : null,
            'step_details' => collect($results)->map(fn ($r, $i) => [
                'step' => $i,
                'agent' => $chain?->steps[$i]['agent_slug'] ?? 'unknown',
                'status' => $r['status'],
                'cost' => $r['cost_usd'],
            ])->values()->all(),
        ];
    }
}
