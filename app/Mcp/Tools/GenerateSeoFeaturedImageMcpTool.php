<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\SeoCTAService;
use App\Services\Seo\SeoFeaturedImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GenerateSeoFeaturedImageMcpTool extends Tool
{
    protected string $name = 'generate-seo-featured-image';

    protected string $title = 'Generate SEO Featured Image';

    protected string $description = 'Generate a featured image for an SEO page using Gemini AI with brand references, falling back to Unsplash. Also applies the appropriate CTA for the playbook.';

    public function __construct(
        private SeoFeaturedImageService $imageService,
        private SeoCTAService $ctaService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'seo_page_id' => 'required|exists:seo_pages,id',
            'apply_cta' => 'nullable|boolean',
        ]);

        $page = SeoPage::findOrFail($validated['seo_page_id']);
        $applyCta = $validated['apply_cta'] ?? true;

        // Generate featured image
        $imageResult = $this->imageService->generateForPage($page);

        // Apply CTA if requested
        $ctaResult = null;
        if ($applyCta) {
            $ctaResult = $this->ctaService->applyToPage($page);
        }

        $page->refresh();

        return Response::structured([
            'seo_page_id' => $page->id,
            'title' => $page->title,
            'playbook' => $page->playbook,
            'image' => [
                'success' => $imageResult['success'],
                'source' => $imageResult['source'],
                'media_id' => $imageResult['media_id'],
                'url' => $imageResult['url'],
                'error' => $imageResult['error'] ?? null,
            ],
            'cta' => $ctaResult ? [
                'applied' => true,
                'type' => $ctaResult['type'],
                'headline' => $ctaResult['headline'],
                'button_text' => $ctaResult['button_text'],
                'url' => $ctaResult['url'],
            ] : ['applied' => false],
            'message' => $imageResult['success']
                ? "Featured image generated from {$imageResult['source']} for '{$page->title}'"
                : "Failed to generate featured image: {$imageResult['error']}",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_page_id' => $schema->integer()->required()->description('ID of the SEO page to generate a featured image for'),
            'apply_cta' => $schema->boolean()->description('Whether to also apply the playbook-specific CTA (default: true)'),
        ];
    }
}
