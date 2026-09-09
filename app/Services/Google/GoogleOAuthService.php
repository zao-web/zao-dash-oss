<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private array $scopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/drive.file',   // create/update files created by this app (for Google Docs export)
        'https://www.googleapis.com/auth/spreadsheets', // read/write any sheet the user can access — for the per-client task tracker sync
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        // SEO tracking
        'https://www.googleapis.com/auth/webmasters.readonly',    // Search Console
        'https://www.googleapis.com/auth/analytics.readonly',      // GA4 read
        'https://www.googleapis.com/auth/analytics.edit',          // GA4 events
    ];

    public function getAuthUrl(?string $state = null): string
    {
        $state = $state ?? Str::random(40);

        $params = [
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google.redirect_uri'),
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for tokens: '.$response->body());
        }

        return $response->json();
    }

    public function refreshAccessToken(GoogleCredential $credential): GoogleCredential
    {
        $response = Http::post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $credential->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $credential->fresh();
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if (! $response->successful()) {
            throw new \Exception('Failed to get user info: '.$response->body());
        }

        return $response->json();
    }

    public function storeCredentials(User $user, array $tokens): GoogleCredential
    {
        $userInfo = $this->getUserInfo($tokens['access_token']);

        return GoogleCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at' => now()->addSeconds($tokens['expires_in']),
                'scopes' => explode(' ', $tokens['scope'] ?? ''),
                'email' => $userInfo['email'] ?? null,
            ]
        );
    }

    public function getValidAccessToken(User $user): string
    {
        $credential = $user->googleCredential;

        if (! $credential) {
            throw new \Exception('No Google credential found for user '.$user->id);
        }

        // Check if tokens are present (could be null if decryption failed due to APP_KEY change)
        if (! $credential->access_token && ! $credential->refresh_token) {
            throw new \Exception('Google tokens are null - possible APP_KEY change or OAuth incomplete. User needs to re-authenticate.');
        }

        if ($credential->isExpired()) {
            if (! $credential->refresh_token) {
                throw new \Exception('Google access token expired and no refresh token available. User needs to re-authenticate.');
            }
            $credential = $this->refreshAccessToken($credential);
        }

        if (! $credential->access_token) {
            throw new \Exception('Failed to obtain valid Google access token');
        }

        return $credential->access_token;
    }

    public function revokeAccess(GoogleCredential $credential): bool
    {
        $response = Http::post('https://oauth2.googleapis.com/revoke', [
            'token' => $credential->access_token,
        ]);

        $credential->delete();

        return $response->successful();
    }

    public function hasValidCredentials(User $user): bool
    {
        return $user->googleCredential !== null;
    }
}
