<?php

namespace App\Agents\Tools;

use App\Agents\Tools\WebsiteBuilder\SpinupWpProvisionSiteTool;
use App\Events\WebsiteBuilderMessageReceived;
use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use App\Services\SpinupWp\SpinupWpService;
use App\Services\WordPress\WordPressMcpService;

class WebsiteBuilderDeployTool extends BaseTool
{
    public function __construct(
        protected WordPressMcpService $wpService,
        protected SpinupWpService $spinupWpService
    ) {}

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Deploy to WordPress';
    }

    public function description(): string
    {
        return 'Deploy generated pages to the client\'s WordPress staging site. Creates or updates pages with block content and sets the homepage.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The Website Project ID',
                ],
                'wordpress_site_id' => [
                    'type' => 'integer',
                    'description' => 'The WordPress Site ID to deploy to (optional, uses project\'s linked site if not specified)',
                ],
                'pages' => [
                    'type' => 'array',
                    'description' => 'Array of pages to deploy',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Page title',
                            ],
                            'slug' => [
                                'type' => 'string',
                                'description' => 'URL slug (e.g., "about-us")',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Page content in WordPress block format (HTML with block comments)',
                            ],
                            'template' => [
                                'type' => 'string',
                                'description' => 'Page template to use (e.g., "page-no-title", "blank")',
                            ],
                            'is_front_page' => [
                                'type' => 'boolean',
                                'description' => 'Set this page as the site homepage',
                            ],
                            'parent_slug' => [
                                'type' => 'string',
                                'description' => 'Parent page slug for hierarchical pages',
                            ],
                            'menu_order' => [
                                'type' => 'integer',
                                'description' => 'Order in navigation menu',
                            ],
                        ],
                        'required' => ['title', 'slug', 'content'],
                    ],
                ],
                'publish' => [
                    'type' => 'boolean',
                    'description' => 'Publish pages immediately (false = draft for review)',
                    'default' => false,
                ],
                'admin_email' => [
                    'type' => 'string',
                    'description' => 'Admin email for WordPress site provisioning (used if no site exists and SpinupWP is configured)',
                ],
            ],
            'required' => ['project_id', 'pages'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'wordpress_site_id' => 'nullable|integer|exists:wordpress_sites,id',
            'pages' => 'required|array|min:1',
            'pages.*.title' => 'required|string|max:255',
            'pages.*.slug' => 'required|string|max:200',
            'pages.*.content' => 'required|string',
            'pages.*.template' => 'nullable|string|max:100',
            'pages.*.is_front_page' => 'nullable|boolean',
            'pages.*.parent_slug' => 'nullable|string|max:200',
            'pages.*.menu_order' => 'nullable|integer|min:0',
            'publish' => 'nullable|boolean',
            'admin_email' => 'nullable|email',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::findOrFail($params['project_id']);

        $site = $this->resolveWordPressSite($params, $project);

        if (! $site) {
            $provisionResult = $this->provisionSpinupWpSite($project, $params);

            if (! $provisionResult['success']) {
                return $provisionResult;
            }

            $project->refresh();
            $site = $project->wordpressSite;

            if (! $site) {
                return [
                    'success' => false,
                    'error' => 'Failed to provision WordPress site. No site available.',
                ];
            }
        }

        // Test connection first
        if (! $this->wpService->testConnection($site)) {
            return [
                'success' => false,
                'error' => "Cannot connect to WordPress site: {$site->url}. Check credentials.",
            ];
        }

        $results = [
            'success' => true,
            'site_url' => $site->url,
            'site_name' => $site->name,
            'pages_created' => [],
            'pages_updated' => [],
            'errors' => [],
            'front_page_set' => false,
        ];

        $status = $params['publish'] ? 'publish' : 'draft';
        $frontPageId = null;

        // Deploy each page
        foreach ($params['pages'] as $index => $pageData) {
            try {
                $this->broadcastProgress($project, $index, count($params['pages']), $pageData['title']);

                $wpPageData = [
                    'title' => $pageData['title'],
                    'content' => $pageData['content'],
                    'status' => $status,
                ];

                if (! empty($pageData['template'])) {
                    $wpPageData['template'] = $pageData['template'];
                }

                if (isset($pageData['menu_order'])) {
                    $wpPageData['menu_order'] = $pageData['menu_order'];
                }

                // Handle parent page
                if (! empty($pageData['parent_slug'])) {
                    $parent = $this->wpService->getPageBySlug($site, $pageData['parent_slug']);
                    if ($parent) {
                        $wpPageData['parent'] = $parent['id'];
                    }
                }

                // Check if page exists
                $existing = $this->wpService->getPageBySlug($site, $pageData['slug']);

                if ($existing) {
                    $page = $this->wpService->updatePage($site, $existing['id'], $wpPageData);
                    $results['pages_updated'][] = [
                        'id' => $page['id'],
                        'title' => $pageData['title'],
                        'slug' => $pageData['slug'],
                        'url' => $page['link'] ?? null,
                        'edit_url' => rtrim($site->url, '/').'/wp-admin/post.php?post='.$page['id'].'&action=edit',
                    ];
                } else {
                    $wpPageData['slug'] = $pageData['slug'];
                    $page = $this->wpService->createPage($site, $wpPageData);
                    $results['pages_created'][] = [
                        'id' => $page['id'],
                        'title' => $pageData['title'],
                        'slug' => $pageData['slug'],
                        'url' => $page['link'] ?? null,
                        'edit_url' => rtrim($site->url, '/').'/wp-admin/post.php?post='.$page['id'].'&action=edit',
                    ];
                }

                // Track front page
                if (! empty($pageData['is_front_page'])) {
                    $frontPageId = $page['id'];
                }

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'page' => $pageData['title'],
                    'slug' => $pageData['slug'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Set homepage if specified
        if ($frontPageId) {
            try {
                $this->wpService->setHomepage($site, $frontPageId);
                $results['front_page_set'] = true;
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'page' => 'Homepage setting',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $stagingUrl = $site->url;
        $project->update([
            'staging_url' => $stagingUrl,
            'wordpress_site_id' => $site->id,
        ]);

        // Determine overall success
        $totalPages = count($params['pages']);
        $successPages = count($results['pages_created']) + count($results['pages_updated']);
        $results['success'] = $successPages > 0 && count($results['errors']) < $totalPages;

        // Broadcast completion
        $this->broadcastCompletion($project, $results, $stagingUrl);

        return $results;
    }

    protected function resolveWordPressSite(array $params, WebsiteProject $project): ?WordPressSite
    {
        if (! empty($params['wordpress_site_id'])) {
            return WordPressSite::find($params['wordpress_site_id']);
        }

        if ($project->wordpress_site_id) {
            return $project->wordpressSite;
        }

        return null;
    }

    protected function provisionSpinupWpSite(WebsiteProject $project, array $params): array
    {
        if (! $this->spinupWpService->isConfigured()) {
            return [
                'success' => false,
                'error' => 'No WordPress site available and SpinupWP is not configured. Please connect a WordPress site to this project or configure SPINUPWP_API_TOKEN.',
            ];
        }

        broadcast(new WebsiteBuilderMessageReceived(
            project: $project,
            content: 'No WordPress site connected. Provisioning a new staging site via SpinupWP...',
            role: 'assistant',
            metadata: ['action' => 'provisioning_spinupwp'],
        ));

        $provisionTool = app(SpinupWpProvisionSiteTool::class);

        $adminEmail = $params['admin_email'] ?? $project->user?->email ?? 'admin@example.com';

        return $provisionTool->execute([
            'project_id' => $project->id,
            'admin_email' => $adminEmail,
            'site_title' => $project->name,
            'wait_for_completion' => true,
        ]);
    }

    protected function broadcastProgress(WebsiteProject $project, int $index, int $total, string $pageTitle): void
    {
        $progress = 80 + (int) (($index / $total) * 15); // 80-95% range during deploy

        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: 'deploying',
            phase: 'deployment',
            message: "Deploying page: {$pageTitle}",
            phaseProgress: (int) (($index / $total) * 100),
            progress: $progress,
        ));
    }

    protected function broadcastCompletion(WebsiteProject $project, array $results, string $stagingUrl): void
    {
        $createdCount = count($results['pages_created']);
        $updatedCount = count($results['pages_updated']);
        $errorCount = count($results['errors']);

        $message = "Deployment complete: {$createdCount} pages created, {$updatedCount} pages updated.";
        if ($errorCount > 0) {
            $message .= " {$errorCount} errors occurred.";
        }

        broadcast(new WebsiteBuilderMessageReceived(
            project: $project,
            content: $message."\n\nPreview your site: {$stagingUrl}",
            role: 'assistant',
            metadata: [
                'action' => 'deployment_complete',
                'staging_url' => $stagingUrl,
                'pages_created' => $createdCount,
                'pages_updated' => $updatedCount,
                'errors' => $errorCount,
            ],
        ));

        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: $results['success'] ? 'reviewing' : 'failed',
            phase: 'deployment',
            message: $message,
            phaseProgress: 100,
            progress: $results['success'] ? 95 : 80,
            stagingUrl: $stagingUrl,
        ));
    }
}
