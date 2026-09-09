<?php

namespace App\Services\LinkedIn;

use App\Models\LinkedInCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInService
{
    protected string $baseUrl = 'https://api.linkedin.com/v2';

    protected string $oauthUrl = 'https://www.linkedin.com/oauth/v2';

    public function getAuthUrl(string $redirectUri, array $scopes = []): string
    {
        $defaultScopes = ['openid', 'profile', 'email', 'w_member_social'];
        $scopes = array_merge($defaultScopes, $scopes);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => csrf_token(),
        ]);

        return "{$this->oauthUrl}/authorization?{$params}";
    }

    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post("{$this->oauthUrl}/accessToken", [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for token: '.$response->body());
        }

        return $response->json();
    }

    public function refreshToken(LinkedInCredential $credential): array
    {
        $response = Http::asForm()->post("{$this->oauthUrl}/accessToken", [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credential->refresh_token,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data;
    }

    public function getProfile(LinkedInCredential $credential): array
    {
        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->get("{$this->baseUrl}/userinfo");

        if (! $response->successful()) {
            throw new \Exception('Failed to get profile: '.$response->body());
        }

        return $response->json();
    }

    public function createTextPost(LinkedInCredential $credential, string $text): array
    {
        $this->ensureValidToken($credential);

        $authorUrn = "urn:li:person:{$credential->linkedin_id}";

        $payload = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $text,
                    ],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = Http::withToken($credential->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post("{$this->baseUrl}/ugcPosts", $payload);

        if (! $response->successful()) {
            Log::error('LinkedIn post failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to create post: '.$response->body());
        }

        return $response->json();
    }

    public function createArticlePost(LinkedInCredential $credential, string $text, string $url, string $title, ?string $description = null): array
    {
        $this->ensureValidToken($credential);

        $authorUrn = "urn:li:person:{$credential->linkedin_id}";

        $payload = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $text,
                    ],
                    'shareMediaCategory' => 'ARTICLE',
                    'media' => [
                        [
                            'status' => 'READY',
                            'originalUrl' => $url,
                            'title' => [
                                'text' => $title,
                            ],
                            'description' => [
                                'text' => $description ?? '',
                            ],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = Http::withToken($credential->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post("{$this->baseUrl}/ugcPosts", $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create article post: '.$response->body());
        }

        return $response->json();
    }

    public function createOrganizationPost(LinkedInCredential $credential, string $text): array
    {
        if (! $credential->hasOrganizationAccess()) {
            throw new \Exception('No organization access configured');
        }

        $this->ensureValidToken($credential);

        $authorUrn = "urn:li:organization:{$credential->organization_id}";

        $payload = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $text,
                    ],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = Http::withToken($credential->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post("{$this->baseUrl}/ugcPosts", $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create organization post: '.$response->body());
        }

        return $response->json();
    }

    public function getOrganizations(LinkedInCredential $credential): array
    {
        $this->ensureValidToken($credential);

        $response = Http::withToken($credential->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get("{$this->baseUrl}/organizationAcls", [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(*,organization~(*)))',
            ]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json()['elements'] ?? [];
    }

    protected function ensureValidToken(LinkedInCredential $credential): void
    {
        if ($credential->isTokenExpired()) {
            $this->refreshToken($credential);
            $credential->refresh();
        }
    }
}
