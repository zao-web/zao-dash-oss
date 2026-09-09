<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\SeoCTAService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ApplySeoCtaTool extends Tool
{
    protected string $name = 'apply-seo-cta';

    protected string $title = 'Apply SEO CTA';

    protected string $description = 'Apply a playbook-specific CTA to an SEO page. CTAs drive to the contact page with appropriate UTM parameters for attribution tracking.';

    public function __construct(
        private SeoCTAService $ctaService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'seo_page_id' => 'required|exists:seo_pages,id',
            'generate_html' => 'nullable|boolean',
        ]);

        $page = SeoPage::findOrFail($validated['seo_page_id']);
        $generateHtml = $validated['generate_html'] ?? false;

        // Apply CTA to the page
        $cta = $this->ctaService->applyToPage($page);

        $response = [
            'seo_page_id' => $page->id,
            'title' => $page->title,
            'playbook' => $page->playbook,
            'cta' => [
                'type' => $cta['type'],
                'headline' => $cta['headline'],
                'button_text' => $cta['button_text'],
                'url' => $cta['url'],
                'description' => $cta['description'],
            ],
            'message' => "CTA applied to '{$page->title}' with type '{$cta['type']}'",
        ];

        // Optionally include WordPress Blocks HTML
        if ($generateHtml) {
            $response['cta_html'] = $this->ctaService->generateCtaHtml($cta);
            $response['inline_cta_html'] = $this->ctaService->generateInlineCtaHtml($cta);
        }

        return Response::structured($response);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_page_id' => $schema->integer()->required()->description('ID of the SEO page to apply the CTA to'),
            'generate_html' => $schema->boolean()->description('Whether to include WordPress Blocks HTML in the response (default: false)'),
        ];
    }
}
