<?php

use App\Jobs\SyncGSuiteJob;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\CalendarService;
use App\Services\Google\GmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncGSuiteJob::dispatch();

    Queue::assertPushed(SyncGSuiteJob::class);
});

test('job can be dispatched with parameters', function () {
    Queue::fake();

    SyncGSuiteJob::dispatch(userId: 123, fullSync: true);

    Queue::assertPushed(SyncGSuiteJob::class, function ($job) {
        return $job->userId === 123 && $job->fullSync === true;
    });
});

test('job has correct queue configuration', function () {
    $job = new SyncGSuiteJob;

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});

test('handle syncs all active credentials', function () {
    $user1 = User::factory()->hasGoogleCredential()->create();
    $user2 = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->twice()->andReturn(['messages' => []]);
    $gmail->shouldReceive('watchInbox')->never();

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->twice()->andReturn(0);
    $calendar->shouldReceive('watchCalendar')->never();

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle syncs specific user when userId provided', function () {
    $targetUser = User::factory()->hasGoogleCredential()->create();
    $otherUser = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $targetUser->id), Mockery::any())
        ->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $targetUser->id))
        ->andReturn(0);

    $job = new SyncGSuiteJob(userId: $targetUser->id);
    $job->handle($gmail, $calendar);
});

test('handle syncs emails with default limit', function () {
    $user = User::factory()->hasGoogleCredential()->create();

    $messages = [
        ['id' => 'msg1'],
        ['id' => 'msg2'],
    ];

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')
        ->once()
        ->with(Mockery::any(), Mockery::on(fn ($params) => $params['maxResults'] === 25 &&
            isset($params['q'])
        ))
        ->andReturn(['messages' => $messages]);
    $gmail->shouldReceive('syncAndStoreEmail')->twice();

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle syncs emails with full sync', function () {
    $user = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')
        ->once()
        ->with(Mockery::any(), Mockery::on(fn ($params) => $params['maxResults'] === 100 &&
            ! isset($params['q'])
        ))
        ->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob(fullSync: true);
    $job->handle($gmail, $calendar);
});

test('handle syncs calendar events', function () {
    $user = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $user->id))
        ->andReturn(5);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle refreshes gmail watch when expiring', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'watch_expiration' => now()->addHours(12), // Expiring soon
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);
    $gmail->shouldReceive('watchInbox')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $user->id));

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle does not refresh gmail watch when not expiring', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'watch_expiration' => now()->addDays(5), // Not expiring soon
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);
    $gmail->shouldNotReceive('watchInbox');

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle refreshes calendar watch when expiring', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'calendar_watch_expiration' => now()->addHours(18),
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);
    $calendar->shouldReceive('watchCalendar')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $user->id));

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);
});

test('handle updates credential last_synced_at', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);

    $credential->refresh();
    expect($credential->last_synced_at)->not->toBeNull();
});

test('handle logs sync progress', function () {
    Log::spy();

    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'email' => 'test@example.com',
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);

    Log::shouldHaveReceived('info')
        ->with('GSuite sync completed', Mockery::on(fn ($ctx) => $ctx['user_id'] === $user->id && $ctx['email'] === 'test@example.com'
        ));
});

test('handle catches email sync errors', function () {
    Log::spy();

    $user = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')
        ->andThrow(new Exception('API error'));

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);

    Log::shouldHaveReceived('error')
        ->with('Email sync failed', Mockery::any());
});

test('handle catches calendar sync errors', function () {
    Log::spy();

    $user = User::factory()->hasGoogleCredential()->create();

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')
        ->andThrow(new Exception('Calendar error'));

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);

    Log::shouldHaveReceived('error')
        ->with('Calendar sync failed', Mockery::any());
});

test('handle continues after watch refresh failure', function () {
    Log::spy();

    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'watch_expiration' => now()->addHours(12),
    ]);

    $gmail = Mockery::mock(GmailService::class);
    $gmail->shouldReceive('listMessages')->andReturn(['messages' => []]);
    $gmail->shouldReceive('watchInbox')->andThrow(new Exception('Watch error'));

    $calendar = Mockery::mock(CalendarService::class);
    $calendar->shouldReceive('syncEvents')->andReturn(0);

    $job = new SyncGSuiteJob;
    $job->handle($gmail, $calendar);

    Log::shouldHaveReceived('warning')
        ->with('Failed to refresh Gmail watch', Mockery::any());

    // Should still complete sync
    $credential->refresh();
    expect($credential->last_synced_at)->not->toBeNull();
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncGSuiteJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncGSuiteJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
