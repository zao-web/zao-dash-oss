<?php

use App\Models\LinkedInCredential;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has fillable attributes', function () {
    $fillable = (new LinkedInCredential)->getFillable();

    expect($fillable)->toContain('user_id')
        ->and($fillable)->toContain('linkedin_id')
        ->and($fillable)->toContain('name')
        ->and($fillable)->toContain('email')
        ->and($fillable)->toContain('profile_url')
        ->and($fillable)->toContain('profile_picture')
        ->and($fillable)->toContain('headline')
        ->and($fillable)->toContain('access_token')
        ->and($fillable)->toContain('refresh_token')
        ->and($fillable)->toContain('token_expires_at')
        ->and($fillable)->toContain('scopes')
        ->and($fillable)->toContain('organization_id')
        ->and($fillable)->toContain('organization_name')
        ->and($fillable)->toContain('is_active')
        ->and($fillable)->toContain('last_synced_at');
});

test('hides access_token attribute', function () {
    expect((new LinkedInCredential)->getHidden())->toContain('access_token');
});

test('hides refresh_token attribute', function () {
    expect((new LinkedInCredential)->getHidden())->toContain('refresh_token');
});

test('casts token_expires_at to datetime', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'token_expires_at' => now()->addDays(60),
        'is_active' => true,
    ]);

    expect($credential->token_expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts last_synced_at to datetime', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'last_synced_at' => now(),
        'is_active' => true,
    ]);

    expect($credential->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts scopes to array', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'scopes' => ['r_liteprofile', 'w_member_social'],
        'is_active' => true,
    ]);

    expect($credential->scopes)->toBeArray()
        ->and($credential->scopes)->toBe(['r_liteprofile', 'w_member_social']);
});

test('casts is_active to boolean', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'is_active' => false,
    ]);

    expect($credential->is_active)->toBeFalse();
});

test('belongs to user relationship', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isTokenExpired returns true when expired', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'token_expires_at' => now()->subDay(),
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeTrue();
});

test('isTokenExpired returns false when not expired', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'token_expires_at' => now()->addDays(60),
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeFalse();
});

test('isTokenExpired returns false when no expiration set', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->isTokenExpired())->toBeFalse();
});

test('hasOrganizationAccess returns true when organization_id exists', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'organization_id' => 'org-123',
        'organization_name' => 'Test Org',
        'is_active' => true,
    ]);

    expect($credential->hasOrganizationAccess())->toBeTrue();
});

test('hasOrganizationAccess returns false when organization_id is null', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential->hasOrganizationAccess())->toBeFalse();
});

test('can be created', function () {
    $user = User::factory()->create();
    $credential = LinkedInCredential::create([
        'user_id' => $user->id,
        'linkedin_id' => 'linkedin-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    expect($credential)->toBeInstanceOf(LinkedInCredential::class)
        ->and($credential->exists)->toBeTrue();
});
