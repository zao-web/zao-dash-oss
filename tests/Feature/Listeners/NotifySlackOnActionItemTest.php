<?php

use App\Events\SlackActionItemDetected;
use App\Listeners\NotifySlackOnActionItem;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiService = Mockery::mock(SlackApiService::class);
    $this->responseService = new SlackBotResponseService;
});

test('sends high confidence notification with buttons', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::on(fn ($ws) => $ws->id === $workspace->id),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocks = $options['blocks'] ?? [];
                $hasCreateButton = false;
                $hasDismissButton = false;

                foreach ($blocks as $block) {
                    if ($block['type'] === 'actions') {
                        foreach ($block['elements'] as $element) {
                            if ($element['action_id'] === 'create_task_from_action_item') {
                                $hasCreateButton = true;
                            }
                            if ($element['action_id'] === 'dismiss_action_item') {
                                $hasDismissButton = true;
                            }
                        }
                    }
                }

                return $options['thread_ts'] === '1234567890.123456'
                    && $hasCreateButton
                    && $hasDismissButton;
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Need to update the API documentation',
        0.85
    );

    $listener->handle($event);
});

test('sends medium confidence notification with dashboard link', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::on(fn ($ws) => $ws->id === $workspace->id),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'Possible Action Item')
                    && str_contains($blocksJson, 'Review in Dashboard');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Maybe consider adding error handling',
        0.55
    );

    $listener->handle($event);
});

test('does not send notification for low confidence', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'This is probably nothing',
        0.3
    );

    $listener->handle($event);
});

test('does not send notification when workspace is missing', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $message->channel->workspace_id = null;
    $message->channel->setRelation('workspace', null);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Some action item',
        0.8
    );

    $listener->handle($event);
});

test('does not send notification when channel is missing', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $message->setRelation('channel', null);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Some action item',
        0.8
    );

    $listener->handle($event);
});

test('uses thread_ts when available', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
        'thread_ts' => '1234567890.000000',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(fn ($options) => $options['thread_ts'] === '1234567890.000000')
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Action item in thread',
        0.75
    );

    $listener->handle($event);
});

test('shows confidence percentage in message', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, '82%');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'High priority action',
        0.82
    );

    $listener->handle($event);
});

test('handles api error gracefully', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andThrow(new \Exception('API Error'));

    $listener = new NotifySlackOnActionItem($this->apiService, $this->responseService);

    $event = new SlackActionItemDetected(
        $message,
        'Important action',
        0.9
    );

    $listener->handle($event);

    expect(true)->toBeTrue();
});

test('listener is queued on slack-notifications queue', function () {
    $listener = new NotifySlackOnActionItem(
        Mockery::mock(SlackApiService::class),
        new SlackBotResponseService
    );

    expect($listener->queue)->toBe('slack-notifications');
});
