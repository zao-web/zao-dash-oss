<?php

namespace App\Services\Notion;

use App\Models\NotionConnection;
use Illuminate\Support\Facades\Http;

class NotionOAuthService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.notion.client_id');
        $this->clientSecret = config('services.notion.client_secret');
        $this->redirectUri = config('services.notion.redirect_uri');
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'owner' => 'user',
            'state' => $state,
        ]);

        return "https://api.notion.com/v1/oauth/authorize?{$params}";
    }

    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->post('https://api.notion.com/v1/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for token: '.$response->body());
        }

        return $response->json();
    }

    public function storeConnection(array $tokenData, ?int $userId = null): NotionConnection
    {
        return NotionConnection::updateOrCreate(
            ['workspace_id' => $tokenData['workspace_id']],
            [
                'user_id' => $userId,
                'workspace_name' => $tokenData['workspace_name'],
                'workspace_icon' => $tokenData['workspace_icon'] ?? null,
                'access_token' => $tokenData['access_token'],
                'bot_id' => $tokenData['bot_id'],
                'owner_type' => $tokenData['owner']['type'] ?? 'user',
                'owner_id' => $tokenData['owner']['user']['id'] ?? null,
                'duplicated_template_id' => $tokenData['duplicated_template_id'] ?? null,
                'request_id' => $tokenData['request_id'] ?? null,
                'connected_at' => now(),
            ]
        );
    }
}
