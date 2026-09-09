<?php

namespace App\Services\Agents;

use App\Events\AgentRunStatusChanged;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\GitHubRepo;
use App\Services\AgentOutputRouter;
use App\Services\AI\MultiModelConsortium;
use App\Services\GitHub\GitHubAppService;
use App\Services\Vault\VaultService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates agent execution.
 *
 * Handles:
 * - Validation of agent state
 * - Approval flow creation/checking
 * - Delegation to ClaudeCliRunner
 * - Run status management
 * - Post-processing via definitions
 */
class AgentExecutor
{
    /**
     * Status constants for agent runs.
     */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected ?ChainExecutor $chainExecutor = null;

    public function __construct(
        protected ClaudeCliRunner $runner,
        protected ?ClaudeAgentSdk $sdk = null,
        protected ?MultiModelConsortium $consortium = null,
        protected ?AgentOutputRouter $outputRouter = null,
        protected ?VaultService $vault = null,
        protected ?GitHubAppService $githubApp = null,
        protected ?ClaudeTokenService $tokenService = null,
    ) {}

    /**
     * Set the chain executor (avoids circular dependency).
     */
    public function setChainExecutor(ChainExecutor $executor): void
    {
        $this->chainExecutor = $executor;
    }

    /**
     * Determine if agent should use SDK (API) vs CLI.
     *
     * SDK provides: better tool control, streaming, accurate token tracking
     * CLI provides: simpler setup, Claude Code features, uses Claude Max subscription (no API cost)
     *
     * Default: CLI to use user's Claude Max subscription and avoid API costs.
     * Only use SDK if explicitly requested via definition or database.
     */
    protected function shouldUseSdk(Agent $agent): bool
    {
        // Check if SDK is available
        if (! $this->sdk) {
            return false;
        }

        // Check definition-level preference first (PHP code takes priority)
        $definition = $agent->getDefinition();
        if ($definition?->executionMode() === 'sdk') {
            return true;
        }
        if ($definition?->executionMode() === 'cli') {
            return false;
        }

        // Check agent-level preference (database override)
        if ($agent->execution_mode === 'sdk') {
            return true;
        }

        if ($agent->execution_mode === 'cli') {
            return false;
        }

        // Default: prefer CLI to use Claude Max subscription (no API cost)
        // Only use SDK if explicitly configured above
        return false;
    }

    /**
     * Execute an agent.
     *
     * Returns the AgentRun which may be:
     * - completed (if no approval needed)
     * - pending_approval (if approval required)
     * - failed (if validation failed)
     */
    public function execute(
        Agent $agent,
        array $config = [],
        string $invocationSource = AgentRun::SOURCE_MANUAL,
        ?string $invokedBy = null,
        array $triggerMetadata = [],
        ?int $projectId = null,
        ?int $taskId = null
    ): AgentRun {
        // Extract project/task from config if not passed explicitly
        $projectId = $projectId ?? $config['project_id'] ?? $config['context']['project_id'] ?? null;
        $taskId = $taskId ?? $config['task_id'] ?? $config['context']['task_id'] ?? null;

        // Validate agent can run
        $validation = $this->validateAgent($agent);
        if (! $validation['valid']) {
            return $this->createFailedRun($agent, $validation['error'], $invocationSource, $invokedBy, $triggerMetadata, $projectId, $taskId);
        }

        // Create the run record
        $run = $this->createRun($agent, $config, $invocationSource, $invokedBy, $triggerMetadata, $projectId, $taskId);

        // Execute immediately - approval workflow removed
        // All agents auto-execute. The dev agent should submit PRs for review instead of requiring pre-approval.
        return $this->performExecution($run, $config);
    }

    /**
     * Execute an approved run.
     *
     * Called after approval is granted.
     */
    public function executeApproved(AgentRun $run, array $config = []): AgentRun
    {
        if ($run->status !== self::STATUS_PENDING_APPROVAL) {
            throw new \InvalidArgumentException('Run is not pending approval');
        }

        $approval = $run->approvalRequest;
        if (! $approval || $approval->status !== 'approved') {
            throw new \InvalidArgumentException('Run has not been approved');
        }

        // Merge any config from approval payload
        $config = array_merge($approval->payload ?? [], $config);

        return $this->performExecution($run, $config);
    }

    /**
     * Execute a pending run created externally (e.g., via MCP trigger).
     *
     * This allows runs to be created first (for immediate ID return)
     * and executed asynchronously via a job.
     */
    public function executePendingRun(AgentRun $run): AgentRun
    {
        if ($run->status === self::STATUS_CANCELLED) {
            return $run->fresh();
        }

        if ($run->status !== self::STATUS_RUNNING) {
            throw new \InvalidArgumentException("Run status is '{$run->status}', expected 'running'");
        }

        $agent = $run->agent;

        // Validate agent can run
        $validation = $this->validateAgent($agent);
        if (! $validation['valid']) {
            $this->updateRunStatus($run, self::STATUS_FAILED, [
                'output' => ['error' => $validation['error']],
                'completed_at' => now(),
            ]);

            return $run->fresh();
        }

        // Build config from run data
        $context = $run->context ?? [];
        $config = [
            'prompt' => $run->task,
            'context' => $context,
        ];

        // Extract timeout from context if specified
        if (isset($context['timeout_seconds'])) {
            $config['timeout_seconds'] = (int) $context['timeout_seconds'];
        }

        return $this->performExecution($run, $config);
    }

    /**
     * Continue execution after a chained agent.
     */
    public function executeChained(
        Agent $agent,
        AgentRun $previousRun,
        array $config = []
    ): AgentRun {
        // Merge output from previous run into context
        $config['context'] = array_merge(
            $config['context'] ?? [],
            ['previous_run' => $previousRun->output]
        );

        return $this->execute(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_CHAINED,
            invokedBy: 'agent:'.$previousRun->agent_id,
            triggerMetadata: ['chained_from_run_id' => $previousRun->id]
        );
    }

    /**
     * Validate that an agent can be executed.
     */
    protected function validateAgent(Agent $agent): array
    {
        // Check agent status
        if ($agent->status !== 'active') {
            return [
                'valid' => false,
                'error' => "Agent status is '{$agent->status}', must be 'active'",
            ];
        }

        // Check circuit breaker
        if ($agent->circuit_broken_at) {
            return [
                'valid' => false,
                'error' => 'Agent circuit breaker is active',
            ];
        }

        // Validate required secrets (if definition specifies them)
        $definition = $agent->getDefinition();
        if ($definition) {
            $requiredSecrets = $definition->requiredSecrets();
            // TODO: Check Vault for required secrets
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Create a new agent run record.
     *
     * Run is created with STATUS_RUNNING and executes immediately.
     * Approval workflow has been removed - agents auto-execute.
     * Code changes should go through PR review instead of pre-approval.
     */
    protected function createRun(
        Agent $agent,
        array $config,
        string $invocationSource,
        ?string $invokedBy,
        array $triggerMetadata,
        ?int $projectId = null,
        ?int $taskId = null
    ): AgentRun {
        $run = AgentRun::create([
            'agent_id' => $agent->id,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => self::STATUS_RUNNING,
            'task' => $config['prompt'] ?? 'Manual trigger',
            'context' => $config['context'] ?? [],
            'invocation_source' => $invocationSource,
            'invoked_by' => $invokedBy,
            'trigger_metadata' => $triggerMetadata,
            'started_at' => now(),
        ]);

        return $run;
    }

    /**
     * Create a failed run record.
     */
    protected function createFailedRun(
        Agent $agent,
        string $error,
        string $invocationSource,
        ?string $invokedBy,
        array $triggerMetadata,
        ?int $projectId = null,
        ?int $taskId = null
    ): AgentRun {
        return AgentRun::create([
            'agent_id' => $agent->id,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => self::STATUS_FAILED,
            'task' => 'Failed execution',
            'context' => [],
            'output' => ['error' => $error],
            'invocation_source' => $invocationSource,
            'invoked_by' => $invokedBy,
            'trigger_metadata' => $triggerMetadata,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Create an approval request for the run.
     */
    protected function createApprovalRequest(AgentRun $run, array $config): AgentRun
    {
        DB::transaction(function () use ($run, $config) {
            $run->update(['status' => self::STATUS_PENDING_APPROVAL]);

            ApprovalRequest::create([
                'agent_run_id' => $run->id,
                'action_type' => 'agent_execution',
                'description' => 'Agent execution requires approval',
                'status' => 'pending',
                'payload' => $config,
                'expires_at' => now()->addHours(24),
            ]);
        });

        Log::info('Agent execution pending approval', [
            'agent_id' => $run->agent_id,
            'run_id' => $run->id,
        ]);

        return $run->fresh('approvalRequest');
    }

    /**
     * Perform the actual execution.
     */
    protected function performExecution(AgentRun $run, array $config): AgentRun
    {
        $agent = $run->agent;

        if ($this->hasBeenCancelled($run)) {
            return $run->fresh();
        }

        // Update status to running and broadcast
        $this->updateRunStatus($run, self::STATUS_RUNNING, ['started_at' => now()]);

        // Automatically move linked task to in_progress when agent actually starts executing
        if ($run->task_id) {
            $task = \App\Models\Task::find($run->task_id);
            if ($task && $task->status === 'pending') {
                $task->update(['status' => 'in_progress']);
                Log::info('Task status updated to in_progress', [
                    'task_id' => $run->task_id,
                    'agent_run_id' => $run->id,
                ]);
            }
        }

        try {
            // Load secrets from Vault if available
            $config = $this->loadSecretsForExecution($agent, $run, $config);

            // Check if agent uses consortium for high-stakes outputs
            if ($agent->use_consortium && $this->consortium?->isAvailable()) {
                return $this->executeWithConsortium($run, $config);
            }

            // Choose execution method: SDK (API) or CLI
            $result = $this->shouldUseSdk($agent)
                ? $this->sdk->execute($agent, $run, $config)
                : $this->runner->execute($agent, $run, $config);

            if ($this->hasBeenCancelled($run)) {
                $this->cleanupExecutionWorkspace($result->workspace ?? null, $config);

                Log::info('Agent execution completed after cancellation; preserving cancelled status', [
                    'agent_id' => $agent->id,
                    'run_id' => $run->id,
                ]);

                return $run->fresh();
            }

            // Check for token expiration errors
            if ($this->tokenService && ! $result->succeeded()) {
                $this->handlePotentialTokenError($result);
            }

            // Post-process output if definition exists
            $output = $result->output;
            $definition = $agent->getDefinition();
            if ($definition) {
                $output = $definition->processOutput($output);
            }

            // Update run with results and broadcast
            $this->updateRunStatus($run, $result->succeeded() ? self::STATUS_COMPLETED : self::STATUS_FAILED, [
                'output' => $output,
                'cost_usd' => $result->costUsd,
                'input_tokens' => $result->inputTokens ?? null,
                'output_tokens' => $result->outputTokens ?? null,
                'duration_ms' => $result->durationMs ?? null,
                'completed_at' => now(),
            ]);

            // Clean up workspace (only for CLI execution) unless orchestration requested persistence.
            $this->cleanupExecutionWorkspace($result->workspace ?? null, $config);

            // Handle agent chaining
            if ($result->succeeded()) {
                $this->handleChaining($run, $output);

                // Route output to destinations (WordPress, Slack, email)
                if ($this->outputRouter) {
                    $this->outputRouter->route($run->fresh());
                }
            }

            Log::info('Agent execution completed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'success' => $result->succeeded(),
                'duration' => $result->durationSeconds,
                'cost' => $result->costUsd,
            ]);

        } catch (\Throwable $e) {
            Log::error('Agent execution error', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            if ($this->hasBeenCancelled($run)) {
                Log::info('Agent execution error occurred after cancellation; preserving cancelled status', [
                    'agent_id' => $agent->id,
                    'run_id' => $run->id,
                ]);

                return $run->fresh();
            }

            // Update status to failed and broadcast
            $this->updateRunStatus($run, self::STATUS_FAILED, [
                'output' => ['error' => $e->getMessage()],
                'completed_at' => now(),
            ]);

            // Trip circuit breaker after multiple failures
            $this->checkCircuitBreaker($agent);
        }

        return $run->fresh();
    }

    /**
     * Execute using multi-model consortium for high-stakes outputs.
     */
    protected function executeWithConsortium(AgentRun $run, array $config): AgentRun
    {
        $agent = $run->agent;
        $executionConfig = $agent->getExecutionConfig();

        $prompt = $config['prompt'] ?? '';
        $context = $config['context'] ?? [];

        // Build system prompt from agent config
        $systemPrompt = $executionConfig['system_prompt'] ?? null;

        // Get consortium options from agent config
        $consortiumConfig = $agent->consortium_config ?? [];

        try {
            $startTime = microtime(true);

            $result = $this->consortium->generate(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                context: $this->formatContextForConsortium($context),
                options: $consortiumConfig
            );

            $durationSeconds = microtime(true) - $startTime;

            // Build output with consortium metadata
            $output = [
                'content' => $result->content,
                'consortium' => [
                    'consolidated' => $result->consolidated,
                    'providers' => $result->providers,
                    'confidence' => $result->confidence,
                    'reasoning' => $result->reasoning,
                    'conflicts' => $result->conflicts,
                ],
            ];

            // Estimate cost based on provider count
            $estimatedCost = $this->estimateConsortiumCost($result->providers);

            if ($this->hasBeenCancelled($run)) {
                Log::info('Consortium execution completed after cancellation; preserving cancelled status', [
                    'agent_id' => $agent->id,
                    'run_id' => $run->id,
                ]);

                return $run->fresh();
            }

            $run->update([
                'status' => self::STATUS_COMPLETED,
                'output' => $output,
                'cost_usd' => $estimatedCost,
                'completed_at' => now(),
            ]);

            Log::info('Consortium execution completed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'providers' => $result->providers,
                'confidence' => $result->confidence,
                'consolidated' => $result->consolidated,
                'duration' => $durationSeconds,
            ]);

            // Handle chaining if successful
            if ($result->isHighConfidence()) {
                $this->handleChaining($run, $output);
            }

        } catch (\Throwable $e) {
            Log::error('Consortium execution error', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            if ($this->hasBeenCancelled($run)) {
                Log::info('Consortium execution error occurred after cancellation; preserving cancelled status', [
                    'agent_id' => $agent->id,
                    'run_id' => $run->id,
                ]);

                return $run->fresh();
            }

            $run->update([
                'status' => self::STATUS_FAILED,
                'output' => ['error' => $e->getMessage()],
                'completed_at' => now(),
            ]);

            $this->checkCircuitBreaker($agent);
        }

        return $run->fresh();
    }

    /**
     * Format context array for consortium API.
     */
    protected function formatContextForConsortium(array $context): array
    {
        $formatted = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $formatted[] = [
                    'role' => 'user',
                    'content' => "{$key}: ".json_encode($value),
                ];
            } else {
                $formatted[] = [
                    'role' => 'user',
                    'content' => "{$key}: {$value}",
                ];
            }
        }

        return $formatted;
    }

    /**
     * Load secrets from Vault for agent execution.
     *
     * Merges secrets into config['secrets'] for injection into environment.
     * Also tries to get GITHUB_TOKEN from GitHub App installation if not in Vault.
     */
    protected function loadSecretsForExecution(Agent $agent, AgentRun $run, array $config): array
    {
        $definition = $agent->getDefinition();
        $requiredSecrets = $definition?->requiredSecrets() ?? [];

        if (empty($requiredSecrets)) {
            return $config;
        }

        // Get project/client IDs from run context
        $context = $run->context ?? [];
        $projectId = $context['project']['id'] ?? $context['project_id'] ?? null;
        $clientId = $context['client']['id'] ?? $context['client_id'] ?? null;

        // Load secrets from Vault
        $secrets = [];
        if ($this->vault) {
            $vaultSecrets = $this->vault->getAgentSecrets($agent->slug, $projectId, $clientId);
            $secrets = array_intersect_key($vaultSecrets, array_flip($requiredSecrets));
        }

        // If GITHUB_TOKEN is required but not in Vault, try GitHub App installation
        if (in_array('GITHUB_TOKEN', $requiredSecrets) && empty($secrets['GITHUB_TOKEN'])) {
            $githubToken = $this->getGitHubTokenFromApp($run, $context);
            if ($githubToken) {
                $secrets['GITHUB_TOKEN'] = $githubToken;
                Log::info('Using GitHub App installation token for agent', [
                    'agent_slug' => $agent->slug,
                    'run_id' => $run->id,
                ]);
            }
        }

        // Log detailed secret status for debugging
        $loadedKeys = array_keys(array_filter($secrets));
        $missingKeys = array_diff($requiredSecrets, $loadedKeys);

        Log::info('Agent secrets loaded', [
            'agent_slug' => $agent->slug,
            'run_id' => $run->id,
            'required_secrets' => $requiredSecrets,
            'loaded_secrets' => $loadedKeys,
            'missing_secrets' => $missingKeys,
            'has_github_token' => isset($secrets['GITHUB_TOKEN']) && ! empty($secrets['GITHUB_TOKEN']),
            'project_id' => $projectId,
            'client_id' => $clientId,
        ]);

        if (! empty($missingKeys)) {
            Log::warning('Agent missing required secrets - execution may fail', [
                'agent_slug' => $agent->slug,
                'run_id' => $run->id,
                'missing_secrets' => $missingKeys,
            ]);
        }

        // Merge into config['secrets'] - this is what ClaudeCliRunner looks for
        $config['secrets'] = array_merge($config['secrets'] ?? [], $secrets);

        return $config;
    }

    /**
     * Try to get a GitHub token from the GitHub App installation.
     *
     * Looks up the repo from context and gets an installation token.
     */
    protected function getGitHubTokenFromApp(AgentRun $run, array $context): ?string
    {
        if (! $this->githubApp) {
            return null;
        }

        try {
            // Try to find repo from context
            $repoFullName = $context['repo']
                ?? $context['repository']
                ?? $context['project']['github_repo']
                ?? null;

            if (! $repoFullName) {
                // Try to get from project
                $projectId = $context['project']['id'] ?? $context['project_id'] ?? null;
                if ($projectId) {
                    $project = \App\Models\Project::find($projectId);
                    $repoFullName = $project?->github_repo;
                }
            }

            if (! $repoFullName) {
                Log::debug('No GitHub repo found in context for token lookup', [
                    'run_id' => $run->id,
                ]);

                return null;
            }

            // Find the GitHubRepo by full_name
            $repo = GitHubRepo::where('full_name', $repoFullName)->first();

            if (! $repo || ! $repo->installation) {
                Log::warning('GitHub repo or installation not found', [
                    'run_id' => $run->id,
                    'repo' => $repoFullName,
                ]);

                return null;
            }

            // Get installation token
            return $this->githubApp->getInstallationToken($repo->installation);

        } catch (\Throwable $e) {
            Log::error('Failed to get GitHub App installation token', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Estimate cost for consortium execution.
     */
    protected function estimateConsortiumCost(array $providers): float
    {
        // Rough estimates per 1k tokens
        $costPer1k = [
            'claude' => 0.015,
            'gpt' => 0.01,
            'gemini' => 0.00125,
        ];

        $estimatedTokens = 2000; // Average
        $total = 0.0;

        foreach ($providers as $provider) {
            $total += ($costPer1k[$provider] ?? 0.01) * ($estimatedTokens / 1000);
        }

        // Add reasoning agent cost (Claude)
        $total += 0.015 * ($estimatedTokens / 1000);

        return round($total, 4);
    }

    /**
     * Handle agent chaining after successful execution.
     */
    protected function handleChaining(AgentRun $run, array $output): void
    {
        // First, check if this run is part of a chain execution
        if ($run->isPartOfChain() && $this->chainExecutor) {
            $this->chainExecutor->handleStepCompletion($run);

            return;
        }

        // Otherwise, check for simple chain_to in definition
        $definition = $run->agent->getDefinition();
        if (! $definition) {
            return;
        }

        $meta = $definition->metadata();
        $chainTo = $meta['chain_to'] ?? null;

        if (! $chainTo) {
            return;
        }

        // Find the agent to chain to
        $nextAgent = Agent::where('slug', $chainTo)->first();
        if (! $nextAgent) {
            Log::warning('Chain target agent not found', [
                'from_agent' => $run->agent->slug,
                'chain_to' => $chainTo,
            ]);

            return;
        }

        // Queue the chained execution
        dispatch(function () use ($nextAgent, $run) {
            $this->executeChained($nextAgent, $run);
        })->afterResponse();
    }

    /**
     * Check and potentially trip the circuit breaker.
     */
    protected function checkCircuitBreaker(Agent $agent): void
    {
        // Count recent failures
        $recentFailures = AgentRun::where('agent_id', $agent->id)
            ->where('status', self::STATUS_FAILED)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        // Trip circuit breaker after 5 failures in an hour
        if ($recentFailures >= 5) {
            $agent->update(['circuit_broken_at' => now()]);

            Log::warning('Agent circuit breaker tripped', [
                'agent_id' => $agent->id,
                'recent_failures' => $recentFailures,
            ]);
        }
    }

    /**
     * Reset an agent's circuit breaker.
     */
    public function resetCircuitBreaker(Agent $agent): void
    {
        $agent->update(['circuit_broken_at' => null]);

        Log::info('Agent circuit breaker reset', [
            'agent_id' => $agent->id,
        ]);
    }

    /**
     * Handle potential token expiration errors.
     */
    protected function handlePotentialTokenError(ExecutionResult $result): void
    {
        // Check error output for token expiration patterns
        $errorText = $result->errorOutput ?? '';
        $outputText = is_array($result->output) ? json_encode($result->output) : (string) $result->output;
        $combinedText = $errorText.' '.$outputText;

        if ($this->tokenService->isTokenExpiredError($combinedText)) {
            Log::warning('Detected expired Claude OAuth token from agent execution', [
                'error_output' => substr($errorText, 0, 500),
                'output' => substr($outputText, 0, 500),
            ]);

            // Check if we should pause execution
            if ($this->tokenService->shouldPauseAgentExecution()) {
                Log::error('Agent execution paused due to repeated token refresh failures', [
                    'token_status' => $this->tokenService->getTokenStatus(),
                ]);

                return;
            }

            // Handle the token expiration (triggers refresh job and notifies admins)
            $this->tokenService->handleExpiredToken();
        }
    }

    /**
     * Cancel a pending run.
     */
    public function cancel(AgentRun $run, string $reason = 'Cancelled by user'): AgentRun
    {
        if (! in_array($run->status, ['pending', self::STATUS_RUNNING, self::STATUS_PENDING_APPROVAL, AgentRun::STATUS_AWAITING_INPUT], true)) {
            throw new \InvalidArgumentException('Can only cancel pending runs');
        }

        $previousStatus = $run->status;

        $run->update([
            'status' => self::STATUS_CANCELLED,
            'error_message' => $reason,
            'checkpoint' => null,
            'completed_at' => now(),
        ]);

        $run->interactionRequests()
            ->whereNull('responded_at')
            ->update(['expires_at' => now()]);

        // Also cancel any pending approval
        if ($run->approvalRequest) {
            $run->approvalRequest->update([
                'status' => 'cancelled',
                'decided_at' => now(),
            ]);
        }

        $freshRun = $run->fresh();

        if ($previousStatus !== self::STATUS_CANCELLED) {
            $this->broadcastStatusChange($freshRun, $previousStatus);
        }

        return $freshRun;
    }

    /**
     * Dry run (sandbox mode) - validate and preview execution without side effects.
     *
     * Returns what would happen if the agent were executed:
     * - Validation results
     * - Would require approval?
     * - Estimated cost
     * - System prompt preview
     * - Tools that would be available
     */
    public function dryRun(Agent $agent, array $config = []): array
    {
        $result = [
            'valid' => true,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'status' => $agent->status,
            ],
            'validation' => [],
            'execution_preview' => [],
            'warnings' => [],
        ];

        // Validate agent state
        $validation = $this->validateAgent($agent);
        if (! $validation['valid']) {
            $result['valid'] = false;
            $result['validation']['error'] = $validation['error'];

            return $result;
        }

        // Get execution config
        $executionConfig = $agent->getExecutionConfig();

        // Build execution preview
        $result['execution_preview'] = [
            'model' => $executionConfig['model'] ?? 'sonnet',
            'max_budget_usd' => $executionConfig['max_budget_usd'] ?? 10,
            'requires_approval' => $executionConfig['requires_approval'] ?? false,
            'tools' => $executionConfig['tools'] ?? [],
            'has_system_prompt' => ! empty($executionConfig['system_prompt']),
            'system_prompt_preview' => $this->truncatePrompt($executionConfig['system_prompt'] ?? '', 500),
        ];

        // Estimate cost (rough estimate based on model)
        $modelCostPer1kTokens = [
            'opus' => 0.075,
            'sonnet' => 0.015,
            'haiku' => 0.00075,
        ];
        $model = $executionConfig['model'] ?? 'sonnet';
        $estimatedTokens = 2000; // Average execution
        $estimatedCost = ($modelCostPer1kTokens[$model] ?? 0.015) * ($estimatedTokens / 1000);
        $result['execution_preview']['estimated_cost_usd'] = round($estimatedCost, 4);

        // Add warnings
        if (empty($executionConfig['system_prompt'])) {
            $result['warnings'][] = 'Agent has no system prompt configured';
        }

        if (empty($executionConfig['tools'])) {
            $result['warnings'][] = 'Agent has no tools configured (will run with LLM reasoning only)';
        }

        if ($agent->status !== 'active') {
            $result['warnings'][] = "Agent status is '{$agent->status}' - must be 'active' to execute";
        }

        // Check for recent failures
        $recentFailures = AgentRun::where('agent_id', $agent->id)
            ->where('status', self::STATUS_FAILED)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentFailures > 0) {
            $result['warnings'][] = "Agent has {$recentFailures} failure(s) in the last hour";
        }

        // Definition-specific validation
        $definition = $agent->getDefinition();
        if ($definition) {
            $result['has_definition'] = true;

            // Validate context against definition
            $contextValid = $definition->validateContext($config['context'] ?? []);
            if (! $contextValid) {
                $result['validation']['context'] = 'Context does not meet definition requirements';
                $result['warnings'][] = 'Context validation failed';
            }

            // Check required secrets
            $requiredSecrets = $definition->requiredSecrets();
            if (! empty($requiredSecrets)) {
                $result['execution_preview']['required_secrets'] = $requiredSecrets;
                $result['warnings'][] = 'Agent requires secrets: '.implode(', ', $requiredSecrets);
            }
        } else {
            $result['has_definition'] = false;
        }

        return $result;
    }

    /**
     * Truncate a prompt for preview.
     */
    protected function truncatePrompt(string $prompt, int $maxLength): string
    {
        if (strlen($prompt) <= $maxLength) {
            return $prompt;
        }

        return substr($prompt, 0, $maxLength).'...';
    }

    /**
     * Broadcast a run status change event.
     */
    protected function broadcastStatusChange(AgentRun $run, string $previousStatus): void
    {
        try {
            event(new AgentRunStatusChanged($run, $previousStatus));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast status change', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update run status and broadcast the change.
     */
    protected function updateRunStatus(AgentRun $run, string $newStatus, array $data = []): void
    {
        $previousStatus = $run->status;

        $run->update(array_merge(['status' => $newStatus], $data));

        if ($previousStatus !== $newStatus) {
            $this->broadcastStatusChange($run->fresh(), $previousStatus);
        }
    }

    protected function hasBeenCancelled(AgentRun $run): bool
    {
        return $run->fresh()?->status === self::STATUS_CANCELLED;
    }

    protected function cleanupExecutionWorkspace(mixed $workspace, array $config): void
    {
        $preserveWorkspace = (bool) ($config['preserve_workspace'] ?? false);

        if ($workspace && ! $preserveWorkspace) {
            $this->runner->cleanupWorkspace($workspace);
        }
    }
}
