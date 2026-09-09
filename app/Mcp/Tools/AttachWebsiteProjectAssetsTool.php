<?php

namespace App\Mcp\Tools;

use App\Models\WebsiteProject;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AttachWebsiteProjectAssetsTool extends Tool
{
    protected string $name = 'attach-website-project-assets';

    protected string $title = 'Attach Assets to Website Project';

    protected string $description = 'Attach images, PDFs, or other files to a website project. Assets can be attached from local file paths or URLs. These assets will be available to the website builder agent during the build process.';

    public function __construct(
        protected WebsiteProjectAssetService $assetService
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project_id' => 'required_without:project_slug|integer|exists:website_projects,id',
            'project_slug' => 'required_without:project_id|string',
            'assets' => 'required|array|min:1|max:50',
            'assets.*.path' => 'required_without:assets.*.url|string',
            'assets.*.url' => 'required_without:assets.*.path|url',
            'assets.*.category' => 'nullable|string|in:logo,hero,background,brief,content,reference,icon,photo',
            'assets.*.description' => 'nullable|string|max:500',
        ]);

        $project = isset($validated['project_id'])
            ? WebsiteProject::findOrFail($validated['project_id'])
            : WebsiteProject::where('slug', $validated['project_slug'])->firstOrFail();

        $user = $request->user();
        $uploaded = [];
        $errors = [];

        foreach ($validated['assets'] as $assetData) {
            try {
                if (isset($assetData['path'])) {
                    $asset = $this->assetService->uploadFromPath(
                        $project,
                        $assetData['path'],
                        $assetData['category'] ?? null,
                        $assetData['description'] ?? null,
                        $user
                    );
                } else {
                    $asset = $this->assetService->uploadFromUrl(
                        $project,
                        $assetData['url'],
                        $assetData['category'] ?? null,
                        $assetData['description'] ?? null,
                        $user
                    );
                }
                $uploaded[] = $asset->toArrayForAgent();
            } catch (\Exception $e) {
                $errors[] = [
                    'source' => $assetData['path'] ?? $assetData['url'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        $allAssets = $this->assetService->getAssetsForAgent($project);

        return Response::structured([
            'success' => count($uploaded) > 0,
            'project_id' => $project->id,
            'project_slug' => $project->slug,
            'uploaded' => $uploaded,
            'errors' => $errors,
            'total_uploaded' => count($uploaded),
            'total_errors' => count($errors),
            'all_project_assets' => $allAssets,
            'message' => count($uploaded) > 0
                ? count($uploaded).' asset(s) attached successfully.'.(count($errors) > 0 ? ' '.count($errors).' failed.' : '')
                : 'No assets were uploaded. '.count($errors).' error(s) occurred.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Website project ID (alternative to project_slug)'),
            'project_slug' => $schema->string()->description('Website project slug (alternative to project_id)'),
            'assets' => $schema->array()->items(
                $schema->object([
                    'path' => $schema->string()->description('Local file path to the asset (alternative to url)'),
                    'url' => $schema->string()->description('URL to download the asset from (alternative to path)'),
                    'category' => $schema->string()->enum([
                        'logo', 'hero', 'background', 'brief', 'content', 'reference', 'icon', 'photo',
                    ])->description('Asset category'),
                    'description' => $schema->string()->description('Description of the asset (max 500 chars)'),
                ])
            )->required()->description('Array of assets to attach. Each asset needs either a local file path or a URL.'),
        ];
    }
}
