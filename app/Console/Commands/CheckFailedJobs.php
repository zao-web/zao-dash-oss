<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobs:check-failed {--limit=5 : Number of failed jobs to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check failed jobs and display error details';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->info('No failed jobs found.');

            return 0;
        }

        $this->info("Found {$failedJobs->count()} failed job(s):\n");

        foreach ($failedJobs as $job) {
            $payload = json_decode($job->payload, true);
            $displayName = $payload['displayName'] ?? 'Unknown';
            $data = unserialize($payload['data']['command'] ?? '');

            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line("<fg=yellow>Job ID:</> {$job->id}");
            $this->line("<fg=yellow>UUID:</> {$job->uuid}");
            $this->line("<fg=yellow>Queue:</> {$job->queue}");
            $this->line("<fg=yellow>Job Type:</> {$displayName}");

            if ($data && method_exists($data, 'run')) {
                $this->line("<fg=yellow>Agent Run ID:</> {$data->run->id}");
                $this->line("<fg=yellow>Agent ID:</> {$data->run->agent_id}");
            }

            $this->line("<fg=yellow>Failed At:</> {$job->failed_at}");
            $this->newLine();
            $this->line("<fg=red>Exception:</>");
            $this->line($job->exception);
            $this->newLine();
        }

        return 0;
    }
}
