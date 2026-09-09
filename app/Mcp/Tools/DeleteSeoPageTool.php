<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteSeoPageTool extends Tool
{
    protected string $name = 'delete-seo-page';

    protected string $title = 'Delete SEO Page';

    protected string $description = 'Delete an SEO page. Requires confirmation. Use with caution.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page_id' => 'required|exists:seo_pages,id',
            'confirm' => 'required|boolean|accepted',
        ]);

        if (! $validated['confirm']) {
            return Response::error('Deletion requires confirmation. Set confirm=true to proceed.');
        }

        $page = SeoPage::findOrFail($validated['page_id']);

        $pageTitle = $page->meta_title;
        $pageUrl = $page->page_url;
        $playbook = $page->playbook;

        // Check if page has leads attributed
        $leadCount = $page->leads()->count();
        if ($leadCount > 0) {
            return Response::error("Cannot delete page with {$leadCount} attributed leads. Remove lead attributions first.");
        }

        $page->delete();

        return Response::structured([
            'deleted' => true,
            'page_id' => $validated['page_id'],
            'page_title' => $pageTitle,
            'page_url' => $pageUrl,
            'playbook' => $playbook,
            'message' => "Deleted SEO page '{$pageTitle}'",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->required()->description('SEO page ID to delete'),
            'confirm' => $schema->boolean()->required()->description('Confirm deletion (must be true)'),
        ];
    }
}
