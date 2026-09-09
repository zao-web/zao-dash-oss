<?php

namespace App\Console\Commands;

use App\Models\GitHubInstallation;
use App\Services\GitHub\GitHubAppService;
use App\Services\SelfHealing\SelfHealingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SelfHealingDiagnoseCommand extends Command
{
    protected $signature = 'self-healing:diagnose';

    protected $description = 'Diagnose self-healing configuration and test Claude CLI';

    public function handle(SelfHealingService $service, GitHubAppService $githubService): int
    {
        $this->info('Self-Healing System Diagnostics');
        $this->line('================================');
        $this->newLine();

        // Check if enabled
        $enabled = config('self-healing.enabled', false);
        $this->line('Enabled: '.($enabled ? '<fg=green>Yes</>' : '<fg=yellow>No</>'));

        // Check Claude CLI
        $claudePath = config('self-healing.agent.claude_path', 'claude');
        $this->line("Claude CLI path: {$claudePath}");

        $whichProcess = Process::run(['which', $claudePath]);
        if ($whichProcess->successful()) {
            $this->line('<fg=green>Claude CLI found at: '.trim($whichProcess->output()).'</>');

            // Get version
            $versionProcess = Process::run([$claudePath, '--version']);
            if ($versionProcess->successful()) {
                $this->line('Version: '.trim($versionProcess->output()));
            }
        } else {
            $this->error("Claude CLI NOT FOUND at '{$claudePath}'");
            $this->line('Set CLAUDE_CLI_PATH in .env to the full path');
        }

        $this->newLine();

        // Check API key
        $apiKey = config('services.anthropic.api_key');
        if ($apiKey) {
            $this->line('<fg=green>Anthropic API key: Configured</> (ends with ...'.substr($apiKey, -4).')');
        } else {
            $this->error('Anthropic API key: NOT SET');
            $this->line('Set ANTHROPIC_API_KEY in .env');
        }

        $this->newLine();

        // Check GitHub token for git push
        $this->line('GitHub Configuration:');
        $githubToken = null;
        try {
            $installation = GitHubInstallation::whereHas('repos', function ($q) {
                $q->where('full_name', 'zao-web/zao-dash');
            })->first();

            if ($installation) {
                $githubToken = $githubService->getInstallationToken($installation);
                $this->line('  <fg=green>GitHub App: Connected</> (installation '.$installation->installation_id.')');
                $this->line('  Token: Available (expires '.$installation->token_expires_at?->diffForHumans().')');
            } else {
                $this->line('  <fg=yellow>GitHub App: No installation found for zao-web/zao-dash</>');
            }
        } catch (\Exception $e) {
            $this->line('  <fg=red>GitHub App: Error - '.$e->getMessage().'</>');
        }

        // Fallback token
        if (! $githubToken && config('services.github.token')) {
            $this->line('  <fg=green>Fallback GITHUB_TOKEN: Configured</>');
        } elseif (! $githubToken) {
            $this->line('  <fg=red>No GitHub token available - git push will fail!</>');
        }

        $this->newLine();

        // Check Slack config
        $this->line('Slack Configuration:');
        $this->line('  Channel ID: '.(config('self-healing.slack_channel_id') ?: '<fg=yellow>Not set</>'));
        $this->line('  Nightwatch Bot ID: '.(config('self-healing.nightwatch_bot_id') ?: '<fg=yellow>Not set</>'));

        $this->newLine();

        // Check rate limits
        $stats = $service->getStats(24);
        $this->line('Last 24 hours:');
        $this->line("  Total attempts: {$stats['total']}");
        $this->line("  Successful: <fg=green>{$stats['success']}</>");
        $this->line("  Failed: <fg=red>{$stats['failed']}</>");
        $this->line("  Escalated: <fg=yellow>{$stats['escalated']}</>");
        $this->line("  In progress: {$stats['in_progress']}");
        $this->line('  Circuit breaker: '.($stats['circuit_breaker_open'] ? '<fg=red>OPEN</>' : '<fg=green>Closed</>'));

        $this->newLine();
        $this->line("Rate limit: {$stats['rate_limit']['hourly']['used']}/{$stats['rate_limit']['hourly']['limit']} hourly, {$stats['rate_limit']['daily']['used']}/{$stats['rate_limit']['daily']['limit']} daily");

        $this->newLine();

        // Test dry run option
        if ($this->confirm('Run a quick Claude CLI test?', false)) {
            $this->line('Testing Claude CLI...');

            $testProcess = Process::timeout(30)
                ->env(['ANTHROPIC_API_KEY' => $apiKey])
                ->run([$claudePath, '-p', 'Reply with just "OK"', '--max-turns', '1']);

            if ($testProcess->successful()) {
                $this->info('Claude CLI test passed!');
                $this->line('Output: '.trim($testProcess->output()));
            } else {
                $this->error('Claude CLI test failed');
                $this->line('Exit code: '.$testProcess->exitCode());
                $this->line('Error: '.$testProcess->errorOutput());
            }
        }

        return Command::SUCCESS;
    }
}
