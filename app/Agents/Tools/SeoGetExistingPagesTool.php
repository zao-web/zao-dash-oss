<?php

namespace App\Agents\Tools;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;

class SeoGetExistingPagesTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-get-existing-pages';
    }

    public function name(): string
    {
        return 'Get Existing SEO Pages';
    }

    public function description(): string
    {
        return 'Get list of existing published SEO pages for internal linking. Returns URLs, keywords, and metadata of pages that can be linked to. Use when generating new content to add relevant internal links.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_type' => [
                    'type' => 'string',
                    'description' => 'Filter by page type (service_page, comparison, how_to, category_hub, etc.)',
                ],
                'playbook' => [
                    'type' => 'string',
                    'description' => 'Filter by playbook (Location, Comparisons, Persona, Vertical, etc.)',
                ],
                'technology' => [
                    'type' => 'string',
                    'description' => 'Filter by technology keyword (laravel, wordpress, react-native, woocommerce)',
                ],
                'exclude_page_id' => [
                    'type' => 'integer',
                    'description' => 'Exclude this page ID from results (the page currently being generated)',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search keyword or topic to find related pages',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of pages to return (default: 20, max: 50)',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['impressions', 'clicks', 'revenue', 'recent'],
                    'description' => 'Sort results by metric (default: impressions)',
                ],
            ],
            'required' => [],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        try {
            $query = SeoPage::where('status', SeoPageStatus::Published);

            // Filter by page type
            if (! empty($params['page_type'])) {
                $query->where('page_type', $params['page_type']);
            }

            // Filter by playbook
            if (! empty($params['playbook'])) {
                $query->where('playbook', $params['playbook']);
            }

            // Filter by technology in keyword or URL
            if (! empty($params['technology'])) {
                $tech = $params['technology'];
                $query->where(function ($q) use ($tech) {
                    $q->where('target_keyword', 'like', "%{$tech}%")
                        ->orWhere('page_url', 'like', "%{$tech}%")
                        ->orWhere('meta_title', 'like', "%{$tech}%");
                });
            }

            // Search across multiple fields
            if (! empty($params['search'])) {
                $search = $params['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('target_keyword', 'like', "%{$search}%")
                        ->orWhere('page_url', 'like', "%{$search}%")
                        ->orWhere('meta_title', 'like', "%{$search}%")
                        ->orWhere('meta_description', 'like', "%{$search}%");
                });
            }

            // Exclude current page
            if (! empty($params['exclude_page_id'])) {
                $query->where('id', '!=', (int) $params['exclude_page_id']);
            }

            // Sort by specified metric
            $sortBy = $params['sort_by'] ?? 'impressions';
            $query = match ($sortBy) {
                'clicks' => $query->orderByDesc('clicks_30d'),
                'revenue' => $query->orderByDesc('total_revenue'),
                'recent' => $query->orderByDesc('published_at'),
                default => $query->orderByDesc('impressions_30d'),
            };

            // Apply limit
            $limit = min((int) ($params['limit'] ?? 20), 50);
            $pages = $query->limit($limit)
                ->select([
                    'id',
                    'page_url',
                    'target_keyword',
                    'meta_title',
                    'meta_description',
                    'page_type',
                    'playbook',
                    'impressions_30d',
                    'clicks_30d',
                ])
                ->get();

            // Format for internal linking use
            $formattedPages = $pages->map(function ($page) {
                return [
                    'id' => $page->id,
                    'url' => $page->page_url,
                    'keyword' => $page->target_keyword,
                    'title' => $page->meta_title,
                    'type' => $page->page_type,
                    'playbook' => $page->playbook,
                    'anchor_suggestions' => $this->generateAnchorSuggestions($page),
                    'performance' => [
                        'impressions' => $page->impressions_30d,
                        'clicks' => $page->clicks_30d,
                    ],
                ];
            });

            return [
                'success' => true,
                'data' => [
                    'pages' => $formattedPages->toArray(),
                    'count' => $formattedPages->count(),
                    'filters_applied' => array_filter([
                        'page_type' => $params['page_type'] ?? null,
                        'playbook' => $params['playbook'] ?? null,
                        'technology' => $params['technology'] ?? null,
                        'search' => $params['search'] ?? null,
                    ]),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate anchor text suggestions for a page.
     */
    protected function generateAnchorSuggestions(SeoPage $page): array
    {
        $suggestions = [];

        // Primary: target keyword
        if ($page->target_keyword) {
            $suggestions[] = $page->target_keyword;
        }

        // Extract key phrases from title
        if ($page->meta_title) {
            // Remove brand suffix like "| Zao" or "- Zao"
            $cleanTitle = preg_replace('/\s*[|\-–—]\s*Zao.*$/i', '', $page->meta_title);
            if ($cleanTitle && $cleanTitle !== $page->target_keyword) {
                $suggestions[] = $cleanTitle;
            }
        }

        // Technology-specific anchors
        $technologies = ['Laravel', 'WordPress', 'WooCommerce', 'React Native', 'React', 'Vue'];
        foreach ($technologies as $tech) {
            if (stripos($page->target_keyword, $tech) !== false) {
                $suggestions[] = "{$tech} development";
                $suggestions[] = "{$tech} services";
                break;
            }
        }

        // Add action-based anchors
        $suggestions[] = 'learn more';
        $suggestions[] = 'read more about '.strtolower($page->target_keyword);

        return array_unique(array_slice($suggestions, 0, 5));
    }
}
