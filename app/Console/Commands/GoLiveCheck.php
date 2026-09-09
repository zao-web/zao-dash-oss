<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GoogleCredential;
use App\Models\HarvestCredential;
use App\Models\NotionConnection;
use App\Models\PmConnection;
use App\Models\QuickBooksConnection;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Models\WordPressSite;
use Illuminate\Console\Command;

class GoLiveCheck extends Command
{
    protected $signature = 'app:go-live-check';

    protected $description = 'Verify app is ready for production go-live';

    public function handle(): int
    {
        $this->info('Running Go-Live Pre-Flight Check...');
        $this->newLine();

        $checks = [
            'Seeded data cleaned' => fn () => Client::whereNotNull('seeded_at')->count() === 0,
            'Owner user exists' => fn () => User::where('role', 'owner')->exists(),
            'QuickBooks connected' => fn () => QuickBooksConnection::where('is_active', true)->exists(),
            'Google connected' => fn () => GoogleCredential::exists(),
            'Slack connected' => fn () => SlackWorkspace::exists(),
            'GitHub connected' => fn () => GitHubInstallation::exists(),
            'Harvest connected' => fn () => HarvestCredential::exists(),
            'Notion connected' => fn () => NotionConnection::exists(),
            'WordPress configured' => fn () => WordPressSite::exists(),
            'ClickUp connected' => fn () => PmConnection::where('provider', 'clickup')->exists(),
            'All syncs completed' => fn () => $this->allSyncsComplete(),
            'Wise configured' => fn () => ! empty(config('services.wise.api_key')),
            'Mail configured' => fn () => ! empty(config('mail.mailers.smtp.host')),
            'Queue worker ready' => fn () => config('queue.default') !== 'sync',
        ];

        $failed = 0;
        $warnings = 0;

        // Required checks
        $requiredChecks = [
            'Seeded data cleaned',
            'Owner user exists',
            'QuickBooks connected',
            'All syncs completed',
        ];

        foreach ($checks as $name => $check) {
            try {
                $passed = $check();
            } catch (\Exception $e) {
                $passed = false;
            }

            $isRequired = in_array($name, $requiredChecks);
            $status = $passed ? '<fg=green>✓</>' : ($isRequired ? '<fg=red>✗</>' : '<fg=yellow>⚠</>');

            $this->line("  {$status} {$name}");

            if (! $passed) {
                if ($isRequired) {
                    $failed++;
                } else {
                    $warnings++;
                }
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("{$failed} required checks failed. Fix before going live.");

            return 1;
        }

        if ($warnings > 0) {
            $this->warn("{$warnings} optional integrations not connected.");
            $this->info('You can proceed, but some features may be limited.');
        } else {
            $this->info('All checks passed! Ready for production.');
        }

        return 0;
    }

    protected function allSyncsComplete(): bool
    {
        // Check if any connection has a failed or in-progress sync
        $connectionTables = [
            QuickBooksConnection::class,
            GoogleCredential::class,
            SlackWorkspace::class,
            GitHubInstallation::class,
            HarvestCredential::class,
            NotionConnection::class,
        ];

        foreach ($connectionTables as $model) {
            $pending = $model::where('sync_status', 'syncing')->exists();
            if ($pending) {
                return false;
            }
        }

        return true;
    }
}
