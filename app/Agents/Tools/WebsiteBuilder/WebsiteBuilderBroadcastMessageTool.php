<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Events\WebsiteBuilderMessageChunk;
use App\Events\WebsiteBuilderMessageReceived;
use App\Models\WebsiteProject;
use Illuminate\Support\Str;

/**
 * Send real-time chat messages to WebSocket clients.
 *
 * Used by autonomous mode for interactive chat experience.
 */
class WebsiteBuilderBroadcastMessageTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Broadcast Chat Message';
    }

    public function description(): string
    {
        return 'Send a real-time chat message to the user via WebSocket. Messages appear instantly in the UI chat interface.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['assistant', 'system'],
                    'description' => 'Message role (assistant for AI, system for status updates)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Message content',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Optional metadata (phase, action, tool, etc.)',
                ],
                'streaming' => [
                    'type' => 'boolean',
                    'description' => 'Whether to stream the message in chunks',
                ],
            ],
            'required' => ['project_id', 'content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'role' => 'nullable|in:assistant,system',
            'content' => 'required|string|max:5000',
            'metadata' => 'nullable|array',
            'streaming' => 'nullable|boolean',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return [
                'success' => false,
                'error' => 'Project not found',
            ];
        }

        $messageId = 'msg_'.now()->timestamp.'_'.Str::random(6);
        $role = $params['role'] ?? 'assistant';
        $content = $params['content'];
        $metadata = $params['metadata'] ?? [];
        $streaming = $params['streaming'] ?? false;

        if ($streaming) {
            // Stream message in chunks for typing effect
            $chunks = $this->chunkMessage($content);

            // Send start marker
            broadcast(new WebsiteBuilderMessageChunk(
                project: $project,
                messageId: $messageId,
                chunk: $chunks[0] ?? '',
                isStart: true
            ));

            // Send middle chunks
            foreach (array_slice($chunks, 1, -1) as $chunk) {
                broadcast(new WebsiteBuilderMessageChunk(
                    project: $project,
                    messageId: $messageId,
                    chunk: $chunk
                ));
            }

            // Send end marker
            broadcast(new WebsiteBuilderMessageChunk(
                project: $project,
                messageId: $messageId,
                chunk: end($chunks),
                isEnd: true,
                metadata: $metadata
            ));
        } else {
            // Send complete message
            broadcast(new WebsiteBuilderMessageReceived(
                project: $project,
                content: $content,
                role: $role,
                messageId: $messageId,
                metadata: $metadata
            ));
        }

        return [
            'success' => true,
            'message_id' => $messageId,
            'broadcast_sent' => true,
            'streaming' => $streaming,
            'chunk_count' => $streaming ? count($this->chunkMessage($content)) : 0,
        ];
    }

    /**
     * Split message into chunks for streaming effect.
     */
    private function chunkMessage(string $content, int $chunkSize = 50): array
    {
        // Split on word boundaries
        $words = explode(' ', $content);
        $chunks = [];
        $currentChunk = '';

        foreach ($words as $word) {
            if (strlen($currentChunk) + strlen($word) > $chunkSize && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $word.' ';
            } else {
                $currentChunk .= $word.' ';
            }
        }

        if ($currentChunk !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks ?: [$content];
    }
}
