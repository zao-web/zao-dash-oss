<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\WebsiteProject;

/**
 * Update project progress and broadcast status to WebSocket clients.
 *
 * Updates database and triggers real-time UI updates.
 */
class WebsiteBuilderUpdateProgressTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Update Project Progress';
    }

    public function description(): string
    {
        return 'Update website project status, progress, and phase information. Automatically broadcasts updates to connected WebSocket clients for real-time UI updates.';
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
                'status' => [
                    'type' => 'string',
                    'enum' => ['created', 'analyzing', 'designing', 'building', 'reviewing', 'deploying', 'complete', 'failed'],
                    'description' => 'Current project status',
                ],
                'overall_progress' => [
                    'type' => 'integer',
                    'description' => 'Overall progress percentage (0-100)',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'phase_progress' => [
                    'type' => 'object',
                    'description' => 'Progress for individual phases',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Progress message to display to user',
                ],
                'staging_url' => [
                    'type' => 'string',
                    'description' => 'Staging site URL (if available)',
                ],
                'production_url' => [
                    'type' => 'string',
                    'description' => 'Production site URL (if deployed)',
                ],
                'error' => [
                    'type' => 'string',
                    'description' => 'Error message (if status is failed)',
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'status' => 'nullable|in:created,analyzing,designing,building,reviewing,deploying,complete,failed',
            'overall_progress' => 'nullable|integer|min:0|max:100',
            'phase_progress' => 'nullable|array',
            'message' => 'nullable|string|max:500',
            'staging_url' => 'nullable|url',
            'production_url' => 'nullable|url',
            'error' => 'nullable|string|max:1000',
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

        $updateData = [];

        // Update status if provided
        if (isset($params['status'])) {
            $updateData['status'] = $params['status'];

            // Auto-set timestamps based on status
            if ($params['status'] === 'analyzing' && ! $project->started_at) {
                $updateData['started_at'] = now();
            }

            if (in_array($params['status'], ['complete', 'failed'])) {
                $updateData['completed_at'] = now();
            }
        }

        // Update progress if provided
        if (isset($params['overall_progress'])) {
            $updateData['overall_progress'] = $params['overall_progress'];
        }

        // Update phase progress if provided
        if (isset($params['phase_progress'])) {
            $updateData['phase_progress'] = array_merge(
                $project->phase_progress ?? [],
                $params['phase_progress']
            );
        }

        // Update URLs if provided
        if (isset($params['staging_url'])) {
            $updateData['staging_url'] = $params['staging_url'];
        }

        if (isset($params['production_url'])) {
            $updateData['production_url'] = $params['production_url'];
        }

        // Update error if provided
        if (isset($params['error'])) {
            $updateData['last_error'] = $params['error'];
            $updateData['retry_count'] = ($project->retry_count ?? 0) + 1;
        }

        // Apply updates
        $project->update($updateData);

        // Broadcast status update to WebSocket channel
        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: $project->status,
            phase: $params['phase'] ?? null,
            message: $params['message'] ?? null,
            phaseProgress: $project->phase_progress['current'] ?? 0,
            progress: $project->overall_progress ?? 0,
            stagingUrl: $project->staging_url,
            productionUrl: $project->production_url,
            error: $project->last_error
        ));

        return [
            'success' => true,
            'project_id' => $project->id,
            'status' => $project->status,
            'overall_progress' => $project->overall_progress,
            'phase_progress' => $project->phase_progress,
            'broadcast_sent' => true,
        ];
    }
}
