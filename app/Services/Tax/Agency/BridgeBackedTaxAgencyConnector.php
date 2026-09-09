<?php

namespace App\Services\Tax\Agency;

use App\Models\TaxAgencyConnection;
use App\Services\Tax\Agency\Contracts\TaxAgencyPortalConnector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

abstract class BridgeBackedTaxAgencyConnector implements TaxAgencyPortalConnector
{
    public function __construct(
        protected TaxAgencyBridgeConfigurationService $bridgeConfigurationService,
    ) {}

    /**
     * @return array{
     *     balances: array<int, array<string, mixed>>,
     *     documents: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     * }
     */
    public function pull(TaxAgencyConnection $connection, int $year): array
    {
        $authPayload = $connection->auth_payload ?? [];

        if (isset($authPayload['mock_result']) && is_array($authPayload['mock_result'])) {
            return $this->normalizeResult($authPayload['mock_result']);
        }

        $bridgeConfiguration = $this->bridgeConfigurationService->resolve($connection);
        $bridgeUrl = $bridgeConfiguration['bridge_url'] ?? null;
        if (! is_string($bridgeUrl) || $bridgeUrl === '') {
            throw new RuntimeException("No browser-session bridge configured for {$connection->portal_name}.");
        }

        $bridgePath = $bridgeConfiguration['bridge_path'] ?? '/sync';
        $response = $this->client($bridgeConfiguration)
            ->post(rtrim($bridgeUrl, '/').'/'.ltrim((string) $bridgePath, '/'), [
                'agency' => $this->agencyCode(),
                'portal_name' => $connection->portal_name,
                'tax_year' => $year,
                'capabilities' => $connection->capabilities ?? [],
                'session' => $bridgeConfiguration['session'] ?? null,
                'session_bundle' => $bridgeConfiguration['session_bundle'] ?? null,
                'credentials_reference' => $bridgeConfiguration['credentials_reference'] ?? null,
                'credentials_bundle' => $bridgeConfiguration['credentials_bundle'] ?? null,
                'worker_profile' => $bridgeConfiguration['worker_profile'] ?? null,
                'mfa_callback' => $this->mfaCallback($connection),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("{$connection->portal_name} bridge sync failed: ".$response->body());
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException("{$connection->portal_name} bridge returned an invalid response.");
        }

        return $this->normalizeResult($payload);
    }

    protected function client(array $bridgeConfiguration): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) ($bridgeConfiguration['timeout_seconds'] ?? 120));

        if (isset($bridgeConfiguration['bridge_token']) && is_string($bridgeConfiguration['bridge_token']) && $bridgeConfiguration['bridge_token'] !== '') {
            $request = $request->withToken($bridgeConfiguration['bridge_token']);
        }

        if (isset($bridgeConfiguration['bridge_headers']) && is_array($bridgeConfiguration['bridge_headers'])) {
            $request = $request->withHeaders($bridgeConfiguration['bridge_headers']);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     balances: array<int, array<string, mixed>>,
     *     documents: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     * }
     */
    protected function normalizeResult(array $payload): array
    {
        return [
            'balances' => array_values(is_array($payload['balances'] ?? null) ? $payload['balances'] : []),
            'documents' => array_values(is_array($payload['documents'] ?? null) ? $payload['documents'] : []),
            'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
        ];
    }

    /**
     * @return array{
     *     request_url: string,
     *     consume_url: string,
     *     delivery_method: string,
     *     code_pattern: string,
     *     poll_after_seconds: int,
     *     expires_in_minutes: int,
     * }
     */
    protected function mfaCallback(TaxAgencyConnection $connection): array
    {
        return [
            'request_url' => URL::temporarySignedRoute(
                'tax-agency-bridge.mfa.request',
                now()->addMinutes(30),
                ['connection' => $connection->id],
            ),
            'consume_url' => URL::temporarySignedRoute(
                'tax-agency-bridge.mfa.consume',
                now()->addMinutes(30),
                ['connection' => $connection->id],
            ),
            'delivery_method' => 'slack_dm',
            'code_pattern' => '\\d{6,8}',
            'poll_after_seconds' => TaxAgencySlackMfaService::DEFAULT_POLL_AFTER_SECONDS,
            'expires_in_minutes' => 10,
        ];
    }
}
