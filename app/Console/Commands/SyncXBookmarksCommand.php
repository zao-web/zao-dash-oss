<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeXBookmarksJob;
use App\Jobs\SyncXBookmarksJob;
use App\Models\XCredential;
use App\Services\X\XService;
use Illuminate\Console\Command;

class SyncXBookmarksCommand extends Command
{
    protected $signature = 'x:sync-bookmarks
        {--user= : Sync for specific user ID}
        {--credential= : Sync specific credential ID}
        {--force : Force sync even if within 24h cooldown}
        {--analyze : Also run analysis after sync}
        {--sync : Run synchronously instead of queuing}';

    protected $description = 'Sync X bookmarks for analysis and PR generation';

    public function handle(XService $xService): int
    {
        $credentials = $this->getCredentials();

        if ($credentials->isEmpty()) {
            $this->warn('No X credentials found with bookmark.read scope.');
            $this->info('Users need to reconnect their X account to grant bookmark permissions.');

            return Command::SUCCESS;
        }

        $this->info("Found {$credentials->count()} credential(s) to sync.");

        foreach ($credentials as $credential) {
            if (! $xService->hasBookmarkScope($credential)) {
                $this->warn("[@{$credential->username}] Missing bookmark.read scope - skipping");

                continue;
            }

            $this->info("[@{$credential->username}] Syncing bookmarks...");

            if ($this->option('sync')) {
                try {
                    $job = new SyncXBookmarksJob($credential->id, $this->option('force'));
                    $job->handle($xService);
                    $this->info("[@{$credential->username}] Sync completed.");

                    if ($this->option('analyze')) {
                        $this->info("[@{$credential->username}] Running analysis...");
                        $analyzeJob = new AnalyzeXBookmarksJob($credential->id);
                        $analyzeJob->handle();
                        $this->info("[@{$credential->username}] Analysis completed.");
                    }
                } catch (\Exception $e) {
                    $this->error("[@{$credential->username}] Error: ".$e->getMessage());
                }
            } else {
                SyncXBookmarksJob::dispatch($credential->id, $this->option('force'));
                $this->info("[@{$credential->username}] Queued for sync.");
            }
        }

        return Command::SUCCESS;
    }

    protected function getCredentials()
    {
        $query = XCredential::where('is_active', true);

        if ($credentialId = $this->option('credential')) {
            return $query->where('id', $credentialId)->get();
        }

        if ($userId = $this->option('user')) {
            return $query->where('user_id', $userId)->get();
        }

        // Only get credentials that have bookmark scope
        return $query->get()->filter(function ($credential) {
            $scopes = $credential->scopes ?? [];

            return in_array('bookmark.read', $scopes);
        });
    }
}
