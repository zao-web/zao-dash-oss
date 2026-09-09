<?php

namespace App\Console\Commands;

use App\Models\AgentChainRun;
use Illuminate\Console\Command;

class ChainStatusCommand extends Command
{
    protected $signature = 'agents:chain-status
        {run_id : Chain run ID to check}
        {--watch : Watch for updates}';

    protected $description = 'Check status of an agent chain execution';

    public function handle(): int
    {
        $runId = $this->argument('run_id');
        $chainRun = AgentChainRun::with('chain', 'agentRuns.agent')->find($runId);

        if (! $chainRun) {
            $this->error("Chain run #{$runId} not found.");

            return Command::FAILURE;
        }

        if ($this->option('watch')) {
            return $this->watchRun($chainRun);
        }

        return $this->showStatus($chainRun);
    }

    protected function showStatus(AgentChainRun $chainRun): int
    {
        $chain = $chainRun->chain;
        $summary = $chainRun->getSummary();

        $this->info("Chain Run #{$chainRun->id}");
        $this->newLine();

        $this->components->twoColumnDetail('Chain', $summary['chain_name'] ?? 'Unknown');
        $this->components->twoColumnDetail('Status', $this->formatStatus($chainRun->status));
        $this->components->twoColumnDetail('Progress', "{$summary['steps_completed']}/{$summary['total_steps']} steps");
        $this->components->twoColumnDetail('Total Cost', '$'.number_format($summary['total_cost'] ?? 0, 4));

        if ($summary['duration_seconds']) {
            $this->components->twoColumnDetail('Duration', $summary['duration_seconds'].'s');
        }

        $this->newLine();
        $this->info('Steps:');

        $steps = $chain?->steps ?? [];
        foreach ($steps as $i => $step) {
            $result = $chainRun->getStepResult($i);
            $status = $result ? $this->formatStatus($result['status']) : '<fg=gray>pending</>';
            $cost = $result ? '$'.number_format($result['cost_usd'] ?? 0, 4) : '-';

            $this->line("  [{$i}] {$step['agent_slug']} - {$status} ({$cost})");

            if ($step['condition']) {
                $this->line("      <fg=gray>condition: {$step['condition']}</>");
            }
        }

        if ($chainRun->error_message) {
            $this->newLine();
            $this->error("Error: {$chainRun->error_message}");
        }

        return Command::SUCCESS;
    }

    protected function watchRun(AgentChainRun $chainRun): int
    {
        $this->info("Watching chain run #{$chainRun->id}...");
        $this->info('Press Ctrl+C to stop.');
        $this->newLine();

        $lastStatus = null;
        $lastStep = -1;

        while (true) {
            $chainRun->refresh();

            // Check for status change
            if ($chainRun->status !== $lastStatus) {
                $this->line('['.now()->format('H:i:s')."] Status: {$this->formatStatus($chainRun->status)}");
                $lastStatus = $chainRun->status;
            }

            // Check for step progress
            $currentStep = $chainRun->getCurrentStep();
            if ($currentStep > $lastStep && $lastStep >= 0) {
                $prevResult = $chainRun->getStepResult($lastStep);
                $this->line('['.now()->format('H:i:s')."] Step {$lastStep} completed: {$prevResult['status']}");
            }
            $lastStep = $currentStep;

            // Exit if complete
            if (in_array($chainRun->status, ['completed', 'failed', 'cancelled'])) {
                $this->newLine();
                $this->showStatus($chainRun);
                break;
            }

            sleep(2);
        }

        return Command::SUCCESS;
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'completed' => '<fg=green>completed</>',
            'running' => '<fg=blue>running</>',
            'pending' => '<fg=gray>pending</>',
            'failed' => '<fg=red>failed</>',
            'cancelled' => '<fg=yellow>cancelled</>',
            default => $status,
        };
    }
}
