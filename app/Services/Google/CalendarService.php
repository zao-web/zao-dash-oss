<?php

namespace App\Services\Google;

use App\Models\CalendarEvent;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class CalendarService
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private GoogleOAuthService $oauth
    ) {}

    public function watchCalendar(User $user, string $calendarId = 'primary'): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $channelId = 'zao-calendar-'.$user->id.'-'.time();

        $response = Http::withToken($token)->post(
            self::BASE_URL."/calendars/{$calendarId}/events/watch",
            [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => config('app.url').'/webhooks/google/calendar',
                'expiration' => now()->addDays(7)->getTimestampMs(),
            ]
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to set up calendar watch: '.$response->body());
        }

        $data = $response->json();

        $user->googleCredential->update([
            'calendar_watch_expiration' => now()->createFromTimestampMs($data['expiration']),
            'calendar_watch_resource_id' => $data['resourceId'] ?? null,
        ]);

        return $data;
    }

    public function listEvents(User $user, array $params = []): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $defaults = [
            'maxResults' => 50,
            'timeMin' => now()->toIso8601String(),
            'timeMax' => now()->addDays(30)->toIso8601String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
        ];

        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/calendars/primary/events', array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list events: '.$response->body());
        }

        return $response->json();
    }

    public function getEvent(User $user, string $eventId, string $calendarId = 'primary'): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)
            ->get(self::BASE_URL."/calendars/{$calendarId}/events/{$eventId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get event: '.$response->body());
        }

        return $response->json();
    }

    public function syncEvents(User $user): int
    {
        $events = $this->listEvents($user);
        $count = 0;

        foreach ($events['items'] ?? [] as $event) {
            $this->storeEvent($event);
            $count++;
        }

        return $count;
    }

    public function storeEvent(array $event): CalendarEvent
    {
        $attendees = $event['attendees'] ?? [];
        $clientId = $this->matchAttendeesToClient($attendees);
        $isClientMeeting = $clientId !== null || $this->hasExternalAttendees($attendees);

        // Parse Google Calendar times and convert to UTC for consistent storage
        // Google returns ISO 8601 with timezone: 2025-12-30T14:00:00-08:00
        $startAt = isset($event['start']['dateTime'])
            ? \Carbon\Carbon::parse($event['start']['dateTime'])->utc()
            : \Carbon\Carbon::parse($event['start']['date'])->startOfDay()->utc();

        $endAt = isset($event['end']['dateTime'])
            ? \Carbon\Carbon::parse($event['end']['dateTime'])->utc()
            : \Carbon\Carbon::parse($event['end']['date'])->endOfDay()->utc();

        return CalendarEvent::updateOrCreate(
            ['google_event_id' => $event['id']],
            [
                'calendar_id' => 'primary',
                'client_id' => $clientId,
                'title' => $event['summary'] ?? 'Untitled Event',
                'description' => $event['description'] ?? null,
                'location' => $event['location'] ?? null,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_all_day' => ! isset($event['start']['dateTime']),
                'attendees' => $attendees,
                'meet_link' => $event['hangoutLink'] ?? null,
                'is_client_meeting' => $isClientMeeting,
                'status' => $event['status'] ?? 'confirmed',
            ]
        );
    }

    private function matchAttendeesToClient(array $attendees): ?int
    {
        foreach ($attendees as $attendee) {
            $email = $attendee['email'] ?? '';
            $contact = ClientContact::where('email', $email)->first();
            if ($contact) {
                return $contact->client_id;
            }
        }

        return null;
    }

    private function hasExternalAttendees(array $attendees): bool
    {
        $internalDomains = ['example.com', 'internal.example.com'];

        foreach ($attendees as $attendee) {
            $email = $attendee['email'] ?? '';
            $isInternal = false;
            foreach ($internalDomains as $domain) {
                if (str_ends_with($email, "@{$domain}")) {
                    $isInternal = true;
                    break;
                }
            }
            if (! $isInternal && $email) {
                return true;
            }
        }

        return false;
    }

    public function getUpcomingClientMeetings(User $user, int $hours = 24): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/calendars/primary/events', [
                'timeMin' => now()->toIso8601String(),
                'timeMax' => now()->addHours($hours)->toIso8601String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $events = $response->json()['items'] ?? [];

        // Filter to client meetings only
        return array_filter($events, function ($event) {
            $attendees = $event['attendees'] ?? [];

            return $this->hasExternalAttendees($attendees);
        });
    }
}
