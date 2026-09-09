<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use Cron\CronExpression;
use Illuminate\Console\Command;

/**
 * Runs agents that are due based on their cron schedule.
 */
class RunScheduledAgentsCommand extends Command
{
    protected $signature = 'agents:run-scheduled
        {--dry-run : Show what would run without executing}
        {--status : Show status of all scheduled agents}';

    protected $description = 'Execute agents whose schedule is due';

    public function handle(): int
    {
        $agents = Agent::where('status', 'active')
            ->whereNotNull('schedule')
            ->get();

        if ($agents->isEmpty()) {
            $this->info('No scheduled agents found.');

            return Command::SUCCESS;
        }

        // Status mode shows all scheduled agents and their next run time
        if ($this->option('status')) {
            $this->showScheduleStatus($agents);

            return Command::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $now = now();
        $triggered = 0;

        foreach ($agents as $agent) {
            try {
                // Validate cron expression
                if (! CronExpression::isValidExpression($agent->schedule)) {
                    $this->warn("Invalid cron for {$agent->name}: {$agent->schedule}");

                    continue;
                }

                $cron = new CronExpression($agent->schedule);

                if (! $cron->isDue($now)) {
                    continue;
                }

                // Check if already ran this minute (prevent duplicate runs)
                $recentRun = AgentRun::where('agent_id', $agent->id)
                    ->where('invocation_source', AgentRun::SOURCE_SCHEDULED)
                    ->where('created_at', '>=', $now->copy()->startOfMinute())
                    ->exists();

                if ($recentRun) {
                    $this->line("Skipping {$agent->name} - already ran this minute");

                    continue;
                }

                if ($dryRun) {
                    $this->info("[DRY RUN] Would trigger: {$agent->name} ({$agent->schedule})");
                } else {
                    ExecuteAgentJob::dispatch(
                        agent: $agent,
                        config: ['prompt' => 'Scheduled execution'],
                        invocationSource: AgentRun::SOURCE_SCHEDULED,
                        invokedBy: 'scheduler',
                        triggerMetadata: [
                            'scheduled_at' => $now->toIso8601String(),
                            'cron_expression' => $agent->schedule,
                        ],
                    );

                    $this->info("Triggered: {$agent->name}");
                }

                $triggered++;

            } catch (\Exception $e) {
                $this->error("Error with {$agent->name}: {$e->getMessage()}");
            }
        }

        $this->info("Triggered {$triggered} agent(s).");

        return Command::SUCCESS;
    }

    /**
     * Display status of all scheduled agents.
     */
    protected function showScheduleStatus($agents): void
    {
        $this->info('Scheduled Agents Status');
        $this->newLine();

        $now = now();
        $rows = [];

        foreach ($agents as $agent) {
            if (! CronExpression::isValidExpression($agent->schedule)) {
                $rows[] = [
                    $agent->name,
                    $agent->schedule,
                    '<fg=red>INVALID</>',
                    '-',
                ];

                continue;
            }

            $cron = new CronExpression($agent->schedule);
            $nextRun = $cron->getNextRunDate($now);
            $isDue = $cron->isDue($now) ? '<fg=green>DUE NOW</>' : 'Waiting';

            $rows[] = [
                $agent->name,
                $agent->schedule,
                $isDue,
                $nextRun->format('D M j, g:ia'),
            ];
        }

        $this->table(
            ['Agent', 'Schedule', 'Status', 'Next Run'],
            $rows
        );
    }
}
