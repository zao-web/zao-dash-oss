<?php

use App\Events\NotificationCreated;
use App\Jobs\ParseMeetingJob;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Http::fake();

    $this->event = CalendarEvent::factory()->create([
        'title' => 'Team Meeting',
        'notes' => "Action items:\n- TODO: Follow up with client\n- John will send proposal",
        'start_at' => now(),
    ]);
});

test('job can be dispatched', function () {
    Queue::fake();

    ParseMeetingJob::dispatch($this->event);

    Queue::assertPushed(ParseMeetingJob::class);
});

test('job has correct queue configuration', function () {
    $job = new ParseMeetingJob($this->event);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});

test('handle returns early if no content', function () {
    Log::spy();

    $emptyEvent = CalendarEvent::factory()->create([
        'notes' => null,
        'description' => null,
    ]);

    $job = new ParseMeetingJob($emptyEvent);
    $job->handle();

    Log::shouldHaveReceived('info')
        ->with('No meeting content to parse', Mockery::any());
});

test('handle parses meeting with Claude API', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                [
                    'text' => json_encode([
                        'summary' => 'Discussed project timeline',
                        'action_items' => [
                            ['task' => 'Send proposal', 'assignee' => 'John', 'due' => null, 'priority' => 'high'],
                        ],
                        'client_mentions' => [],
                        'follow_ups' => [],
                        'key_decisions' => ['Approved budget'],
                    ]),
                ],
            ],
        ], 200),
    ]);

    $job = new ParseMeetingJob($this->event);
    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key');
    });
});

test('handle creates tasks from action items', function () {
    Config::set('services.anthropic.api_key', null);

    $event = CalendarEvent::factory()->create([
        'title' => 'Project Meeting',
        'notes' => "- TODO: Review documentation\n- ACTION: Update timeline",
    ]);

    $job = new ParseMeetingJob($event);
    $job->handle();

    expect(Task::count())->toBeGreaterThan(0);

    $task = Task::first();
    expect($task->source)->toBe('meeting_parser')
        ->and($task->source_id)->toBe($event->id);
});

test('handle updates client mentions', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    $client = Client::factory()->create(['name' => 'Acme Corp']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                [
                    'text' => json_encode([
                        'summary' => 'Meeting summary',
                        'action_items' => [],
                        'client_mentions' => [
                            ['name' => 'Acme Corp', 'context' => 'Happy with progress', 'sentiment' => 'positive'],
                        ],
                        'follow_ups' => [],
                        'key_decisions' => [],
                    ]),
                ],
            ],
        ], 200),
    ]);

    $job = new ParseMeetingJob($this->event);
    $job->handle();

    $client->refresh();
    expect($client->notes)->toContain('Happy with progress');
});

test('handle updates leads for follow-ups', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    $lead = Lead::factory()->create([
        'company_name' => 'Test Company',
        'stage' => 'new',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                [
                    'text' => json_encode([
                        'summary' => 'Meeting summary',
                        'action_items' => [],
                        'client_mentions' => [],
                        'follow_ups' => [
                            ['contact' => 'Test Company', 'reason' => 'Send pricing', 'urgency' => 'high'],
                        ],
                        'key_decisions' => [],
                    ]),
                ],
            ],
        ], 200),
    ]);

    $job = new ParseMeetingJob($this->event);
    $job->handle();

    $lead->refresh();
    expect($lead->stage)->toBe('qualified');
});

test('handle stores summary and decisions', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                [
                    'text' => json_encode([
                        'summary' => 'We decided on the project scope',
                        'action_items' => [],
                        'client_mentions' => [],
                        'follow_ups' => [],
                        'key_decisions' => ['Approved budget', 'Start next week'],
                    ]),
                ],
            ],
        ], 200),
    ]);

    $job = new ParseMeetingJob($this->event);
    $job->handle();

    $this->event->refresh();
    expect($this->event->parsed_summary)->toBe('We decided on the project scope')
        ->and($this->event->key_decisions)->toBe(['Approved budget', 'Start next week'])
        ->and($this->event->parsed_at)->not->toBeNull();
});

test('handle sends notification after parsing', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                [
                    'text' => json_encode([
                        'summary' => 'Test',
                        'action_items' => [
                            ['task' => 'Task 1', 'assignee' => null, 'due' => null, 'priority' => 'medium'],
                        ],
                        'client_mentions' => [],
                        'follow_ups' => [],
                        'key_decisions' => [],
                    ]),
                ],
            ],
        ], 200),
    ]);

    $job = new ParseMeetingJob($this->event);
    $job->handle();

    Event::assertDispatched(NotificationCreated::class);
});

test('handle falls back to regex parsing when API fails', function () {
    Config::set('services.anthropic.api_key', 'test-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response('Error', 500),
    ]);

    $event = CalendarEvent::factory()->create([
        'notes' => '- TODO: Send email\n- ACTION: Review code',
    ]);

    $job = new ParseMeetingJob($event);
    $job->handle();

    expect(Task::count())->toBeGreaterThan(0);
});

test('handle uses regex parsing when no API key', function () {
    Config::set('services.anthropic.api_key', null);

    $event = CalendarEvent::factory()->create([
        'notes' => '- TODO: Test feature\nJohn will deploy tomorrow',
    ]);

    $job = new ParseMeetingJob($event);
    $job->handle();

    $tasks = Task::all();
    expect($tasks->count())->toBeGreaterThan(0);
});

test('regex parsing extracts TODO patterns', function () {
    Config::set('services.anthropic.api_key', null);

    $event = CalendarEvent::factory()->create([
        'notes' => "- TODO: First task\n* ACTION: Second task\n- @john: Third task",
    ]);

    $job = new ParseMeetingJob($event);
    $job->handle();

    expect(Task::count())->toBeGreaterThanOrEqual(3);
});

test('regex parsing extracts will do patterns', function () {
    Config::set('services.anthropic.api_key', null);

    $event = CalendarEvent::factory()->create([
        'notes' => "John will send the proposal.\nMary will review the code.",
    ]);

    $job = new ParseMeetingJob($event);
    $job->handle();

    $tasks = Task::all();
    expect($tasks->count())->toBeGreaterThan(0);
});

test('job implements ShouldQueue interface', function () {
    $job = new ParseMeetingJob($this->event);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(ParseMeetingJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
