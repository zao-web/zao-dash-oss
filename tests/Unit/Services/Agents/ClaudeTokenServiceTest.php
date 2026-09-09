<?php

use App\Jobs\RefreshClaudeTokenJob;
use App\Services\Agents\ClaudeTokenService;
use App\Services\Vault\VaultService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Bus::fake();
    Queue::fake();
});

it('detects expired token errors from error messages', function () {
    $service = app(ClaudeTokenService::class);

    $patterns = [
        'Invalid API key',
        'Please run /login',
        'authentication failed',
        'Unauthorized',
        'invalid_api_key',
        'authentication_error',
        'token expired',
    ];

    foreach ($patterns as $pattern) {
        expect($service->isTokenExpiredError($pattern))->toBeTrue();
        expect($service->isTokenExpiredError(strtoupper($pattern)))->toBeTrue();
    }
});

it('returns false for non-expired error messages', function () {
    $service = app(ClaudeTokenService::class);

    $messages = [
        'Connection timeout',
        'Network error',
        'Rate limit exceeded',
        'Internal server error',
    ];

    foreach ($messages as $message) {
        expect($service->isTokenExpiredError($message))->toBeFalse();
    }
});

it('detects token expiration from result array', function () {
    $service = app(ClaudeTokenService::class);

    $result = [
        'is_error' => true,
        'result' => 'Invalid API key · Please run /login',
    ];

    expect($service->isTokenExpiredFromResult($result))->toBeTrue();
});

it('marks token as expired in cache', function () {
    $service = app(ClaudeTokenService::class);

    $service->markTokenAsExpired();

    $metadata = Cache::get('claude_token_metadata');

    expect($metadata['is_expired'])->toBeTrue();
    expect($metadata['last_error'])->toBe('Token expired or invalid');
    expect($metadata['expired_at'])->not->toBeNull();
});

it('identifies when token needs refresh', function () {
    $service = app(ClaudeTokenService::class);

    // Set token to expire in 5 days
    Cache::put('claude_token_metadata', [
        'is_expired' => false,
        'expires_at' => now()->addDays(5),
    ], now()->addDays(30));

    expect($service->needsRefresh())->toBeTrue();
});

it('identifies when token does not need refresh', function () {
    $service = app(ClaudeTokenService::class);

    // Set token to expire in 30 days
    Cache::put('claude_token_metadata', [
        'is_expired' => false,
        'expires_at' => now()->addDays(30),
    ], now()->addDays(60));

    expect($service->needsRefresh())->toBeFalse();
});

it('handles expired token by dispatching refresh job', function () {
    $service = app(ClaudeTokenService::class);

    $service->handleExpiredToken();

    // Verify refresh job was dispatched
    Bus::assertDispatched(RefreshClaudeTokenJob::class);

    // Verify token was marked as expired
    $metadata = Cache::get('claude_token_metadata');
    expect($metadata['is_expired'])->toBeTrue();
});

it('updates token metadata after successful refresh', function () {
    $service = app(ClaudeTokenService::class);

    $token = 'new_test_token';
    $expiresAt = now()->addDays(90);

    $service->updateTokenMetadata($token, $expiresAt);

    $metadata = Cache::get('claude_token_metadata');

    expect($metadata['is_expired'])->toBeFalse();
    expect($metadata['last_refreshed_at'])->not->toBeNull();
    expect($metadata['expires_at'])->toBeInstanceOf(Carbon::class);
    expect($metadata['expires_at']->format('Y-m-d H:i'))->toBe($expiresAt->format('Y-m-d H:i'));
    expect($metadata['refresh_attempts'])->toBe(0);
    expect($metadata['token_length'])->toBe(strlen($token));
});

it('increments refresh attempt counter', function () {
    $service = app(ClaudeTokenService::class);

    $attempts1 = $service->incrementRefreshAttempts();
    expect($attempts1)->toBe(1);

    $attempts2 = $service->incrementRefreshAttempts();
    expect($attempts2)->toBe(2);

    $attempts3 = $service->incrementRefreshAttempts();
    expect($attempts3)->toBe(3);
});

it('determines when to pause agent execution', function () {
    $service = app(ClaudeTokenService::class);

    // Not expired - should not pause
    expect($service->shouldPauseAgentExecution())->toBeFalse();

    // Expired with < 3 attempts - should not pause
    Cache::put('claude_token_metadata', [
        'is_expired' => true,
        'refresh_attempts' => 2,
    ], now()->addDays(30));

    expect($service->shouldPauseAgentExecution())->toBeFalse();

    // Expired with >= 3 attempts - should pause
    Cache::put('claude_token_metadata', [
        'is_expired' => true,
        'refresh_attempts' => 3,
    ], now()->addDays(30));

    expect($service->shouldPauseAgentExecution())->toBeTrue();
});

it('returns comprehensive token status', function () {
    $service = app(ClaudeTokenService::class);

    Cache::put('claude_token_metadata', [
        'is_expired' => false,
        'last_refreshed_at' => now()->subDays(10),
        'expires_at' => now()->addDays(20),
        'refresh_attempts' => 0,
    ], now()->addDays(30));

    $status = $service->getTokenStatus();

    expect($status)->toHaveKeys([
        'has_token',
        'is_expired',
        'needs_refresh',
        'should_pause_execution',
        'expires_at',
        'last_refreshed_at',
        'last_error',
        'refresh_attempts',
        'days_until_expiration',
    ]);

    expect($status['is_expired'])->toBeFalse();
    expect($status['refresh_attempts'])->toBe(0);
});

it('stores token in Vault', function () {
    $vault = Mockery::mock(VaultService::class);
    $vault->shouldReceive('store')
        ->once()
        ->withArgs(function ($key, $value, $name, $options) {
            return $key === 'claude_oauth_token'
                && strlen($value) > 0
                && $name === 'Claude CLI OAuth Token'
                && $options['category'] === 'oauth';
        })
        ->andReturn(new \App\Models\VaultSecret);

    $service = new ClaudeTokenService($vault);

    $result = $service->storeToken('test_token_12345');

    expect($result)->toBeTrue();

    // Verify metadata was updated
    $metadata = Cache::get('claude_token_metadata');
    expect($metadata['is_expired'])->toBeFalse();
    expect($metadata['token_length'])->toBe(16); // 'test_token_12345' is 16 chars
});
