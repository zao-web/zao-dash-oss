<?php

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('event implements ShouldBroadcast interface', function () {
    expect(NotificationCreated::class)->toImplement(ShouldBroadcast::class);
});

test('event contains notification', function () {
    $notification = Notification::factory()->create();
    $event = new NotificationCreated($notification);

    expect($event->notification)->toBe($notification);
    expect($event->notification->id)->toBe($notification->id);
});

test('event broadcasts on public notifications channel for global notifications', function () {
    $notification = Notification::factory()->global()->create();
    $event = new NotificationCreated($notification);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0])->not->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('notifications');
});

test('event broadcasts on both public and private channels for user-specific notifications', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->forUser($user->id)->create();
    $event = new NotificationCreated($notification);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(2);

    // First channel should be public
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0]->name)->toBe('notifications');

    // Second channel should be private user channel
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe('private-notifications.'.$user->id);
});

test('event broadcasts with correct data structure', function () {
    $notification = Notification::factory()->create([
        'type' => 'agent_run',
        'title' => 'Test Title',
        'message' => 'Test Message',
        'icon' => '✅',
        'severity' => 'success',
        'action_url' => '/test',
        'action_label' => 'View',
    ]);

    $event = new NotificationCreated($notification);
    $broadcastData = $event->broadcastWith();

    expect($broadcastData)->toHaveKeys([
        'id',
        'type',
        'title',
        'message',
        'icon',
        'severity',
        'action_url',
        'action_label',
        'is_read',
        'created_at',
    ]);

    expect($broadcastData['id'])->toBe($notification->id);
    expect($broadcastData['type'])->toBe('agent_run');
    expect($broadcastData['title'])->toBe('Test Title');
    expect($broadcastData['message'])->toBe('Test Message');
    expect($broadcastData['icon'])->toBe('✅');
    expect($broadcastData['severity'])->toBe('success');
    expect($broadcastData['action_url'])->toBe('/test');
    expect($broadcastData['action_label'])->toBe('View');
});

test('event broadcasts with custom event name', function () {
    $notification = Notification::factory()->create();
    $event = new NotificationCreated($notification);

    expect($event->broadcastAs())->toBe('notification.created');
});

test('event always broadcasts is_read as false', function () {
    $notification = Notification::factory()->read()->create();
    $event = new NotificationCreated($notification);

    $broadcastData = $event->broadcastWith();

    // Even though notification is marked as read in DB, broadcast should show false for new notification
    expect($broadcastData['is_read'])->toBe(false);
});

test('event formats created_at as human-readable time', function () {
    $notification = Notification::factory()->create();
    $event = new NotificationCreated($notification);

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['created_at'])->toBeString();
    // Should be a relative time like "1 second ago", "2 minutes ago", etc.
    expect($broadcastData['created_at'])->toMatch('/(ago|second|minute|hour|day|just now)/i');
});

test('event serializes correctly for queue', function () {
    $notification = Notification::factory()->create();
    $event = new NotificationCreated($notification);

    $serialized = serialize($event);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(NotificationCreated::class);
    expect($unserialized->notification->id)->toBe($notification->id);
});

test('event can be dispatched', function () {
    Event::fake();

    $notification = Notification::factory()->create();
    NotificationCreated::dispatch($notification);

    Event::assertDispatched(NotificationCreated::class, function ($event) use ($notification) {
        return $event->notification->id === $notification->id;
    });
});

test('event broadcasts different notification types correctly', function () {
    $types = ['agent_run', 'approval_needed', 'system', 'sync_complete'];

    foreach ($types as $type) {
        $notification = Notification::factory()->create(['type' => $type]);
        $event = new NotificationCreated($notification);

        $broadcastData = $event->broadcastWith();
        expect($broadcastData['type'])->toBe($type);
    }
});

test('event broadcasts different severity levels correctly', function () {
    $severities = ['info', 'success', 'warning', 'error'];

    foreach ($severities as $severity) {
        $notification = Notification::factory()->create(['severity' => $severity]);
        $event = new NotificationCreated($notification);

        $broadcastData = $event->broadcastWith();
        expect($broadcastData['severity'])->toBe($severity);
    }
});

test('event handles null action URL and label', function () {
    $notification = Notification::factory()->create([
        'action_url' => null,
        'action_label' => null,
    ]);

    $event = new NotificationCreated($notification);
    $broadcastData = $event->broadcastWith();

    expect($broadcastData['action_url'])->toBeNull();
    expect($broadcastData['action_label'])->toBeNull();
});

test('event broadcasts on correct private channel for specific user', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->forUser($user->id)->create();
    $event = new NotificationCreated($notification);

    $channels = $event->broadcastOn();
    $privateChannel = collect($channels)->first(fn ($ch) => $ch instanceof PrivateChannel);

    expect($privateChannel)->not->toBeNull();
    expect($privateChannel->name)->toBe('private-notifications.'.$user->id);
});

test('event does not include private channel for global notifications', function () {
    $notification = Notification::factory()->global()->create();
    $event = new NotificationCreated($notification);

    $channels = $event->broadcastOn();
    $privateChannels = collect($channels)->filter(fn ($ch) => $ch instanceof PrivateChannel);

    expect($privateChannels)->toBeEmpty();
});

test('event includes only necessary data in broadcast', function () {
    $notification = Notification::factory()->create([
        'metadata' => ['extra' => 'data', 'hidden' => 'value'],
        'read_at' => now(),
        'dismissed_at' => now(),
    ]);

    $event = new NotificationCreated($notification);
    $broadcastData = $event->broadcastWith();

    // Should not include internal fields like read_at, dismissed_at, metadata
    expect($broadcastData)->not->toHaveKey('metadata');
    expect($broadcastData)->not->toHaveKey('read_at');
    expect($broadcastData)->not->toHaveKey('dismissed_at');
    expect($broadcastData)->not->toHaveKey('user_id');
});

test('event handles approval notification correctly', function () {
    $notification = Notification::factory()->approvalNeeded()->create();
    $event = new NotificationCreated($notification);

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['type'])->toBe('approval_needed');
    expect($broadcastData['icon'])->toBe('⚠️');
    expect($broadcastData['severity'])->toBe('warning');
});

test('event handles agent run notification correctly', function () {
    $notification = Notification::factory()->agentRun()->create();
    $event = new NotificationCreated($notification);

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['type'])->toBe('agent_run');
    expect($broadcastData['icon'])->toBe('✅');
    expect($broadcastData['severity'])->toBe('success');
});

test('event broadcasts for multiple users with same notification', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $notification1 = Notification::factory()->forUser($user1->id)->create();
    $notification2 = Notification::factory()->forUser($user2->id)->create();

    $event1 = new NotificationCreated($notification1);
    $event2 = new NotificationCreated($notification2);

    $channels1 = $event1->broadcastOn();
    $channels2 = $event2->broadcastOn();

    $privateChannel1 = collect($channels1)->first(fn ($ch) => $ch instanceof PrivateChannel);
    $privateChannel2 = collect($channels2)->first(fn ($ch) => $ch instanceof PrivateChannel);

    expect($privateChannel1->name)->toBe('private-notifications.'.$user1->id);
    expect($privateChannel2->name)->toBe('private-notifications.'.$user2->id);
});

test('event preserves notification title and message exactly', function () {
    $title = "Special Title with 'quotes' and \"double quotes\"";
    $message = 'Message with <html> tags and émojis 🎉';

    $notification = Notification::factory()->create([
        'title' => $title,
        'message' => $message,
    ]);

    $event = new NotificationCreated($notification);
    $broadcastData = $event->broadcastWith();

    expect($broadcastData['title'])->toBe($title);
    expect($broadcastData['message'])->toBe($message);
});
