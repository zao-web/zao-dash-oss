<?php

namespace App\Mcp\Tools;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use App\Services\Seo\SeoQualityGateService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListSeoPagesTool extends Tool
{
    protected string $name = 'list-seo-pages';

    protected string $title = 'List SEO Pages';

    protected string $description = 'List SEO pages with filtering by status, playbook, quality gate status, and more. Useful for finding pages that need work or are ready to publish.';

    public function __construct(
        private SeoQualityGateService $qualityGateService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => 'nullable|string',
            'playbook' => 'nullable|string',
            'needs_featured_image' => 'nullable|boolean',
            'needs_cta' => 'nullable|boolean',
            'publishable_only' => 'nullable|boolean',
            'needs_work_only' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        // Use quality gate service for special filters
        if ($validated['publishable_only'] ?? false) {
            $pages = $this->qualityGateService->getPublishablePages();

            return $this->formatResponse($pages, 'publishable');
        }

        if ($validated['needs_work_only'] ?? false) {
            $pages = $this->qualityGateService->getPagesNeedingWork();

            return $this->formatResponse($pages, 'needs_work');
        }

        // Build query for regular filters
        $query = SeoPage::query();

        if ($status = $validated['status'] ?? null) {
            $statusEnum = SeoPageStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        if ($playbook = $validated['playbook'] ?? null) {
            $query->where('playbook', $playbook);
        }

        if ($validated['needs_featured_image'] ?? false) {
            $query->whereNull('featured_image_wordpress_id');
        }

        if ($validated['needs_cta'] ?? false) {
            $query->whereNull('cta_type');
        }

        $limit = $validated['limit'] ?? 20;
        $pages = $query->orderBy('updated_at', 'desc')->limit($limit)->get();

        return $this->formatResponse($pages, 'filtered');
    }

    private function formatResponse($pages, string $filterType): Response
    {
        $formatted = $pages->map(function ($page) {
            return [
                'id' => $page->id,
                'title' => $page->title,
                'keyword' => $page->keyword,
                'playbook' => $page->playbook,
                'status' => $page->status->value ?? $page->status,
                'url_slug' => $page->url_slug,
                'has_featured_image' => $page->hasFeaturedImage(),
                'has_cta' => $page->hasCta(),
                'word_count' => $page->word_count,
                'humanization_score' => $page->humanization_score,
                'updated_at' => $page->updated_at?->toIso8601String(),
            ];
        });

        return Response::structured([
            'filter_type' => $filterType,
            'count' => $pages->count(),
            'pages' => $formatted->toArray(),
            'message' => "Found {$pages->count()} SEO pages matching criteria",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([
                'draft', 'generating', 'review', 'approved', 'published', 'failed',
            ])->description('Filter by page status'),
            'playbook' => $schema->string()->description('Filter by playbook type (e.g., "location", "comparison", "persona")'),
            'needs_featured_image' => $schema->boolean()->description('Only show pages without a featured image'),
            'needs_cta' => $schema->boolean()->description('Only show pages without a CTA configured'),
            'publishable_only' => $schema->boolean()->description('Only show pages that pass all quality gates and are ready to publish'),
            'needs_work_only' => $schema->boolean()->description('Only show pages that need additional work to meet quality requirements'),
            'limit' => $schema->integer()->description('Maximum number of pages to return (default: 20, max: 100)'),
        ];
    }
}
