<?php

namespace App\Listeners;

use App\Events\SlackActionItemDetected;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifySlackOnActionItem implements ShouldQueue
{
    public string $queue = 'slack-notifications';

    public function __construct(
        private SlackApiService $api,
        private SlackBotResponseService $responseService,
    ) {}

    public function handle(SlackActionItemDetected $event): void
    {
        $message = $event->message;
        $actionItem = $event->actionItem;
        $confidence = $event->confidence;

        $channel = $message->channel;
        $workspace = $channel?->workspace;

        if (! $workspace || ! $channel) {
            Log::warning('NotifySlackOnActionItem: Missing workspace or channel', [
                'message_id' => $message->id,
            ]);

            return;
        }

        if ($confidence >= 0.7) {
            $this->notifyHighConfidenceActionItem($workspace, $channel, $message, $actionItem, $confidence);
        } elseif ($confidence >= 0.4) {
            $this->notifyMediumConfidenceActionItem($workspace, $channel, $message, $actionItem, $confidence);
        }
    }

    private function notifyHighConfidenceActionItem($workspace, $channel, $message, string $actionItem, float $confidence): void
    {
        $threadTs = $message->thread_ts ?? $message->message_ts;

        try {
            $this->api->postMessage($workspace, $channel->channel_id, '', [
                'thread_ts' => $threadTs,
                'blocks' => [
                    $this->responseService->section(":clipboard: *Action Item Detected*\n\n> {$actionItem}"),
                    [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'text' => ['type' => 'plain_text', 'text' => 'Create Task'],
                                'style' => 'primary',
                                'action_id' => 'create_task_from_action_item',
                                'value' => json_encode([
                                    'message_id' => $message->id,
                                    'action_item' => $actionItem,
                                ]),
                            ],
                            [
                                'type' => 'button',
                                'text' => ['type' => 'plain_text', 'text' => 'Dismiss'],
                                'action_id' => 'dismiss_action_item',
                                'value' => (string) $message->id,
                            ],
                        ],
                    ],
                    $this->responseService->context([
                        'Confidence: '.round($confidence * 100).'% | Reply to this thread to add more context',
                    ]),
                ],
            ]);

            Log::info('NotifySlackOnActionItem: High confidence notification sent', [
                'message_id' => $message->id,
                'confidence' => $confidence,
            ]);
        } catch (\Exception $e) {
            Log::error('NotifySlackOnActionItem: Failed to send notification', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyMediumConfidenceActionItem($workspace, $channel, $message, string $actionItem, float $confidence): void
    {
        $threadTs = $message->thread_ts ?? $message->message_ts;

        try {
            $this->api->postMessage($workspace, $channel->channel_id, '', [
                'thread_ts' => $threadTs,
                'blocks' => [
                    $this->responseService->section(":thinking_face: *Possible Action Item*\n\n> {$actionItem}"),
                    $this->responseService->context([
                        'Confidence: '.round($confidence * 100).'% | <'.config('app.url').'/messages/'.$message->id.'|Review in Dashboard>',
                    ]),
                ],
            ]);

            Log::info('NotifySlackOnActionItem: Medium confidence notification sent', [
                'message_id' => $message->id,
                'confidence' => $confidence,
            ]);
        } catch (\Exception $e) {
            Log::warning('NotifySlackOnActionItem: Failed to send medium confidence notification', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
