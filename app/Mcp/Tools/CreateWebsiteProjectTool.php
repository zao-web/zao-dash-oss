<?php

namespace App\Mcp\Tools;

use App\Models\WebsiteProject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateWebsiteProjectTool extends Tool
{
    protected string $name = 'create-website-project';

    protected string $title = 'Create Website Project';

    protected string $description = 'Create a new website builder project. Specify the project type (autonomous, guided, migration, redesign) and source information.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|in:autonomous,guided,migration,redesign',
            'source_type' => 'nullable|in:domain,brief,url,github,manual',
            'source_data' => 'nullable|array',
            'domain' => 'nullable|string|max:255',
            'brief' => 'nullable|string',
            'hosting_type' => 'nullable|in:wordpress_com,self_hosted,existing_site',
            'budget_allocated' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        if (! $user) {
            return Response::structured([
                'success' => false,
                'message' => 'Authentication required to create website projects.',
            ]);
        }

        $projectData = [
            'name' => $validated['name'],
            'user_id' => $user->id,
            'project_type' => $validated['project_type'],
            'source_type' => $validated['source_type'] ?? WebsiteProject::SOURCE_BRIEF,
            'status' => WebsiteProject::STATUS_CREATED,
            'hosting_type' => $validated['hosting_type'] ?? WebsiteProject::HOSTING_WORDPRESS_COM,
            'environment' => WebsiteProject::ENV_STAGING,
        ];

        if (isset($validated['domain'])) {
            $projectData['domain'] = $validated['domain'];
        }

        if (isset($validated['brief'])) {
            $projectData['source_data'] = ['brief' => $validated['brief']];
        } elseif (isset($validated['source_data'])) {
            $projectData['source_data'] = $validated['source_data'];
        }

        if (isset($validated['budget_allocated'])) {
            $projectData['budget_allocated'] = $validated['budget_allocated'];
        }

        $project = WebsiteProject::create($projectData);

        return Response::structured([
            'success' => true,
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'project_type' => $project->project_type,
            'status' => $project->status,
            'message' => "Website project '{$project->name}' created successfully. Use trigger-website-build to start building.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Website project name'),
            'project_type' => $schema->string()->required()
                ->enum(['autonomous', 'guided', 'migration', 'redesign'])
                ->description('Type of website project: autonomous (AI builds from brief), guided (step-by-step), migration (from existing site), redesign (refresh existing)'),
            'source_type' => $schema->string()
                ->enum(['domain', 'brief', 'url', 'github', 'manual'])
                ->description('Source of project content/requirements'),
            'source_data' => $schema->object()->description('Additional source data (e.g., URLs to migrate, GitHub repo info)'),
            'domain' => $schema->string()->description('Target domain for the website'),
            'brief' => $schema->string()->description('Project brief describing what to build (for autonomous/guided types)'),
            'hosting_type' => $schema->string()
                ->enum(['wordpress_com', 'self_hosted', 'existing_site'])
                ->description('Where the site will be hosted (default: wordpress_com)'),
            'budget_allocated' => $schema->number()->description('Budget allocated for this project'),
        ];
    }
}
