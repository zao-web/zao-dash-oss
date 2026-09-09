<?php

namespace App\Jobs;

use App\Services\Agents\ClaudeTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RefreshClaudeTokenJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(ClaudeTokenService $tokenService): void
    {
        Log::info('Starting Claude OAuth token refresh job', [
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        // Increment attempt counter
        $attemptCount = $tokenService->incrementRefreshAttempts();

        // Check if token exists
        $currentToken = $tokenService->getCurrentToken();

        if (! $currentToken) {
            Log::error('No Claude OAuth token found to refresh', [
                'action_required' => 'manual_setup',
                'command' => 'claude setup-token',
            ]);

            $this->fail('No token found to refresh');

            return;
        }

        // Try to validate the token by testing it
        $isValid = $this->validateToken($currentToken);

        if ($isValid) {
            Log::info('Claude OAuth token is still valid', [
                'action' => 'no_refresh_needed',
            ]);

            // Update metadata to mark as not expired
            $tokenService->updateTokenMetadata($currentToken);

            return;
        }

        // Token is invalid - log detailed information
        Log::warning('Claude OAuth token validation failed', [
            'attempt' => $attemptCount,
            'max_attempts' => 3,
            'action_required' => 'manual_refresh',
            'instructions' => $this->getRefreshInstructions(),
        ]);

        // Check if we've exceeded max attempts
        if ($attemptCount >= 3) {
            Log::error('Max token refresh attempts reached - manual intervention required', [
                'attempts' => $attemptCount,
                'instructions' => $this->getRefreshInstructions(),
            ]);

            // At this point, admins should have been notified via ClaudeTokenService
            // The circuit breaker in AgentExecutor will prevent further agent runs
            $this->fail('Max refresh attempts reached - manual intervention required');

            return;
        }

        // Since Claude OAuth tokens require interactive browser authentication,
        // we cannot programmatically refresh them. Log instructions for manual refresh.
        Log::channel('security')->critical('Claude OAuth token refresh required - MANUAL INTERVENTION NEEDED', [
            'attempt' => $attemptCount,
            'status' => 'awaiting_manual_refresh',
            'instructions' => $this->getRefreshInstructions(),
            'impact' => 'All agent executions using Claude CLI will fail until token is refreshed',
        ]);
    }

    /**
     * Validate the token by testing it with a simple Claude CLI command.
     */
    protected function validateToken(string $token): bool
    {
        try {
            // Try to run a simple Claude CLI command to validate the token
            $result = Process::timeout(30)
                ->env([
                    'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                    'ANTHROPIC_API_KEY' => '', // Explicitly unset to force OAuth token usage
                ])
                ->run('claude --version');

            // If the command succeeds, token is valid
            if ($result->successful()) {
                Log::info('Token validation succeeded', [
                    'output' => $result->output(),
                ]);

                return true;
            }

            // Check if the error indicates auth issues
            $output = $result->output().$result->errorOutput();
            if (str_contains(strtolower($output), 'invalid api key') ||
                str_contains(strtolower($output), 'please run /login') ||
                str_contains(strtolower($output), 'unauthorized')) {
                Log::warning('Token validation failed - authentication error detected', [
                    'output' => $output,
                ]);

                return false;
            }

            // Other errors might not be token-related
            Log::warning('Token validation command failed but may not be token-related', [
                'output' => $output,
                'exit_code' => $result->exitCode(),
            ]);

            // Assume valid if we're not sure
            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to validate token - assuming invalid', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get detailed instructions for manually refreshing the token.
     */
    protected function getRefreshInstructions(): array
    {
        return [
            'step_1' => 'SSH into the server where agents run',
            'step_2' => 'Run: claude setup-token',
            'step_3' => 'Follow the browser authentication flow',
            'step_4' => 'Copy the new token from the Claude CLI output',
            'step_5' => 'Update CLAUDE_CODE_OAUTH_TOKEN in .env or via: php artisan tinker -> ClaudeTokenService->storeToken("new_token")',
            'step_6' => 'Verify with: php artisan claude:token-status',
            'alternative' => 'Or use the web UI at /admin/settings/integrations to update the token',
            'docs' => 'See /docs/claude-token-management.md for detailed instructions',
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RefreshClaudeTokenJob failed permanently', [
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'instructions' => $this->getRefreshInstructions(),
        ]);

        // Log to security channel for visibility
        Log::channel('security')->error('Claude token refresh job failed - agents may be unable to execute', [
            'exception' => $exception->getMessage(),
            'action_required' => 'URGENT - Manual token refresh required',
        ]);
    }
}
