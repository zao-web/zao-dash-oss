<?php

use App\Models\User;
use App\Models\XCredential;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has correct table name', function () {
    expect((new XCredential)->getTable())->toBe('x_credentials');
});

test('has fillable attributes', function () {
    $fillable = (new XCredential)->getFillable();

    expect($fillable)->toContain('user_id')
        ->and($fillable)->toContain('x_user_id')
        ->and($fillable)->toContain('username')
        ->and($fillable)->toContain('name')
        ->and($fillable)->toContain('profile_image_url')
        ->and($fillable)->toContain('description')
        ->and($fillable)->toContain('verified')
        ->and($fillable)->toContain('followers_count')
        ->and($fillable)->toContain('following_count')
        ->and($fillable)->toContain('tweet_count')
        ->and($fillable)->toContain('access_token')
        ->and($fillable)->toContain('refresh_token')
        ->and($fillable)->toContain('token_expires_at')
        ->and($fillable)->toContain('scopes')
        ->and($fillable)->toContain('is_active')
        ->and($fillable)->toContain('last_synced_at');
});

test('hides access_token attribute', function () {
    expect((new XCredential)->getHidden())->toContain('access_token');
});

test('hides refresh_token attribute', function () {
    expect((new XCredential)->getHidden())->toContain('refresh_token');
});

test('casts token_expires_at to datetime', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'token_expires_at' => now()->addHours(2),
        'is_active' => true,
    ]);

    expect($credential->token_expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts last_synced_at to datetime', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'last_synced_at' => now(),
        'is_active' => true,
    ]);

    expect($credential->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts scopes to array', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'scopes' => ['tweet.read', 'tweet.write'],
        'is_active' => true,
    ]);

    expect($credential->scopes)->toBeArray()
        ->and($credential->scopes)->toBe(['tweet.read', 'tweet.write']);
});

test('casts is_active to boolean', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->is_active)->toBeTrue();
});

test('casts verified to boolean', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'verified' => true,
        'is_active' => true,
    ]);

    expect($credential->verified)->toBeTrue();
});

test('casts followers_count to integer', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'followers_count' => 1500,
        'is_active' => true,
    ]);

    expect($credential->followers_count)->toBeInt()
        ->and($credential->followers_count)->toBe(1500);
});

test('casts following_count to integer', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'following_count' => 500,
        'is_active' => true,
    ]);

    expect($credential->following_count)->toBeInt()
        ->and($credential->following_count)->toBe(500);
});

test('casts tweet_count to integer', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'tweet_count' => 10000,
        'is_active' => true,
    ]);

    expect($credential->tweet_count)->toBeInt()
        ->and($credential->tweet_count)->toBe(10000);
});

test('belongs to user relationship', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isTokenExpired returns true when expired', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'token_expires_at' => now()->subHour(),
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeTrue();
});

test('isTokenExpired returns false when not expired', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'token_expires_at' => now()->addHours(2),
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeFalse();
});

test('isTokenExpired returns false when no expiration set', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeFalse();
});

test('can be created', function () {
    $user = User::factory()->create();
    $credential = XCredential::create([
        'user_id' => $user->id,
        'x_user_id' => 'x-123',
        'username' => 'testuser',
        'name' => 'Test User',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential)->toBeInstanceOf(XCredential::class)
        ->and($credential->exists)->toBeTrue();
});
