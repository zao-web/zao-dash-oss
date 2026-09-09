<?php

namespace App\Services\Harvest;

use App\Models\HarvestCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class HarvestOAuthService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.harvest.client_id');
        $this->clientSecret = config('services.harvest.client_secret');
        $this->redirectUri = config('services.harvest.redirect_uri');
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return "https://id.getharvest.com/oauth2/authorize?{$params}";
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::post('https://id.getharvest.com/api/v2/oauth2/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for tokens: '.$response->body());
        }

        return $response->json();
    }

    public function refreshAccessToken(HarvestCredential $credential): HarvestCredential
    {
        $response = Http::post('https://id.getharvest.com/api/v2/oauth2/token', [
            'refresh_token' => $credential->refresh_token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $credential->fresh();
    }

    public function storeCredentials(User $user, array $tokenData, array $accountData): HarvestCredential
    {
        return HarvestCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_at' => now()->addSeconds($tokenData['expires_in']),
                'account_id' => $accountData['id'] ?? null,
                'account_name' => $accountData['name'] ?? null,
            ]
        );
    }

    public function getValidToken(User $user): ?string
    {
        $credential = $user->harvestCredential;

        if (! $credential) {
            return null;
        }

        return $this->getValidTokenForCredential($credential);
    }

    public function getValidTokenForCredential(HarvestCredential $credential): string
    {
        if ($credential->isExpired()) {
            $credential = $this->refreshAccessToken($credential);
        }

        return $credential->access_token;
    }

    public function getAccounts(string $accessToken): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
        ])->get('https://id.getharvest.com/api/v2/accounts');

        if (! $response->successful()) {
            throw new \Exception('Failed to get accounts: '.$response->body());
        }

        return $response->json()['accounts'] ?? [];
    }
}
