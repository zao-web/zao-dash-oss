<?php

namespace App\Services\QuickBooks;

use App\Models\QuickBooksConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class QuickBooksOAuthService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $redirectUri;

    protected string $environment;

    public function __construct()
    {
        $this->clientId = config('services.quickbooks.client_id');
        $this->clientSecret = config('services.quickbooks.client_secret');
        $this->redirectUri = config('services.quickbooks.redirect_uri');
        $this->environment = config('services.quickbooks.environment', 'production');
    }

    protected function getAuthBaseUrl(): string
    {
        return 'https://appcenter.intuit.com/connect/oauth2';
    }

    protected function getTokenUrl(): string
    {
        return 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting',
            'state' => $state,
        ]);

        return $this->getAuthBaseUrl()."?{$params}";
    }

    public function exchangeCodeForTokens(string $code, string $realmId): array
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post($this->getTokenUrl(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for tokens: '.$response->body());
        }

        $data = $response->json();
        $data['realm_id'] = $realmId;

        return $data;
    }

    public function refreshAccessToken(QuickBooksConnection $connection): QuickBooksConnection
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post($this->getTokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'access_token_expires_at' => now()->addSeconds($data['expires_in']),
            'refresh_token_expires_at' => now()->addSeconds($data['x_refresh_token_expires_in']),
        ]);

        return $connection->fresh();
    }

    public function storeConnection(User $user, array $tokenData): QuickBooksConnection
    {
        // Get company info
        $companyName = $this->getCompanyName($tokenData['access_token'], $tokenData['realm_id']);

        return QuickBooksConnection::updateOrCreate(
            ['realm_id' => $tokenData['realm_id']],
            [
                'user_id' => $user->id,
                'company_name' => $companyName,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'access_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                'refresh_token_expires_at' => now()->addSeconds($tokenData['x_refresh_token_expires_in']),
                'sync_status' => 'pending',
                'sync_started_at' => null,
                'sync_completed_at' => null,
                'sync_progress' => 0,
                'sync_error' => null,
            ]
        );
    }

    protected function getCompanyName(string $accessToken, string $realmId): string
    {
        $baseUrl = $this->environment === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';

        $response = Http::withToken($accessToken)
            ->get("{$baseUrl}/v3/company/{$realmId}/companyinfo/{$realmId}");

        if ($response->successful()) {
            return $response->json()['CompanyInfo']['CompanyName'] ?? 'Unknown Company';
        }

        return 'Unknown Company';
    }

    public function getValidAccessToken(QuickBooksConnection $connection): string
    {
        if ($connection->needsTokenRefresh()) {
            $connection = $this->refreshAccessToken($connection);
        }

        return $connection->access_token;
    }
}
