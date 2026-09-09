<?php

namespace App\Services\Slack;

use App\Models\SlackWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SlackOAuthService
{
    private const AUTH_URL = 'https://slack.com/oauth/v2/authorize';

    private const TOKEN_URL = 'https://slack.com/api/oauth.v2.access';

    /** Bot scopes — used by the bot identity to do things on behalf of the workspace. */
    private array $scopes = [
        'channels:history',
        'channels:read',
        'groups:history',
        'groups:read',
        'im:history',
        'im:read',
        'mpim:history',
        'mpim:read',
        'users:read',
        'users:read.email',
        'chat:write',
        'commands',
        'reactions:read',
    ];

    /**
     * User scopes — issued to the user authorizing the install. Lets us read
     * conversations the user is in (incl. private channels and group DMs) without
     * inviting the bot. Required for retainer report aggregation of group DMs.
     */
    private array $userScopes = [
        // History scopes — let us read messages in conversations the user is in.
        'channels:history',
        'groups:history',
        'im:history',
        'mpim:history',
        // Read scopes — needed to LIST conversations via conversations.list.
        // Without these, listChannels with the user token fails missing_scope.
        'channels:read',
        'groups:read',
        'im:read',
        'mpim:read',
    ];

    public function getAuthUrl(?string $state = null): string
    {
        $state = $state ?? Str::random(40);

        $params = [
            'client_id' => config('services.slack.client_id'),
            'redirect_uri' => config('services.slack.redirect_uri'),
            'scope' => implode(',', $this->scopes),
            'user_scope' => implode(',', $this->userScopes),
            'state' => $state,
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.slack.client_id'),
            'client_secret' => config('services.slack.client_secret'),
            'code' => $code,
            'redirect_uri' => config('services.slack.redirect_uri'),
        ]);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new \Exception('Failed to exchange code for tokens: '.$response->body());
        }

        return $response->json();
    }

    public function storeWorkspace(array $tokenData): SlackWorkspace
    {
        $team = $tokenData['team'] ?? [];
        $authedUser = $tokenData['authed_user'] ?? [];

        $attrs = [
            'workspace_name' => $team['name'] ?? 'Unknown',
            'access_token' => $tokenData['access_token'],
            'bot_user_id' => $tokenData['bot_user_id'] ?? null,
        ];

        // Capture the user OAuth token if Slack issued one (only when user_scope was requested).
        if (! empty($authedUser['access_token'])) {
            $attrs['user_access_token'] = $authedUser['access_token'];
            $attrs['authed_user_id'] = $authedUser['id'] ?? null;
        }

        return SlackWorkspace::updateOrCreate(
            ['workspace_id' => $team['id']],
            $attrs,
        );
    }

    public function revokeAccess(SlackWorkspace $workspace): bool
    {
        $response = Http::withToken($workspace->access_token)
            ->get('https://slack.com/api/auth.revoke');

        // Don't delete the workspace — that cascades to slack_channels and
        // slack_messages and obliterates synced history. Mark inactive and
        // clear the user OAuth token. The bot access_token is left in place
        // and gets overwritten on the next OAuth re-auth (updateOrCreate
        // matches on workspace_id / Slack team id). All synced channels and
        // messages survive.
        $workspace->update([
            'user_access_token' => null,
            'authed_user_id' => null,
            'is_active' => false,
        ]);

        return $response->successful();
    }

    public function testConnection(SlackWorkspace $workspace): bool
    {
        $response = Http::withToken($workspace->access_token)
            ->get('https://slack.com/api/auth.test');

        return $response->successful() && $response->json('ok');
    }
}
