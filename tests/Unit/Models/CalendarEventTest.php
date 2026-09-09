<?php

use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Project;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new CalendarEvent)->getGuarded())->toBe(['*']);
});

test('casts start_at to datetime', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->start_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts end_at to datetime', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->end_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts attendees to array', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'attendees' => [
            ['email' => 'user1@example.com', 'name' => 'User 1'],
            ['email' => 'user2@example.com', 'name' => 'User 2'],
        ],
        'is_client_meeting' => true,
    ]);

    expect($event->attendees)->toBeArray()
        ->and($event->attendees)->toHaveCount(2);
});

test('casts key_decisions to array', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'key_decisions' => ['Decision 1', 'Decision 2'],
        'is_client_meeting' => true,
    ]);

    expect($event->key_decisions)->toBeArray()
        ->and($event->key_decisions)->toHaveCount(2);
});

test('casts is_all_day to boolean', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'All Day Event',
        'start_at' => today(),
        'end_at' => today()->addDay(),
        'is_all_day' => true,
        'is_client_meeting' => false,
    ]);

    expect($event->is_all_day)->toBeTrue();
});

test('casts is_client_meeting to boolean', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->is_client_meeting)->toBeTrue();
});

test('casts pre_brief_sent to boolean', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
        'pre_brief_sent' => true,
    ]);

    expect($event->pre_brief_sent)->toBeTrue();
});

test('casts post_followup_sent to boolean', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHours(1),
        'is_client_meeting' => true,
        'post_followup_sent' => true,
    ]);

    expect($event->post_followup_sent)->toBeTrue();
});

test('casts parsed_at to datetime', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
        'parsed_at' => now(),
    ]);

    expect($event->parsed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to client relationship', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isUpcoming returns true for future events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->isUpcoming())->toBeTrue();
});

test('isUpcoming returns false for past events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subHours(3),
        'end_at' => now()->subHours(2),
        'is_client_meeting' => true,
    ]);

    expect($event->isUpcoming())->toBeFalse();
});

test('isInProgress returns true for ongoing events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subMinutes(30),
        'end_at' => now()->addMinutes(30),
        'is_client_meeting' => true,
    ]);

    expect($event->isInProgress())->toBeTrue();
});

test('isInProgress returns false for future events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->isInProgress())->toBeFalse();
});

test('isPast returns true for past events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subHours(3),
        'end_at' => now()->subHours(2),
        'is_client_meeting' => true,
    ]);

    expect($event->isPast())->toBeTrue();
});

test('isPast returns false for future events', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->isPast())->toBeFalse();
});

test('needsPreBrief returns false when already sent', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addMinutes(20),
        'end_at' => now()->addHours(1),
        'is_client_meeting' => true,
        'pre_brief_sent' => true,
    ]);

    expect($event->needsPreBrief())->toBeFalse();
});

test('needsPreBrief returns false when not client meeting', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Internal Meeting',
        'start_at' => now()->addMinutes(20),
        'end_at' => now()->addHours(1),
        'is_client_meeting' => false,
    ]);

    expect($event->needsPreBrief())->toBeFalse();
});

test('needsPostFollowup returns false when already sent', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHours(1),
        'is_client_meeting' => true,
        'post_followup_sent' => true,
    ]);

    expect($event->needsPostFollowup())->toBeFalse();
});

test('needsPostFollowup returns false when not client meeting', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Internal Meeting',
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHours(1),
        'is_client_meeting' => false,
    ]);

    expect($event->needsPostFollowup())->toBeFalse();
});

test('needsPostFollowup returns true for past client meetings without followup', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHours(1),
        'is_client_meeting' => true,
        'post_followup_sent' => false,
    ]);

    expect($event->needsPostFollowup())->toBeTrue();
});

test('getExternalAttendeesAttribute filters internal domains', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'attendees' => [
            ['email' => 'internal@example.com', 'name' => 'Internal User'],
            ['email' => 'client@example.com', 'name' => 'Client User'],
            ['email' => 'staff@internal.example.com', 'name' => 'Staff User'],
            ['email' => 'external@client.com', 'name' => 'External User'],
        ],
        'is_client_meeting' => true,
    ]);

    $external = $event->external_attendees;

    expect($external)->toHaveCount(2)
        ->and(array_column($external, 'email'))->toContain('client@example.com')
        ->and(array_column($external, 'email'))->toContain('external@client.com')
        ->and(array_column($external, 'email'))->not->toContain('internal@example.com')
        ->and(array_column($external, 'email'))->not->toContain('staff@internal.example.com');
});

test('getExternalAttendeesAttribute returns empty when no attendees', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event->external_attendees)->toBeArray()
        ->and($event->external_attendees)->toBeEmpty();
});

test('can be created', function () {
    $client = Client::factory()->create();
    $event = CalendarEvent::create([
        'client_id' => $client->id,
        'title' => 'Test Meeting',
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
        'is_client_meeting' => true,
    ]);

    expect($event)->toBeInstanceOf(CalendarEvent::class)
        ->and($event->exists)->toBeTrue();
});
