<?php

use App\Models\GitHubInstallation;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new GitHubInstallation)->getGuarded())->toBe([]);
});

test('casts permissions to array', function () {
    $installation = GitHubInstallation::factory()->create([
        'permissions' => ['read', 'write'],
    ]);

    expect($installation->permissions)->toBeArray()
        ->and($installation->permissions)->toBe(['read', 'write']);
});

test('casts token_expires_at to datetime', function () {
    $installation = GitHubInstallation::factory()->create([
        'token_expires_at' => now()->addHour(),
    ]);

    expect($installation->token_expires_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts connected_at to datetime', function () {
    $installation = GitHubInstallation::factory()->create([
        'connected_at' => now(),
    ]);

    expect($installation->connected_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('hides access_token attribute', function () {
    expect((new GitHubInstallation)->getHidden())->toContain('access_token');
});

test('has many repos relationship', function () {
    $installation = GitHubInstallation::factory()->create();

    expect($installation->repos())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('encrypts access_token on set', function () {
    $installation = new GitHubInstallation;
    $installation->access_token = 'test-token-123';

    expect($installation->getAttributes()['access_token'])->not->toBe('test-token-123');
});

test('decrypts access_token on get', function () {
    $installation = GitHubInstallation::factory()->create();
    $installation->access_token = 'test-token-123';
    $installation->save();

    $retrieved = GitHubInstallation::find($installation->id);
    expect($retrieved->access_token)->toBe('test-token-123');
});

test('handles null access_token on set', function () {
    $installation = new GitHubInstallation;
    $installation->access_token = null;

    expect($installation->getAttributes()['access_token'])->toBeNull();
});

test('handles null access_token on get', function () {
    $installation = GitHubInstallation::factory()->create(['access_token' => null]);

    expect($installation->access_token)->toBeNull();
});

test('tokenIsExpired returns true when token_expires_at is null', function () {
    $installation = GitHubInstallation::factory()->create(['token_expires_at' => null]);

    expect($installation->tokenIsExpired())->toBeTrue();
});

test('tokenIsExpired returns true when token is expired', function () {
    $installation = GitHubInstallation::factory()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect($installation->tokenIsExpired())->toBeTrue();
});

test('tokenIsExpired returns false when token is not expired', function () {
    $installation = GitHubInstallation::factory()->create([
        'token_expires_at' => now()->addHour(),
    ]);

    expect($installation->tokenIsExpired())->toBeFalse();
});

test('isOrgInstallation returns true for Organization', function () {
    $installation = GitHubInstallation::factory()->create(['account_type' => 'Organization']);

    expect($installation->isOrgInstallation())->toBeTrue();
});

test('isOrgInstallation returns false for User', function () {
    $installation = GitHubInstallation::factory()->create(['account_type' => 'User']);

    expect($installation->isOrgInstallation())->toBeFalse();
});

test('can be created via factory', function () {
    $installation = GitHubInstallation::factory()->create();

    expect($installation)->toBeInstanceOf(GitHubInstallation::class)
        ->and($installation->exists)->toBeTrue();
});
