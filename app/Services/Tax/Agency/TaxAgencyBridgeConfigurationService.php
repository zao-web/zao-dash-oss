<?php

namespace App\Services\Tax\Agency;

use App\Models\TaxAgencyConnection;
use App\Services\Vault\VaultService;

class TaxAgencyBridgeConfigurationService
{
    public function __construct(
        protected VaultService $vaultService,
    ) {}

    /**
     * @return array{
     *     bridge_url: string|null,
     *     bridge_path: string,
     *     bridge_token: string|null,
     *     bridge_headers: array<string, string>,
     *     timeout_seconds: int,
     *     worker_profile: string|null,
     *     credentials_reference: string|null,
     *     credentials_bundle: mixed,
     *     session: mixed,
     *     session_bundle: mixed,
     * }
     */
    public function resolve(TaxAgencyConnection $connection): array
    {
        $authPayload = $connection->auth_payload ?? [];
        $bridgeConfig = config('services.tax_agency_bridge', []);
        $vaultEnvironment = $this->vaultEnvironment($authPayload);

        $bridgeUrl = $this->stringValue($authPayload['bridge_url'] ?? null)
            ?? $this->stringValue($bridgeConfig['url'] ?? null);

        $bridgePath = $this->stringValue($authPayload['bridge_path'] ?? null)
            ?? $this->stringValue($bridgeConfig['sync_path'] ?? null)
            ?? '/sync';

        $bridgeToken = $this->stringValue($authPayload['bridge_token'] ?? null);
        if ($bridgeToken === null) {
            $bridgeToken = $this->stringValue(
                $this->vaultValue(
                    connection: $connection,
                    key: $this->stringValue($authPayload['bridge_token_vault_key'] ?? null),
                    environment: $vaultEnvironment,
                ),
            );
        }
        if ($bridgeToken === null) {
            $bridgeToken = $this->stringValue($bridgeConfig['token'] ?? null);
        }

        $bridgeHeaders = array_merge(
            $this->normalizeHeaders($bridgeConfig['headers'] ?? []),
            $this->normalizeHeaders($authPayload['bridge_headers'] ?? []),
        );

        $credentialsReference = $this->stringValue($authPayload['credentials_reference'] ?? null)
            ?? $this->stringValue($authPayload['credentials_vault_key'] ?? null);

        return [
            'bridge_url' => $bridgeUrl,
            'bridge_path' => $bridgePath,
            'bridge_token' => $bridgeToken,
            'bridge_headers' => $bridgeHeaders,
            'timeout_seconds' => max(1, (int) ($authPayload['bridge_timeout_seconds'] ?? $bridgeConfig['timeout_seconds'] ?? 120)),
            'worker_profile' => $this->stringValue($authPayload['worker_profile'] ?? null)
                ?? $this->stringValue($bridgeConfig['worker_profile'] ?? null),
            'credentials_reference' => $credentialsReference,
            'credentials_bundle' => $this->decodeSecretValue(
                $this->vaultValue(
                    connection: $connection,
                    key: $this->stringValue($authPayload['credentials_vault_key'] ?? null),
                    environment: $vaultEnvironment,
                ),
            ) ?? $this->decodeSecretValue($authPayload['credentials_bundle'] ?? null),
            'session' => $authPayload['session'] ?? null,
            'session_bundle' => $this->decodeSecretValue(
                $this->vaultValue(
                    connection: $connection,
                    key: $this->stringValue($authPayload['session_vault_key'] ?? null),
                    environment: $vaultEnvironment,
                ),
            ) ?? $this->decodeSecretValue($authPayload['session_bundle'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $authPayload
     */
    protected function vaultEnvironment(array $authPayload): ?string
    {
        return $this->stringValue($authPayload['vault_environment'] ?? null)
            ?? $this->stringValue($authPayload['credentials_vault_environment'] ?? null)
            ?? config('app.env');
    }

    protected function vaultValue(TaxAgencyConnection $connection, ?string $key, ?string $environment): ?string
    {
        if ($key === null) {
            return null;
        }

        return $this->vaultService->getForEnvironment(
            key: $key,
            environment: $environment,
            user: $connection->user,
        );
    }

    protected function decodeSecretValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
