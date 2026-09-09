<?php

namespace App\Services\Slack;

use App\Models\SlackMessage;

class SlackContextGathererService
{
    public function __construct(
        protected SlackService $slackService
    ) {}

    public function gatherContext(SlackMessage $message, int $beforeCount = 10, int $afterCount = 5): array
    {
        $context = [
            'trigger_message' => $this->formatMessage($message),
            'user_info' => $this->getUserInfo($message),
            'thread_context' => [],
            'channel_context' => [],
        ];

        if ($message->thread_ts) {
            $context['thread_context'] = $this->getThreadContext($message);
        }

        $context['channel_context'] = $this->getChannelContext($message, $beforeCount, $afterCount);

        return $context;
    }

    protected function getUserInfo(SlackMessage $message): array
    {
        if (! $message->user_id || ! $message->channel?->workspace) {
            return [
                'name' => $message->user_name,
                'is_external' => $message->user_is_external,
            ];
        }

        $userInfo = $this->slackService->getUserInfo(
            $message->channel->workspace,
            $message->user_id
        );

        return [
            'name' => $userInfo['real_name'] ?? $userInfo['name'] ?? $message->user_name,
            'email' => $userInfo['email'] ?? null,
            'is_external' => $userInfo['is_external'] ?? $message->user_is_external,
        ];
    }

    protected function getThreadContext(SlackMessage $message): array
    {
        $threadMessages = SlackMessage::where('channel_id', $message->channel_id)
            ->where('thread_ts', $message->thread_ts)
            ->where('id', '!=', $message->id)
            ->orderBy('message_ts')
            ->limit(20)
            ->get();

        return $threadMessages->map(fn ($m) => $this->formatMessage($m))->toArray();
    }

    protected function getChannelContext(SlackMessage $message, int $before, int $after): array
    {
        $beforeMessages = SlackMessage::where('channel_id', $message->channel_id)
            ->where('message_ts', '<', $message->message_ts)
            ->whereNull('thread_ts')
            ->orderByDesc('message_ts')
            ->limit($before)
            ->get()
            ->reverse();

        $afterMessages = SlackMessage::where('channel_id', $message->channel_id)
            ->where('message_ts', '>', $message->message_ts)
            ->whereNull('thread_ts')
            ->orderBy('message_ts')
            ->limit($after)
            ->get();

        $combined = $beforeMessages->merge($afterMessages);

        return $combined->map(fn ($m) => $this->formatMessage($m))->toArray();
    }

    protected function formatMessage(SlackMessage $message): array
    {
        return [
            'id' => $message->id,
            'user' => $message->user_name,
            'is_external' => $message->user_is_external,
            'content' => $message->content,
            'timestamp' => $message->created_at?->toIso8601String(),
            'has_action_item' => $message->has_action_item,
            'action_item' => $message->action_item_extracted,
        ];
    }

    public function buildPromptContext(array $context): string
    {
        $parts = [];

        $parts[] = '## Trigger Message';
        $parts[] = "From: {$context['trigger_message']['user']} (external: ".($context['trigger_message']['is_external'] ? 'yes' : 'no').')';
        $parts[] = "Content: {$context['trigger_message']['content']}";
        $parts[] = "Action Item: {$context['trigger_message']['action_item']}";

        if (! empty($context['user_info']['email'])) {
            $parts[] = "\n## User Info";
            $parts[] = "Email: {$context['user_info']['email']}";
        }

        if (! empty($context['thread_context'])) {
            $parts[] = "\n## Thread Context";
            foreach ($context['thread_context'] as $msg) {
                $parts[] = "[{$msg['user']}]: {$msg['content']}";
            }
        }

        if (! empty($context['channel_context'])) {
            $parts[] = "\n## Recent Channel Context";
            foreach ($context['channel_context'] as $msg) {
                $parts[] = "[{$msg['user']}]: ".substr($msg['content'], 0, 200);
            }
        }

        return implode("\n", $parts);
    }
}
