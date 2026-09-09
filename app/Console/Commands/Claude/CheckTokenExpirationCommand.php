<?php

namespace App\Console\Commands\Claude;

use App\Jobs\RefreshClaudeTokenJob;
use App\Services\Agents\ClaudeTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTokenExpirationCommand extends Command
{
    protected $signature = 'claude:check-token-expiration
                            {--force : Force refresh even if not needed}';

    protected $description = 'Check if the Claude OAuth token needs refresh and trigger refresh job if needed';

    public function handle(ClaudeTokenService $tokenService): int
    {
        $this->info('Checking Claude OAuth token expiration...');

        $status = $tokenService->getTokenStatus();

        // Log current status
        Log::info('Claude token expiration check', [
            'has_token' => $status['has_token'],
            'is_expired' => $status['is_expired'],
            'needs_refresh' => $status['needs_refresh'],
            'days_until_expiration' => $status['days_until_expiration'],
        ]);

        // Check if token exists
        if (! $status['has_token']) {
            $this->error('No Claude OAuth token found');
            Log::warning('No Claude OAuth token configured');

            return self::FAILURE;
        }

        // Check if already expired
        if ($status['is_expired']) {
            $this->warn('Token is expired - triggering refresh job');
            Log::warning('Claude OAuth token is expired - triggering refresh', [
                'last_error' => $status['last_error'],
                'refresh_attempts' => $status['refresh_attempts'],
            ]);

            RefreshClaudeTokenJob::dispatch();
            $this->info('Refresh job dispatched');

            return self::SUCCESS;
        }

        // Check if needs refresh
        if ($status['needs_refresh'] || $this->option('force')) {
            $reason = $this->option('force') ? 'forced by --force option' : 'approaching expiration';
            $this->warn("Token needs refresh ({$reason}) - triggering refresh job");

            Log::info('Claude OAuth token needs refresh - triggering proactive refresh', [
                'days_until_expiration' => $status['days_until_expiration'],
                'forced' => $this->option('force'),
            ]);

            RefreshClaudeTokenJob::dispatch();
            $this->info('Refresh job dispatched');

            return self::SUCCESS;
        }

        // Token is healthy
        $this->info('✓ Token is healthy - no refresh needed');
        if ($status['days_until_expiration'] !== null) {
            $this->line("  Days until expiration: {$status['days_until_expiration']}");
        }

        return self::SUCCESS;
    }
}
