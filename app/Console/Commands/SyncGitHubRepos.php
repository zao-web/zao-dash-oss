<?php

namespace App\Console\Commands;

use App\Models\GitHubInstallation;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Console\Command;

class SyncGitHubRepos extends Command
{
    protected $signature = 'github:sync-repos 
                            {--installation= : Sync only a specific installation ID}
                            {--account= : Sync only installations matching this account login}';

    protected $description = 'Sync GitHub repositories from all installations (or a specific one)';

    public function handle(GitHubAppService $githubService): int
    {
        $query = GitHubInstallation::query();

        if ($installationId = $this->option('installation')) {
            $query->where('installation_id', $installationId);
        }

        if ($account = $this->option('account')) {
            $query->where('account_login', $account);
        }

        $installations = $query->get();

        if ($installations->isEmpty()) {
            $this->error('No GitHub installations found matching criteria.');

            return self::FAILURE;
        }

        $this->info("Found {$installations->count()} installation(s) to sync.");

        $totalRepos = 0;

        foreach ($installations as $installation) {
            $this->line("Syncing repos for: {$installation->account_login} (ID: {$installation->installation_id})");

            try {
                $count = $githubService->syncRepos($installation);
                $this->info("  Synced {$count} repositories.");
                $totalRepos += $count;
            } catch (\Exception $e) {
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Total repositories synced: {$totalRepos}");

        return self::SUCCESS;
    }
}
