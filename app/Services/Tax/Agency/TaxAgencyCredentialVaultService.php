<?php

namespace App\Services\Tax\Agency;

use App\Models\TaxAgencyConnection;
use App\Models\User;
use App\Models\VaultSecret;
use App\Models\VaultSecretValue;
use Illuminate\Support\Facades\Crypt;

class TaxAgencyCredentialVaultService
{
    public const DEFAULT_ENVIRONMENT = VaultSecretValue::ENVIRONMENT_PRODUCTION;

    /**
     * @return array{
     *     agency_code: string,
     *     agency_name: string,
     *     portal_name: string,
     *     credentials_vault_key: string,
     *     session_vault_key: string,
     *     capabilities: array<int, string>,
     * }
     */
    public function agencyDefaults(string $agencyCode): array
    {
        return match ($agencyCode) {
            TaxAgencyConnection::AGENCY_IRS => [
                'agency_code' => TaxAgencyConnection::AGENCY_IRS,
                'agency_name' => 'Internal Revenue Service',
                'portal_name' => 'IRS Individual Online Account',
                'credentials_vault_key' => 'tax.agency.irs.credentials',
                'session_vault_key' => 'tax.agency.irs.session',
                'capabilities' => ['balances', 'payments', 'notices', 'transcripts'],
            ],
            TaxAgencyConnection::AGENCY_OREGON_DOR => [
                'agency_code' => TaxAgencyConnection::AGENCY_OREGON_DOR,
                'agency_name' => 'Oregon Department of Revenue',
                'portal_name' => 'Revenue Online',
                'credentials_vault_key' => 'tax.agency.oregon.credentials',
                'session_vault_key' => 'tax.agency.oregon.session',
                'capabilities' => ['balances', 'payments', 'notices', 'transcripts'],
            ],
            default => throw new \InvalidArgumentException("Unsupported tax agency [{$agencyCode}]."),
        };
    }

    /**
     * @return array<int, array{
     *     agency_code: string,
     *     agency_name: string,
     *     portal_name: string,
     *     credentials_vault_key: string,
     *     session_vault_key: string,
     *     capabilities: array<int, string>,
     * }>
     */
    public function supportedAgencies(): array
    {
        return [
            $this->agencyDefaults(TaxAgencyConnection::AGENCY_IRS),
            $this->agencyDefaults(TaxAgencyConnection::AGENCY_OREGON_DOR),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $authPayload
     * @return array{
     *     credentials_vault_key: string,
     *     session_vault_key: string,
     *     vault_environment: string,
     *     has_credentials: bool,
     *     has_session: bool,
     * }
     */
    public function connectionSecretState(User $user, string $agencyCode, ?array $authPayload = null): array
    {
        $defaults = $this->agencyDefaults($agencyCode);
        $credentialsVaultKey = $this->stringValue($authPayload['credentials_vault_key'] ?? null)
            ?? $defaults['credentials_vault_key'];
        $sessionVaultKey = $this->stringValue($authPayload['session_vault_key'] ?? null)
            ?? $defaults['session_vault_key'];
        $vaultEnvironment = $this->stringValue($authPayload['vault_environment'] ?? null)
            ?? self::DEFAULT_ENVIRONMENT;

        return [
            'credentials_vault_key' => $credentialsVaultKey,
            'session_vault_key' => $sessionVaultKey,
            'vault_environment' => $vaultEnvironment,
            'has_credentials' => $this->hasActiveValue($user, $credentialsVaultKey, $vaultEnvironment),
            'has_session' => $this->hasActiveValue($user, $sessionVaultKey, $vaultEnvironment),
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function storeCredentialBundle(
        User $user,
        string $agencyCode,
        string $environment,
        array $bundle,
        ?string $vaultKey = null,
    ): string {
        $defaults = $this->agencyDefaults($agencyCode);

        return $this->upsertEnvironmentValue(
            user: $user,
            key: $vaultKey ?: $defaults['credentials_vault_key'],
            environment: $environment,
            value: $this->encodeBundle($bundle),
            name: "{$defaults['agency_name']} credentials",
            description: "Delegated credential bundle for {$defaults['portal_name']}.",
        )->key;
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function storeSessionBundle(
        User $user,
        string $agencyCode,
        string $environment,
        array $bundle,
        ?string $vaultKey = null,
    ): string {
        $defaults = $this->agencyDefaults($agencyCode);

        return $this->upsertEnvironmentValue(
            user: $user,
            key: $vaultKey ?: $defaults['session_vault_key'],
            environment: $environment,
            value: $this->encodeBundle($bundle),
            name: "{$defaults['agency_name']} browser session",
            description: "Reusable browser-session bundle for {$defaults['portal_name']}.",
        )->key;
    }

    /**
     * @param  array<string, mixed>|null  $authPayload
     * @param  array<string, mixed>  $sessionBundle
     * @return array<string, mixed>
     */
    public function persistSessionBundleForConnection(
        TaxAgencyConnection $connection,
        array $sessionBundle,
        ?array $authPayload = null,
    ): array {
        $authPayload = $authPayload ?? ($connection->auth_payload ?? []);
        $secretState = $this->connectionSecretState($connection->user, $connection->agency_code, $authPayload);

        $sessionKey = $this->storeSessionBundle(
            user: $connection->user,
            agencyCode: $connection->agency_code,
            environment: $secretState['vault_environment'],
            bundle: $sessionBundle,
            vaultKey: $secretState['session_vault_key'],
        );

        $authPayload['session_vault_key'] = $sessionKey;
        $authPayload['vault_environment'] = $secretState['vault_environment'];

        return $authPayload;
    }

    protected function hasActiveValue(User $user, string $key, string $environment): bool
    {
        return $this->activeValue($this->secretForUser($user, $key), $environment) !== null;
    }

    protected function secretForUser(User $user, string $key): ?VaultSecret
    {
        return VaultSecret::query()
            ->where('key', $key)
            ->where(function ($query) use ($user): void {
                $query->whereNull('allowed_users')
                    ->orWhereJsonContains('allowed_users', $user->id);
            })
            ->active()
            ->notExpired()
            ->first();
    }

    protected function activeValue(?VaultSecret $secret, ?string $environment): ?VaultSecretValue
    {
        if (! $secret) {
            return null;
        }

        return $secret->values()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->first()
            ?? $secret->values()
                ->whereNull('environment')
                ->where('is_active', true)
                ->first();
    }

    protected function upsertEnvironmentValue(
        User $user,
        string $key,
        string $environment,
        string $value,
        string $name,
        string $description,
    ): VaultSecret {
        $secret = VaultSecret::query()
            ->firstOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'encrypted_value' => Crypt::encryptString('placeholder'),
                    'category' => VaultSecret::CATEGORY_CREDENTIAL,
                    'description' => $description,
                    'allowed_users' => [$user->id],
                    'is_sensitive' => true,
                    'created_by' => $user->id,
                    'is_active' => true,
                ],
            );

        $allowedUsers = collect($secret->allowed_users ?? [])
            ->push($user->id)
            ->filter(fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $secret->forceFill([
            'name' => $name,
            'category' => VaultSecret::CATEGORY_CREDENTIAL,
            'description' => $description,
            'allowed_users' => $allowedUsers,
            'is_sensitive' => true,
            'is_active' => true,
            'updated_by' => $user->id,
        ])->save();

        $secretValue = $secret->values()
            ->where('environment', $environment)
            ->first();

        if (! $secretValue) {
            $secretValue = new VaultSecretValue([
                'vault_secret_id' => $secret->id,
                'environment' => $environment,
            ]);
        }

        $secretValue->forceFill([
            'encrypted_value' => Crypt::encryptString($value),
            'is_active' => true,
            'expires_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    protected function encodeBundle(array $bundle): string
    {
        return (string) json_encode($bundle, JSON_THROW_ON_ERROR);
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
