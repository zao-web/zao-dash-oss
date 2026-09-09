<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateRfpJob;
use App\Models\RfpOpportunity;
use Illuminate\Console\Command;

class RfpEvaluateBacklogCommand extends Command
{
    protected $signature = 'rfp:evaluate-backlog {--force : Re-evaluate all statuses, not just discovered}';

    protected $description = 'Dispatch batch evaluation for all discovered (unevaluated) RFP opportunities';

    public function handle(): int
    {
        $query = RfpOpportunity::query();

        if (! $this->option('force')) {
            $query->where('status', 'discovered');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No opportunities to evaluate.');

            return self::SUCCESS;
        }

        $this->info("Dispatching batch evaluation for {$count} opportunities...");

        EvaluateRfpJob::dispatch();

        $this->info('Batch evaluation job dispatched on the slack-mentions queue.');
        $this->line('Opportunities will move to qualified / evaluating / declined as the job runs.');

        return self::SUCCESS;
    }
}
