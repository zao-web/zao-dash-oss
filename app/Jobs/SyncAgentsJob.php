<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Syncs agent definitions from code to database.
 *
 * Triggered automatically when agent definition files are pushed to GitHub.
 */
class SyncAgentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * The files that triggered this sync (for logging).
     *
     * @var array<string>
     */
    public array $changedFiles;

    /**
     * Create a new job instance.
     *
     * @param  array<string>  $changedFiles  List of changed agent definition files
     */
    public function __construct(array $changedFiles = [])
    {
        $this->changedFiles = $changedFiles;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('SyncAgentsJob: Starting agent definition sync', [
            'changed_files' => $this->changedFiles,
        ]);

        try {
            $exitCode = Artisan::call('agents:sync');
            $output = Artisan::output();

            if ($exitCode === 0) {
                Log::info('SyncAgentsJob: Agent sync completed successfully', [
                    'output' => $output,
                ]);
            } else {
                Log::error('SyncAgentsJob: Agent sync failed', [
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SyncAgentsJob: Exception during agent sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['agents', 'sync', 'github-webhook'];
    }
}
