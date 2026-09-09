<?php

namespace App\Mcp\Tools;

use App\Agents\Tools\WpUpdatePostTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class WpUpdatePostMcpTool extends Tool
{
    protected string $name = 'wp-update-post';

    protected string $title = 'Update WordPress Post';

    protected string $description = 'Update an existing WordPress post through the Dash WordPress integration (stored application password). PUTs /wp/v2/posts/{id} via WordPressMcpService::updatePost. Provide post_id and at least one field to change.';

    public function __construct(protected WpUpdatePostTool $updater) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'min:1'],
            'title' => 'nullable|string',
            'content' => 'nullable|string',
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
        ]);

        return Response::structured($this->updater->execute($validated));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->description('WordPress post ID to update')->required(),
            'title' => $schema->string()->description('Updated post title'),
            'content' => $schema->string()->description('Updated post content in HTML'),
            'excerpt' => $schema->string()->description('Updated excerpt for previews and SEO'),
            'status' => $schema->string()->enum(['draft', 'publish', 'future', 'pending'])->description('Post status'),
            'categories' => $schema->array()->items($schema->integer())->description('Category IDs'),
            'tags' => $schema->array()->items($schema->integer())->description('Tag IDs'),
            'featured_media' => $schema->integer()->description('WordPress media ID for the featured image'),
            'slug' => $schema->string()->description('Custom URL slug'),
            'schedule_date' => $schema->string()->description('ISO 8601 date when status is future'),
        ];
    }
}
