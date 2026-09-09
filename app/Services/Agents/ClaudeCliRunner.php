<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Executes agents via Claude CLI in sandboxed workspaces.
 *
 * Handles:
 * - Workspace creation and cleanup
 * - Tool allowlist enforcement
 * - Process isolation and timeouts
 * - Cost and token tracking
 * - Output parsing
 */
class ClaudeCliRunner
{
    protected string $workspacesPath;

    protected int $defaultTimeoutSeconds = 600; // 10 minutes

    protected int $maxTimeoutSeconds = 1800; // 30 minutes

    public function __construct()
    {
        $this->workspacesPath = storage_path('app/agent-workspaces');
    }

    /**
     * Execute an agent with the given configuration.
     */
    public function execute(Agent $agent, AgentRun $run, array $config = []): ExecutionResult
    {
        $workspace = $this->createWorkspace($run, $config);

        try {
            // Build the prompt from system prompt + user context
            $prompt = $this->buildPrompt($agent, $config);

            // Build CLI command with tool allowlist
            $command = $this->buildCommand($agent, $prompt, $workspace);

            Log::info('Executing agent', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'workspace' => $workspace,
            ]);

            $startTime = microtime(true);

            // Execute with timeout
            $timeout = min(
                $config['timeout_seconds'] ?? $this->defaultTimeoutSeconds,
                $this->maxTimeoutSeconds
            );

            $envVars = $this->buildEnv($agent, $config);
            Log::info('Claude CLI execution starting', [
                'agent_id' => $agent->id,
                'agent_slug' => $agent->slug,
                'env_keys' => array_keys($envVars),
                'has_anthropic_key' => ! empty($envVars['ANTHROPIC_API_KEY']),
                'has_oauth_token' => ! empty($envVars['CLAUDE_CODE_OAUTH_TOKEN']),
                'has_github_token' => ! empty($envVars['GITHUB_TOKEN']),
                'has_gh_token' => ! empty($envVars['GH_TOKEN']),
            ]);

            Log::debug('Claude CLI command', [
                'command' => $command,
                'workspace' => $workspace,
                'timeout' => $timeout,
            ]);

            $result = Process::timeout($timeout)
                ->path($workspace)
                ->env($this->buildEnv($agent, $config))
                ->input($prompt)
                ->run($command);

            $duration = microtime(true) - $startTime;

            Log::debug('Claude CLI result', [
                'exit_code' => $result->exitCode(),
                'output_length' => strlen($result->output()),
                'error_output' => substr($result->errorOutput(), 0, 1000),
                'output_preview' => substr($result->output(), 0, 500),
            ]);

            // Parse output
            $output = $this->parseOutput($result->output());
            $usage = $this->extractUsage($result->output());

            return new ExecutionResult(
                success: $result->successful(),
                output: $output,
                rawOutput: $result->output(),
                errorOutput: $result->errorOutput(),
                exitCode: $result->exitCode(),
                durationSeconds: $duration,
                tokensUsed: $usage['tokens'] ?? 0,
                costUsd: $usage['cost'] ?? 0.0,
                workspace: $workspace,
            );

        } catch (ProcessTimedOutException $e) {
            Log::warning('Agent execution timed out', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
            ]);

            return new ExecutionResult(
                success: false,
                output: ['error' => 'Execution timed out'],
                rawOutput: '',
                errorOutput: 'Process exceeded timeout limit',
                exitCode: 124,
                durationSeconds: $timeout ?? $this->defaultTimeoutSeconds,
                tokensUsed: 0,
                costUsd: 0.0,
                workspace: $workspace,
            );

        } catch (\Throwable $e) {
            Log::error('Agent execution failed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return new ExecutionResult(
                success: false,
                output: ['error' => $e->getMessage()],
                rawOutput: '',
                errorOutput: $e->getMessage(),
                exitCode: 1,
                durationSeconds: 0,
                tokensUsed: 0,
                costUsd: 0.0,
                workspace: $workspace,
            );
        }
    }

    /**
     * Create isolated workspace for this run.
     */
    protected function createWorkspace(AgentRun $run, array $config = []): string
    {
        $configuredPath = $config['workspace_path'] ?? null;
        $path = is_string($configuredPath) && trim($configuredPath) !== ''
            ? $configuredPath
            : $this->workspacesPath.'/'.$run->id.'-'.Str::random(8);

        File::ensureDirectoryExists($path);

        // Create basic workspace structure
        File::ensureDirectoryExists($path.'/input');
        File::ensureDirectoryExists($path.'/output');

        return $path;
    }

    /**
     * Clean up workspace after execution.
     */
    public function cleanupWorkspace(string $workspace): void
    {
        if (File::isDirectory($workspace) && str_starts_with($workspace, $this->workspacesPath)) {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * Build the full prompt for execution.
     */
    protected function buildPrompt(Agent $agent, array $config): string
    {
        $definition = $agent->getDefinition();
        $systemPrompt = $definition?->systemPrompt() ?? $agent->system_prompt ?? '';

        // Add context from config
        $context = $config['context'] ?? '';
        if (is_array($context)) {
            $context = json_encode($context, JSON_PRETTY_PRINT);
        }

        $userPrompt = $config['prompt'] ?? '';

        return <<<PROMPT
{$systemPrompt}

---

## Context
{$context}

## Task
{$userPrompt}
PROMPT;
    }

    /**
     * Build the Claude CLI command.
     */
    protected function buildCommand(Agent $agent, string $prompt, string $workspace): string
    {
        $model = $agent->model ?? 'sonnet';

        // Get allowed tools from definition or agent config
        $definition = $agent->getDefinition();
        $tools = $definition?->allowedTools() ?? $agent->tools ?? [];

        // Build tool allowlist flags
        $toolFlags = $this->buildToolFlags($tools);

        // Escape the prompt for shell
        $escapedPrompt = escapeshellarg($prompt);

        // Build command - using claude CLI
        // Prefer OAuth token (Max subscription) over API key billing
        $oauthToken = env('CLAUDE_CODE_OAUTH_TOKEN') ?: getenv('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = env('ANTHROPIC_API_KEY') ?: getenv('ANTHROPIC_API_KEY');

        if ($oauthToken) {
            // OAuth token = Max subscription (no per-token billing)
            $command = 'claude --print --output-format json --dangerously-skip-permissions';
        } elseif ($apiKey) {
            // Fall back to API key if no OAuth token
            $command = "ANTHROPIC_API_KEY={$apiKey} claude --print --output-format json --dangerously-skip-permissions";
        } else {
            // No auth configured - will fail but let CLI report the error
            $command = 'claude --print --output-format json --dangerously-skip-permissions';
        }

        // Set model
        $command .= " --model {$model}";

        if ($toolFlags) {
            $command .= " {$toolFlags}";
        }

        // Set max budget if specified
        if ($agent->max_budget) {
            $command .= " --max-budget-usd {$agent->max_budget}";
        }

        return $command;
    }

    /**
     * Build tool allowlist flags for CLI.
     */
    protected function buildToolFlags(array $tools): string
    {
        if (empty($tools)) {
            return '--allowedTools ""'; // No tools allowed
        }

        // Map our tool names to Claude CLI tool names
        $toolMapping = [
            'web_search' => 'WebSearch',
            'web-search' => 'WebSearch',
            'code_exec' => 'Bash',
            'file_ops' => 'Read,Write,Edit',
            'api_calls' => 'WebFetch',
            'email' => 'WebFetch', // Email via API
            'slack' => 'WebFetch', // Slack via API
            'github' => 'Bash', // GitHub via CLI
            'database' => 'Bash', // Database via CLI
            // Website builder tools need Bash for curl API calls
            'website-builder-update-progress' => 'Bash',
            'website-builder-broadcast-message' => 'Bash',
            'website-builder-project-status' => 'Bash',
            'website-builder-parse-brief' => 'Bash,Read',
            'website-builder-analyze-site' => 'Bash,WebFetch',
            'website-builder-analyze-repo' => 'Bash',
            'website-builder-analyze-pages' => 'Bash,WebFetch',
            'website-builder-generate-theme' => 'Bash,Write',
            'website-builder-list-patterns' => 'Bash',
            'website-builder-compose-page' => 'Bash,Write',
            'website-builder-update-template-part' => 'Bash',
            'website-builder-update-global-styles' => 'Bash',
            'website-builder-upload-theme-file' => 'Bash',
            'website-builder-upload-media' => 'Bash,WebFetch',
            'website-builder-extract-content' => 'Bash,Read,WebFetch',
            'website-builder-download-assets' => 'Bash,WebFetch,Write',
            'website-builder-deploy' => 'Bash',
        ];

        $cliTools = [];
        foreach ($tools as $tool) {
            if (isset($toolMapping[$tool])) {
                $mapped = explode(',', $toolMapping[$tool]);
                $cliTools = array_merge($cliTools, $mapped);
            }
        }

        $cliTools = array_unique($cliTools);

        if (empty($cliTools)) {
            return '';
        }

        return '--allowedTools "'.implode(',', $cliTools).'"';
    }

    /**
     * Build environment variables for execution.
     */
    protected function buildEnv(Agent $agent, array $config): array
    {
        $env = [
            'AGENT_ID' => $agent->id,
            'AGENT_SLUG' => $agent->slug,
        ];

        // Prefer OAuth token (Max subscription) over API key
        $oauthToken = env('CLAUDE_CODE_OAUTH_TOKEN') ?: getenv('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = env('ANTHROPIC_API_KEY') ?: getenv('ANTHROPIC_API_KEY');

        if ($oauthToken) {
            // OAuth token = Max subscription (no per-token billing)
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $oauthToken;
            // Explicitly unset API key to prevent inheritance from server environment
            $env['ANTHROPIC_API_KEY'] = '';
        } elseif ($apiKey) {
            // Fall back to API key only if no OAuth token
            $env['ANTHROPIC_API_KEY'] = $apiKey;
        }

        // Add secrets from config['secrets'] (direct injection)
        $secrets = $config['secrets'] ?? [];
        foreach ($secrets as $key => $value) {
            $env[strtoupper($key)] = $value;
        }

        // Also check context.credentials (from TaskAgentService)
        $credentials = $config['context']['credentials'] ?? [];
        foreach ($credentials as $key => $value) {
            $env[strtoupper($key)] = $value;
        }

        // GitHub CLI uses GH_TOKEN, git uses GITHUB_TOKEN - set both
        if (isset($env['GITHUB_TOKEN']) && ! isset($env['GH_TOKEN'])) {
            $env['GH_TOKEN'] = $env['GITHUB_TOKEN'];
        } elseif (isset($env['GH_TOKEN']) && ! isset($env['GITHUB_TOKEN'])) {
            $env['GITHUB_TOKEN'] = $env['GH_TOKEN'];
        }

        // Agent internal API token for callbacks
        $agentToken = config('services.agent.internal_token') ?: env('AGENT_INTERNAL_TOKEN');
        if ($agentToken) {
            $env['AGENT_INTERNAL_TOKEN'] = $agentToken;
        }

        // App URL for API callbacks
        $env['APP_URL'] = config('app.url');

        return $env;
    }

    /**
     * Parse JSON output from Claude CLI.
     */
    protected function parseOutput(string $output): array
    {
        // Claude CLI with --output-format json returns JSON
        $lines = explode("\n", trim($output));

        // Try to find JSON in output
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        // Fall back to structured response
        return [
            'response' => $output,
            'parsed' => false,
        ];
    }

    /**
     * Extract usage statistics from output.
     */
    protected function extractUsage(string $output): array
    {
        // Look for usage info in output
        // Format varies by CLI version, this is a basic implementation
        $tokens = 0;
        $cost = 0.0;

        // Try to extract from JSON output
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            $tokens = $decoded['usage']['total_tokens'] ?? 0;
            $cost = $decoded['usage']['cost_usd'] ?? 0.0;
        }

        return [
            'tokens' => $tokens,
            'cost' => $cost,
        ];
    }
}
