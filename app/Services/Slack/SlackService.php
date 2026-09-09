<?php

namespace App\Services\Slack;

use App\Models\SlackWorkspace;
use Illuminate\Support\Facades\Http;

/**
 * Wrapper service for SyncSlackJob - provides the expected method signatures.
 */
class SlackService
{
    private const BASE_URL = 'https://slack.com/api';

    public function listChannels(SlackWorkspace $workspace, bool $excludeArchived = true): array
    {
        // Query with both tokens and union the results — user tokens see
        // private channels the user is in, bot tokens see channels the bot is
        // in. Dedupe by channel ID. Without the user-token pass we'd miss
        // private channels like #helpguide-retainer the bot wasn't invited to.
        $byId = [];

        foreach (array_filter([$workspace->access_token, $workspace->user_access_token]) as $token) {
            $cursor = null;
            do {
                $params = [
                    'limit' => 200,
                    'types' => 'public_channel,private_channel',
                    'exclude_archived' => $excludeArchived,
                ];
                if ($cursor) {
                    $params['cursor'] = $cursor;
                }

                $response = Http::withToken($token)
                    ->get(self::BASE_URL.'/conversations.list', $params);

                if (! $response->successful() || ! $response->json('ok')) {
                    break;
                }

                foreach ($response->json('channels', []) as $ch) {
                    $byId[$ch['id']] = $ch;
                }

                $cursor = $response->json('response_metadata.next_cursor');
            } while ($cursor);
        }

        return array_values($byId);
    }

    public function getChannelHistory(
        SlackWorkspace $workspace,
        string $channelId,
        ?int $oldest = null,
        int $limit = 100
    ): array {
        $result = $this->getChannelHistoryWithError($workspace, $channelId, $oldest, $limit);

        return $result['messages'];
    }

    public function getChannelHistoryWithError(
        SlackWorkspace $workspace,
        string $channelId,
        ?int $oldest = null,
        int $limit = 100
    ): array {
        $params = [
            'channel' => $channelId,
            'limit' => $limit,
        ];

        if ($oldest) {
            $params['oldest'] = $oldest;
        }

        // Try bot token first. If Slack says the bot can't see the channel
        // (channel_not_found / not_in_channel / missing_scope), retry with the
        // user OAuth token if we have one — user tokens see private channels
        // and group DMs the user is a member of, no bot invite needed.
        $data = $this->callConversationsHistory($workspace->access_token, $params);

        $botSawNothing = ($data['ok'] ?? false) && empty($data['messages'] ?? []);
        $botErrored = ! ($data['ok'] ?? false)
            && in_array($data['error'] ?? '', ['channel_not_found', 'not_in_channel', 'missing_scope'], true);

        // Fall back to the user token on an explicit access error OR on an
        // ok-but-empty result. Bots are never members of DMs/group DMs (mpims),
        // and for those Slack often returns ok=true with an empty message list
        // instead of an error — which silently yields zero history. The user
        // token can read any conversation the authorizing user is in, so retry
        // there and keep its result when it actually returns messages.
        if (($botErrored || $botSawNothing) && $workspace->user_access_token) {
            $userData = $this->callConversationsHistory($workspace->user_access_token, $params);

            if ($botErrored || (($userData['ok'] ?? false) && ! empty($userData['messages'] ?? []))) {
                $data = $userData;
            }
        }

        if (isset($data['_http_error'])) {
            return [
                'ok' => false,
                'error' => 'HTTP '.$data['_http_error'],
                'messages' => [],
            ];
        }

        if (! ($data['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $data['error'] ?? 'unknown_error',
                'messages' => [],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'messages' => $data['messages'] ?? [],
        ];
    }

    /**
     * Single conversations.history call. Returns Slack's JSON response, or
     * ['_http_error' => statusCode] if the HTTP layer itself failed.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function callConversationsHistory(string $token, array $params): array
    {
        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/conversations.history', $params);

        if (! $response->successful()) {
            return ['_http_error' => $response->status()];
        }

        return $response->json() ?? [];
    }

    public function getUserInfo(SlackWorkspace $workspace, string $userId): array
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/users.info', ['user' => $userId]);

        if (! $response->successful() || ! $response->json('ok')) {
            return ['name' => 'Unknown', 'real_name' => 'Unknown', 'is_external' => false];
        }

        $user = $response->json('user', []);

        return [
            'id' => $user['id'] ?? $userId,
            'name' => $user['name'] ?? 'Unknown',
            'real_name' => $user['real_name'] ?? $user['name'] ?? 'Unknown',
            'email' => $user['profile']['email'] ?? null,
            'team_id' => $user['team_id'] ?? null,
            'is_bot' => $user['is_bot'] ?? false,
            'is_restricted' => $user['is_restricted'] ?? false,  // Single-channel guest
            'is_ultra_restricted' => $user['is_ultra_restricted'] ?? false,  // Multi-channel guest
            'is_stranger' => $user['is_stranger'] ?? false,  // External user from shared channel
            'is_external' => $this->isUserExternal($user, $workspace),
        ];
    }

    /**
     * Check if a user is external (guest, restricted, or from different team).
     */
    public function isUserExternal(array $user, SlackWorkspace $workspace): bool
    {
        // Guest accounts (restricted users)
        if (! empty($user['is_restricted']) || ! empty($user['is_ultra_restricted'])) {
            return true;
        }

        // User from a different team (Slack Connect)
        if (! empty($user['team_id']) && $user['team_id'] !== $workspace->team_id) {
            return true;
        }

        // Stranger (external user in shared channel)
        if (! empty($user['is_stranger'])) {
            return true;
        }

        return false;
    }

    /**
     * List direct messages (1:1 and group DMs).
     * Returns only DMs that have at least one external/guest user.
     */
    public function listDirectMessages(SlackWorkspace $workspace): array
    {
        $dms = [];
        $cursor = null;

        do {
            $params = [
                'limit' => 200,
                'types' => 'im,mpim',  // 1:1 DMs and group DMs
            ];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = Http::withToken($workspace->access_token)
                ->get(self::BASE_URL.'/conversations.list', $params);

            if ($response->successful() && $response->json('ok')) {
                $dms = array_merge($dms, $response->json('channels', []));
                $cursor = $response->json('response_metadata.next_cursor');
            } else {
                break;
            }
        } while ($cursor);

        return $dms;
    }

    /**
     * Get members of a conversation (channel or DM).
     */
    public function getConversationMembers(SlackWorkspace $workspace, string $conversationId): array
    {
        $members = [];
        $cursor = null;

        do {
            $params = ['channel' => $conversationId];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = Http::withToken($workspace->access_token)
                ->get(self::BASE_URL.'/conversations.members', $params);

            if ($response->successful() && $response->json('ok')) {
                $members = array_merge($members, $response->json('members', []));
                $cursor = $response->json('response_metadata.next_cursor');
            } else {
                break;
            }
        } while ($cursor);

        return $members;
    }

    /**
     * Search messages across workspace.
     *
     * Note: Requires search:read scope (user token, not bot token).
     *
     * @see https://api.slack.com/methods/search.messages
     */
    public function searchMessages(SlackWorkspace $workspace, string $query, int $count = 20): array
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/search.messages', [
                'query' => $query,
                'count' => $count,
                'sort' => 'timestamp',
                'sort_dir' => 'desc',
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            return [
                'ok' => false,
                'error' => $response->json('error') ?? 'search_failed',
                'messages' => [],
            ];
        }

        $matches = $response->json('messages.matches', []);

        return [
            'ok' => true,
            'total' => $response->json('messages.total', 0),
            'messages' => collect($matches)->map(fn ($m) => [
                'text' => $m['text'] ?? '',
                'user' => $m['user'] ?? $m['username'] ?? 'unknown',
                'channel' => $m['channel']['name'] ?? 'unknown',
                'timestamp' => $m['ts'] ?? null,
                'permalink' => $m['permalink'] ?? null,
            ])->toArray(),
        ];
    }

    /**
     * Check if a DM conversation has at least one external user.
     */
    public function dmHasExternalUser(SlackWorkspace $workspace, array $dm): bool
    {
        // For 1:1 DMs, check the 'user' field directly
        if (isset($dm['user'])) {
            $userInfo = $this->getUserInfo($workspace, $dm['user']);

            return $userInfo['is_external'] ?? false;
        }

        // For group DMs (mpim), check all members
        $memberIds = $this->getConversationMembers($workspace, $dm['id']);
        foreach ($memberIds as $memberId) {
            $userInfo = $this->getUserInfo($workspace, $memberId);
            if ($userInfo['is_external'] ?? false) {
                return true;
            }
        }

        return false;
    }
}
