<?php

namespace App\Services\Vault;

use App\Models\User;
use App\Models\VaultAccessLog;
use App\Models\VaultSecret;
use App\Models\VaultSecretValue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class VaultService
{
    /**
     * Get a secret value by key.
     */
    public function get(
        string $key,
        ?User $user = null,
        ?string $agentSlug = null,
        array $context = []
    ): ?string {
        $secret = VaultSecret::where('key', $key)
            ->active()
            ->notExpired()
            ->first();

        if (! $secret) {
            return null;
        }

        // Check access
        if (! $secret->canBeAccessedBy($user, $agentSlug)) {
            $this->logAccess($secret, VaultAccessLog::ACTION_READ, $user, $agentSlug, false, 'Access denied', $context);

            return null;
        }

        // Log successful access
        $this->logAccess($secret, VaultAccessLog::ACTION_READ, $user, $agentSlug, true, null, $context);

        // Update access stats
        $secret->increment('access_count');
        $secret->update(['last_accessed_at' => now()]);

        return $secret->getDecryptedValue();
    }

    /**
     * Get multiple secrets by keys.
     */
    public function getMany(
        array $keys,
        ?User $user = null,
        ?string $agentSlug = null
    ): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $user, $agentSlug);
        }

        return $result;
    }

    /**
     * Store a new secret.
     */
    public function store(
        string $key,
        string $value,
        string $name,
        array $options = []
    ): VaultSecret {
        $secret = VaultSecret::create([
            'key' => $key,
            'name' => $name,
            'encrypted_value' => Crypt::encryptString($value),
            'category' => $options['category'] ?? VaultSecret::CATEGORY_OTHER,
            'description' => $options['description'] ?? null,
            'project_id' => $options['project_id'] ?? null,
            'client_id' => $options['client_id'] ?? null,
            'allowed_agents' => $options['allowed_agents'] ?? null,
            'allowed_users' => $options['allowed_users'] ?? null,
            'is_sensitive' => $options['is_sensitive'] ?? true,
            'expires_at' => $options['expires_at'] ?? null,
            'created_by' => auth()->id(),
            'is_active' => true,
        ]);

        $this->logAccess(
            $secret,
            VaultAccessLog::ACTION_WRITE,
            auth()->user(),
            null,
            true,
            null,
            ['action' => 'create']
        );

        Log::info('Vault secret created', ['key' => $key, 'category' => $secret->category]);

        return $secret;
    }

    /**
     * Update an existing secret's value.
     */
    public function update(VaultSecret $secret, string $newValue, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (! $secret->canBeAccessedBy($user)) {
            $this->logAccess($secret, VaultAccessLog::ACTION_WRITE, $user, null, false, 'Access denied');

            return false;
        }

        $secret->update([
            'encrypted_value' => Crypt::encryptString($newValue),
            'updated_by' => $user?->id,
        ]);

        $this->logAccess($secret, VaultAccessLog::ACTION_WRITE, $user, null, true, null, ['action' => 'update']);

        Log::info('Vault secret updated', ['key' => $secret->key]);

        return true;
    }

    /**
     * Rotate a secret (update with new value and log rotation).
     */
    public function rotate(VaultSecret $secret, string $newValue, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (! $secret->canBeAccessedBy($user)) {
            $this->logAccess($secret, VaultAccessLog::ACTION_ROTATE, $user, null, false, 'Access denied');

            return false;
        }

        $secret->update([
            'encrypted_value' => Crypt::encryptString($newValue),
            'updated_by' => $user?->id,
        ]);

        $this->logAccess($secret, VaultAccessLog::ACTION_ROTATE, $user, null, true);

        Log::info('Vault secret rotated', ['key' => $secret->key]);

        return true;
    }

    /**
     * Delete a secret.
     */
    public function delete(VaultSecret $secret, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (! $user?->is_admin) {
            $this->logAccess($secret, VaultAccessLog::ACTION_DELETE, $user, null, false, 'Admin required');

            return false;
        }

        $key = $secret->key;

        $this->logAccess($secret, VaultAccessLog::ACTION_DELETE, $user, null, true);

        $secret->delete();

        Log::warning('Vault secret deleted', ['key' => $key, 'deleted_by' => $user->id]);

        return true;
    }

    /**
     * Get secrets for an agent execution context.
     */
    public function getAgentSecrets(string $agentSlug, ?int $projectId = null, ?int $clientId = null): array
    {
        $query = VaultSecret::active()
            ->notExpired()
            ->where(function ($q) use ($agentSlug) {
                $q->whereNull('allowed_agents')
                    ->orWhereJsonContains('allowed_agents', $agentSlug);
            });

        // Get global secrets
        $secrets = $query->clone()->global()->pluck('key')->toArray();

        // Add project-scoped secrets
        if ($projectId) {
            $projectSecrets = $query->clone()->forProject($projectId)->pluck('key')->toArray();
            $secrets = array_merge($secrets, $projectSecrets);
        }

        // Add client-scoped secrets
        if ($clientId) {
            $clientSecrets = $query->clone()->forClient($clientId)->pluck('key')->toArray();
            $secrets = array_merge($secrets, $clientSecrets);
        }

        return $this->getMany(array_unique($secrets), null, $agentSlug);
    }

    /**
     * Check if a secret exists and is accessible.
     */
    public function exists(string $key, ?User $user = null, ?string $agentSlug = null): bool
    {
        $secret = VaultSecret::where('key', $key)
            ->active()
            ->notExpired()
            ->first();

        if (! $secret) {
            return false;
        }

        return $secret->canBeAccessedBy($user, $agentSlug);
    }

    /**
     * List accessible secrets (metadata only, no values).
     */
    public function list(?User $user = null, ?string $category = null): array
    {
        $query = VaultSecret::active()->notExpired();

        if ($category) {
            $query->category($category);
        }

        // Non-admins only see secrets they have access to
        if ($user && ! $user->is_admin) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('allowed_users')
                    ->orWhereJsonContains('allowed_users', $user->id);
            });
        }

        return $query->get()->map(fn ($s) => [
            'id' => $s->id,
            'key' => $s->key,
            'name' => $s->name,
            'category' => $s->category,
            'scope' => $s->scope_label,
            'is_sensitive' => $s->is_sensitive,
            'expires_at' => $s->expires_at?->toIso8601String(),
            'last_accessed_at' => $s->last_accessed_at?->toIso8601String(),
            'access_count' => $s->access_count,
        ])->toArray();
    }

    /**
     * Get a secret value for a specific environment with precedence resolution.
     * Precedence: project > client > global
     * Falls back to default (null environment) if specific environment not found.
     */
    public function getForEnvironment(
        string $key,
        ?string $environment = null,
        ?int $projectId = null,
        ?int $clientId = null,
        ?User $user = null,
        ?string $agentSlug = null
    ): ?string {
        $user = $user ?? auth()->user();
        $secret = $this->resolveSecret($key, $projectId, $clientId);

        if (! $secret) {
            return null;
        }

        if (! $secret->canBeAccessedBy($user, $agentSlug)) {
            $this->logAccess($secret, VaultAccessLog::ACTION_READ, $user, $agentSlug, false, 'Access denied');

            return null;
        }

        $value = $secret->values()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->first();

        if (! $value && $environment !== null) {
            $value = $secret->values()
                ->whereNull('environment')
                ->where('is_active', true)
                ->first();
        }

        if (! $value) {
            return null;
        }

        $this->logAccess($secret, VaultAccessLog::ACTION_READ, $user, $agentSlug, true, null, ['environment' => $environment]);
        $value->recordAccess();

        return $value->getDecryptedValue();
    }

    /**
     * Resolve secret with precedence: project > client > global
     */
    protected function resolveSecret(string $key, ?int $projectId = null, ?int $clientId = null): ?VaultSecret
    {
        if ($projectId) {
            $secret = VaultSecret::where('key', $key)
                ->where('project_id', $projectId)
                ->active()
                ->notExpired()
                ->first();
            if ($secret) {
                return $secret;
            }
        }

        if ($clientId) {
            $secret = VaultSecret::where('key', $key)
                ->where('client_id', $clientId)
                ->whereNull('project_id')
                ->active()
                ->notExpired()
                ->first();
            if ($secret) {
                return $secret;
            }
        }

        return VaultSecret::where('key', $key)
            ->whereNull('project_id')
            ->whereNull('client_id')
            ->active()
            ->notExpired()
            ->first();
    }

    /**
     * Store a new secret with an environment value.
     */
    public function storeWithEnvironment(
        string $key,
        string $value,
        string $name,
        ?string $environment = null,
        array $options = []
    ): VaultSecret {
        $secret = VaultSecret::create([
            'key' => $key,
            'name' => $name,
            'encrypted_value' => Crypt::encryptString('placeholder'),
            'category' => $options['category'] ?? VaultSecret::CATEGORY_OTHER,
            'description' => $options['description'] ?? null,
            'project_id' => $options['project_id'] ?? null,
            'client_id' => $options['client_id'] ?? null,
            'allowed_agents' => $options['allowed_agents'] ?? null,
            'allowed_users' => $options['allowed_users'] ?? null,
            'is_sensitive' => $options['is_sensitive'] ?? true,
            'expires_at' => $options['expires_at'] ?? null,
            'created_by' => auth()->id(),
            'is_active' => true,
        ]);

        $this->addEnvironmentValue($secret, $environment, $value);

        $this->logAccess(
            $secret,
            VaultAccessLog::ACTION_WRITE,
            auth()->user(),
            null,
            true,
            null,
            ['action' => 'create', 'environment' => $environment]
        );

        Log::info('Vault secret created with environment', ['key' => $key, 'environment' => $environment]);

        return $secret;
    }

    /**
     * Add a value for a specific environment to an existing secret.
     */
    public function addEnvironmentValue(VaultSecret $secret, ?string $environment, string $value): VaultSecretValue
    {
        return VaultSecretValue::create([
            'vault_secret_id' => $secret->id,
            'environment' => $environment,
            'encrypted_value' => Crypt::encryptString($value),
            'is_active' => true,
        ]);
    }

    /**
     * Get secrets for an agent in a specific environment.
     */
    public function getAgentSecretsForEnvironment(
        string $agentSlug,
        ?string $environment = null,
        ?int $projectId = null,
        ?int $clientId = null
    ): array {
        $query = VaultSecret::active()
            ->notExpired()
            ->where(function ($q) use ($agentSlug) {
                $q->whereNull('allowed_agents')
                    ->orWhereJsonContains('allowed_agents', $agentSlug);
            });

        $keys = collect();

        if ($projectId) {
            $keys = $keys->merge($query->clone()->where('project_id', $projectId)->pluck('key'));
        }

        if ($clientId) {
            $keys = $keys->merge($query->clone()->where('client_id', $clientId)->whereNull('project_id')->pluck('key'));
        }

        $keys = $keys->merge($query->clone()->global()->pluck('key'));

        $result = [];
        foreach ($keys->unique() as $key) {
            $value = $this->getForEnvironment($key, $environment, $projectId, $clientId, null, $agentSlug);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * List secrets with their environment information.
     */
    public function listWithEnvironments(?int $projectId = null, ?int $clientId = null): array
    {
        $query = VaultSecret::active()->notExpired();

        if ($projectId) {
            $query->where('project_id', $projectId);
        } elseif ($clientId) {
            $query->where('client_id', $clientId);
        }

        return $query->with('values')->get()->map(fn ($s) => [
            'id' => $s->id,
            'key' => $s->key,
            'name' => $s->name,
            'category' => $s->category,
            'scope' => $s->scope_label,
            'environments' => $s->values->pluck('environment')->filter()->values()->toArray(),
            'has_default' => $s->values->whereNull('environment')->isNotEmpty(),
        ])->toArray();
    }

    /**
     * Check if a secret exists for a specific environment.
     */
    public function existsForEnvironment(
        string $key,
        ?string $environment = null,
        ?int $projectId = null,
        ?int $clientId = null
    ): bool {
        $secret = $this->resolveSecret($key, $projectId, $clientId);

        if (! $secret) {
            return false;
        }

        return $secret->values()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Rotate a secret value for a specific environment.
     */
    public function rotateForEnvironment(
        VaultSecret $secret,
        ?string $environment,
        string $newValue,
        ?User $user = null
    ): bool {
        $user = $user ?? auth()->user();

        if (! $secret->canBeAccessedBy($user)) {
            $this->logAccess($secret, VaultAccessLog::ACTION_ROTATE, $user, null, false, 'Access denied');

            return false;
        }

        $value = $secret->values()
            ->where('environment', $environment)
            ->first();

        if (! $value) {
            $this->addEnvironmentValue($secret, $environment, $newValue);
        } else {
            $value->update([
                'encrypted_value' => Crypt::encryptString($newValue),
            ]);
        }

        $this->logAccess($secret, VaultAccessLog::ACTION_ROTATE, $user, null, true, null, ['environment' => $environment]);

        Log::info('Vault secret rotated for environment', ['key' => $secret->key, 'environment' => $environment]);

        return true;
    }

    /**
     * Get a secret value with fingerprint for sync operations.
     */
    public function getValueWithFingerprint(
        string $key,
        ?string $environment = null,
        ?int $projectId = null,
        ?int $clientId = null
    ): ?array {
        $secret = $this->resolveSecret($key, $projectId, $clientId);

        if (! $secret) {
            return null;
        }

        $value = $secret->values()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->first();

        if (! $value && $environment !== null) {
            $value = $secret->values()
                ->whereNull('environment')
                ->where('is_active', true)
                ->first();
        }

        if (! $value) {
            return null;
        }

        return [
            'value' => $value->getDecryptedValue(),
            'fingerprint' => $value->value_fingerprint,
            'environment' => $value->environment,
            'secret_id' => $secret->id,
            'value_id' => $value->id,
        ];
    }

    /**
     * Log an access attempt.
     */
    protected function logAccess(
        VaultSecret $secret,
        string $action,
        ?User $user,
        ?string $agentSlug,
        bool $success,
        ?string $failureReason = null,
        array $context = []
    ): void {
        $accessorType = $agentSlug ? VaultAccessLog::TYPE_AGENT : ($user ? VaultAccessLog::TYPE_USER : VaultAccessLog::TYPE_SYSTEM);

        VaultAccessLog::log(
            $secret,
            $action,
            $accessorType,
            $user?->id,
            $agentSlug ?? $user?->name,
            $success,
            $failureReason,
            $context
        );

        // Extra logging for sensitive secrets
        if ($secret->is_sensitive) {
            Log::channel('security')->info('Vault access', [
                'secret_key' => $secret->key,
                'action' => $action,
                'accessor' => $agentSlug ?? $user?->name ?? 'system',
                'success' => $success,
            ]);
        }
    }
}
