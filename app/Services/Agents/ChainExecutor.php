<?php

namespace App\Services\Agents;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentChain;
use App\Models\AgentChainRun;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates execution of agent chains.
 * Handles step sequencing, condition evaluation, and output transformation.
 */
class ChainExecutor
{
    public function __construct(
        protected AgentExecutor $executor
    ) {}

    /**
     * Start a new chain execution.
     */
    public function startChain(
        AgentChain $chain,
        string $initialInput,
        string $triggeredBy = 'manual',
        array $triggerMetadata = []
    ): AgentChainRun {
        $chainRun = AgentChainRun::create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
            'initial_input' => $initialInput,
            'step_results' => [],
            'triggered_by' => $triggeredBy,
            'trigger_metadata' => $triggerMetadata,
            'started_at' => now(),
        ]);

        Log::info('Starting chain execution', [
            'chain' => $chain->name,
            'chain_run_id' => $chainRun->id,
            'steps' => $chain->getStepCount(),
        ]);

        // Execute first step
        $this->executeNextStep($chainRun);

        return $chainRun;
    }

    /**
     * Start a chain from a template.
     */
    public function startFromTemplate(
        string $templateKey,
        string $initialInput,
        string $triggeredBy = 'manual'
    ): ?AgentChainRun {
        // Find or create chain from template
        $chain = AgentChain::where('slug', $templateKey)->first();

        if (! $chain) {
            $chain = AgentChain::createFromTemplate($templateKey);
        }

        if (! $chain) {
            Log::warning('Chain template not found', ['template' => $templateKey]);

            return null;
        }

        return $this->startChain($chain, $initialInput, $triggeredBy, ['template' => $templateKey]);
    }

    /**
     * Execute the next step in a chain run.
     */
    public function executeNextStep(AgentChainRun $chainRun): void
    {
        $chain = $chainRun->chain;
        if (! $chain) {
            $chainRun->markFailed('Chain not found');

            return;
        }

        $currentStep = $chainRun->getCurrentStep();

        // Check if chain is complete
        if ($currentStep >= $chain->getStepCount()) {
            $chainRun->markCompleted();
            Log::info('Chain completed', [
                'chain_run_id' => $chainRun->id,
                'steps_executed' => $currentStep,
            ]);

            return;
        }

        // Check if step should execute based on conditions
        if (! $chainRun->shouldContinue()) {
            // Condition not met, mark as completed (skipped remaining steps)
            $chainRun->markCompleted();
            Log::info('Chain completed (condition not met for next step)', [
                'chain_run_id' => $chainRun->id,
                'stopped_at_step' => $currentStep,
            ]);

            return;
        }

        // Get the agent for this step
        $agent = $chain->getStepAgent($currentStep);
        if (! $agent) {
            $chainRun->markFailed("Agent not found for step {$currentStep}");

            return;
        }

        // Get transformed input for this step
        $stepInput = $chainRun->getTransformedInput();

        Log::info('Executing chain step', [
            'chain_run_id' => $chainRun->id,
            'step' => $currentStep,
            'agent' => $agent->slug,
        ]);

        // Queue the agent execution
        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: [
                'prompt' => $stepInput,
                'context' => [
                    'chain_run_id' => $chainRun->id,
                    'chain_step' => $currentStep,
                    'chain_name' => $chain->name,
                ],
            ],
            invocationSource: AgentRun::SOURCE_CHAIN,
            invokedBy: "chain:{$chain->slug}:step:{$currentStep}",
            triggerMetadata: [
                'chain_run_id' => $chainRun->id,
                'step_index' => $currentStep,
            ]
        );
    }

    /**
     * Handle completion of a step (called from AgentExecutor).
     */
    public function handleStepCompletion(AgentRun $run): void
    {
        $chainRunId = $run->context['chain_run_id'] ?? null;
        $stepIndex = $run->context['chain_step'] ?? null;

        if (! $chainRunId || $stepIndex === null) {
            return;
        }

        $chainRun = AgentChainRun::find($chainRunId);
        if (! $chainRun || $chainRun->status !== AgentChainRun::STATUS_RUNNING) {
            return;
        }

        // Record this step's result
        $chainRun->recordStepResult($stepIndex, $run);

        // Link the agent run to the chain run
        $run->update([
            'chain_run_id' => $chainRun->id,
            'chain_step_index' => $stepIndex,
        ]);

        // Check if step failed
        if ($run->status === 'failed') {
            $chainRun->markFailed("Step {$stepIndex} failed: ".($run->error ?? 'Unknown error'));

            return;
        }

        // Execute next step
        $this->executeNextStep($chainRun);
    }

    /**
     * Cancel a running chain.
     */
    public function cancelChain(AgentChainRun $chainRun): void
    {
        if ($chainRun->status !== AgentChainRun::STATUS_RUNNING) {
            return;
        }

        $chainRun->update([
            'status' => AgentChainRun::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        Log::info('Chain cancelled', ['chain_run_id' => $chainRun->id]);
    }

    /**
     * Retry a failed chain from the failed step.
     */
    public function retryChain(AgentChainRun $chainRun): void
    {
        if ($chainRun->status !== AgentChainRun::STATUS_FAILED) {
            return;
        }

        $chainRun->update([
            'status' => AgentChainRun::STATUS_RUNNING,
            'error_message' => null,
            'completed_at' => null,
        ]);

        Log::info('Retrying chain', [
            'chain_run_id' => $chainRun->id,
            'from_step' => $chainRun->current_step,
        ]);

        $this->executeNextStep($chainRun);
    }

    /**
     * Get available chain templates.
     */
    public function getTemplates(): array
    {
        return AgentChain::templates();
    }

    /**
     * Validate that all agents in a chain exist and are active.
     */
    public function validateChain(AgentChain $chain): array
    {
        $issues = [];
        $steps = $chain->steps ?? [];

        foreach ($steps as $i => $step) {
            $agent = Agent::where('slug', $step['agent_slug'])->first();

            if (! $agent) {
                $issues[] = "Step {$i}: Agent '{$step['agent_slug']}' not found";
            } elseif ($agent->status !== 'active') {
                $issues[] = "Step {$i}: Agent '{$step['agent_slug']}' is not active";
            }
        }

        return $issues;
    }
}
