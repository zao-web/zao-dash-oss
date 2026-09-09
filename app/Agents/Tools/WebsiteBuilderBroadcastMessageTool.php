<?php

namespace App\Agents\Tools;

use App\Events\WebsiteBuilderMessageReceived;
use App\Models\WebsiteProject;

/**
 * Broadcast a message to the Website Builder chat interface.
 *
 * Allows the orchestrator agent to stream chat messages to the frontend
 * during long-running website building operations.
 */
class WebsiteBuilderBroadcastMessageTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Broadcast Message';
    }

    public function description(): string
    {
        return 'Send a chat message to the Website Builder interface. Use this to communicate progress, ask for feedback, or share updates with the user during website building.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The Website Project ID to send the message to',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The message content to display in the chat',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['assistant', 'system'],
                    'description' => 'Message role: assistant for normal messages, system for status updates',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Optional metadata to attach to the message (e.g., phase, action type)',
                ],
            ],
            'required' => ['project_id', 'content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'content' => 'required|string|max:50000',
            'role' => 'nullable|string|in:assistant,system',
            'metadata' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::findOrFail($params['project_id']);
        $role = $params['role'] ?? 'assistant';
        $metadata = $params['metadata'] ?? null;

        event(new WebsiteBuilderMessageReceived(
            project: $project,
            content: $params['content'],
            role: $role,
            metadata: $metadata,
        ));

        return [
            'success' => true,
            'project_id' => $project->id,
            'message_sent' => true,
            'role' => $role,
        ];
    }
}
