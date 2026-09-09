<?php

namespace App\Agents\Tools\SiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Events\SiteBuilderStatusUpdated;
use App\Models\SiteBuilderProject;

class SiteBuilderStatusUpdateTool extends BaseTool
{
    public function id(): string
    {
        return 'site_builder_status_update';
    }

    public function name(): string
    {
        return 'Site Builder Status Update';
    }

    public function description(): string
    {
        return 'Update the status and phase of a site builder project. Broadcasts progress to the user interface in real-time.';
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
                'status' => [
                    'type' => 'string',
                    'enum' => ['research', 'wordpress_setup', 'content_generation', 'content_sync', 'qa', 'complete', 'failed'],
                    'description' => 'Current phase/status of the build process',
                ],
                'progress' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Overall progress percentage (0-100)',
                ],
                'phase_details' => [
                    'type' => 'string',
                    'description' => 'Description of current activity within the phase',
                ],
                'urls' => [
                    'type' => 'object',
                    'properties' => [
                        'staging' => ['type' => 'string'],
                        'production' => ['type' => 'string'],
                    ],
                    'description' => 'URLs for the staging and production sites when available',
                ],
                'error' => [
                    'type' => 'string',
                    'description' => 'Error message if status is failed',
                ],
            ],
            'required' => ['project_id', 'status'],
        ];
    }

    public function execute(array $params): array
    {
        $project = SiteBuilderProject::findOrFail($params['project_id']);

        $statusConstant = match ($params['status']) {
            'research' => SiteBuilderProject::STATUS_RESEARCH,
            'wordpress_setup' => SiteBuilderProject::STATUS_WORDPRESS_SETUP,
            'content_generation' => SiteBuilderProject::STATUS_CONTENT_GENERATION,
            'content_sync' => SiteBuilderProject::STATUS_CONTENT_SYNC,
            'qa' => SiteBuilderProject::STATUS_QA,
            'complete' => SiteBuilderProject::STATUS_COMPLETE,
            'failed' => SiteBuilderProject::STATUS_FAILED,
            default => $params['status'],
        };

        $updateData = ['status' => $statusConstant];

        if (isset($params['phase_details'])) {
            $progressData = $project->progress_data ?? [];
            $progressData[$params['status']] = $params['phase_details'];
            $updateData['progress_data'] = $progressData;
        }

        if (isset($params['urls']['staging'])) {
            $updateData['staging_url'] = $params['urls']['staging'];
        }

        if (isset($params['urls']['production'])) {
            $updateData['production_url'] = $params['urls']['production'];
        }

        if (isset($params['error'])) {
            $updateData['last_error'] = $params['error'];
        }

        if ($statusConstant === SiteBuilderProject::STATUS_COMPLETE) {
            $updateData['completed_at'] = now();
        }

        $project->update($updateData);
        $project->refresh();

        broadcast(new SiteBuilderStatusUpdated(
            project: $project,
            status: $project->status,
            phase: $params['status'],
            phaseStatus: $params['phase_details'] ?? null,
            phaseProgress: 100,
            progress: $params['progress'] ?? $project->getProgressPercentage(),
            error: $params['error'] ?? null
        ));

        return [
            'success' => true,
            'project_id' => $project->id,
            'status' => $project->status,
            'progress' => $project->getProgressPercentage(),
            'staging_url' => $project->staging_url,
            'production_url' => $project->production_url,
        ];
    }
}
