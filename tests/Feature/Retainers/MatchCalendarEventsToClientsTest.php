<?php

use App\Jobs\MatchCalendarEventsToClients;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->create();
    $this->client = Client::factory()->create();
    $this->contact = ClientContact::factory()->create([
        'client_id' => $this->client->id,
        'email' => 'john@resort.com',
    ]);
    $this->retainer = RetainerPeriod::factory()->create([
        'client_id' => $this->client->id,
        'last_client_activity_at' => now()->subDays(20),
    ]);
});

it('matches calendar event to client by attendee email address', function () {
    $event = CalendarEvent::factory()->create([
        'attendees' => [
            ['email' => 'john@resort.com', 'name' => 'John'],
            ['email' => 'owner@example.com', 'name' => 'Justin'],
        ],
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHour(),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    $event->refresh();
    expect($event->client_id)->toBe($this->client->id);
});

it('sets is_client_meeting to true on matched event', function () {
    $event = CalendarEvent::factory()->create([
        'attendees' => [['email' => 'john@resort.com']],
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHour(),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    expect($event->refresh()->is_client_meeting)->toBeTrue();
});

it('creates time_entry for matched meeting with correct duration and effort_type meeting', function () {
    CalendarEvent::factory()->create([
        'attendees' => [['email' => 'john@resort.com']],
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHour(),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    $entry = TimeEntry::where('client_id', $this->client->id)
        ->where('effort_type', 'meeting')
        ->first();

    expect($entry)->not->toBeNull();
    expect((float) $entry->hours)->toBe(1.0);
    expect($entry->effort_type)->toBe('meeting');
});

it('does not create duplicate time_entry when external_reference already contains google_event_id', function () {
    $event = CalendarEvent::factory()->create([
        'attendees' => [['email' => 'john@resort.com']],
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHour(),
        'is_client_meeting' => false,
        'client_id' => null,
        'google_event_id' => 'evt_123',
    ]);

    // Pre-create a matching entry
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'external_reference' => ['source' => 'calendar', 'google_event_id' => 'evt_123'],
    ]);

    (new MatchCalendarEventsToClients)->handle();

    $count = TimeEntry::where('external_reference->google_event_id', 'evt_123')->count();
    expect($count)->toBe(1);
});

it('does not match events with no attendees in client_contacts', function () {
    $event = CalendarEvent::factory()->create([
        'attendees' => [['email' => 'nobody@unknown.com']],
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHour(),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    expect($event->refresh()->is_client_meeting)->toBeFalse();
    expect($event->client_id)->toBeNull();
});

it('updates last_client_activity_at on retainer period after match', function () {
    $meetingTime = now()->subHours(2);

    CalendarEvent::factory()->create([
        'attendees' => [['email' => 'john@resort.com']],
        'start_at' => $meetingTime,
        'end_at' => $meetingTime->copy()->addHour(),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    $this->retainer->refresh();
    expect($this->retainer->last_client_activity_at->format('Y-m-d H:i'))
        ->toBe($meetingTime->format('Y-m-d H:i'));
});

it('skips future events that have not ended yet', function () {
    CalendarEvent::factory()->create([
        'attendees' => [['email' => 'john@resort.com']],
        'start_at' => now()->addHour(),
        'end_at' => now()->addHours(2),
        'is_client_meeting' => false,
        'client_id' => null,
    ]);

    (new MatchCalendarEventsToClients)->handle();

    expect(TimeEntry::where('client_id', $this->client->id)->count())->toBe(0);
});
