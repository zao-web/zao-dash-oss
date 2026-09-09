<?php

use App\Models\HarvestCredential;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new HarvestCredential)->getGuarded())->toBe(['*']);
});

test('casts expires_at to datetime', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    expect($credential->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('hides access_token attribute', function () {
    expect((new HarvestCredential)->getHidden())->toContain('access_token');
});

test('hides refresh_token attribute', function () {
    expect((new HarvestCredential)->getHidden())->toContain('refresh_token');
});

test('belongs to user relationship', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    expect($credential->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('encrypts access_token on set', function () {
    $credential = new HarvestCredential;
    $credential->access_token = 'test-token';

    expect($credential->getAttributes()['access_token'])->not->toBe('test-token');
});

test('decrypts access_token on get', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    $retrieved = HarvestCredential::find($credential->id);
    expect($retrieved->access_token)->toBe('test-token');
});

test('encrypts refresh_token on set', function () {
    $credential = new HarvestCredential;
    $credential->refresh_token = 'refresh-token';

    expect($credential->getAttributes()['refresh_token'])->not->toBe('refresh-token');
});

test('decrypts refresh_token on get', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    $retrieved = HarvestCredential::find($credential->id);
    expect($retrieved->refresh_token)->toBe('refresh-token');
});

test('isExpired returns true when expired', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->subHours(1),
    ]);

    expect($credential->isExpired())->toBeTrue();
});

test('isExpired returns false when not expired', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    expect($credential->isExpired())->toBeFalse();
});

test('can be created', function () {
    $user = User::factory()->create();
    $credential = HarvestCredential::create([
        'user_id' => $user->id,
        'account_id' => '12345',
        'access_token' => 'test-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHours(12),
    ]);

    expect($credential)->toBeInstanceOf(HarvestCredential::class)
        ->and($credential->exists)->toBeTrue();
});
