<?php

namespace App\Console\Commands;

use App\Services\HealthAlertEscalationService;
use Illuminate\Console\Command;

class ProcessHealthEscalationsCommand extends Command
{
    protected $signature = 'health:process-escalations
        {--dry-run : Show what would be escalated without actually escalating}';

    protected $description = 'Process and escalate unacknowledged health alerts';

    public function handle(HealthAlertEscalationService $service): int
    {
        $this->info('Processing health alert escalations...');

        if ($this->option('dry-run')) {
            $alerts = \App\Models\HealthAlert::needsEscalation()->with('client')->get();

            if ($alerts->isEmpty()) {
                $this->info('No alerts need escalation.');

                return 0;
            }

            $this->table(
                ['Alert ID', 'Client', 'Current Level', 'Severity', 'Created'],
                $alerts->map(fn ($a) => [
                    $a->id,
                    $a->client->name,
                    $a->escalation_level_name,
                    $a->severity,
                    $a->created_at->diffForHumans(),
                ])
            );

            $this->warn("Would escalate {$alerts->count()} alerts (dry run)");

            return 0;
        }

        $escalated = $service->processEscalations();

        if (empty($escalated)) {
            $this->info('No alerts needed escalation.');

            return 0;
        }

        $count = count($escalated);
        $this->info("Escalated {$count} alerts:");

        foreach ($escalated as $item) {
            $this->line("  - Alert #{$item['alert_id']} ({$item['client']}) → Level {$item['new_level']}");
        }

        return 0;
    }
}
