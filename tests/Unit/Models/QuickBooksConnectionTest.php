<?php

use App\Models\QuickBooksConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new QuickBooksConnection)->getGuarded())->toBe([]);
});

test('uses correct table name', function () {
    expect((new QuickBooksConnection)->getTable())->toBe('quickbooks_connections');
});

test('casts access_token_expires_at to datetime', function () {
    $connection = QuickBooksConnection::factory()->create(['access_token_expires_at' => now()]);

    expect($connection->access_token_expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts refresh_token_expires_at to datetime', function () {
    $connection = QuickBooksConnection::factory()->create(['refresh_token_expires_at' => now()]);

    expect($connection->refresh_token_expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts last_synced_at to datetime', function () {
    $connection = QuickBooksConnection::factory()->create(['last_synced_at' => now()]);

    expect($connection->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts sync_enabled to boolean', function () {
    $connection = QuickBooksConnection::factory()->create(['sync_enabled' => true]);

    expect($connection->sync_enabled)->toBeBool()
        ->and($connection->sync_enabled)->toBeTrue();
});

test('hides access_token in array', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->toArray())->not->toHaveKey('access_token');
});

test('hides refresh_token in array', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->toArray())->not->toHaveKey('refresh_token');
});

test('belongs to user relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many accounts relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->accounts())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many transactions relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->transactions())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many invoices relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->invoices())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many customers relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->customers())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many snapshots relationship', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection->snapshots())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('encrypts access token on set', function () {
    $connection = new QuickBooksConnection;
    $plainToken = 'test-access-token-123';

    $connection->access_token = $plainToken;

    expect($connection->attributes['access_token'])->not->toBe($plainToken)
        ->and(Crypt::decryptString($connection->attributes['access_token']))->toBe($plainToken);
});

test('decrypts access token on get', function () {
    $connection = QuickBooksConnection::factory()->create();
    $plainToken = 'test-access-token-456';

    $connection->access_token = $plainToken;
    $connection->save();
    $connection->refresh();

    expect($connection->access_token)->toBe($plainToken);
});

test('returns null for empty access token', function () {
    $connection = new QuickBooksConnection;

    expect($connection->access_token)->toBeNull();
});

test('encrypts refresh token on set', function () {
    $connection = new QuickBooksConnection;
    $plainToken = 'test-refresh-token-123';

    $connection->refresh_token = $plainToken;

    expect($connection->attributes['refresh_token'])->not->toBe($plainToken)
        ->and(Crypt::decryptString($connection->attributes['refresh_token']))->toBe($plainToken);
});

test('decrypts refresh token on get', function () {
    $connection = QuickBooksConnection::factory()->create();
    $plainToken = 'test-refresh-token-456';

    $connection->refresh_token = $plainToken;
    $connection->save();
    $connection->refresh();

    expect($connection->refresh_token)->toBe($plainToken);
});

test('returns null for empty refresh token', function () {
    $connection = new QuickBooksConnection;

    expect($connection->refresh_token)->toBeNull();
});

test('isAccessTokenExpired returns true when token is expired', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token_expires_at' => now()->subHour(),
    ]);

    expect($connection->isAccessTokenExpired())->toBeTrue();
});

test('isAccessTokenExpired returns false when token is not expired', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token_expires_at' => now()->addHour(),
    ]);

    expect($connection->isAccessTokenExpired())->toBeFalse();
});

test('isRefreshTokenExpiring returns true when token expires within 7 days', function () {
    $connection = QuickBooksConnection::factory()->create([
        'refresh_token_expires_at' => now()->addDays(5),
    ]);

    expect($connection->isRefreshTokenExpiring())->toBeTrue();
});

test('isRefreshTokenExpiring returns false when token has more than 7 days', function () {
    $connection = QuickBooksConnection::factory()->create([
        'refresh_token_expires_at' => now()->addDays(14),
    ]);

    expect($connection->isRefreshTokenExpiring())->toBeFalse();
});

test('needsTokenRefresh returns true when token expires within 10 minutes', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token_expires_at' => now()->addMinutes(5),
    ]);

    expect($connection->needsTokenRefresh())->toBeTrue();
});

test('needsTokenRefresh returns false when token has more than 10 minutes', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token_expires_at' => now()->addMinutes(15),
    ]);

    expect($connection->needsTokenRefresh())->toBeFalse();
});

test('can be created via factory', function () {
    $connection = QuickBooksConnection::factory()->create();

    expect($connection)->toBeInstanceOf(QuickBooksConnection::class)
        ->and($connection->exists)->toBeTrue();
});
