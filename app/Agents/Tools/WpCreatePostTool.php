<?php

namespace App\Agents\Tools;

use App\Services\WordPress\WordPressMcpService;

/**
 * Create and publish posts to WordPress.
 *
 * Supports drafts, scheduling, categories, tags, and featured images.
 * Uses MCP if available, falls back to REST API.
 */
class WpCreatePostTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'wp-create-post';
    }

    public function name(): string
    {
        return 'Create WordPress Post';
    }

    public function description(): string
    {
        return 'Create a new blog post on WordPress. Supports HTML content, categories, tags, featured images, and scheduling. Posts are created as drafts by default for review.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Post title (required unless dry_run)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Post content in HTML format. Use proper heading hierarchy (h2, h3), paragraphs, lists. Required unless dry_run.',
                ],
                'excerpt' => [
                    'type' => 'string',
                    'description' => 'Short excerpt/summary for previews and SEO (recommended 150-160 chars)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'publish', 'future', 'pending'],
                    'description' => 'Post status: draft (default), publish (immediate), future (scheduled), pending (for review)',
                    'default' => 'draft',
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
                    'description' => 'Media ID of the featured image (from generate-featured-image tool)',
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
                    'description' => 'Custom URL slug (auto-generated from title if not provided)',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'Handshake REST auth and persist rest_url without creating a post',
                    'default' => false,
                ],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return true; // Publishing requires human approval
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

        if (! empty($params['dry_run'])) {
            try {
                return $wpService->handshake($site);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($params['title']) || empty($params['content'])) {
            return [
                'success' => false,
                'error' => 'Provide title and content, or set dry_run true.',
            ];
        }

        // Build post data
        $postData = [
            'title' => $params['title'],
            'content' => $params['content'],
            'status' => $params['status'] ?? 'draft',
        ];

        if (! empty($params['excerpt'])) {
            $postData['excerpt'] = $params['excerpt'];
        }

        if (! empty($params['categories'])) {
            $postData['categories'] = $params['categories'];
        }

        if (! empty($params['tags'])) {
            $postData['tags'] = $params['tags'];
        }

        if (! empty($params['featured_media'])) {
            $postData['featured_media'] = $params['featured_media'];
        }

        if (! empty($params['slug'])) {
            $postData['slug'] = $params['slug'];
        }

        // Handle scheduling
        if (($params['status'] ?? 'draft') === 'future' && ! empty($params['schedule_date'])) {
            $postData['date'] = $params['schedule_date'];
        }

        // Add meta fields (for SEO plugins like Yoast)
        if (! empty($params['meta'])) {
            $postData['meta'] = $params['meta'];
        }

        try {
            $result = $wpService->createPost($site, $postData);

            return [
                'success' => true,
                'post_id' => $result['id'] ?? null,
                'post_url' => $result['link'] ?? null,
                'edit_url' => rtrim($site->url, '/').'/wp-admin/post.php?post='.($result['id'] ?? '').'&action=edit',
                'status' => $result['status'] ?? $postData['status'],
                'wordpress_site' => $site->name,
                'message' => $this->getStatusMessage($result['status'] ?? $postData['status']),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getStatusMessage(string $status): string
    {
        return match ($status) {
            'publish' => 'Post published and live on the site.',
            'future' => 'Post scheduled for future publication.',
            'draft' => 'Post saved as draft. Review and publish from WordPress admin.',
            'pending' => 'Post submitted for review. Editor approval required.',
            default => 'Post created.',
        };
    }
}
