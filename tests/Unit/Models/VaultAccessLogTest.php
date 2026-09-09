<?php

use App\Models\VaultAccessLog;
use App\Models\VaultSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has correct fillable attributes', function () {
    $expected = [
        'vault_secret_id',
        'accessor_type',
        'accessor_id',
        'accessor_name',
        'action',
        'ip_address',
        'user_agent',
        'context',
        'was_successful',
        'failure_reason',
        'created_at',
    ];

    expect((new VaultAccessLog)->getFillable())->toEqual($expected);
});

test('does not use timestamps', function () {
    $log = new VaultAccessLog;

    expect($log->timestamps)->toBeFalse();
});

test('casts context to array', function () {
    $context = ['key' => 'value', 'reason' => 'testing'];
    $log = VaultAccessLog::factory()->create(['context' => $context]);

    expect($log->context)->toBeArray()
        ->and($log->context)->toHaveKey('key')
        ->and($log->context['key'])->toBe('value');
});

test('casts was_successful to boolean', function () {
    $log = VaultAccessLog::factory()->create(['was_successful' => true]);

    expect($log->was_successful)->toBeBool()
        ->and($log->was_successful)->toBeTrue();
});

test('casts created_at to datetime', function () {
    $log = VaultAccessLog::factory()->create(['created_at' => now()]);

    expect($log->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to vault secret relationship', function () {
    $log = VaultAccessLog::factory()->create();

    expect($log->secret())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has correct type constants', function () {
    expect(VaultAccessLog::TYPE_USER)->toBe('user')
        ->and(VaultAccessLog::TYPE_AGENT)->toBe('agent')
        ->and(VaultAccessLog::TYPE_SYSTEM)->toBe('system');
});

test('has correct action constants', function () {
    expect(VaultAccessLog::ACTION_READ)->toBe('read')
        ->and(VaultAccessLog::ACTION_WRITE)->toBe('write')
        ->and(VaultAccessLog::ACTION_DELETE)->toBe('delete')
        ->and(VaultAccessLog::ACTION_ROTATE)->toBe('rotate');
});

test('log static method creates access log with minimal params', function () {
    $secret = VaultSecret::factory()->create();

    $log = VaultAccessLog::log(
        $secret,
        VaultAccessLog::ACTION_READ,
        VaultAccessLog::TYPE_USER
    );

    expect($log)->toBeInstanceOf(VaultAccessLog::class)
        ->and($log->exists)->toBeTrue()
        ->and($log->vault_secret_id)->toBe($secret->id)
        ->and($log->action)->toBe(VaultAccessLog::ACTION_READ)
        ->and($log->accessor_type)->toBe(VaultAccessLog::TYPE_USER)
        ->and($log->was_successful)->toBeTrue();
});

test('log static method creates access log with all params', function () {
    $secret = VaultSecret::factory()->create();

    $log = VaultAccessLog::log(
        secret: $secret,
        action: VaultAccessLog::ACTION_WRITE,
        accessorType: VaultAccessLog::TYPE_AGENT,
        accessorId: 123,
        accessorName: 'test-agent',
        wasSuccessful: false,
        failureReason: 'Insufficient permissions',
        context: ['key' => 'value']
    );

    expect($log)->toBeInstanceOf(VaultAccessLog::class)
        ->and($log->exists)->toBeTrue()
        ->and($log->vault_secret_id)->toBe($secret->id)
        ->and($log->action)->toBe(VaultAccessLog::ACTION_WRITE)
        ->and($log->accessor_type)->toBe(VaultAccessLog::TYPE_AGENT)
        ->and($log->accessor_id)->toBe(123)
        ->and($log->accessor_name)->toBe('test-agent')
        ->and($log->was_successful)->toBeFalse()
        ->and($log->failure_reason)->toBe('Insufficient permissions')
        ->and($log->context)->toHaveKey('key')
        ->and($log->context['key'])->toBe('value');
});

test('log static method captures ip address from request', function () {
    $secret = VaultSecret::factory()->create();

    $log = VaultAccessLog::log(
        $secret,
        VaultAccessLog::ACTION_READ,
        VaultAccessLog::TYPE_USER
    );

    expect($log->ip_address)->not->toBeNull();
});

test('log static method captures user agent from request', function () {
    $secret = VaultSecret::factory()->create();

    $log = VaultAccessLog::log(
        $secret,
        VaultAccessLog::ACTION_READ,
        VaultAccessLog::TYPE_USER
    );

    expect($log->user_agent)->not->toBeNull();
});

test('can be created via factory', function () {
    $log = VaultAccessLog::factory()->create();

    expect($log)->toBeInstanceOf(VaultAccessLog::class)
        ->and($log->exists)->toBeTrue();
});
