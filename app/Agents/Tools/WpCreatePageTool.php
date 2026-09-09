<?php

namespace App\Agents\Tools;

use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;

/**
 * Create and publish pages to WordPress.
 *
 * Pages are typically used for static content like service pages, about pages, etc.
 * Unlike posts, pages don't have categories or tags but can have parent pages.
 */
class WpCreatePageTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'wp-create-page';
    }

    public function name(): string
    {
        return 'Create WordPress Page';
    }

    public function description(): string
    {
        return 'Create a new page on WordPress for static content like service pages, landing pages, or about pages. Pages are created as drafts by default for review.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Page content in HTML format. Use proper heading hierarchy (h2, h3), paragraphs, lists.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'publish', 'pending'],
                    'description' => 'Page status: draft (default, for review), publish (immediate), pending (for editor review)',
                    'default' => 'draft',
                ],
                'parent' => [
                    'type' => 'integer',
                    'description' => 'Parent page ID if this is a child page',
                ],
                'template' => [
                    'type' => 'string',
                    'description' => 'Page template name (e.g., template-fullwidth.php)',
                ],
                'featured_media' => [
                    'type' => 'integer',
                    'description' => 'Media ID of the featured image',
                ],
                'menu_order' => [
                    'type' => 'integer',
                    'description' => 'Order in navigation menus (lower numbers appear first)',
                ],
                'meta' => [
                    'type' => 'object',
                    'description' => 'Meta fields for SEO plugins (e.g., _yoast_wpseo_title, _yoast_wpseo_metadesc)',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Custom URL slug (auto-generated from title if not provided)',
                ],
            ],
            'required' => ['title', 'content'],
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
        $site = WordPressSite::where('is_primary', true)->first()
            ?? WordPressSite::first();

        if (! $site) {
            return [
                'success' => false,
                'error' => 'No WordPress site configured. Go to Settings → Integrations to connect your WordPress site.',
            ];
        }

        $wpService = app(WordPressMcpService::class);

        // Build page data
        $pageData = [
            'title' => $params['title'],
            'content' => $params['content'],
            'status' => $params['status'] ?? 'draft',
        ];

        if (! empty($params['parent'])) {
            $pageData['parent'] = $params['parent'];
        }

        if (! empty($params['template'])) {
            $pageData['template'] = $params['template'];
        }

        if (! empty($params['featured_media'])) {
            $pageData['featured_media'] = $params['featured_media'];
        }

        if (! empty($params['menu_order'])) {
            $pageData['menu_order'] = $params['menu_order'];
        }

        if (! empty($params['slug'])) {
            $pageData['slug'] = $params['slug'];
        }

        // Add meta fields (for SEO plugins like Yoast)
        if (! empty($params['meta'])) {
            $pageData['meta'] = $params['meta'];
        }

        try {
            $result = $wpService->createPage($site, $pageData);

            return [
                'success' => true,
                'page_id' => $result['id'] ?? null,
                'page_url' => $result['link'] ?? null,
                'edit_url' => rtrim($site->url, '/').'/wp-admin/post.php?post='.($result['id'] ?? '').'&action=edit',
                'status' => $result['status'] ?? $pageData['status'],
                'wordpress_site' => $site->name,
                'message' => $this->getStatusMessage($result['status'] ?? $pageData['status']),
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
            'publish' => 'Page published and live on the site.',
            'draft' => 'Page saved as draft. Review and publish from WordPress admin.',
            'pending' => 'Page submitted for review. Editor approval required.',
            default => 'Page created.',
        };
    }
}
