<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\LeadAttributionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetSeoAttributionTool extends Tool
{
    protected string $name = 'get-seo-attribution';

    protected string $title = 'Get SEO Lead Attribution';

    protected string $description = 'Get lead attribution data and conversion metrics for an SEO page.';

    public function __construct(
        private LeadAttributionService $attribution,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page_id' => 'required|exists:seo_pages,id',
        ]);

        $page = SeoPage::findOrFail($validated['page_id']);
        $summary = $this->attribution->getAttributionSummary($page);

        return Response::structured([
            'page_id' => $page->id,
            'page_title' => $page->meta_title,
            'page_url' => $page->page_url,
            'playbook' => $page->playbook,
            'attribution' => [
                'total_leads' => $summary['total_leads'],
                'total_value' => $summary['total_value'],
                'converted_leads' => $summary['converted_leads'],
                'conversion_rate' => $summary['conversion_rate'],
                'avg_time_on_site' => $summary['avg_time_on_site']
                    ? round($summary['avg_time_on_site'] / 60, 1).' min'
                    : null,
                'avg_pages_viewed' => $summary['avg_pages_viewed']
                    ? round($summary['avg_pages_viewed'], 1)
                    : null,
                'top_sources' => $summary['top_sources'],
            ],
            'message' => $summary['total_leads'] > 0
                ? "Page has generated {$summary['total_leads']} leads with {$summary['conversion_rate']}% conversion rate"
                : 'No leads attributed to this page yet',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->required()->description('SEO page ID to get attribution data for'),
        ];
    }
}
