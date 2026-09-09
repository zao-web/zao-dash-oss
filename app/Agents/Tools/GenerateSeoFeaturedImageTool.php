<?php

namespace App\Agents\Tools;

use App\Models\SeoPage;
use App\Services\Seo\SeoCTAService;
use App\Services\Seo\SeoFeaturedImageService;

class GenerateSeoFeaturedImageTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'generate-seo-featured-image';
    }

    public function name(): string
    {
        return 'Generate SEO Featured Image';
    }

    public function description(): string
    {
        return 'Generate a featured image for an SEO page using AI (Gemini) with brand references, falling back to Unsplash. Also applies the appropriate CTA based on playbook type. Returns the WordPress media ID and URL.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'seo_page_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the SeoPage record to generate a featured image for',
                ],
            ],
            'required' => ['seo_page_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'seo_page_id' => 'required|integer|exists:seo_pages,id',
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $page = SeoPage::findOrFail($params['seo_page_id']);

        // Check if page already has a featured image
        if ($page->hasFeaturedImage()) {
            return [
                'success' => true,
                'message' => 'Page already has a featured image',
                'source' => $page->featured_image_source,
                'media_id' => $page->featured_image_wordpress_id,
                'url' => $page->featured_image_url,
                'skipped' => true,
            ];
        }

        // Generate featured image
        $imageService = app(SeoFeaturedImageService::class);
        $result = $imageService->generateForPage($page);

        // Apply CTA regardless of image result
        $ctaService = app(SeoCTAService::class);
        $cta = $ctaService->applyToPage($page);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'source' => $result['source'],
                'cta_applied' => true,
                'cta_type' => $cta['type'],
            ];
        }

        return [
            'success' => true,
            'source' => $result['source'],
            'media_id' => $result['media_id'],
            'url' => $result['url'],
            'cta_applied' => true,
            'cta_type' => $cta['type'],
            'cta_url' => $cta['url'],
        ];
    }
}
