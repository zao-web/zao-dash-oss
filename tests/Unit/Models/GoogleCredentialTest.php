<?php

use App\Models\GoogleCredential;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new GoogleCredential)->getGuarded())->toBe([]);
});

test('casts scopes to array', function () {
    $credential = GoogleCredential::factory()->create([
        'scopes' => ['gmail', 'calendar'],
    ]);

    expect($credential->scopes)->toBeArray()
        ->and($credential->scopes)->toBe(['gmail', 'calendar']);
});

test('casts expires_at to datetime', function () {
    $credential = GoogleCredential::factory()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect($credential->expires_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts watch_expiration to datetime', function () {
    $credential = GoogleCredential::factory()->create([
        'watch_expiration' => now()->addDay(),
    ]);

    expect($credential->watch_expiration)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts calendar_watch_expiration to datetime', function () {
    $credential = GoogleCredential::factory()->create([
        'calendar_watch_expiration' => now()->addDay(),
    ]);

    expect($credential->calendar_watch_expiration)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('hides access_token attribute', function () {
    expect((new GoogleCredential)->getHidden())->toContain('access_token');
});

test('hides refresh_token attribute', function () {
    expect((new GoogleCredential)->getHidden())->toContain('refresh_token');
});

test('belongs to user relationship', function () {
    $credential = GoogleCredential::factory()->create();

    expect($credential->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('encrypts access_token on set', function () {
    $credential = new GoogleCredential;
    $credential->access_token = 'test-access-token';

    expect($credential->getAttributes()['access_token'])->not->toBe('test-access-token');
});

test('decrypts access_token on get', function () {
    $credential = GoogleCredential::factory()->create();
    $credential->access_token = 'test-access-token';
    $credential->save();

    $retrieved = GoogleCredential::find($credential->id);
    expect($retrieved->access_token)->toBe('test-access-token');
});

test('handles null access_token on get', function () {
    $credential = new GoogleCredential;
    $credential->setAttribute('access_token', null);

    expect($credential->access_token)->toBeNull();
});

test('encrypts refresh_token on set', function () {
    $credential = new GoogleCredential;
    $credential->refresh_token = 'test-refresh-token';

    expect($credential->getAttributes()['refresh_token'])->not->toBe('test-refresh-token');
});

test('decrypts refresh_token on get', function () {
    $credential = GoogleCredential::factory()->create();
    $credential->refresh_token = 'test-refresh-token';
    $credential->save();

    $retrieved = GoogleCredential::find($credential->id);
    expect($retrieved->refresh_token)->toBe('test-refresh-token');
});

test('handles null refresh_token on get', function () {
    $credential = new GoogleCredential;
    $credential->setAttribute('refresh_token', null);

    expect($credential->refresh_token)->toBeNull();
});

test('isExpired returns true when token is expired', function () {
    $credential = GoogleCredential::factory()->create([
        'expires_at' => now()->subHour(),
    ]);

    expect($credential->isExpired())->toBeTrue();
});

test('isExpired returns false when token is not expired', function () {
    $credential = GoogleCredential::factory()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect($credential->isExpired())->toBeFalse();
});

test('needsWatchRenewal returns true when watch_expiration is null', function () {
    $credential = GoogleCredential::factory()->create(['watch_expiration' => null]);

    expect($credential->needsWatchRenewal())->toBeTrue();
});

test('needsWatchRenewal returns true when watch expires soon', function () {
    $credential = GoogleCredential::factory()->create([
        'watch_expiration' => now()->addHours(12),
    ]);

    expect($credential->needsWatchRenewal())->toBeTrue();
});

test('needsWatchRenewal returns false when watch has time', function () {
    $credential = GoogleCredential::factory()->create([
        'watch_expiration' => now()->addDays(2),
    ]);

    expect($credential->needsWatchRenewal())->toBeFalse();
});

test('needsCalendarWatchRenewal returns true when calendar_watch_expiration is null', function () {
    $credential = GoogleCredential::factory()->create(['calendar_watch_expiration' => null]);

    expect($credential->needsCalendarWatchRenewal())->toBeTrue();
});

test('needsCalendarWatchRenewal returns true when calendar watch expires soon', function () {
    $credential = GoogleCredential::factory()->create([
        'calendar_watch_expiration' => now()->addHours(12),
    ]);

    expect($credential->needsCalendarWatchRenewal())->toBeTrue();
});

test('needsCalendarWatchRenewal returns false when calendar watch has time', function () {
    $credential = GoogleCredential::factory()->create([
        'calendar_watch_expiration' => now()->addDays(2),
    ]);

    expect($credential->needsCalendarWatchRenewal())->toBeFalse();
});

test('can be created via factory', function () {
    $credential = GoogleCredential::factory()->create();

    expect($credential)->toBeInstanceOf(GoogleCredential::class)
        ->and($credential->exists)->toBeTrue();
});
