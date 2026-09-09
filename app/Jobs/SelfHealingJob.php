<?php

namespace App\Jobs;

use App\DTOs\NightwatchError;
use App\Models\GitHubInstallation;
use App\Models\SelfHealingAttempt;
use App\Services\GitHub\GitHubAppService;
use App\Services\SelfHealing\SelfHealingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SelfHealingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // No retries - each attempt is logged separately

    public int $timeout;

    public function __construct(
        public NightwatchError $error
    ) {
        $this->timeout = config('self-healing.agent.timeout', 300);
    }

    public function handle(SelfHealingService $service): void
    {
        // Final safety check
        if (! $service->shouldAttemptFix($this->error)) {
            Log::info('Self-healing: Pre-flight check failed, skipping', [
                'signature' => $this->error->getSignature(),
            ]);

            return;
        }

        // Create attempt record
        $attempt = $service->createAttempt($this->error);

        try {
            // Post initial status
            $service->postSlackUpdate($attempt,
                "🤖 *Self-healing triggered*\n\n".
                'Analyzing error and preparing fix...'
            );

            // Mark as in progress
            $attempt->markInProgress();

            // Execute the fix
            $result = $this->executeDevAgent($attempt);
            $agentOutput = $result['raw_output'] ?? null;

            if ($result['success']) {
                $attempt->markSuccess(
                    commitSha: $result['commit_sha'],
                    commitUrl: $result['commit_url'],
                    fixDescription: $result['description'] ?? null,
                    agentOutput: $agentOutput
                );

                $service->postSlackUpdate($attempt, sprintf(
                    "✅ *Fixed and deployed!*\n\n".
                    "*Fix:* %s\n".
                    "*Commit:* <%s|%s>\n\n".
                    'The fix will be live after deployment completes (~2 min).',
                    $result['description'] ?? 'See commit for details',
                    $result['commit_url'],
                    substr($result['commit_sha'], 0, 7)
                ));

                Log::info('Self-healing: Fix successful', [
                    'attempt_id' => $attempt->id,
                    'commit' => $result['commit_sha'],
                ]);

            } else {
                // Determine if we should escalate or just fail
                if ($result['should_escalate'] ?? false) {
                    $service->escalate($attempt, $result['reason'] ?? 'Unknown error', $agentOutput);
                } else {
                    $attempt->markFailed($result['reason'] ?? 'Unknown error', $agentOutput);

                    $service->postSlackUpdate($attempt, sprintf(
                        "❌ *Self-healing couldn't fix this automatically*\n\n*Reason:* %s\n\nManual intervention required.",
                        $result['reason'] ?? 'Unknown error'
                    ));
                }

                // Check if circuit breaker should open
                if ($service->isCircuitBreakerOpen()) {
                    $service->notifyCircuitBreakerOpen();
                }
            }

        } catch (\Exception $e) {
            Log::error('Self-healing: Exception during fix', [
                'attempt_id' => $attempt->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $attempt->markFailed($e->getMessage());

            $service->postSlackUpdate($attempt, sprintf(
                "❌ *Fix attempt failed with exception*\n\n```%s```",
                substr($e->getMessage(), 0, 500)
            ));

            if ($service->isCircuitBreakerOpen()) {
                $service->notifyCircuitBreakerOpen();
            }
        }
    }

    /**
     * Execute the Dev Agent to fix the error.
     */
    protected function executeDevAgent(SelfHealingAttempt $attempt): array
    {
        $task = $this->buildAgentTask();
        $workspacePath = base_path();

        Log::info('Self-healing: Executing Dev Agent', [
            'attempt_id' => $attempt->id,
            'task_length' => strlen($task),
        ]);

        // Build the Claude CLI command
        $claudePath = config('self-healing.agent.claude_path', 'claude');

        // Verify Claude CLI exists
        $whichProcess = Process::run(['which', $claudePath]);
        if (! $whichProcess->successful()) {
            Log::error('Self-healing: Claude CLI not found', [
                'claude_path' => $claudePath,
                'error' => $whichProcess->errorOutput(),
            ]);

            return [
                'success' => false,
                'reason' => "Claude CLI not found at '{$claudePath}'. Set CLAUDE_CLI_PATH env var.",
                'should_escalate' => true,
            ];
        }

        $command = [
            $claudePath,
            '--print', 'all',
            '--output-format', 'json',
            '--max-turns', '30',
            '--dangerously-skip-permissions',
            '-p', $task,
        ];

        // Get GitHub token for git push authentication
        $githubToken = $this->getGitHubToken();
        if (! $githubToken) {
            Log::warning('Self-healing: No GitHub token available, git push may fail');
        }

        Log::debug('Self-healing: Running Claude CLI', [
            'command' => implode(' ', array_slice($command, 0, 5)).'...',
            'timeout' => $this->timeout,
            'workspace' => $workspacePath,
            'has_github_token' => ! empty($githubToken),
        ]);

        // Build environment - prefer OAuth token (Max subscription) over API key
        $env = [];
        $oauthToken = config('services.anthropic.oauth_token') ?: env('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = config('services.anthropic.api_key');

        if ($oauthToken) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $oauthToken;
            // Explicitly unset API key to prevent inheritance from server environment
            $env['ANTHROPIC_API_KEY'] = '';
        } elseif ($apiKey) {
            $env['ANTHROPIC_API_KEY'] = $apiKey;
        }

        // Add GitHub token for git push via HTTPS
        if ($githubToken) {
            $env['GH_TOKEN'] = $githubToken;
            $env['GITHUB_TOKEN'] = $githubToken;
        }

        $process = Process::timeout($this->timeout)
            ->path($workspacePath)
            ->env($env)
            ->run($command);

        if (! $process->successful()) {
            $exitCode = $process->exitCode();
            $stderr = substr($process->errorOutput(), 0, 1000);
            $stdout = substr($process->output(), 0, 500);

            Log::error('Self-healing: Agent process failed', [
                'attempt_id' => $attempt->id,
                'exit_code' => $exitCode,
                'stderr' => $stderr,
                'stdout_preview' => $stdout,
                'timed_out' => $exitCode === null,
            ]);

            $reason = $exitCode === null
                ? "Agent timed out after {$this->timeout}s"
                : "Agent process failed (exit {$exitCode}): {$stderr}";

            return [
                'success' => false,
                'reason' => $reason,
                'should_escalate' => true,
            ];
        }

        // Parse the agent output
        return $this->parseAgentOutput($process->output(), $attempt);
    }

    /**
     * Build the task prompt for the Dev Agent.
     */
    protected function buildAgentTask(): string
    {
        $prefix = config('self-healing.agent.commit_prefix', '[auto-fix]');
        $runTests = config('self-healing.agent.run_tests', true) ? 'Yes' : 'No';

        return <<<TASK
You are fixing a production error in zao-dash. This is an automated self-healing task.

## Error Details

**Exception Class:** {$this->error->exceptionClass}
**Error Message:** {$this->error->message}
**Source Job/Controller:** {$this->error->sourceJob}
**File:** {$this->error->file}
**Line:** {$this->error->line}
**Occurrences:** {$this->error->occurrenceCount} time(s)

## Instructions

1. **Analyze** the error message and identify the root cause
2. **Search** the codebase to understand the context
3. **Implement** a fix:
   - If "column does not exist" → Create a database migration
   - If "class does not exist" → Create the class OR fix the import/namespace
   - If "undefined method" → Add the method OR fix the method call
   - If "type error" → Fix the type mismatch
4. **Run tests** to verify no regressions: `php -l` for syntax, optionally `php artisan test`
5. **Commit** with message: "{$prefix} {brief description of fix}"
6. **Push** to main branch using: `git push https://x-access-token:\${GITHUB_TOKEN}@github.com/zao-web/zao-dash.git HEAD:main`
   (The GITHUB_TOKEN env var is pre-configured)

## Safety Rules

- ONLY fix the specific error, nothing else
- Do NOT make unrelated changes or "improvements"
- Do NOT modify any config files unless directly related
- If the fix isn't obvious, report that and do NOT commit
- If you're unsure, output "ESCALATE: {reason}" instead of making changes

## Output Format

After completing the fix, output a JSON block with the result:

```json
{
  "success": true,
  "commit_sha": "abc123...",
  "description": "Brief description of what was fixed"
}
```

Or if unable to fix:

```json
{
  "success": false,
  "reason": "Explanation of why it couldn't be fixed",
  "should_escalate": true
}
```

Run tests before committing: {$runTests}

Begin analysis now.
TASK;
    }

    /**
     * Parse the Dev Agent output to extract results.
     */
    protected function parseAgentOutput(string $output, SelfHealingAttempt $attempt): array
    {
        // Always include raw output for debugging
        $baseResult = ['raw_output' => $output];

        // Look for JSON result block in output (multiple patterns)
        $jsonPatterns = [
            '/```json\s*(\{.+?\})\s*```/s',           // Standard code block
            '/```\s*(\{.+?"success".+?\})\s*```/s',   // Code block without json marker
            '/(\{[^{}]*"success"\s*:\s*(true|false)[^{}]*\})/s', // Inline JSON
        ];

        foreach ($jsonPatterns as $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                try {
                    $result = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

                    if (isset($result['success'])) {
                        // If successful, verify we have a commit
                        if ($result['success'] && empty($result['commit_sha'])) {
                            $commitInfo = $this->getLatestCommit();
                            if ($commitInfo) {
                                $result['commit_sha'] = $commitInfo['sha'];
                                $result['commit_url'] = $commitInfo['url'];
                            }
                        }

                        return array_merge($baseResult, $result);
                    }
                } catch (\JsonException $e) {
                    Log::warning('Self-healing: Failed to parse agent JSON output', [
                        'error' => $e->getMessage(),
                        'json_candidate' => substr($matches[1], 0, 200),
                    ]);
                }
            }
        }

        // Check for ESCALATE marker (explicit escalation request)
        if (preg_match('/ESCALATE:\s*(.+)/i', $output, $matches)) {
            return array_merge($baseResult, [
                'success' => false,
                'reason' => trim($matches[1]),
                'should_escalate' => true,
            ]);
        }

        // IMPORTANT: Check if a commit was made BEFORE checking failure indicators
        // The agent might say "complex" while describing the problem but still fix it
        $commitInfo = $this->getLatestCommit();
        $prefix = config('self-healing.agent.commit_prefix', '[auto-fix]');

        if ($commitInfo && str_contains($commitInfo['message'], $prefix)) {
            return array_merge($baseResult, [
                'success' => true,
                'commit_sha' => $commitInfo['sha'],
                'commit_url' => $commitInfo['url'],
                'description' => trim(str_replace($prefix, '', $commitInfo['message'])),
            ]);
        }

        // Only check failure indicators if no commit was made
        // Use more specific patterns to reduce false positives
        $failureIndicators = [
            '/(?:I\s+)?(?:cannot|can\'t|am unable to|don\'t know how to)\s+(?:fix|resolve|address)\s+this/i' => 'Agent indicated it cannot fix this error',
            '/(?:I\s+)?(?:need|require|am missing)\s+(?:more )?(?:context|information|details)\s+(?:to|about)/i' => 'Agent needs more context to fix',
            '/(?:I\'m\s+)?(?:not sure|unclear|uncertain)\s+(?:how|what|about)/i' => 'Agent was uncertain how to proceed',
            '/(?:this|the)\s+(?:fix|error|issue)\s+(?:is\s+)?(?:too\s+)?(?:complex|complicated)/i' => 'Fix is too complex for automation',
            '/requires?\s+manual\s+(?:intervention|review|fix)/i' => 'Requires manual intervention',
        ];

        foreach ($failureIndicators as $pattern => $reason) {
            if (preg_match($pattern, $output)) {
                return array_merge($baseResult, [
                    'success' => false,
                    'reason' => $reason,
                    'should_escalate' => true,
                ]);
            }
        }

        // Extract a more helpful failure reason from the output
        $reason = 'Agent completed but no fix was committed.';

        // Try to find Claude's conclusion
        if (preg_match('/(?:conclusion|summary|result|therefore|in summary)[:\s]+(.{20,200})/i', $output, $matches)) {
            $reason .= ' Agent conclusion: '.trim($matches[1]);
        }

        return array_merge($baseResult, [
            'success' => false,
            'reason' => $reason,
            'should_escalate' => true,
        ]);
    }

    /**
     * Get the latest commit info from git.
     */
    protected function getLatestCommit(): ?array
    {
        $process = Process::path(base_path())
            ->run(['git', 'log', '-1', '--format=%H|%s']);

        if (! $process->successful()) {
            return null;
        }

        $parts = explode('|', trim($process->output()), 2);
        if (count($parts) !== 2) {
            return null;
        }

        $sha = $parts[0];
        $message = $parts[1];

        // Get repo URL for commit link
        $remoteProcess = Process::path(base_path())
            ->run(['git', 'remote', 'get-url', 'origin']);

        $remoteUrl = trim($remoteProcess->output());
        $repoUrl = preg_replace('/\.git$/', '', $remoteUrl);
        $repoUrl = preg_replace('/^git@github\.com:/', 'https://github.com/', $repoUrl);

        return [
            'sha' => $sha,
            'message' => $message,
            'url' => "{$repoUrl}/commit/{$sha}",
        ];
    }

    /**
     * Get a GitHub token for git push authentication.
     * Uses GitHub App installation token if available.
     */
    protected function getGitHubToken(): ?string
    {
        // First, try to get installation token for zao-dash repo
        try {
            $installation = GitHubInstallation::whereHas('repos', function ($q) {
                $q->where('full_name', 'zao-web/zao-dash');
            })->first();

            if ($installation) {
                $githubService = app(GitHubAppService::class);
                $token = $githubService->getInstallationToken($installation);

                Log::debug('Self-healing: Using GitHub App installation token', [
                    'installation_id' => $installation->id,
                ]);

                return $token;
            }
        } catch (\Exception $e) {
            Log::warning('Self-healing: Failed to get GitHub App token', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to env token if configured
        $envToken = config('services.github.token');
        if ($envToken) {
            Log::debug('Self-healing: Using GITHUB_TOKEN from env');

            return $envToken;
        }

        return null;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SelfHealingJob failed permanently', [
            'error_signature' => $this->error->getSignature(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
