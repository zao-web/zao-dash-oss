<?php

namespace App\Console\Commands\Slack;

use App\Jobs\SyncSlackJob;
use App\Models\Client;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Services\Slack\SlackService;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;

class BackfillChannelHistory extends Command
{
    protected $signature = 'slack:backfill-channel
        {channel : slack_channels.id (numeric) or the Slack channel/DM id, e.g. C00EXAMPLE02}
        {--client= : Client id or name/slug to link the channel to before syncing}
        {--since=30 days : How far back to pull, relative ("60 days") or absolute ("2026-05-01")}
        {--limit=500 : Max messages to request from Slack in the backfill window}';

    protected $description = 'Backfill a Slack channel\'s historical messages past the normal incremental sync window, optionally linking it to a client first. Use when a channel\'s history never synced (e.g. it was unlinked) and needs to appear on retainer reports.';

    public function handle(SlackService $slack): int
    {
        $channel = $this->resolveChannel($this->argument('channel'));

        if (! $channel) {
            $this->error("No Slack channel found for: {$this->argument('channel')}");

            return self::FAILURE;
        }

        if ($clientNeedle = $this->option('client')) {
            if (! $this->linkClient($channel, $clientNeedle)) {
                return self::FAILURE;
            }
        }

        if (! $channel->client_id) {
            $this->warn('Channel is not linked to a client — synced messages will not carry a client_id and will not surface on retainer reports. Pass --client= to link it.');
        }

        $since = $this->resolveSince((string) $this->option('since'));
        if (! $since) {
            $this->error("Could not parse --since value: {$this->option('since')}");

            return self::FAILURE;
        }

        $before = SlackMessage::where('channel_id', $channel->id)->count();

        $this->info(sprintf(
            'Backfilling #%s (id=%d) since %s …',
            $channel->name ?? $channel->channel_name,
            $channel->id,
            $since->toDateTimeString(),
        ));

        // Run synchronously so the operator sees the result immediately.
        // Passing channelId bypasses the monitored-channel filter, so this
        // works even on a channel not flagged for routine syncing.
        SyncSlackJob::dispatchSync(
            workspaceId: $channel->workspace_id,
            channelId: $channel->id,
            messageLimit: (int) $this->option('limit'),
            sinceTimestamp: $since->timestamp,
        );

        $after = SlackMessage::where('channel_id', $channel->id)->count();
        $added = $after - $before;
        $this->info("Done. Messages for this channel: {$before} → {$after} (+{$added}).");

        // A zero delta is ambiguous: genuinely no messages in the window, or a
        // silently-swallowed Slack API failure (bot not in channel, missing
        // scope, wrong conversation id). SyncSlackJob only logs that — probe
        // directly so the operator gets the real reason here.
        if ($added === 0) {
            $this->diagnoseEmptyResult($slack, $channel->fresh(), $since);
        }

        return self::SUCCESS;
    }

    protected function diagnoseEmptyResult(SlackService $slack, SlackChannel $channel, Carbon $since): void
    {
        $conversationId = $channel->slack_id ?: $channel->channel_id;

        if (! $conversationId) {
            $this->warn('No Slack conversation id on this channel row — cannot fetch history.');

            return;
        }

        $result = $slack->getChannelHistoryWithError(
            $channel->workspace,
            $conversationId,
            $since->timestamp,
            (int) $this->option('limit'),
        );

        if (! $result['ok']) {
            $this->warn("Slack returned no history for {$conversationId}: {$result['error']}.");
            $this->line('Likely fixes: invite the Zao bot to the channel, grant the workspace a user OAuth token with the right *_history scope (channels:history / groups:history / mpim:history), or confirm the conversation id.');

            return;
        }

        $this->line("Slack reports no messages in range for {$conversationId} — nothing to backfill. Confirm this is the conversation you meant (group DMs and same-named channels are easy to mix up).");
    }

    protected function resolveChannel(string $needle): ?SlackChannel
    {
        if (is_numeric($needle)) {
            return SlackChannel::find((int) $needle);
        }

        return SlackChannel::where('slack_id', $needle)
            ->orWhere('channel_id', $needle)
            ->first();
    }

    protected function linkClient(SlackChannel $channel, string $needle): bool
    {
        $client = is_numeric($needle)
            ? Client::find((int) $needle)
            : Client::where('slug', $needle)->orWhere('name', $needle)->first();

        if (! $client) {
            $this->error("No client found for: {$needle}");

            return false;
        }

        $channel->update([
            'client_id' => $client->id,
            'classification' => 'client',
            'is_monitored' => true,
            'monitoring_enabled' => true,
        ]);

        $this->line("Linked channel → {$client->name} (and enabled monitoring).");

        return true;
    }

    protected function resolveSince(string $value): ?Carbon
    {
        $value = trim($value);

        try {
            // Relative phrase like "30 days", "2 weeks", "6 months" — read as
            // an interval and subtract from now ("ago"). Anything else is
            // treated as an absolute date/time, e.g. "2026-05-01".
            if (preg_match('/^\d+\s+\w+$/', $value)) {
                return now()->sub(CarbonInterval::fromString($value));
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
