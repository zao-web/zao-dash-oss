<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync Slack channels and messages from the Slack API.
 *
 * After syncing, dispatches ProcessSlackMessagesJob for AI analysis.
 */
class SyncSlackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $workspaceId = null,
        public ?int $channelId = null,
        public int $messageLimit = 100,
        /**
         * Explicit Unix timestamp to fetch messages back to. Overrides the
         * incremental/default window — used by `slack:backfill-channel` to
         * pull historical messages that predate a channel's first sync.
         */
        public ?int $sinceTimestamp = null,
    ) {}

    public function handle(SlackService $slackService): void
    {
        $workspaces = $this->workspaceId
            ? SlackWorkspace::where('id', $this->workspaceId)->get()
            : SlackWorkspace::where('is_active', true)->get();

        foreach ($workspaces as $workspace) {
            try {
                $this->syncWorkspace($workspace, $slackService);

                // Dispatch AI processing job after sync completes
                // Enable auto-task creation for high-confidence action items
                ProcessSlackMessagesJob::dispatch(
                    workspaceId: $workspace->id,
                    autoCreateTasks: true
                )
                    ->onQueue('slack-analysis')
                    ->delay(now()->addSeconds(10));
            } catch (\Exception $e) {
                Log::error('Slack sync failed for workspace', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncWorkspace(SlackWorkspace $workspace, SlackService $slackService): void
    {
        $this->initSyncTracking($workspace);

        try {
            // Sync channels first
            $this->syncChannels($workspace, $slackService);
            $this->updateSyncProgress(25, 'channels');

            // Sync client DMs if enabled
            if ($workspace->sync_client_dms) {
                $this->syncDirectMessages($workspace, $slackService);
                $this->updateSyncProgress(35, 'client DMs');
            }

            // Then sync messages for monitored channels and DMs (skip inactive ones)
            $channels = $this->channelId
                ? SlackChannel::where('id', $this->channelId)->get()
                : $this->getActiveMonitoredChannels($workspace);

            $totalChannels = $channels->count();
            foreach ($channels as $index => $channel) {
                $this->syncChannelMessages($channel, $slackService);
                $progress = 35 + (int) (($index + 1) / max(1, $totalChannels) * 60);
                $this->updateSyncProgress($progress, $channel->is_dm ? "DM: {$channel->name}" : "channel: {$channel->name}");
            }

            $workspace->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    /**
     * Get monitored channels and DMs that are considered "active" for message syncing.
     * Skips: archived, low-member channels, inactive Slack Connect channels, and stale channels.
     * Always includes: DMs, client-linked channels, and channels with recent activity.
     */
    protected function getActiveMonitoredChannels(SlackWorkspace $workspace)
    {
        $staleThreshold = now()->subDays(90);

        return $workspace->channels()
            ->where('is_monitored', true)
            ->where('is_archived', false)
            ->where(function ($query) use ($staleThreshold) {
                $query
                    // Always include DMs (already filtered for external users)
                    ->where('is_dm', true)
                    // Always include channels linked to clients
                    ->orWhereNotNull('client_id')
                    // Regular channels: need at least 2 members AND recent activity
                    ->orWhere(function ($q) use ($staleThreshold) {
                        $q->where('is_dm', false)
                            ->where('name', 'not like', 'z-%')
                            ->where('member_count', '>=', 2)
                            ->where(function ($recent) use ($staleThreshold) {
                                $recent->where('last_message_at', '>=', $staleThreshold)
                                    ->orWhereNull('last_message_at'); // New channels without messages yet
                            });
                    })
                    // Slack Connect channels (z-*): need at least 3 members AND recent activity
                    ->orWhere(function ($q) use ($staleThreshold) {
                        $q->where('is_dm', false)
                            ->where('name', 'like', 'z-%')
                            ->where('member_count', '>=', 3)
                            ->where(function ($recent) use ($staleThreshold) {
                                $recent->where('last_message_at', '>=', $staleThreshold)
                                    ->orWhereNull('last_message_at');
                            });
                    });
            })
            ->get();
    }

    protected function syncChannels(SlackWorkspace $workspace, SlackService $slackService): void
    {
        $channels = $slackService->listChannels($workspace);

        // Get existing channel slack_ids for this workspace to know what we're already tracking
        $existingChannelIds = $workspace->channels()->pluck('slack_id')->toArray();

        $synced = 0;
        $skipped = 0;

        foreach ($channels as $channelData) {
            $slackId = $channelData['id'];
            $isExisting = in_array($slackId, $existingChannelIds);
            $isArchived = $channelData['is_archived'] ?? false;
            $name = $channelData['name'] ?? '';
            // null means "Slack didn't tell us" — different from "0 members".
            // Don't filter based on unknown counts; only skip when count is
            // explicitly low.
            $memberCount = $channelData['num_members'] ?? null;

            // Skip criteria for NEW channels only (always update existing ones)
            if (! $isExisting) {
                // Skip archived channels
                if ($isArchived) {
                    $skipped++;

                    continue;
                }

                // Skip channels with very few members (likely inactive).
                // Only applies when the member count is known.
                if ($memberCount !== null && $memberCount < 2) {
                    $skipped++;

                    continue;
                }

                // For Slack Connect channels (z-* prefix), only skip if they seem inactive
                // Keep them if they have 3+ members (likely active client channels)
                if (str_starts_with($name, 'z-') && $memberCount !== null && $memberCount < 3) {
                    $skipped++;

                    continue;
                }
            }

            $isShared = $channelData['is_shared'] ?? $channelData['is_ext_shared'] ?? false;
            $shouldMonitor = $isShared;

            $updateData = [
                'name' => $name,
                'channel_id' => $slackId,
                'channel_name' => $name,
                'is_private' => $channelData['is_private'] ?? false,
                'is_archived' => $isArchived,
                'is_shared' => $isShared,
                'member_count' => $memberCount,
                'topic' => $channelData['topic']['value'] ?? null,
                'purpose' => $channelData['purpose']['value'] ?? null,
            ];

            if (! $isExisting) {
                $updateData['monitoring_enabled'] = $shouldMonitor;
                $updateData['is_monitored'] = $shouldMonitor;
                $updateData['classification'] = $isShared ? 'client' : 'general';
            }

            SlackChannel::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'slack_id' => $slackId,
                ],
                $updateData
            );
            $synced++;
        }

        Log::info('Channel sync completed', [
            'workspace' => $workspace->workspace_name,
            'synced' => $synced,
            'skipped' => $skipped,
            'total_from_api' => count($channels),
        ]);
    }

    /**
     * Sync direct messages with external/client users.
     * Only syncs DMs where at least one participant is a guest or external user.
     */
    protected function syncDirectMessages(SlackWorkspace $workspace, SlackService $slackService): void
    {
        $dms = $slackService->listDirectMessages($workspace);

        $synced = 0;
        $skipped = 0;

        foreach ($dms as $dm) {
            $slackId = $dm['id'];

            // Only sync DMs with external users (clients)
            if (! $slackService->dmHasExternalUser($workspace, $dm)) {
                $skipped++;

                continue;
            }

            // Build a readable name for the DM
            $name = $this->buildDmName($workspace, $dm, $slackService);

            // Get member IDs for the DM
            $memberIds = [];
            if (isset($dm['user'])) {
                $memberIds = [$dm['user']];
            } else {
                $memberIds = $slackService->getConversationMembers($workspace, $slackId);
            }

            SlackChannel::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'slack_id' => $slackId,
                ],
                [
                    'name' => $name,
                    'channel_id' => $slackId,
                    'channel_name' => $name,
                    'is_private' => true,
                    'is_archived' => false,
                    'is_shared' => false,
                    'is_dm' => true,
                    'dm_user_ids' => $memberIds,
                    'member_count' => count($memberIds),
                    'is_monitored' => true,  // Auto-monitor client DMs
                ]
            );
            $synced++;
        }

        Log::info('Client DM sync completed', [
            'workspace' => $workspace->workspace_name,
            'synced' => $synced,
            'skipped_internal' => $skipped,
            'total_dms' => count($dms),
        ]);
    }

    /**
     * Build a readable name for a DM conversation.
     */
    protected function buildDmName(SlackWorkspace $workspace, array $dm, SlackService $slackService): string
    {
        // For 1:1 DMs, use the other user's name
        if (isset($dm['user'])) {
            $userInfo = $slackService->getUserInfo($workspace, $dm['user']);

            return 'DM: '.($userInfo['real_name'] ?? $userInfo['name'] ?? 'Unknown');
        }

        // For group DMs, list participant names
        $memberIds = $slackService->getConversationMembers($workspace, $dm['id']);
        $names = [];
        foreach (array_slice($memberIds, 0, 3) as $memberId) {
            $userInfo = $slackService->getUserInfo($workspace, $memberId);
            $names[] = $userInfo['real_name'] ?? $userInfo['name'] ?? 'Unknown';
        }

        $suffix = count($memberIds) > 3 ? ' +'.(count($memberIds) - 3).' more' : '';

        return 'Group DM: '.implode(', ', $names).$suffix;
    }

    protected function syncChannelMessages(SlackChannel $channel, SlackService $slackService): void
    {
        Log::info('Syncing messages for channel', ['channel' => $channel->name]);

        // Backfill override wins; then incremental (since last sync); then a
        // 30-day cold-start window for never-synced channels. The cold-start
        // was 7 days, which silently dropped most of a just-linked channel's
        // recent history before the first scheduled sync caught up.
        $oldest = $this->sinceTimestamp
            ?? $channel->last_message_at?->timestamp
            ?? now()->subDays(30)->timestamp;

        // Use the error-surfacing variant so we can log when the bot can't
        // see a channel — the silent variant returns [] for permission
        // failures and a healthy channel with zero new messages identically,
        // which masks "bot not in channel" until someone notices their
        // retainer report has no Slack content.
        $result = $slackService->getChannelHistoryWithError(
            $channel->workspace,
            $channel->slack_id,
            $oldest,
            $this->messageLimit
        );

        if (! $result['ok']) {
            // For client-linked channels we expect access — surface as a
            // warning with enough context to act (likely fix: invite the
            // Zao bot to that channel, or grant user OAuth scope).
            $level = $channel->client_id ? 'warning' : 'info';
            Log::$level('SyncSlackJob: channel history fetch failed', [
                'channel' => $channel->name,
                'channel_slack_id' => $channel->slack_id,
                'client_id' => $channel->client_id,
                'error' => $result['error'],
            ]);

            return;
        }

        $messages = $result['messages'];

        $count = 0;
        foreach ($messages as $msg) {
            if (($msg['bot_id'] ?? null) && ! $channel->sync_bot_messages) {
                continue;
            }

            $userId = $msg['user'] ?? null;
            $isExternal = false;
            $userName = $this->resolveUserName($msg, $channel->workspace, $slackService);

            if ($userId) {
                $userInfo = $slackService->getUserInfo($channel->workspace, $userId);
                $isExternal = $userInfo['is_external'] ?? false;
            }

            SlackMessage::updateOrCreate(
                [
                    'channel_id' => $channel->id,
                    'message_ts' => $msg['ts'],
                ],
                [
                    'workspace_id' => $channel->workspace_id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'user_is_external' => $isExternal,
                    'content' => $msg['text'] ?? '',
                    'thread_ts' => $msg['thread_ts'] ?? null,
                    'attachments' => $msg['attachments'] ?? [],
                    'client_id' => $channel->client_id,
                    // Actual Slack send time (not row creation time). Critical
                    // for date-range queries — created_at is the sync timestamp.
                    'sent_at' => isset($msg['ts']) ? \Carbon\Carbon::createFromTimestamp((float) $msg['ts']) : null,
                ]
            );
            $count++;
        }

        $channel->update([
            'last_message_at' => now(),
            'message_count' => $channel->messages()->count(),
        ]);

        Log::info('Synced messages', ['channel' => $channel->name, 'count' => $count]);
    }

    protected function resolveUserName(array $msg, SlackWorkspace $workspace, SlackService $slackService): ?string
    {
        if (! isset($msg['user'])) {
            return $msg['username'] ?? 'Unknown';
        }

        // Try cache first, then API
        static $userCache = [];
        $cacheKey = "{$workspace->id}:{$msg['user']}";

        if (! isset($userCache[$cacheKey])) {
            try {
                $userInfo = $slackService->getUserInfo($workspace, $msg['user']);
                $userCache[$cacheKey] = $userInfo['real_name'] ?? $userInfo['name'] ?? 'Unknown';
            } catch (\Exception $e) {
                $userCache[$cacheKey] = $msg['user'];
            }
        }

        return $userCache[$cacheKey];
    }
}
