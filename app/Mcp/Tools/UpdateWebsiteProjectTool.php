<?php

namespace App\Mcp\Tools;

use App\Models\WebsiteProject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateWebsiteProjectTool extends Tool
{
    protected string $name = 'update-website-project';

    protected string $title = 'Update Website Project';

    protected string $description = 'Update a website project\'s status, domain, or other properties. Useful for resetting stuck builds or updating configuration.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $project = $request->get('id')
            ? WebsiteProject::findOrFail($request->get('id'))
            : WebsiteProject::where('slug', $request->get('slug'))->firstOrFail();

        $updatedFields = [];

        if ($request->has('status')) {
            $validStatuses = ['created', 'analyzing', 'designing', 'building', 'reviewing', 'deploying', 'complete', 'failed'];
            $status = $request->get('status');

            if (! in_array($status, $validStatuses)) {
                return Response::structured([
                    'success' => false,
                    'message' => "Invalid status '{$status}'. Valid statuses: ".implode(', ', $validStatuses),
                ]);
            }

            $project->status = $status;
            $updatedFields[] = 'status';

            // Clear error if resetting to a non-failed state
            if ($status !== 'failed' && $project->last_error) {
                $project->last_error = null;
                $updatedFields[] = 'last_error';
            }
        }

        if ($request->has('domain')) {
            $project->domain = $request->get('domain');
            $updatedFields[] = 'domain';
        }

        if ($request->has('staging_url')) {
            $project->staging_url = $request->get('staging_url');
            $updatedFields[] = 'staging_url';
        }

        if ($request->has('production_url')) {
            $project->production_url = $request->get('production_url');
            $updatedFields[] = 'production_url';
        }

        if ($request->has('overall_progress')) {
            $project->overall_progress = (int) $request->get('overall_progress');
            $updatedFields[] = 'overall_progress';
        }

        if (empty($updatedFields)) {
            return Response::structured([
                'success' => false,
                'message' => 'No fields to update. Provide at least one of: status, domain, staging_url, production_url, overall_progress',
            ]);
        }

        $project->save();

        return Response::structured([
            'success' => true,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'updated_fields' => $updatedFields,
            'current_status' => $project->status,
            'message' => "Website project '{$project->name}' updated successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Website project ID'),
            'slug' => $schema->string()->description('Website project slug (alternative to ID)'),
            'status' => $schema->string()->description('New status: created, analyzing, designing, building, reviewing, deploying, complete, failed'),
            'domain' => $schema->string()->description('Update the target domain'),
            'staging_url' => $schema->string()->description('Update the staging URL'),
            'production_url' => $schema->string()->description('Update the production URL'),
            'overall_progress' => $schema->integer()->description('Set overall progress percentage (0-100)'),
        ];
    }
}
