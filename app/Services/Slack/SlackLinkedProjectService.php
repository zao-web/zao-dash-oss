<?php

namespace App\Services\Slack;

use App\Models\Project;
use App\Models\SlackChannel;

class SlackLinkedProjectService
{
    /**
     * @return array{project?: Project, error?: string}
     */
    public function resolve(SlackChannel $channel): array
    {
        $project = $channel->project()->first();

        if ($project) {
            return ['project' => $project];
        }

        $client = $channel->client;

        if (! $client) {
            return ['error' => $this->missingProjectMessage()];
        }

        $activeProjects = $client->projects()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($activeProjects->count() === 1) {
            return ['project' => $activeProjects->first()];
        }

        if ($activeProjects->count() > 1) {
            return ['error' => 'This Slack channel is linked to a client with multiple active projects. Link the channel to a specific project first.'];
        }

        return ['error' => $this->missingProjectMessage()];
    }

    private function missingProjectMessage(): string
    {
        return "No active project found for this channel.\n\nPlease link this Slack channel to a project in Zao Dash, or specify a project manually.";
    }
}
