<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\ClientContact;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MatchCalendarEventsToClients implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        CalendarEvent::where('is_client_meeting', false)
            ->where('end_at', '<', now())
            ->whereNull('client_id')
            ->each(function (CalendarEvent $event) {
                $this->matchEvent($event);
            });
    }

    protected function matchEvent(CalendarEvent $event): void
    {
        $attendees = $event->attendees ?? [];
        $emails = collect($attendees)
            ->pluck('email')
            ->filter()
            ->values()
            ->toArray();

        if (empty($emails)) {
            return;
        }

        $contact = ClientContact::whereIn('email', $emails)
            ->with('client.projects')
            ->first();

        if (! $contact) {
            return;
        }

        $clientId = $contact->client_id;

        // Find project — if client has exactly one active retainer project, use it
        $retainerProjects = $contact->client->projects
            ->where('type', 'retainer')
            ->where('status', 'active');
        $projectId = $retainerProjects->count() === 1
            ? $retainerProjects->first()->id
            : null;

        $event->update([
            'is_client_meeting' => true,
            'client_id' => $clientId,
            'project_id' => $projectId,
        ]);

        // Create time entry if no duplicate exists
        $alreadyExists = TimeEntry::where('external_reference->google_event_id', $event->google_event_id)
            ->exists();

        if (! $alreadyExists) {
            TimeEntry::create([
                'harvest_id' => 0,
                'harvest_project_id' => 0,
                'user_id' => User::first()?->id ?? 1,
                'client_id' => $clientId,
                'project_id' => $projectId,
                'hours' => $event->duration_hours,
                'effort_type' => 'meeting',
                'spent_date' => $event->start_at->toDateString(),
                'notes' => "Client meeting: {$event->title} (auto-captured)",
                'is_billable' => false,
                'external_reference' => ['source' => 'calendar', 'google_event_id' => $event->google_event_id],
            ]);
        }

        // Update last_client_activity_at on active retainer
        $retainer = RetainerPeriod::currentForClient($clientId);
        if ($retainer) {
            $retainer->update(['last_client_activity_at' => $event->start_at]);
        }

        Log::info('Matched calendar event to client', [
            'event_id' => $event->id,
            'client_id' => $clientId,
            'google_event_id' => $event->google_event_id,
        ]);
    }
}
