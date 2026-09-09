<?php

namespace App\Console\Commands\Claude;

use App\Services\Agents\ClaudeTokenService;
use Illuminate\Console\Command;

class TokenStatusCommand extends Command
{
    protected $signature = 'claude:token-status
                            {--refresh : Attempt to refresh the token if needed}
                            {--json : Output status as JSON}';

    protected $description = 'Check the status of the Claude OAuth token used for agent execution';

    public function handle(ClaudeTokenService $tokenService): int
    {
        $status = $tokenService->getTokenStatus();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return $status['is_expired'] ? self::FAILURE : self::SUCCESS;
        }

        // Display status information
        $this->info('Claude OAuth Token Status');
        $this->newLine();

        // Token availability
        if ($status['has_token']) {
            $this->line('✓ Token found: <fg=green>Yes</>');
        } else {
            $this->line('✗ Token found: <fg=red>No</>');
            $this->error('No token configured. Run: claude setup-token');

            return self::FAILURE;
        }

        // Expiration status
        if ($status['is_expired']) {
            $this->line('✗ Token status: <fg=red>EXPIRED</>');
            $this->warn('Token has expired and needs to be refreshed');
        } else {
            $this->line('✓ Token status: <fg=green>Valid</>');
        }

        // Days until expiration
        if ($status['days_until_expiration'] !== null) {
            $days = $status['days_until_expiration'];
            $color = $days < 7 ? 'yellow' : 'green';
            $this->line("  Days until expiration: <fg={$color}>{$days} days</>");
        }

        // Needs refresh
        if ($status['needs_refresh']) {
            $this->line('⚠ Needs refresh: <fg=yellow>Yes</>');
        } else {
            $this->line('✓ Needs refresh: <fg=green>No</>');
        }

        // Execution pause status
        if ($status['should_pause_execution']) {
            $this->line('✗ Agent execution: <fg=red>PAUSED</>');
            $this->error('Agent execution has been paused due to repeated token refresh failures');
            $this->warn('Refresh attempts: '.$status['refresh_attempts']);
        } else {
            $this->line('✓ Agent execution: <fg=green>Active</>');
        }

        // Last refresh
        if ($status['last_refreshed_at']) {
            $this->line('  Last refreshed: '.$status['last_refreshed_at']);
        }

        // Expiration date
        if ($status['expires_at']) {
            $this->line('  Expires at: '.$status['expires_at']);
        }

        // Last error
        if ($status['last_error']) {
            $this->newLine();
            $this->warn('Last error: '.$status['last_error']);
        }

        $this->newLine();

        // Show refresh instructions if needed
        if ($status['is_expired'] || $status['needs_refresh']) {
            $this->warn('Token refresh required!');
            $this->newLine();
            $this->info('To refresh the token:');
            $this->line('  1. Run: <fg=cyan>claude setup-token</>');
            $this->line('  2. Follow the browser authentication flow');
            $this->line('  3. The new token will be automatically saved');
            $this->newLine();

            if ($this->option('refresh')) {
                $this->info('Triggering token refresh job...');
                \App\Jobs\RefreshClaudeTokenJob::dispatch();
                $this->info('Refresh job dispatched. Check logs for progress.');
            } else {
                $this->comment('Tip: Use --refresh to attempt automatic refresh');
            }
        }

        // Return exit code based on status
        if ($status['is_expired'] || $status['should_pause_execution']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
