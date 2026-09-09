<?php

namespace App\Listeners;

use App\Events\SlackActionItemDetected;
use App\Jobs\TriggerDevAgentFromSlackJob;
use Illuminate\Support\Facades\Log;

class TriggerDevAgentForSlackActionItem
{
    public function handle(SlackActionItemDetected $event): void
    {
        $message = $event->message;

        if (! $message->user_is_external) {
            Log::debug('Skipping dev agent trigger - not from external user', [
                'message_id' => $message->id,
            ]);

            return;
        }

        if (! $message->client_id) {
            Log::warning('Cannot trigger dev agent - no client linked to message', [
                'message_id' => $message->id,
                'channel_id' => $message->channel_id,
            ]);

            return;
        }

        Log::info('Dispatching dev agent job for Slack action item', [
            'message_id' => $message->id,
            'client_id' => $message->client_id,
            'action_item' => $event->actionItem,
        ]);

        TriggerDevAgentFromSlackJob::dispatch($message->id);
    }
}
