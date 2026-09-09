<?php

namespace App\Observers;

use App\Jobs\ClassifyTimeEntryEffort;
use App\Models\TimeEntry;

class TimeEntryObserver
{
    public function created(TimeEntry $timeEntry): void
    {
        if (! $timeEntry->effort_type) {
            $timeEntry->updateQuietly([
                'effort_type' => app(ClassifyTimeEntryEffort::class)->classify($timeEntry),
            ]);
        }
    }

    public function updated(TimeEntry $timeEntry): void
    {
        if ($timeEntry->wasChanged('notes') && ! $timeEntry->effort_type) {
            $timeEntry->updateQuietly([
                'effort_type' => app(ClassifyTimeEntryEffort::class)->classify($timeEntry),
            ]);
        }
    }
}
