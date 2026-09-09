<?php

namespace App\Jobs;

use App\Models\TimeEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyTimeEntryEffort implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        TimeEntry::whereNull('effort_type')
            ->each(function (TimeEntry $entry) {
                $entry->update(['effort_type' => $this->classify($entry)]);
            });
    }

    public function classify(TimeEntry $entry): string
    {
        $notes = strtolower($entry->notes ?? '');
        $combined = $notes;

        if ($this->containsAny($combined, ['meeting', 'call', 'sync', 'standup', 'demo', 'review call'])) {
            return 'meeting';
        }
        if ($this->containsAny($combined, ['review', 'qa', 'test', 'audit'])) {
            return 'review';
        }
        if ($this->containsAny($combined, ['deploy', 'migration', 'release', 'launch'])) {
            return 'deployment';
        }
        if ($this->containsAny($combined, ['plan', 'spec', 'scope', 'estimate', 'discovery'])) {
            return 'planning';
        }
        if ($this->containsAny($combined, ['email', 'slack', 'message', 'comms', 'response'])) {
            return 'communication';
        }

        return 'development';
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
