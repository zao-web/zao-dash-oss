<?php

namespace App\Console\Commands;

use App\Models\GoalPeriod;
use App\Models\HarvestInvoice;
use App\Models\StrategicGoal;
use Illuminate\Console\Command;

class SyncRevenueCommand extends Command
{
    protected $signature = 'revenue:sync
        {--dry-run : Show what would be updated without making changes}
        {--date-field=paid_at : Date field to use for revenue attribution (paid_at or issue_date)}
        {--goal-id= : Sync only a specific goal by ID}';

    protected $description = 'Sync revenue from Harvest invoices to goal period actuals';

    public function handle(): int
    {
        $dateField = $this->option('date-field');
        if (! in_array($dateField, ['paid_at', 'issue_date'])) {
            $this->error("Invalid date field: {$dateField}. Must be 'paid_at' or 'issue_date'");

            return self::FAILURE;
        }

        $this->info("Syncing revenue using {$dateField} for date attribution...");
        $this->newLine();

        $goalsQuery = StrategicGoal::with('periods')
            ->where('status', 'active');

        if ($goalId = $this->option('goal-id')) {
            $goalsQuery->where('id', $goalId);
        }

        $goals = $goalsQuery->get();

        if ($goals->isEmpty()) {
            $this->warn('No active goals found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$goals->count()} goal(s)...");
        $this->newLine();

        $stats = ['updated' => 0, 'unchanged' => 0];
        $updatedPeriods = [];

        foreach ($goals as $goal) {
            $this->line("<fg=cyan>Goal:</> {$goal->name} (FY{$goal->fiscal_year})");

            foreach ($goal->periods as $period) {
                $revenue = $this->calculatePeriodRevenue($period, $dateField);

                if (abs($period->revenue_actual - $revenue) > 0.01) {
                    $oldValue = $period->revenue_actual;

                    if (! $this->option('dry-run')) {
                        $period->revenue_actual = $revenue;
                        $period->calculateVariance();
                        $period->updateStatus();
                        $period->save();
                    }

                    $updatedPeriods[] = [
                        $period->period_type,
                        $period->period_label,
                        $period->period_start->format('Y-m-d'),
                        $period->period_end->format('Y-m-d'),
                        '$'.number_format($oldValue, 2),
                        '$'.number_format($revenue, 2),
                        '$'.number_format($revenue - $oldValue, 2),
                    ];

                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
        }

        if (count($updatedPeriods) > 0) {
            $this->newLine();
            $this->info('Updated periods:');
            $this->table(
                ['Type', 'Label', 'Start', 'End', 'Old', 'New', 'Change'],
                $updatedPeriods
            );
        }

        $this->newLine();
        if ($this->option('dry-run')) {
            $this->warn('Dry run mode - no changes made');
        }

        $this->info('Sync complete:');
        $this->line("  <fg=green>Updated:</> {$stats['updated']}");
        $this->line("  <fg=gray>Unchanged:</> {$stats['unchanged']}");

        $this->newLine();
        $this->showMtdSummary($dateField);

        return self::SUCCESS;
    }

    protected function calculatePeriodRevenue(GoalPeriod $period, string $dateField): float
    {
        return (float) HarvestInvoice::where('state', 'paid')
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$period->period_start, $period->period_end])
            ->sum('amount');
    }

    protected function showMtdSummary(string $dateField): void
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $today = now();

        $mtdRevenue = HarvestInvoice::where('state', 'paid')
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$monthStart, $today])
            ->sum('amount');

        $monthRevenue = HarvestInvoice::where('state', 'paid')
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$monthStart, $monthEnd])
            ->sum('amount');

        $lastMonthStart = now()->subMonth()->startOfMonth();
        $lastMonthEnd = now()->subMonth()->endOfMonth();
        $lastMonthRevenue = HarvestInvoice::where('state', 'paid')
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $yearStart = now()->startOfYear();
        $ytdRevenue = HarvestInvoice::where('state', 'paid')
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$yearStart, $today])
            ->sum('amount');

        $this->info('Revenue Summary (from paid invoices):');
        $this->line('  MTD:        <fg=green>$'.number_format($mtdRevenue, 2).'</> (through today)');
        $this->line('  This Month: <fg=cyan>$'.number_format($monthRevenue, 2).'</> (full month)');
        $this->line('  Last Month: <fg=yellow>$'.number_format($lastMonthRevenue, 2).'</>');
        $this->line('  YTD:        <fg=white>$'.number_format($ytdRevenue, 2).'</>');

        if ($lastMonthRevenue > 0) {
            $changePct = (($mtdRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100;
            $color = $changePct >= 0 ? 'green' : 'red';
            $sign = $changePct >= 0 ? '+' : '';
            $this->line("  MTD vs Last Month: <fg={$color}>{$sign}".number_format($changePct, 1).'%</>');
        }
    }
}
