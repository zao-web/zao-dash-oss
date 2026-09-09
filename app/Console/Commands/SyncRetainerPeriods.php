<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Services\Reports\RetainerPeriodService;
use Illuminate\Console\Command;

class SyncRetainerPeriods extends Command
{
    protected $signature = 'retainers:sync {--months=0 : Also backfill this many prior months}';

    protected $description = 'Create/update retainer periods for clients with recurring invoices';

    public function __construct(private RetainerPeriodService $periods)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $monthsBack = (int) $this->option('months');
        $created = 0;
        $updated = 0;

        $clients = Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->get();

        foreach ($clients as $client) {
            // Current month: create/activate it and close any stale prior active
            // period. This is the same path the on-demand UI uses.
            $before = RetainerPeriod::where('client_id', $client->id)
                ->where('period_start', now()->startOfMonth()->toDateString())
                ->exists();

            $this->periods->ensureCurrentPeriod($client);

            $before ? $updated++ : $created++;

            // Optional backfill of prior months as closed historical periods.
            for ($offset = 1; $offset <= $monthsBack; $offset++) {
                $periodStart = now()->subMonths($offset)->startOfMonth();
                $periodEnd = now()->subMonths($offset)->endOfMonth();

                $retainer = RetainerPeriod::firstOrNew([
                    'client_id' => $client->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);

                $isNew = ! $retainer->exists;

                $hourlyRate = (float) ($client->default_hourly_rate ?: 250);
                $monthlyAmount = (float) $client->recurring_invoice_amount;
                $estimatedHours = $hourlyRate > 0 ? round($monthlyAmount / $hourlyRate, 1) : 20;

                $retainer->fill([
                    'hours_used' => TimeEntry::where('client_id', $client->id)
                        ->whereBetween('spent_date', [$periodStart, $periodEnd])
                        ->sum('hours'),
                    'status' => 'closed',
                    'monthly_amount' => $retainer->monthly_amount ?? $monthlyAmount,
                    'internal_hourly_rate' => $retainer->internal_hourly_rate ?? $hourlyRate,
                ]);

                if ($isNew) {
                    $retainer->hours_included = $estimatedHours;
                    $retainer->last_client_activity_at = $periodEnd;
                    $created++;
                } else {
                    $updated++;
                }

                $retainer->save();
            }
        }

        $this->info("Done. Created: {$created}, Updated: {$updated}");

        return self::SUCCESS;
    }
}
