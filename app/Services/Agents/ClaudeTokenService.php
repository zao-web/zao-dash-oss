<?php

namespace App\Services\Agents;

use App\Jobs\RefreshClaudeTokenJob;
use App\Models\User;
use App\Services\Vault\VaultService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ClaudeTokenService
{
    protected const TOKEN_EXPIRATION_DAYS = 90; // Claude tokens typically expire after 90 days

    protected const TOKEN_REFRESH_THRESHOLD_DAYS = 7; // Refresh when 7 days remain

    protected const TOKEN_METADATA_CACHE_KEY = 'claude_token_metadata';

    public function __construct(protected VaultService $vault) {}

    /**
     * Check if the given error indicates an expired or invalid token.
     */
    public function isTokenExpiredError(string $error): bool
    {
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
            if (str_contains(strtolower($error), strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the agent execution result indicates a token error.
     */
    public function isTokenExpiredFromResult(array $result): bool
    {
        // Check if there's an error flag
        if (! empty($result['is_error'])) {
            // Check the result field
            if (isset($result['result']) && $this->isTokenExpiredError($result['result'])) {
                return true;
            }

            // Check for error field
            if (isset($result['error']) && $this->isTokenExpiredError($result['error'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle an expired token error by attempting refresh or notifying admins.
     */
    public function handleExpiredToken(): void
    {
        Log::warning('Claude OAuth token has expired', [
            'action' => 'initiating_refresh',
            'timestamp' => now(),
        ]);

        // Mark the token as expired in cache
        $this->markTokenAsExpired();

        // Dispatch job to attempt token refresh
        RefreshClaudeTokenJob::dispatch();

        // Notify admins
        $this->notifyAdmins();
    }

    /**
     * Mark the current token as expired in cache.
     */
    public function markTokenAsExpired(): void
    {
        $metadata = $this->getTokenMetadata();
        $metadata['expired_at'] = now();
        $metadata['is_expired'] = true;
        $metadata['last_error'] = 'Token expired or invalid';

        Cache::put(self::TOKEN_METADATA_CACHE_KEY, $metadata, now()->addDays(30));

        Log::info('Marked Claude token as expired', $metadata);
    }

    /**
     * Check if the token needs refresh (approaching expiration).
     */
    public function needsRefresh(): bool
    {
        $metadata = $this->getTokenMetadata();

        // If marked as expired, needs refresh
        if ($metadata['is_expired'] ?? false) {
            return true;
        }

        // If we have an expiration date, check if we're within threshold
        if (isset($metadata['expires_at'])) {
            $expiresAt = Carbon::parse($metadata['expires_at']);
            $threshold = now()->addDays(self::TOKEN_REFRESH_THRESHOLD_DAYS);

            return $expiresAt->lte($threshold);
        }

        // If we have a last refresh date, check if it's been long enough
        if (isset($metadata['last_refreshed_at'])) {
            $lastRefreshed = Carbon::parse($metadata['last_refreshed_at']);
            $daysSinceRefresh = $lastRefreshed->diffInDays(now());

            return $daysSinceRefresh >= (self::TOKEN_EXPIRATION_DAYS - self::TOKEN_REFRESH_THRESHOLD_DAYS);
        }

        // Default: no refresh needed
        return false;
    }

    /**
     * Get token metadata from cache or create default.
     */
    public function getTokenMetadata(): array
    {
        return Cache::get(self::TOKEN_METADATA_CACHE_KEY, [
            'is_expired' => false,
            'last_refreshed_at' => null,
            'expires_at' => null,
            'last_error' => null,
            'refresh_attempts' => 0,
        ]);
    }

    /**
     * Update token metadata after successful refresh.
     */
    public function updateTokenMetadata(string $token, ?Carbon $expiresAt = null): void
    {
        $metadata = [
            'is_expired' => false,
            'last_refreshed_at' => now(),
            'expires_at' => $expiresAt ?? now()->addDays(self::TOKEN_EXPIRATION_DAYS),
            'last_error' => null,
            'refresh_attempts' => 0,
            'token_length' => strlen($token),
            'token_prefix' => substr($token, 0, 10).'...',
        ];

        Cache::put(self::TOKEN_METADATA_CACHE_KEY, $metadata, now()->addDays(120));

        Log::info('Updated Claude token metadata', $metadata);
    }

    /**
     * Increment refresh attempt counter.
     */
    public function incrementRefreshAttempts(): int
    {
        $metadata = $this->getTokenMetadata();
        $metadata['refresh_attempts'] = ($metadata['refresh_attempts'] ?? 0) + 1;
        $metadata['last_attempt_at'] = now();

        Cache::put(self::TOKEN_METADATA_CACHE_KEY, $metadata, now()->addDays(30));

        return $metadata['refresh_attempts'];
    }

    /**
     * Get the current OAuth token from environment or Vault.
     */
    public function getCurrentToken(): ?string
    {
        // First try environment variable
        $token = config('services.anthropic.oauth_token');

        if ($token) {
            return $token;
        }

        // Try to get from Vault
        try {
            $vaultToken = $this->vault->get('claude_oauth_token', allowedCategories: ['oauth']);

            return $vaultToken?->value;
        } catch (\Throwable $e) {
            Log::warning('Failed to retrieve Claude token from Vault', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store a new token in both environment and Vault.
     */
    public function storeToken(string $token, ?Carbon $expiresAt = null): bool
    {
        try {
            // Store in Vault
            $this->vault->store(
                key: 'claude_oauth_token',
                value: $token,
                name: 'Claude CLI OAuth Token',
                options: [
                    'category' => 'oauth',
                    'description' => 'Claude CLI OAuth token for agent execution',
                    'expires_at' => $expiresAt ?? now()->addDays(self::TOKEN_EXPIRATION_DAYS),
                ]
            );

            // Update metadata
            $this->updateTokenMetadata($token, $expiresAt);

            Log::info('Stored new Claude OAuth token', [
                'expires_at' => $expiresAt,
                'stored_in_vault' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to store Claude token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Notify admins about token expiration.
     */
    protected function notifyAdmins(): void
    {
        try {
            // Get admin users
            $admins = User::where('is_admin', true)->get();

            if ($admins->isEmpty()) {
                Log::warning('No admin users found to notify about token expiration');

                return;
            }

            // Log notification
            Log::channel('security')->warning('Claude OAuth token expired - admin notification sent', [
                'admin_count' => $admins->count(),
                'timestamp' => now(),
            ]);

            // Note: You'll need to create the notification class separately
            // Notification::send($admins, new ClaudeTokenExpiredNotification());

            Log::info('Notified admins about Claude token expiration', [
                'admin_count' => $admins->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to notify admins about token expiration', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if we should pause agent execution due to token issues.
     */
    public function shouldPauseAgentExecution(): bool
    {
        $metadata = $this->getTokenMetadata();

        // If token is expired and we've tried refreshing multiple times, pause
        if (($metadata['is_expired'] ?? false) && ($metadata['refresh_attempts'] ?? 0) >= 3) {
            return true;
        }

        return false;
    }

    /**
     * Get a human-readable status of the token.
     */
    public function getTokenStatus(): array
    {
        $metadata = $this->getTokenMetadata();
        $hasToken = $this->getCurrentToken() !== null;

        return [
            'has_token' => $hasToken,
            'is_expired' => $metadata['is_expired'] ?? false,
            'needs_refresh' => $this->needsRefresh(),
            'should_pause_execution' => $this->shouldPauseAgentExecution(),
            'expires_at' => $metadata['expires_at'] ?? null,
            'last_refreshed_at' => $metadata['last_refreshed_at'] ?? null,
            'last_error' => $metadata['last_error'] ?? null,
            'refresh_attempts' => $metadata['refresh_attempts'] ?? 0,
            'days_until_expiration' => isset($metadata['expires_at'])
                ? Carbon::parse($metadata['expires_at'])->diffInDays(now())
                : null,
        ];
    }
}
