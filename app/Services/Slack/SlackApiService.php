<?php

namespace App\Services\Slack;

use App\Models\ClientContact;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackThread;
use App\Models\SlackWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackApiService
{
    private const BASE_URL = 'https://slack.com/api';

    public function listChannels(SlackWorkspace $workspace): array
    {
        $channels = [];
        $cursor = null;

        do {
            $params = ['limit' => 200];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            // Get public channels
            $response = Http::withToken($workspace->access_token)
                ->get(self::BASE_URL.'/conversations.list', $params + ['types' => 'public_channel,private_channel']);

            if ($response->successful() && $response->json('ok')) {
                $channels = array_merge($channels, $response->json('channels', []));
                $cursor = $response->json('response_metadata.next_cursor');
            } else {
                break;
            }
        } while ($cursor);

        return $channels;
    }

    public function syncChannels(SlackWorkspace $workspace): int
    {
        $channels = $this->listChannels($workspace);
        $count = 0;

        foreach ($channels as $channel) {
            $slackChannel = SlackChannel::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'channel_id' => $channel['id'],
                ],
                [
                    'channel_name' => $channel['name'],
                    'is_private' => $channel['is_private'] ?? false,
                    'is_shared' => $channel['is_shared'] ?? $channel['is_ext_shared'] ?? false,
                ]
            );

            // Auto-classify if not already set
            if ($slackChannel->classification === 'general') {
                $slackChannel->update([
                    'classification' => $slackChannel->classifyAutomatically(),
                ]);
            }

            $count++;
        }

        return $count;
    }

    public function getChannelHistory(SlackWorkspace $workspace, SlackChannel $channel, array $params = []): array
    {
        $defaults = [
            'channel' => $channel->channel_id,
            'limit' => 100,
        ];

        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/conversations.history', array_merge($defaults, $params));

        if (! $response->successful() || ! $response->json('ok')) {
            throw new \Exception('Failed to get channel history: '.$response->body());
        }

        return $response->json();
    }

    public function getThreadReplies(SlackWorkspace $workspace, SlackChannel $channel, string $threadTs): array
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/conversations.replies', [
                'channel' => $channel->channel_id,
                'ts' => $threadTs,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new \Exception('Failed to get thread replies: '.$response->body());
        }

        return $response->json('messages', []);
    }

    public function getUserInfo(SlackWorkspace $workspace, string $userId): array
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/users.info', ['user' => $userId]);

        if (! $response->successful() || ! $response->json('ok')) {
            return ['name' => 'Unknown', 'email' => null, 'is_external' => false];
        }

        $user = $response->json('user', []);

        return [
            'name' => $user['real_name'] ?? $user['name'] ?? 'Unknown',
            'email' => $user['profile']['email'] ?? null,
            'is_external' => ($user['is_restricted'] ?? false) ||
                ($user['is_ultra_restricted'] ?? false) ||
                ($user['is_stranger'] ?? false),
        ];
    }

    public function storeMessage(SlackWorkspace $workspace, SlackChannel $channel, array $message): SlackMessage
    {
        $userInfo = $this->getUserInfo($workspace, $message['user'] ?? '');

        // Try to match to client
        $clientId = null;
        $email = $userInfo['email'] ?? null;
        if ($email) {
            $contact = ClientContact::where('email', $email)->first();
            $clientId = $contact?->client_id ?? $channel->client_id;
        } else {
            $clientId = $channel->client_id;
        }

        return SlackMessage::updateOrCreate(
            [
                'channel_id' => $channel->id,
                'message_ts' => $message['ts'],
            ],
            [
                'workspace_id' => $workspace->id,
                'thread_ts' => $message['thread_ts'] ?? null,
                'user_id' => $message['user'] ?? '',
                'user_name' => $userInfo['name'],
                'user_is_external' => $userInfo['is_external'],
                'content' => $message['text'] ?? '',
                'attachments' => $message['attachments'] ?? null,
                'client_id' => $clientId,
            ]
        );
    }

    public function syncThread(SlackWorkspace $workspace, SlackChannel $channel, string $threadTs): SlackThread
    {
        $replies = $this->getThreadReplies($workspace, $channel, $threadTs);

        $participants = [];
        $hasExternalParticipant = false;

        foreach ($replies as $reply) {
            $userId = $reply['user'] ?? '';
            if ($userId && ! in_array($userId, $participants)) {
                $participants[] = $userId;
                $userInfo = $this->getUserInfo($workspace, $userId);
                if ($userInfo['is_external']) {
                    $hasExternalParticipant = true;
                }
            }

            // Store each message
            $this->storeMessage($workspace, $channel, $reply);
        }

        return SlackThread::updateOrCreate(
            [
                'channel_id' => $channel->id,
                'thread_ts' => $threadTs,
            ],
            [
                'message_count' => count($replies),
                'participants' => $participants,
                'has_external_participant' => $hasExternalParticipant,
                'last_reply_at' => isset($replies[count($replies) - 1])
                    ? now()->createFromTimestamp((float) $replies[count($replies) - 1]['ts'])
                    : now(),
            ]
        );
    }

    public function postMessage(SlackWorkspace $workspace, string $channelId, string $text, array $options = []): array
    {
        $response = Http::withToken($workspace->access_token)
            ->post(self::BASE_URL.'/chat.postMessage', array_merge([
                'channel' => $channelId,
                'text' => $text,
            ], $options));

        if (! $response->successful() || ! $response->json('ok')) {
            throw new \Exception('Failed to post message: '.$response->body());
        }

        return $response->json();
    }

    public function lookupUserByEmail(SlackWorkspace $workspace, string $email): ?array
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/users.lookupByEmail', ['email' => $email]);

        if (! $response->successful() || ! $response->json('ok')) {
            return null;
        }

        return $response->json('user');
    }

    public function openDirectMessage(SlackWorkspace $workspace, string $userId): ?string
    {
        $response = Http::withToken($workspace->access_token)
            ->post(self::BASE_URL.'/conversations.open', [
                'users' => $userId,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            return null;
        }

        return $response->json('channel.id');
    }

    public function getPermalink(SlackWorkspace $workspace, string $channelId, string $messageTs): ?string
    {
        $response = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/chat.getPermalink', [
                'channel' => $channelId,
                'message_ts' => $messageTs,
            ]);

        if ($response->successful() && $response->json('ok')) {
            return $response->json('permalink');
        }

        return null;
    }

    /**
     * Post a message using the primary workspace bot token.
     * Useful for self-healing and automated responses where we don't have workspace context.
     */
    /**
     * Upload a file to a user via DM, with optional blocks for action buttons.
     *
     * Uses Slack's two-step external upload API (files.getUploadURLExternal +
     * files.completeUploadExternal). The DM channel is opened first via
     * conversations.open so the file lands in the user's DM thread.
     *
     * @return array{file_id: ?string, channel_id: ?string, message_ts: ?string}
     */
    public function uploadFileToUser(string $userId, string $filePath, string $filename, ?string $title = null, ?string $initialComment = null, array $blocks = []): array
    {
        $workspace = SlackWorkspace::where('is_primary', true)->first();

        if (! $workspace) {
            throw new \Exception('No primary Slack workspace configured');
        }

        if (! file_exists($filePath)) {
            throw new \Exception("File not found at path: {$filePath}");
        }

        $dmChannel = $this->openDirectMessage($workspace, $userId);
        if (! $dmChannel) {
            throw new \Exception("Failed to open DM channel for user {$userId}");
        }

        $fileSize = filesize($filePath);

        $urlResponse = Http::withToken($workspace->access_token)
            ->get(self::BASE_URL.'/files.getUploadURLExternal', [
                'filename' => $filename,
                'length' => $fileSize,
            ]);

        if (! $urlResponse->successful() || ! $urlResponse->json('ok')) {
            Log::warning('Slack files.getUploadURLExternal failed', [
                'error' => $urlResponse->json('error') ?? $urlResponse->body(),
            ]);
            throw new \Exception('Failed to get upload URL: '.($urlResponse->json('error') ?? 'unknown'));
        }

        $uploadUrl = $urlResponse->json('upload_url');
        $fileId = $urlResponse->json('file_id');

        $uploadResponse = Http::withBody(file_get_contents($filePath), 'application/octet-stream')
            ->post($uploadUrl);

        if (! $uploadResponse->successful()) {
            throw new \Exception('Failed to upload file bytes: HTTP '.$uploadResponse->status());
        }

        $completePayload = [
            'files' => [['id' => $fileId, 'title' => $title ?? $filename]],
            'channel_id' => $dmChannel,
        ];

        if ($initialComment) {
            $completePayload['initial_comment'] = $initialComment;
        }

        if (! empty($blocks)) {
            $completePayload['blocks'] = $blocks;
        }

        $completeResponse = Http::withToken($workspace->access_token)
            ->asJson()
            ->post(self::BASE_URL.'/files.completeUploadExternal', $completePayload);

        if (! $completeResponse->successful() || ! $completeResponse->json('ok')) {
            Log::warning('Slack files.completeUploadExternal failed', [
                'error' => $completeResponse->json('error') ?? $completeResponse->body(),
            ]);
            throw new \Exception('Failed to complete upload: '.($completeResponse->json('error') ?? 'unknown'));
        }

        return [
            'file_id' => $fileId,
            'channel_id' => $dmChannel,
            'message_ts' => $completeResponse->json('files.0.shares.private.'.$dmChannel.'.0.ts'),
        ];
    }

    public function postMessageDirect(string $channel, string $text, ?string $threadTs = null, array $blocks = []): array
    {
        $workspace = SlackWorkspace::where('is_primary', true)->first();

        if (! $workspace) {
            throw new \Exception('No primary Slack workspace configured');
        }

        $payload = [
            'channel' => $channel,
            'text' => $text,
        ];

        if ($threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        if (! empty($blocks)) {
            $payload['blocks'] = $blocks;
        }

        $response = Http::withToken($workspace->access_token)
            ->post(self::BASE_URL.'/chat.postMessage', $payload);

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Failed to post Slack message', [
                'channel' => $channel,
                'error' => $response->json('error') ?? $response->body(),
            ]);
            throw new \Exception('Failed to post message: '.($response->json('error') ?? $response->body()));
        }

        return $response->json();
    }
}
