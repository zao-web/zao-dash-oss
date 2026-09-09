<?php

use App\Models\Notification;
use App\Models\User;

test('has fillable attributes', function () {
    $fillable = (new Notification)->getFillable();

    expect($fillable)->toContain('user_id')
        ->and($fillable)->toContain('type')
        ->and($fillable)->toContain('title')
        ->and($fillable)->toContain('message')
        ->and($fillable)->toContain('icon')
        ->and($fillable)->toContain('severity')
        ->and($fillable)->toContain('action_url')
        ->and($fillable)->toContain('action_label')
        ->and($fillable)->toContain('metadata')
        ->and($fillable)->toContain('read_at')
        ->and($fillable)->toContain('dismissed_at');
});

test('casts metadata to array', function () {
    $notification = Notification::factory()->create(['metadata' => ['key' => 'value']]);

    expect($notification->metadata)->toBeArray()
        ->and($notification->metadata)->toBe(['key' => 'value']);
});

test('casts read_at to datetime', function () {
    $notification = Notification::factory()->create(['read_at' => now()]);

    expect($notification->read_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts dismissed_at to datetime', function () {
    $notification = Notification::factory()->create(['dismissed_at' => now()]);

    expect($notification->dismissed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to user relationship', function () {
    $notification = Notification::factory()->create();

    expect($notification->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('scopeUnread filters unread notifications', function () {
    Notification::factory()->create(['read_at' => null]);
    Notification::factory()->create(['read_at' => now()]);

    $unread = Notification::unread()->count();

    expect($unread)->toBe(1);
});

test('scopeUndismissed filters undismissed notifications', function () {
    Notification::factory()->create(['dismissed_at' => null]);
    Notification::factory()->create(['dismissed_at' => now()]);

    $undismissed = Notification::undismissed()->count();

    expect($undismissed)->toBe(1);
});

test('scopeForUser filters by user', function () {
    $user = User::factory()->create();
    Notification::factory()->create(['user_id' => $user->id]);
    Notification::factory()->create(['user_id' => null]);

    $userNotifications = Notification::forUser($user->id)->count();

    expect($userNotifications)->toBe(2); // User's notifications + global
});

test('markAsRead sets read_at timestamp', function () {
    $notification = Notification::factory()->create(['read_at' => null]);

    $notification->markAsRead();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('dismiss sets dismissed_at timestamp', function () {
    $notification = Notification::factory()->create(['dismissed_at' => null]);

    $notification->dismiss();

    expect($notification->fresh()->dismissed_at)->not->toBeNull();
});

test('isUnread returns true for unread notifications', function () {
    $notification = Notification::factory()->create(['read_at' => null]);

    expect($notification->isUnread())->toBeTrue();
});

test('isUnread returns false for read notifications', function () {
    $notification = Notification::factory()->create(['read_at' => now()]);

    expect($notification->isUnread())->toBeFalse();
});

test('can be created via factory', function () {
    $notification = Notification::factory()->create();

    expect($notification)->toBeInstanceOf(Notification::class)
        ->and($notification->exists)->toBeTrue();
});
