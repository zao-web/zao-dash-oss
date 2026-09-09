<?php

namespace App\Events;

use App\Models\SlackMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a Slack message from an external user contains an action item.
 *
 * This event triggers the automation flow:
 * 1. Gather chat context around the message
 * 2. Identify related GitHub repos for the client
 * 3. Start a Dev Agent to investigate and create a PR
 */
class SlackActionItemDetected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SlackMessage $message,
        public string $actionItem,
        public float $confidence
    ) {}
}
