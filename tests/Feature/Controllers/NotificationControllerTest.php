<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can get notifications for user', function () {
    Notification::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'dismissed_at' => null,
    ]);

    $response = $this->getJson(route('notifications.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'notifications' => [
            '*' => [
                'id',
                'type',
                'title',
                'message',
                'icon',
                'severity',
                'is_read',
                'created_at',
            ],
        ],
        'unread_count',
    ]);
    expect(count($response->json('notifications')))->toBe(3);
});

test('does not return dismissed notifications', function () {
    Notification::factory()->create([
        'user_id' => $this->user->id,
        'dismissed_at' => now(),
    ]);

    Notification::factory()->create([
        'user_id' => $this->user->id,
        'dismissed_at' => null,
    ]);

    $response = $this->getJson(route('notifications.index'));

    $response->assertStatus(200);
    expect(count($response->json('notifications')))->toBe(1);
});

test('can get unread count', function () {
    Notification::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    Notification::factory()->create([
        'user_id' => $this->user->id,
        'read_at' => now(),
        'dismissed_at' => null,
    ]);

    $response = $this->getJson(route('notifications.unreadCount'));

    $response->assertStatus(200);
    expect($response->json('count'))->toBe(3);
});

test('can mark notification as read', function () {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'read_at' => null,
    ]);

    $response = $this->postJson(route('notifications.markAsRead', $notification));

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    $notification->refresh();
    expect($notification->read_at)->not->toBeNull();
});

test('can mark all notifications as read', function () {
    Notification::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'read_at' => null,
    ]);

    $response = $this->postJson(route('notifications.markAllAsRead'));

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    $unreadCount = Notification::where('user_id', $this->user->id)
        ->whereNull('read_at')
        ->count();

    expect($unreadCount)->toBe(0);
});

test('can dismiss notification', function () {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'dismissed_at' => null,
    ]);

    $response = $this->postJson(route('notifications.dismiss', $notification));

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    $notification->refresh();
    expect($notification->dismissed_at)->not->toBeNull();
});

test('can dismiss all notifications', function () {
    Notification::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'dismissed_at' => null,
    ]);

    $response = $this->postJson(route('notifications.dismissAll'));

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    $undismissedCount = Notification::where('user_id', $this->user->id)
        ->whereNull('dismissed_at')
        ->count();

    expect($undismissedCount)->toBe(0);
});

test('only returns notifications for authenticated user', function () {
    $otherUser = User::factory()->create();
    Notification::factory()->create(['user_id' => $otherUser->id]);
    Notification::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('notifications.index'));

    $response->assertStatus(200);
    expect(count($response->json('notifications')))->toBe(1);
});
