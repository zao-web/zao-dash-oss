<?php

namespace App\Services\Activity\Signals;

use App\Models\Client;
use App\Models\SlackMessage;
use Carbon\Carbon;

class SlackSignalCollector
{
    /**
     * Collect raw inbound Slack signals from external (client-side) users
     * over the rolling window. We do NOT filter to "unreplied" here — the
     * synthesis layer reads the surrounding thread context and decides
     * what's noise vs. a real ask.
     *
     * @return array<int, array{source_type:string, external_id:string, permalink:?string, occurred_at:Carbon, actor:?string, content:string, meta:array<string,mixed>}>
     */
    public function collect(Client $client, Carbon $since): array
    {
        $messages = SlackMessage::query()
            ->where('client_id', $client->id)
            ->where('user_is_external', true)
            ->where('sent_at', '>=', $since)
            ->with(['channel', 'workspace'])
            ->orderBy('sent_at')
            ->limit(400)
            ->get();

        return $messages->map(function (SlackMessage $msg): array {
            $channelKey = $msg->channel?->channel_id ?? 'unknown';
            $externalId = $channelKey.':'.$msg->message_ts;

            return [
                'source_type' => 'slack',
                'external_id' => $externalId,
                'permalink' => $msg->permalink ?? null,
                'occurred_at' => $msg->sent_at,
                'actor' => $msg->user_name,
                'content' => (string) $msg->content,
                'meta' => [
                    'channel_id' => $channelKey,
                    'thread_ts' => $msg->thread_ts,
                    'is_thread_reply' => $msg->isThreadReply(),
                    'has_action_item' => (bool) $msg->has_action_item,
                    'action_item_confidence' => $msg->action_item_confidence,
                ],
            ];
        })->all();
    }
}
