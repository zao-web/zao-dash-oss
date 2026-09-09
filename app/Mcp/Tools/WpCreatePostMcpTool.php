<?php

namespace App\Mcp\Tools;

use App\Agents\Tools\WpCreatePostTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class WpCreatePostMcpTool extends Tool
{
    protected string $name = 'wp-create-post';

    protected string $title = 'Create WordPress Post';

    protected string $description = 'Create a WordPress post through the Dash WordPress integration (stored application password). Defaults to draft. Set dry_run true to handshake REST auth and persist rest_url without creating a post.';

    public function __construct(protected WpCreatePostTool $publisher) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $dryRun = $request->boolean('dry_run');

        $validated = $request->validate([
            'title' => [$dryRun ? 'nullable' : 'required', 'string'],
            'content' => [$dryRun ? 'nullable' : 'required', 'string'],
            'excerpt' => 'nullable|string',
            'status' => 'nullable|in:draft,publish,future,pending',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'tags' => 'nullable|array',
            'tags.*' => 'integer',
            'featured_media' => 'nullable|integer',
            'meta' => 'nullable|array',
            'schedule_date' => 'nullable|string',
            'slug' => 'nullable|string',
            'dry_run' => 'nullable|boolean',
        ]);

        $validated['dry_run'] = $dryRun;

        return Response::structured($this->publisher->execute($validated));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Post title (required unless dry_run)'),
            'content' => $schema->string()->description('Post content in HTML (required unless dry_run)'),
            'excerpt' => $schema->string()->description('Short excerpt for previews and SEO'),
            'status' => $schema->string()->enum(['draft', 'publish', 'future', 'pending'])->description('Post status (default: draft)'),
            'categories' => $schema->array()->items($schema->integer())->description('Category IDs'),
            'tags' => $schema->array()->items($schema->integer())->description('Tag IDs'),
            'featured_media' => $schema->integer()->description('WordPress media ID for the featured image'),
            'slug' => $schema->string()->description('Custom URL slug'),
            'schedule_date' => $schema->string()->description('ISO 8601 date when status is future'),
            'dry_run' => $schema->boolean()->description('Handshake REST auth and persist rest_url without creating a post'),
        ];
    }
}
