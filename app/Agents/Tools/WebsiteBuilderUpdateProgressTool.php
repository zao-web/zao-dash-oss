<?php

namespace App\Agents\Tools;

use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\WebsiteProject;

/**
 * Update project progress and broadcast status to frontend.
 */
class WebsiteBuilderUpdateProgressTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Update Progress';
    }

    public function description(): string
    {
        return 'Update the Website Builder project status and progress. Broadcasts real-time updates to the frontend. Use this to track phase completion and overall progress.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The Website Project ID to update',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['analyzing', 'designing', 'building', 'reviewing', 'deploying', 'complete', 'failed'],
                    'description' => 'The new project status',
                ],
                'phase' => [
                    'type' => 'string',
                    'description' => 'Current phase name (e.g., research, design, content, implementation)',
                ],
                'phase_progress' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Progress percentage for the current phase (0-100)',
                ],
                'overall_progress' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Overall project progress percentage (0-100)',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Status message to display to the user',
                ],
                'staging_url' => [
                    'type' => 'string',
                    'description' => 'Staging URL once available',
                ],
                'production_url' => [
                    'type' => 'string',
                    'description' => 'Production URL once deployed',
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'status' => 'nullable|string|in:analyzing,designing,building,reviewing,deploying,complete,failed',
            'phase' => 'nullable|string|max:100',
            'phase_progress' => 'nullable|integer|min:0|max:100',
            'overall_progress' => 'nullable|integer|min:0|max:100',
            'message' => 'nullable|string|max:1000',
            'staging_url' => 'nullable|url|max:500',
            'production_url' => 'nullable|url|max:500',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::findOrFail($params['project_id']);

        $updates = [];

        if (isset($params['status'])) {
            $updates['status'] = $params['status'];
        }

        if (isset($params['overall_progress'])) {
            $updates['overall_progress'] = $params['overall_progress'];
        }

        if (isset($params['phase']) && isset($params['phase_progress'])) {
            $phaseProgress = $project->phase_progress ?? [];
            $phaseProgress[$params['phase']] = $params['phase_progress'];
            $updates['phase_progress'] = $phaseProgress;
        }

        if (isset($params['staging_url'])) {
            $updates['staging_url'] = $params['staging_url'];
        }

        if (isset($params['production_url'])) {
            $updates['production_url'] = $params['production_url'];
        }

        if (! empty($updates)) {
            $project->update($updates);
            $project->refresh();
        }

        event(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: $project->status,
            phase: $params['phase'] ?? null,
            message: $params['message'] ?? null,
            phaseProgress: $params['phase_progress'] ?? 0,
            progress: $project->overall_progress ?? $project->getProgressPercentage(),
            stagingUrl: $project->staging_url,
            productionUrl: $project->production_url,
        ));

        return [
            'success' => true,
            'project_id' => $project->id,
            'status' => $project->status,
            'overall_progress' => $project->overall_progress,
            'phase_progress' => $project->phase_progress,
            'staging_url' => $project->staging_url,
            'production_url' => $project->production_url,
        ];
    }
}
