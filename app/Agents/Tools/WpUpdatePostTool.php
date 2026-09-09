<?php

namespace App\Agents\Tools;

use App\Services\WordPress\WordPressMcpService;

/**
 * Update an existing WordPress post via REST PUT /wp/v2/posts/{id}.
 *
 * Uses WordPressMcpService::updatePost and the stored Dash application password.
 */
class WpUpdatePostTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'wp-update-post';
    }

    public function name(): string
    {
        return 'Update WordPress Post';
    }

    public function description(): string
    {
        return 'Update an existing WordPress post through the Dash WordPress integration (stored application password). PUTs /wp/v2/posts/{id}. Provide post_id and at least one field to change.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post ID to update',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Updated post title',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Updated post content in HTML',
                ],
                'excerpt' => [
                    'type' => 'string',
                    'description' => 'Updated excerpt/summary for previews and SEO',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'publish', 'future', 'pending'],
                    'description' => 'Post status: draft, publish, future, or pending',
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of category IDs to assign',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of tag IDs to assign',
                ],
                'featured_media' => [
                    'type' => 'integer',
                    'description' => 'Media ID of the featured image',
                ],
                'meta' => [
                    'type' => 'object',
                    'description' => 'Meta fields for SEO plugins (e.g., _yoast_wpseo_title, _yoast_wpseo_metadesc)',
                ],
                'schedule_date' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 date for scheduling (only used when status=future)',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Custom URL slug',
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        $wpService = app(WordPressMcpService::class);
        $site = $wpService->defaultSite();

        if (! $site) {
            return [
                'success' => false,
                'error' => 'No WordPress site configured. Go to Settings → Integrations to connect your WordPress site.',
            ];
        }

        $postId = (int) ($params['post_id'] ?? 0);

        if ($postId < 1) {
            return [
                'success' => false,
                'error' => 'Provide a WordPress post_id to update.',
            ];
        }

        $postData = $this->buildPostData($params);

        if ($postData === []) {
            return [
                'success' => false,
                'error' => 'Provide at least one field to update (title, content, excerpt, status, categories, tags, featured_media, slug, or meta).',
            ];
        }

        try {
            $result = $wpService->updatePost($site, $postId, $postData);

            return [
                'success' => true,
                'post_id' => $result['id'] ?? $postId,
                'post_url' => $result['link'] ?? null,
                'edit_url' => rtrim($site->url, '/').'/wp-admin/post.php?post='.($result['id'] ?? $postId).'&action=edit',
                'status' => $result['status'] ?? ($postData['status'] ?? null),
                'wordpress_site' => $site->name,
                'message' => 'WordPress post updated via PUT /wp/v2/posts/'.$postId.'.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function buildPostData(array $params): array
    {
        $postData = [];

        foreach (['title', 'content', 'excerpt', 'categories', 'tags', 'slug'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null && $params[$field] !== '') {
                $postData[$field] = $params[$field];
            }
        }

        if (! empty($params['status'])) {
            $postData['status'] = $params['status'];
        }

        if (array_key_exists('featured_media', $params) && $params['featured_media'] !== null && $params['featured_media'] !== '') {
            $postData['featured_media'] = $params['featured_media'];
        }

        if (($params['status'] ?? null) === 'future' && ! empty($params['schedule_date'])) {
            $postData['date'] = $params['schedule_date'];
        }

        if (! empty($params['meta'])) {
            $postData['meta'] = $params['meta'];
        }

        return $postData;
    }
}
