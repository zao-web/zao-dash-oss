<?php

namespace App\Jobs;

use App\Models\TaxCalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Seeds all tax deadlines for a given year.
 * Runs annually on January 2nd or on-demand.
 */
class SeedTaxCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $year = null,
    ) {}

    public function handle(): void
    {
        $year = $this->year ?? now()->year;
        $user = User::first();

        if (! $user) {
            return;
        }

        $events = $this->buildCalendar($year);
        $created = 0;

        foreach ($events as $event) {
            $existing = TaxCalendarEvent::where('user_id', $user->id)
                ->where('tax_year', $year)
                ->where('event_type', $event['event_type'])
                ->where('quarter', $event['quarter'] ?? null)
                ->where('filing_jurisdiction', $event['filing_jurisdiction'])
                ->first();

            if (! $existing) {
                TaxCalendarEvent::create(array_merge($event, ['user_id' => $user->id]));
                $created++;
            }
        }

        Log::info("SeedTaxCalendarJob: seeded {$created} events for {$year}");
    }

    protected function buildCalendar(int $year): array
    {
        $events = [];

        // Federal quarterly estimates
        $federalQuarters = [
            1 => $this->adjustForWeekend(Carbon::create($year, 4, 15)),
            2 => $this->adjustForWeekend(Carbon::create($year, 6, 15)),
            3 => $this->adjustForWeekend(Carbon::create($year, 9, 15)),
            4 => $this->adjustForWeekend(Carbon::create($year + 1, 1, 15)),
        ];

        foreach ($federalQuarters as $q => $dueDate) {
            $events[] = [
                'tax_year' => $year,
                'quarter' => $q,
                'event_type' => 'quarterly_estimate',
                'form_type' => '1040-ES',
                'filing_jurisdiction' => 'federal',
                'due_date' => $dueDate,
                'reminder_date' => $dueDate->copy()->subDays(14),
                'status' => 'upcoming',
            ];
        }

        // Oregon quarterly estimates (same dates as federal)
        foreach ($federalQuarters as $q => $dueDate) {
            $events[] = [
                'tax_year' => $year,
                'quarter' => $q,
                'event_type' => 'quarterly_estimate',
                'form_type' => 'OR-40-V',
                'filing_jurisdiction' => 'state',
                'state_code' => 'OR',
                'due_date' => $dueDate,
                'reminder_date' => $dueDate->copy()->subDays(14),
                'status' => 'upcoming',
            ];
        }

        // S-corp return (1120-S) — March 15
        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => 'annual_return',
            'form_type' => '1120-S',
            'filing_jurisdiction' => 'federal',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 3, 15)),
            'reminder_date' => Carbon::create($year + 1, 2, 15),
            'status' => 'upcoming',
        ];

        // Personal return (1040) — April 15
        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => 'annual_return',
            'form_type' => '1040',
            'filing_jurisdiction' => 'federal',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 4, 15)),
            'reminder_date' => Carbon::create($year + 1, 3, 15),
            'status' => 'upcoming',
        ];

        // Oregon return (OR-40) — April 15
        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => 'annual_return',
            'form_type' => 'OR-40',
            'filing_jurisdiction' => 'state',
            'state_code' => 'OR',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 4, 15)),
            'reminder_date' => Carbon::create($year + 1, 3, 15),
            'status' => 'upcoming',
        ];

        // 1099-NEC filing — January 31
        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => '1099_filing',
            'form_type' => '1099-NEC',
            'filing_jurisdiction' => 'federal',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 1, 31)),
            'reminder_date' => Carbon::create($year + 1, 1, 10),
            'status' => 'upcoming',
        ];

        // Extension deadlines
        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => 'extension',
            'form_type' => '7004',
            'filing_jurisdiction' => 'federal',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 9, 15)),
            'reminder_date' => Carbon::create($year + 1, 8, 15),
            'status' => 'upcoming',
        ];

        $events[] = [
            'tax_year' => $year,
            'quarter' => null,
            'event_type' => 'extension',
            'form_type' => '4868',
            'filing_jurisdiction' => 'federal',
            'due_date' => $this->adjustForWeekend(Carbon::create($year + 1, 10, 15)),
            'reminder_date' => Carbon::create($year + 1, 9, 15),
            'status' => 'upcoming',
        ];

        return $events;
    }

    protected function adjustForWeekend(Carbon $date): Carbon
    {
        if ($date->isSaturday()) {
            return $date->addDays(2);
        }
        if ($date->isSunday()) {
            return $date->addDay();
        }

        return $date;
    }
}
