<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class RetainerPeriodService
{
    /**
     * Ensure the current calendar month's retainer period exists for a client,
     * is marked active, and is the *only* active period (closing any stale
     * prior-month period that never rolled over). Returns the current period.
     *
     * Safe to call on demand (e.g. from a controller) so a current-month report
     * is always available, regardless of whether the daily scheduler has run.
     */
    public function ensureCurrentPeriod(Client $client): RetainerPeriod
    {
        $periodStart = Carbon::now()->startOfMonth();
        $periodEnd = Carbon::now()->endOfMonth();

        $period = RetainerPeriod::firstOrNew([
            'client_id' => $client->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $isNew = ! $period->exists;

        $hourlyRate = (float) ($client->default_hourly_rate ?: 250);
        $monthlyAmount = (float) $client->recurring_invoice_amount;
        $estimatedHours = $hourlyRate > 0 ? round($monthlyAmount / $hourlyRate, 1) : 20;

        $period->fill([
            'hours_used' => TimeEntry::where('client_id', $client->id)
                ->whereBetween('spent_date', [$periodStart, $periodEnd])
                ->sum('hours'),
            'status' => 'active',
            'monthly_amount' => $period->monthly_amount ?? $monthlyAmount,
            'internal_hourly_rate' => $period->internal_hourly_rate ?? $hourlyRate,
        ]);

        if ($isNew) {
            $period->hours_included = $estimatedHours;
            $period->last_client_activity_at = $periodEnd;
        }

        $period->save();

        RetainerPeriod::where('client_id', $client->id)
            ->where('id', '!=', $period->id)
            ->where('status', 'active')
            ->update(['status' => 'closed']);

        return $period;
    }
}
