<?php

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NotificationCreated::class]);
});

test('command sends test notification successfully', function () {
    $this->artisan('notifications:test')
        ->expectsOutput('Test notification sent!')
        ->assertExitCode(0);

    expect(Notification::count())->toBe(1);
    Event::assertDispatched(NotificationCreated::class);
});

test('command creates notification with info type by default', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->severity)->toBe('info');
    expect($notification->title)->toBe('System Update');
    expect($notification->message)->toBe('A new feature has been deployed.');
});

test('command creates notification with success type', function () {
    $this->artisan('notifications:test', ['--type' => 'success'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->severity)->toBe('success');
    expect($notification->title)->toBe('Task Completed');
    expect($notification->message)->toBe('Agent finished processing your request.');
});

test('command creates notification with warning type', function () {
    $this->artisan('notifications:test', ['--type' => 'warning'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->severity)->toBe('warning');
    expect($notification->title)->toBe('Attention Needed');
    expect($notification->message)->toBe('Client health score dropped below threshold.');
});

test('command creates notification with error type', function () {
    $this->artisan('notifications:test', ['--type' => 'error'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->severity)->toBe('error');
    expect($notification->title)->toBe('Action Required');
    expect($notification->message)->toBe('Invoice payment is overdue.');
});

test('command creates notification for specific user', function () {
    $user = User::factory()->create();

    $this->artisan('notifications:test', ['--user' => $user->id])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->user_id)->toBe($user->id);
});

test('command creates public notification when no user specified', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->user_id)->toBeNull();
});

test('command sets correct icon for info type', function () {
    $this->artisan('notifications:test', ['--type' => 'info'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->icon)->toBe('information-circle');
});

test('command sets correct icon for success type', function () {
    $this->artisan('notifications:test', ['--type' => 'success'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->icon)->toBe('check-circle');
});

test('command sets correct icon for warning type', function () {
    $this->artisan('notifications:test', ['--type' => 'warning'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->icon)->toBe('exclamation-triangle');
});

test('command sets correct icon for error type', function () {
    $this->artisan('notifications:test', ['--type' => 'error'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->icon)->toBe('x-circle');
});

test('command broadcasts notification event', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    Event::assertDispatched(NotificationCreated::class, function ($event) {
        return $event->notification instanceof Notification;
    });
});

test('command displays notification details in table', function () {
    $this->artisan('notifications:test')
        ->expectsOutputToContain('ID')
        ->expectsOutputToContain('Type')
        ->expectsOutputToContain('Title')
        ->expectsOutputToContain('User')
        ->expectsOutputToContain('Channel')
        ->assertExitCode(0);
});

test('command shows correct channel for user notification', function () {
    $user = User::factory()->create();

    $this->artisan('notifications:test', ['--user' => $user->id])
        ->expectsOutputToContain("notifications.{$user->id}")
        ->assertExitCode(0);
});

test('command shows correct channel for public notification', function () {
    $this->artisan('notifications:test')
        ->expectsOutputToContain('All users (public)')
        ->assertExitCode(0);
});

test('command sets notification type as system', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->type)->toBe('system');
});

test('command sets action url', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->action_url)->toBe('/dashboard');
});

test('command sets action label', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->action_label)->toBe('View Dashboard');
});

test('command displays type in output', function () {
    $this->artisan('notifications:test', ['--type' => 'warning'])
        ->expectsOutputToContain('warning')
        ->assertExitCode(0);
});

test('command displays title in output', function () {
    $this->artisan('notifications:test', ['--type' => 'success'])
        ->expectsOutputToContain('Task Completed')
        ->assertExitCode(0);
});

test('command handles invalid user id gracefully', function () {
    // With foreign key constraint, invalid user ID should cause an error
    // The command should handle this by showing an error message
    $this->artisan('notifications:test', ['--user' => 99999])
        ->assertExitCode(1);
});

test('command falls back to info type for unknown types', function () {
    $this->artisan('notifications:test', ['--type' => 'unknown'])
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification->title)->toBe('System Update');
    expect($notification->severity)->toBe('unknown');
});

test('command creates notification with correct structure', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    $notification = Notification::first();
    expect($notification)->toHaveKeys([
        'type',
        'title',
        'message',
        'icon',
        'severity',
        'action_url',
        'action_label',
    ]);
});

test('command dispatches exactly one notification event', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    Event::assertDispatched(NotificationCreated::class, 1);
});

test('command creates exactly one notification record', function () {
    $this->artisan('notifications:test')
        ->assertExitCode(0);

    expect(Notification::count())->toBe(1);
});
