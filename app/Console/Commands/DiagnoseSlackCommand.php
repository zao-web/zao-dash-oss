<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackChannelMatcherService;
use App\Services\Slack\SlackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseSlackCommand extends Command
{
    protected $signature = 'slack:diagnose 
        {--fix : Auto-fix issues where possible}
        {--link-channels : Interactively link unlinked channels to clients}
        {--hours=48 : Hours of activity to review}
        {--test-api : Test Slack API access for monitored channels}
        {--channels= : Comma-separated channel names to specifically check}';

    protected $description = 'Diagnose Slack integration issues: channel linking, monitoring, and recent activity';

    public function handle(SlackChannelMatcherService $matcher): int
    {
        $this->info('Slack Integration Diagnostic');
        $this->line('='.str_repeat('=', 50));

        $this->checkWorkspaces();
        $this->checkChannelMonitoring();
        $this->checkClientLinking();

        if ($this->option('channels')) {
            $this->checkSpecificChannels($this->option('channels'));
        }

        $this->reviewRecentActivity((int) $this->option('hours'));

        if ($this->option('fix')) {
            $this->autoFix($matcher);
        }

        if ($this->option('link-channels')) {
            $this->interactiveLinkChannels();
        }

        if ($this->option('test-api')) {
            $this->testApiAccess();
        }

        return self::SUCCESS;
    }

    protected function checkWorkspaces(): void
    {
        $this->newLine();
        $this->info('1. Workspace Status');
        $this->line('-'.str_repeat('-', 40));

        $workspaces = SlackWorkspace::all();

        if ($workspaces->isEmpty()) {
            $this->error('No Slack workspaces connected!');
            $this->line('  → Go to Settings → Integrations → Connect Slack');

            return;
        }

        foreach ($workspaces as $ws) {
            $status = $ws->is_active ? '<fg=green>ACTIVE</>' : '<fg=red>INACTIVE</>';
            $dms = $ws->sync_client_dms ? '<fg=green>YES</>' : '<fg=yellow>NO</>';

            $this->line("  {$ws->workspace_name}");
            $this->line("    Status: {$status}");
            $this->line("    Sync Client DMs: {$dms}");
            $this->line('    Last Synced: '.($ws->last_synced_at?->diffForHumans() ?? 'Never'));
            $this->line('    Channels: '.$ws->channels()->count());

            if (! $ws->sync_client_dms) {
                $this->warn("    ⚠ sync_client_dms is FALSE - DMs won't be processed!");

                if ($this->option('fix')) {
                    $ws->update(['sync_client_dms' => true]);
                    $this->info('    ✓ Fixed: sync_client_dms set to TRUE');
                }
            }
        }
    }

    protected function checkChannelMonitoring(): void
    {
        $this->newLine();
        $this->info('2. Channel Monitoring Status');
        $this->line('-'.str_repeat('-', 40));

        $total = SlackChannel::count();
        $monitored = SlackChannel::where('monitoring_enabled', true)->count();
        $isMonitored = SlackChannel::where('is_monitored', true)->count();
        $mismatch = SlackChannel::whereRaw('monitoring_enabled != is_monitored')->count();

        $this->line("  Total channels: {$total}");
        $this->line("  monitoring_enabled=true: {$monitored}");
        $this->line("  is_monitored=true: {$isMonitored}");

        if ($mismatch > 0) {
            $this->warn("  ⚠ {$mismatch} channels have mismatched monitoring flags!");

            if ($this->option('fix')) {
                SlackChannel::whereRaw('monitoring_enabled != is_monitored')
                    ->update(['is_monitored' => DB::raw('monitoring_enabled')]);
                $this->info('  ✓ Fixed: Synced is_monitored to match monitoring_enabled');
            }
        }

        $clientChannels = SlackChannel::where(function ($q) {
            $q->where('classification', 'client')
                ->orWhereNotNull('client_id');
        })->count();

        $this->line("  Client channels: {$clientChannels}");
    }

    protected function checkClientLinking(): void
    {
        $this->newLine();
        $this->info('3. Channel-Client Linking');
        $this->line('-'.str_repeat('-', 40));

        $unlinked = SlackChannel::whereNull('client_id')
            ->where('monitoring_enabled', true)
            ->where('is_archived', false)
            ->where(function ($q) {
                $q->where('is_shared', true)
                    ->orWhere('is_dm', true)
                    ->orWhere('classification', 'client');
            })
            ->orderBy('name')
            ->get();

        $linked = SlackChannel::whereNotNull('client_id')->count();

        $this->line("  Linked to clients: {$linked}");
        $this->line('  Unlinked (potential client channels): '.$unlinked->count());

        if ($unlinked->isNotEmpty()) {
            $this->newLine();
            $this->warn('  Unlinked channels that may need client linking:');

            $rows = $unlinked->map(fn ($ch) => [
                $ch->name,
                $ch->is_shared ? 'Shared' : ($ch->is_dm ? 'DM' : 'Regular'),
                $ch->classification ?? '-',
                $ch->member_count,
                $ch->messages()->where('created_at', '>', now()->subDays(7))->count().' (7d)',
            ])->toArray();

            $this->table(
                ['Channel', 'Type', 'Classification', 'Members', 'Messages'],
                $rows
            );
        }
    }

    protected function checkSpecificChannels(string $channelFilter): void
    {
        $this->newLine();
        $this->info('Checking Specific Channels');
        $this->line('-'.str_repeat('-', 40));

        $channelNames = array_map('trim', explode(',', $channelFilter));
        $workspace = SlackWorkspace::where('is_active', true)->first();

        foreach ($channelNames as $name) {
            $this->line("  <fg=cyan>{$name}</>");

            $channel = SlackChannel::where('workspace_id', $workspace?->id)
                ->where(function ($q) use ($name) {
                    $q->where('name', 'like', "%{$name}%")
                        ->orWhere('channel_name', 'like', "%{$name}%")
                        ->orWhere('name', 'like', "DM: %{$name}%");
                })
                ->orderByDesc('is_dm')
                ->orderByDesc('is_shared')
                ->first();

            if (! $channel) {
                $this->line('    <fg=red>NOT FOUND in database</>');
                $this->line("    → Channel was never synced or doesn't exist");
                $this->line('    → Will be auto-created on first webhook event');

                continue;
            }

            $this->line("    DB ID: {$channel->id}");
            $this->line('    Slack ID: '.($channel->slack_id ?? $channel->channel_id));
            $this->line('    Type: '.($channel->is_dm ? 'DM' : ($channel->is_shared ? 'Shared' : ($channel->is_private ? 'Private' : 'Public'))));
            $this->line("    Classification: {$channel->classification}");
            $this->line('    Client: '.($channel->client?->name ?? '<fg=yellow>NOT LINKED</>'));

            $monitoringStatus = $channel->monitoring_enabled
                ? '<fg=green>ENABLED</>'
                : '<fg=red>DISABLED</>';
            $this->line("    Monitoring: {$monitoringStatus}");

            $messageCount = $channel->messages()->where('created_at', '>', now()->subDays(7))->count();
            $this->line("    Messages (7d): {$messageCount}");

            if (! $channel->monitoring_enabled) {
                $this->warn('    → Messages from this channel are being DROPPED!');
                $this->line("    → Run: UPDATE slack_channels SET monitoring_enabled=1, is_monitored=1 WHERE id={$channel->id}");
            }

            $this->newLine();
        }
    }

    protected function reviewRecentActivity(int $hours): void
    {
        $this->newLine();
        $this->info("4. Recent Activity (Last {$hours} hours)");
        $this->line('-'.str_repeat('-', 40));

        $since = now()->subHours($hours);

        $totalMessages = SlackMessage::where('created_at', '>=', $since)->count();
        $externalMessages = SlackMessage::where('created_at', '>=', $since)
            ->where('user_is_external', true)
            ->count();
        $processed = SlackMessage::where('created_at', '>=', $since)
            ->whereNotNull('processed_at')
            ->count();
        $actionItems = SlackMessage::where('created_at', '>=', $since)
            ->where('has_action_item', true)
            ->count();

        $this->line("  Total messages: {$totalMessages}");
        $this->line("  From external users: {$externalMessages}");
        $this->line("  AI-processed: {$processed}");
        $this->line("  Action items found: {$actionItems}");

        if ($externalMessages > 0 && $processed === 0) {
            $this->warn('  ⚠ External messages exist but none were processed!');
            $this->line('    → Check if channels are linked to clients');
            $this->line('    → Check if ProcessSlackMessagesJob is running');
        }

        $unprocessedExternal = SlackMessage::where('created_at', '>=', $since)
            ->where('user_is_external', true)
            ->whereNull('processed_at')
            ->count();

        if ($unprocessedExternal > 0) {
            $this->warn("  ⚠ {$unprocessedExternal} external messages not yet processed");
        }

        $this->newLine();
        $this->line('  Messages by channel (external users only):');

        $byChannel = SlackMessage::where('created_at', '>=', $since)
            ->where('user_is_external', true)
            ->select('channel_id', DB::raw('COUNT(*) as count'))
            ->groupBy('channel_id')
            ->orderByDesc('count')
            ->limit(15)
            ->get();

        if ($byChannel->isEmpty()) {
            $this->line('    No external user messages in this period.');
        } else {
            $rows = [];
            foreach ($byChannel as $row) {
                $channel = SlackChannel::find($row->channel_id);
                if (! $channel) {
                    continue;
                }

                $client = $channel->client;
                $rows[] = [
                    $channel->name,
                    $client ? $client->name : '<fg=yellow>NOT LINKED</>',
                    $row->count,
                    $channel->monitoring_enabled ? 'Yes' : '<fg=red>No</>',
                ];
            }

            $this->table(
                ['Channel', 'Client', 'Messages', 'Monitored'],
                $rows
            );
        }

        $this->newLine();
        $this->line('  Recent action items detected:');

        $recentActions = SlackMessage::where('created_at', '>=', $since)
            ->where('has_action_item', true)
            ->with('channel')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($recentActions->isEmpty()) {
            $this->line('    No action items detected in this period.');
        } else {
            foreach ($recentActions as $msg) {
                $channel = $msg->channel?->name ?? 'Unknown';
                $time = $msg->created_at->diffForHumans();
                $action = \Illuminate\Support\Str::limit($msg->action_item_extracted ?? $msg->content, 60);
                $this->line("    [{$time}] #{$channel}: {$action}");
            }
        }
    }

    protected function autoFix(SlackChannelMatcherService $matcher): void
    {
        $this->newLine();
        $this->info('5. Auto-Fix');
        $this->line('-'.str_repeat('-', 40));

        $updated = SlackWorkspace::where('sync_client_dms', false)->update(['sync_client_dms' => true]);
        if ($updated > 0) {
            $this->info("  ✓ Enabled sync_client_dms on {$updated} workspace(s)");
        }

        $synced = SlackChannel::whereRaw('monitoring_enabled != is_monitored')
            ->update(['is_monitored' => DB::raw('monitoring_enabled')]);
        if ($synced > 0) {
            $this->info("  ✓ Synced monitoring flags on {$synced} channel(s)");
        }

        $result = $matcher->autoLinkAllChannels();
        if ($result['linked'] > 0) {
            $this->info("  ✓ Auto-linked {$result['linked']} channel(s) to clients");
        }

        if (! empty($result['suggestions'])) {
            $this->line('  Suggestions for manual linking:');
            foreach (array_slice($result['suggestions'], 0, 10) as $suggestion) {
                $this->line("    - {$suggestion['channel']} → {$suggestion['client']} (score: {$suggestion['score']})");
            }
        }

        $sharedUnclassified = SlackChannel::where('is_shared', true)
            ->where('classification', '!=', 'client')
            ->update(['classification' => 'client']);
        if ($sharedUnclassified > 0) {
            $this->info("  ✓ Classified {$sharedUnclassified} shared channel(s) as 'client'");
        }

        $this->info('  Auto-fix complete.');
    }

    protected function interactiveLinkChannels(): void
    {
        $this->newLine();
        $this->info('6. Interactive Channel Linking');
        $this->line('-'.str_repeat('-', 40));

        $unlinked = SlackChannel::whereNull('client_id')
            ->where('monitoring_enabled', true)
            ->where('is_archived', false)
            ->where(function ($q) {
                $q->where('is_shared', true)
                    ->orWhere('is_dm', true)
                    ->orWhere('member_count', '>=', 2);
            })
            ->orderByDesc(
                SlackMessage::selectRaw('COUNT(*)')
                    ->whereColumn('channel_id', 'slack_channels.id')
                    ->where('created_at', '>', now()->subDays(7))
            )
            ->limit(20)
            ->get();

        if ($unlinked->isEmpty()) {
            $this->info('  All active channels are linked to clients!');

            return;
        }

        $clients = Client::orderBy('name')->pluck('name', 'id')->toArray();
        $clientChoices = ['skip' => '-- Skip --', 'create' => '-- Create New Client --'] + $clients;

        foreach ($unlinked as $channel) {
            $recentMessages = $channel->messages()
                ->where('user_is_external', true)
                ->where('created_at', '>', now()->subDays(7))
                ->limit(3)
                ->get();

            $this->newLine();
            $this->line("  <fg=cyan>Channel:</> {$channel->name}");
            $this->line('    Type: '.($channel->is_dm ? 'DM' : ($channel->is_shared ? 'Shared' : 'Regular')));
            $this->line("    Members: {$channel->member_count}");

            if ($recentMessages->isNotEmpty()) {
                $this->line('    Recent messages from external users:');
                foreach ($recentMessages as $msg) {
                    $preview = \Illuminate\Support\Str::limit($msg->content, 80);
                    $this->line("      - {$msg->user_name}: {$preview}");
                }
            }

            $choice = $this->choice(
                "Link '{$channel->name}' to which client?",
                $clientChoices,
                'skip'
            );

            if ($choice === 'skip') {
                continue;
            }

            if ($choice === 'create') {
                $clientName = $this->ask('Enter new client name');
                if ($clientName) {
                    $client = Client::create([
                        'name' => $clientName,
                        'slug' => \Illuminate\Support\Str::slug($clientName),
                        'status' => 'active',
                    ]);
                    $channel->update([
                        'client_id' => $client->id,
                        'classification' => 'client',
                    ]);
                    $this->info("    ✓ Created client '{$clientName}' and linked channel");
                }

                continue;
            }

            $clientId = array_search($choice, $clients);
            if ($clientId) {
                $channel->update([
                    'client_id' => $clientId,
                    'classification' => 'client',
                ]);
                $this->info("    ✓ Linked to {$choice}");
            }
        }

        $this->newLine();
        $this->info('  Interactive linking complete.');
    }

    protected function testApiAccess(): void
    {
        $this->newLine();
        $this->info('7. Testing Slack API Access');
        $this->line('-'.str_repeat('-', 40));

        $slackService = app(SlackService::class);
        $workspace = SlackWorkspace::where('is_active', true)->first();

        if (! $workspace) {
            $this->error('  No active workspace found.');

            return;
        }

        $channelFilter = $this->option('channels');

        if ($channelFilter) {
            $channelNames = array_map('trim', explode(',', $channelFilter));
            $channels = SlackChannel::where('workspace_id', $workspace->id)
                ->where(function ($q) use ($channelNames) {
                    foreach ($channelNames as $name) {
                        $q->orWhere('name', 'like', "%{$name}%")
                            ->orWhere('channel_name', 'like', "%{$name}%");
                    }
                })
                ->get();

            if ($channels->isEmpty()) {
                $this->warn("  No channels found matching: {$channelFilter}");
                $this->line('  These channels may not be synced. Ensure the bot is invited to them.');

                return;
            }
        } else {
            $channels = SlackChannel::where('workspace_id', $workspace->id)
                ->where('monitoring_enabled', true)
                ->where(function ($q) {
                    $q->where('is_shared', true)
                        ->orWhere('is_dm', true)
                        ->orWhereNotNull('client_id');
                })
                ->orderByDesc('last_message_at')
                ->limit(10)
                ->get();
        }

        if ($channels->isEmpty()) {
            $this->warn('  No client channels to test.');

            return;
        }

        $this->line("  Testing API access to {$channels->count()} channels:");
        $this->newLine();

        foreach ($channels as $channel) {
            $monitorStatus = $channel->monitoring_enabled ? '<fg=green>ON</>' : '<fg=red>OFF</>';
            $this->line("  <fg=cyan>{$channel->name}</> [monitoring: {$monitorStatus}]");
            $this->line('    Type: '.($channel->is_dm ? 'DM' : ($channel->is_shared ? 'Shared' : ($channel->is_private ? 'Private' : 'Public'))));
            $this->line('    Client: '.($channel->client?->name ?? '<fg=yellow>NOT LINKED</>'));

            try {
                $channelId = $channel->slack_id ?? $channel->channel_id;
                $result = $slackService->getChannelHistoryWithError(
                    $workspace,
                    $channelId,
                    now()->subDays(7)->timestamp,
                    10
                );

                if (! $result['ok']) {
                    $error = $result['error'];
                    $this->line("    <fg=red>✗ API Error: {$error}</>");

                    if ($error === 'not_in_channel') {
                        $this->line('    <fg=yellow>→ Bot is NOT a member of this private channel</>');
                        $this->line('    <fg=yellow>→ Invite the Zao app: /invite @Zao</>');
                    } elseif ($error === 'channel_not_found') {
                        $this->line("    <fg=yellow>→ Channel doesn't exist or bot has no access</>");
                    } elseif ($error === 'missing_scope') {
                        $this->line('    <fg=yellow>→ OAuth scope missing! Reinstall app with correct scopes</>');
                    }
                } elseif (empty($result['messages'])) {
                    $this->line('    <fg=yellow>⚠ Channel accessible but no messages in last 7 days</>');
                } else {
                    $messages = $result['messages'];
                    $this->line('    <fg=green>✓ Got '.count($messages).' messages</>');

                    $latest = $messages[0] ?? null;
                    if ($latest && isset($latest['ts'])) {
                        $latestTime = \Carbon\Carbon::createFromTimestamp((float) $latest['ts']);
                        $this->line('    Latest: '.$latestTime->diffForHumans());
                    }

                    $external = 0;
                    foreach (array_slice($messages, 0, 5) as $msg) {
                        if (! isset($msg['user'])) {
                            continue;
                        }
                        $userInfo = $slackService->getUserInfo($workspace, $msg['user']);
                        if ($userInfo['is_external'] ?? false) {
                            $external++;
                        }
                    }

                    if ($external > 0) {
                        $this->line("    <fg=green>External user messages found: {$external}</>");
                    }
                }
            } catch (\Exception $e) {
                $this->line("    <fg=red>✗ Exception: {$e->getMessage()}</>");
            }

            $this->newLine();
        }

        $this->line('  <fg=yellow>TIP:</> To check specific channels, use:');
        $this->line('  php artisan slack:diagnose --test-api --channels="sierra-at-tahoe,pgri-zao,help-guide"');
    }
}
