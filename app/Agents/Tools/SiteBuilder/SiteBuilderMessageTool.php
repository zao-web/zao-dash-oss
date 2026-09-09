<?php

namespace App\Agents\Tools\SiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Events\SiteBuilderMessageReceived;
use App\Models\SiteBuilderProject;

class SiteBuilderMessageTool extends BaseTool
{
    public function id(): string
    {
        return 'site_builder_message';
    }

    public function name(): string
    {
        return 'Site Builder Message';
    }

    public function description(): string
    {
        return 'Send a message to the user through the site builder chat interface. Use this to provide updates, ask questions, or share information during the build process.';
    }

    public function category(): string
    {
        return 'site_builder';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The site builder project ID',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'The message content to send to the user',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['assistant', 'system'],
                    'description' => 'Message role: assistant for AI responses, system for automated notifications',
                    'default' => 'assistant',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Additional metadata like tool executed, actions available, etc.',
                ],
            ],
            'required' => ['project_id', 'message'],
        ];
    }

    public function execute(array $params): array
    {
        $project = SiteBuilderProject::findOrFail($params['project_id']);

        $role = $params['role'] ?? 'assistant';
        $metadata = $params['metadata'] ?? [];

        broadcast(new SiteBuilderMessageReceived(
            project: $project,
            content: $params['message'],
            role: $role,
            metadata: $metadata
        ));

        return [
            'success' => true,
            'project_id' => $project->id,
            'message_sent' => true,
            'role' => $role,
        ];
    }
}
