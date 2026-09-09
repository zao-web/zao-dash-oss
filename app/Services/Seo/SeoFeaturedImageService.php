<?php

namespace App\Services\Seo;

use App\Models\SeoBrandReferenceImage;
use App\Models\SeoPage;
use App\Models\WordPressSite;
use App\Services\AI\GeminiImageService;
use App\Services\Unsplash\UnsplashService;
use App\Services\WordPress\WordPressService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates featured image generation for SEO pages.
 *
 * Flow:
 * 1. Build prompt based on playbook type
 * 2. Get brand reference images for category
 * 3. Try Gemini with references
 * 4. Fall back to Unsplash on failure
 * 5. Upload to WordPress
 * 6. Update SeoPage record
 */
class SeoFeaturedImageService
{
    public function __construct(
        protected GeminiImageService $geminiService,
        protected UnsplashService $unsplashService,
        protected WordPressService $wordPressService
    ) {}

    /**
     * Generate and set featured image for an SEO page.
     *
     * @return array{success: bool, source: string, media_id: int|null, url: string|null, error: string|null}
     */
    public function generateForPage(SeoPage $page): array
    {
        $site = $this->getPrimarySite();

        if (! $site) {
            return [
                'success' => false,
                'source' => 'none',
                'media_id' => null,
                'url' => null,
                'error' => 'No primary WordPress site configured',
            ];
        }

        // Build the prompt based on playbook
        $prompt = $this->buildPrompt($page);
        $category = $this->getCategory($page->playbook);

        // Get brand reference images
        $referenceImages = $this->getReferenceImages($category);

        // Try Gemini first
        $result = $this->tryGemini($page, $prompt, $referenceImages, $site);

        if ($result['success']) {
            return $result;
        }

        // Fall back to Unsplash
        Log::info('Gemini image generation failed, falling back to Unsplash', [
            'seo_page_id' => $page->id,
            'error' => $result['error'],
        ]);

        return $this->tryUnsplash($page, $site);
    }

    /**
     * Try generating image with Gemini.
     */
    protected function tryGemini(SeoPage $page, string $prompt, array $referenceImages, WordPressSite $site): array
    {
        if (! $this->geminiService->isConfigured()) {
            return [
                'success' => false,
                'source' => 'gemini',
                'media_id' => null,
                'url' => null,
                'error' => 'Gemini API not configured',
            ];
        }

        try {
            $storagePath = "seo/featured-images/{$page->id}-".Str::random(8);

            if (! empty($referenceImages)) {
                $result = $this->geminiService->generateWithReferencesAndSave(
                    prompt: $prompt,
                    referenceImages: $referenceImages,
                    path: $storagePath,
                    disk: 'public',
                    options: ['aspectRatio' => '16:9']
                );
            } else {
                $result = $this->geminiService->generateAndSave(
                    prompt: $prompt,
                    path: $storagePath,
                    disk: 'public',
                    model: 'gemini-pro',
                    options: ['aspectRatio' => '16:9']
                );
            }

            // Upload to WordPress
            $localPath = Storage::disk('public')->path($result['path']);
            $filename = basename($result['path']);
            $altText = $this->buildAltText($page);

            $wpMedia = $this->wordPressService->uploadMedia($site, $localPath, $filename, $altText);

            // Set as featured image on the post/page
            if ($page->wordpress_post_id) {
                $this->setFeaturedImage($site, $page->wordpress_post_id, $wpMedia['id']);
            }

            // Update SeoPage record
            $page->update([
                'featured_image_wordpress_id' => $wpMedia['id'],
                'featured_image_url' => $wpMedia['source_url'] ?? $result['url'],
                'featured_image_source' => 'gemini',
                'featured_image_prompt' => $prompt,
                'featured_image_alt' => $altText,
            ]);

            // Clean up local file
            Storage::disk('public')->delete($result['path']);

            return [
                'success' => true,
                'source' => 'gemini',
                'media_id' => $wpMedia['id'],
                'url' => $wpMedia['source_url'] ?? $result['url'],
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Gemini image generation failed', [
                'seo_page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'source' => 'gemini',
                'media_id' => null,
                'url' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Try fetching image from Unsplash.
     */
    protected function tryUnsplash(SeoPage $page, WordPressSite $site): array
    {
        if (! $this->unsplashService->isConfigured()) {
            return [
                'success' => false,
                'source' => 'unsplash',
                'media_id' => null,
                'url' => null,
                'error' => 'Unsplash API not configured',
            ];
        }

        try {
            $topic = $page->keyword ?? $page->title ?? '';
            $playbook = $page->playbook ?? 'general';

            $photo = $this->unsplashService->getPhotoForContent($topic, $playbook);

            if (! $photo) {
                return [
                    'success' => false,
                    'source' => 'unsplash',
                    'media_id' => null,
                    'url' => null,
                    'error' => 'No suitable photo found on Unsplash',
                ];
            }

            // Download and save
            $storagePath = "seo/featured-images/{$page->id}-".Str::random(8);
            $result = $this->unsplashService->downloadAndSave($photo, $storagePath, 'public');

            // Upload to WordPress
            $localPath = Storage::disk('public')->path($result['path']);
            $filename = basename($result['path']);

            // Build alt text with Unsplash attribution
            $altText = $this->buildAltText($page);
            $attribution = $this->unsplashService->formatAttributionHtml($result['attribution']);

            $wpMedia = $this->wordPressService->uploadMedia($site, $localPath, $filename, $altText);

            // Set as featured image on the post/page
            if ($page->wordpress_post_id) {
                $this->setFeaturedImage($site, $page->wordpress_post_id, $wpMedia['id']);
            }

            // Update SeoPage record (including attribution in prompt field for reference)
            $page->update([
                'featured_image_wordpress_id' => $wpMedia['id'],
                'featured_image_url' => $wpMedia['source_url'] ?? $result['url'],
                'featured_image_source' => 'unsplash',
                'featured_image_prompt' => "Unsplash ID: {$result['attribution']['unsplash_id']} | {$attribution}",
                'featured_image_alt' => $altText,
            ]);

            // Clean up local file
            Storage::disk('public')->delete($result['path']);

            return [
                'success' => true,
                'source' => 'unsplash',
                'media_id' => $wpMedia['id'],
                'url' => $wpMedia['source_url'] ?? $result['url'],
                'error' => null,
                'attribution' => $result['attribution'],
            ];
        } catch (\Exception $e) {
            Log::error('Unsplash image fetch failed', [
                'seo_page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'source' => 'unsplash',
                'media_id' => null,
                'url' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build an image generation prompt based on playbook type.
     */
    protected function buildPrompt(SeoPage $page): string
    {
        $title = $page->title ?? $page->keyword ?? 'WordPress Development';
        $playbook = strtolower($page->playbook ?? 'general');

        $basePrompt = 'Create a professional, modern featured image for a web development agency website. ';
        $basePrompt .= 'Style: Clean, minimal, professional. Colors: Blues and teals with white accents. ';
        $basePrompt .= 'No text or logos in the image. High quality, 16:9 aspect ratio. ';

        $playbookPrompts = [
            'location' => "The image should represent a thriving tech hub or modern city skyline with professional office buildings. Convey success and local business presence. Topic: {$title}",
            'comparison' => "The image should represent technology choices, decision-making, or a balanced comparison between options. Abstract representation of weighing choices. Topic: {$title}",
            'case-study' => "The image should represent business success, achievement, and results. Show growth, charts trending upward, or a team celebrating success. Topic: {$title}",
            'persona' => "The image should represent a specific industry or professional persona. Show relevant industry imagery - healthcare for medical, finance for banking, etc. Topic: {$title}",
            'template' => "The image should represent templates, documents, or reusable tools. Show clean document layouts, design systems, or organized workspaces. Topic: {$title}",
            'integration' => "The image should represent technology connections, APIs, and system integration. Show connected nodes, flowing data, or puzzle pieces coming together. Topic: {$title}",
            'calculator' => "The image should represent calculation, planning, and estimation. Show modern digital interfaces, dashboards, or analytical tools. Topic: {$title}",
            'glossary' => "The image should represent learning, education, and knowledge. Show books, educational resources, or enlightenment metaphors. Topic: {$title}",
            'curation' => "The image should represent careful selection and best-of-class choices. Show curated collections or premium selections. Topic: {$title}",
        ];

        $specificPrompt = $playbookPrompts[$playbook] ?? "The image should represent web development, technology, and professional services. Topic: {$title}";

        return $basePrompt.$specificPrompt;
    }

    /**
     * Build alt text for the featured image.
     */
    protected function buildAltText(SeoPage $page): string
    {
        $title = $page->title ?? $page->keyword ?? 'Featured Image';
        $title = strip_tags($title);

        // Keep it concise and descriptive
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97).'...';
        }

        return "Featured image for {$title}";
    }

    /**
     * Get the category for brand reference images based on playbook.
     */
    protected function getCategory(string $playbook): string
    {
        $categoryMap = [
            'location' => 'location',
            'comparison' => 'comparison',
            'case-study' => 'case-study',
            'persona' => 'persona',
            'template' => 'template',
            'integration' => 'general',
            'calculator' => 'general',
            'glossary' => 'general',
            'curation' => 'general',
        ];

        return $categoryMap[strtolower($playbook)] ?? 'general';
    }

    /**
     * Get brand reference images formatted for Gemini API.
     *
     * @return array<array{base64: string, mimeType: string}>
     */
    protected function getReferenceImages(string $category): array
    {
        $images = SeoBrandReferenceImage::getForCategory($category);

        if ($images->isEmpty()) {
            // Fall back to general category
            $images = SeoBrandReferenceImage::getForCategory('general');
        }

        $references = [];

        foreach ($images->take(3) as $image) {
            $base64 = $image->getBase64Data();

            if ($base64) {
                $references[] = [
                    'base64' => $base64,
                    'mimeType' => $image->getMimeType(),
                ];
            }
        }

        return $references;
    }

    /**
     * Get the primary WordPress site.
     */
    protected function getPrimarySite(): ?WordPressSite
    {
        return WordPressSite::where('is_primary', true)->first()
            ?? WordPressSite::first();
    }

    /**
     * Set the featured image on a WordPress post/page.
     */
    protected function setFeaturedImage(WordPressSite $site, int $postId, int $mediaId): void
    {
        try {
            $this->wordPressService->updatePage($site, $postId, [
                'featured_media' => $mediaId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to set featured image on post', [
                'post_id' => $postId,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
