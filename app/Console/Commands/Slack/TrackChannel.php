<?php

namespace App\Console\Commands\Slack;

use App\Models\Client;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use Illuminate\Console\Command;

class TrackChannel extends Command
{
    protected $signature = 'slack:track-channel
        {slack_id : Slack channel/DM ID, e.g. C00EXAMPLE01}
        {client : Client id or slug}
        {--name= : Friendly channel name for display (defaults to slack_id)}
        {--workspace= : Workspace id, defaults to the primary workspace}
        {--dm : Mark as a DM/group DM (treated like a DM by the sync job)}';

    protected $description = 'Manually register a Slack channel or group DM to monitor for a client. SyncSlackJob will pull its history on its next run.';

    public function handle(): int
    {
        $slackId = $this->argument('slack_id');
        $clientNeedle = $this->argument('client');

        $client = is_numeric($clientNeedle)
            ? Client::find($clientNeedle)
            : Client::where('slug', $clientNeedle)->orWhere('name', $clientNeedle)->first();

        if (! $client) {
            $this->error("No client found for: {$clientNeedle}");

            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace');
        $workspace = $workspaceId
            ? SlackWorkspace::find($workspaceId)
            : SlackWorkspace::where('is_primary', true)->first() ?? SlackWorkspace::first();

        if (! $workspace) {
            $this->error('No Slack workspace configured.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? "Tracked: {$slackId}";
        $isDm = (bool) $this->option('dm');

        $channel = SlackChannel::updateOrCreate(
            ['workspace_id' => $workspace->id, 'slack_id' => $slackId],
            [
                'channel_id' => $slackId,
                'channel_name' => $name,
                'name' => $name,
                'client_id' => $client->id,
                'is_monitored' => true,
                'monitoring_enabled' => true,
                'is_archived' => false,
                'is_dm' => $isDm,
                'sync_bot_messages' => false,
            ]
        );

        $this->info("Tracking channel {$slackId} → {$client->name} (slack_channels.id={$channel->id})");
        $this->line('Run `php artisan slack:sync` (or wait for the scheduled run) to pull its history.');

        return self::SUCCESS;
    }
}
