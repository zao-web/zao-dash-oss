<?php

namespace App\Services\Unsplash;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Unsplash API service for fetching high-quality stock photos.
 *
 * Used as fallback when AI image generation fails or is unavailable.
 *
 * @see https://unsplash.com/documentation
 */
class UnsplashService
{
    protected string $baseUrl = 'https://api.unsplash.com';

    protected ?string $accessKey;

    public function __construct()
    {
        $this->accessKey = config('services.unsplash.access_key');
    }

    /**
     * Check if the service is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->accessKey);
    }

    /**
     * Search for photos on Unsplash.
     *
     * @param  string  $query  Search query
     * @param  array  $options  Search options (per_page, page, orientation, color, etc.)
     * @return array{results: array, total: int, total_pages: int}
     */
    public function search(string $query, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new \Exception('Unsplash API is not configured');
        }

        $params = array_merge([
            'query' => $query,
            'per_page' => $options['per_page'] ?? 10,
            'page' => $options['page'] ?? 1,
            'orientation' => $options['orientation'] ?? 'landscape',
        ], $options);

        // Remove non-API params
        unset($params['per_page_param']);

        $response = Http::withHeaders([
            'Authorization' => "Client-ID {$this->accessKey}",
            'Accept-Version' => 'v1',
        ])->timeout(30)->get("{$this->baseUrl}/search/photos", $params);

        if (! $response->successful()) {
            Log::error('Unsplash search error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);
            throw new \Exception("Unsplash API error: {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Get a single random photo matching the query.
     *
     * @param  string  $query  Search query
     * @param  array  $options  Options (orientation, content_filter, etc.)
     * @return array|null Photo data or null if not found
     */
    public function getRandom(string $query, array $options = []): ?array
    {
        if (! $this->isConfigured()) {
            throw new \Exception('Unsplash API is not configured');
        }

        $params = array_merge([
            'query' => $query,
            'orientation' => $options['orientation'] ?? 'landscape',
            'content_filter' => $options['content_filter'] ?? 'high',
        ], $options);

        $response = Http::withHeaders([
            'Authorization' => "Client-ID {$this->accessKey}",
            'Accept-Version' => 'v1',
        ])->timeout(30)->get("{$this->baseUrl}/photos/random", $params);

        if (! $response->successful()) {
            Log::warning('Unsplash random photo not found', [
                'query' => $query,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Download a photo and track it (required by Unsplash API guidelines).
     *
     * @param  string  $downloadUrl  The download_location URL from photo data
     * @return string|null The image binary data
     */
    public function downloadPhoto(string $downloadUrl): ?string
    {
        if (! $this->isConfigured()) {
            throw new \Exception('Unsplash API is not configured');
        }

        // Track the download (required by Unsplash API guidelines)
        Http::withHeaders([
            'Authorization' => "Client-ID {$this->accessKey}",
        ])->get($downloadUrl);

        // Extract the actual image URL from download tracking response or use provided URL
        // The download_location returns the actual URL we should use
        $response = Http::timeout(60)->get($downloadUrl);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $actualUrl = $data['url'] ?? $downloadUrl;

        // Download the actual image
        $imageResponse = Http::timeout(60)->get($actualUrl);

        if (! $imageResponse->successful()) {
            return null;
        }

        return $imageResponse->body();
    }

    /**
     * Get the best photo for SEO content based on playbook and topic.
     *
     * @param  string  $topic  The content topic
     * @param  string  $playbook  The SEO playbook type
     * @return array|null Photo data with download URL
     */
    public function getPhotoForContent(string $topic, string $playbook): ?array
    {
        $searchQuery = $this->buildSearchQuery($topic, $playbook);

        // Try random first for variety
        $photo = $this->getRandom($searchQuery);

        if (! $photo) {
            // Fall back to search
            $results = $this->search($searchQuery, ['per_page' => 1]);
            $photo = $results['results'][0] ?? null;
        }

        if (! $photo) {
            // Try a more generic search
            $genericQuery = $this->getGenericQuery($playbook);
            $photo = $this->getRandom($genericQuery);
        }

        return $photo;
    }

    /**
     * Download and save a photo to storage.
     *
     * @param  array  $photo  Photo data from Unsplash API
     * @param  string  $path  Storage path (without extension)
     * @param  string  $disk  Storage disk
     * @return array{path: string, url: string, attribution: array}
     */
    public function downloadAndSave(array $photo, string $path, string $disk = 'public'): array
    {
        // Get the regular size URL (good quality, reasonable file size)
        $imageUrl = $photo['urls']['regular'] ?? $photo['urls']['full'];

        // Track download (required by Unsplash guidelines)
        if (isset($photo['links']['download_location'])) {
            Http::withHeaders([
                'Authorization' => "Client-ID {$this->accessKey}",
            ])->get($photo['links']['download_location']);
        }

        // Download the image
        $response = Http::timeout(60)->get($imageUrl);

        if (! $response->successful()) {
            throw new \Exception('Failed to download Unsplash photo');
        }

        $extension = 'jpg'; // Unsplash images are typically JPEG
        $fullPath = "{$path}.{$extension}";

        Storage::disk($disk)->put($fullPath, $response->body());

        return [
            'path' => $fullPath,
            'url' => Storage::disk($disk)->url($fullPath),
            'attribution' => [
                'photographer' => $photo['user']['name'] ?? 'Unknown',
                'photographer_url' => $photo['user']['links']['html'] ?? null,
                'unsplash_url' => $photo['links']['html'] ?? null,
                'unsplash_id' => $photo['id'],
            ],
        ];
    }

    /**
     * Build a search query based on topic and playbook.
     */
    protected function buildSearchQuery(string $topic, string $playbook): string
    {
        // Clean up the topic
        $topic = strtolower($topic);
        $topic = preg_replace('/\b(laravel|wordpress|development|agency)\b/i', '', $topic);
        $topic = trim($topic);

        $playbookModifiers = [
            'location' => 'city skyline office building',
            'comparison' => 'technology comparison choice decision',
            'case-study' => 'success business achievement',
            'persona' => 'professional business person',
            'template' => 'document template design',
            'integration' => 'technology connection network',
            'calculator' => 'calculator numbers business',
            'glossary' => 'education learning book',
            'curation' => 'collection curated selection',
        ];

        $modifier = $playbookModifiers[$playbook] ?? 'technology business professional';

        return trim("{$topic} {$modifier}");
    }

    /**
     * Get a generic fallback query based on playbook.
     */
    protected function getGenericQuery(string $playbook): string
    {
        $queries = [
            'location' => 'modern office cityscape',
            'comparison' => 'technology abstract',
            'case-study' => 'business success team',
            'persona' => 'professional workplace',
            'template' => 'minimal design workspace',
            'integration' => 'technology network abstract',
            'calculator' => 'business planning strategy',
            'glossary' => 'education learning knowledge',
            'curation' => 'minimal modern design',
        ];

        return $queries[$playbook] ?? 'technology business modern';
    }

    /**
     * Format attribution text for Unsplash photo (required by their guidelines).
     *
     * Per Unsplash API Guidelines: Photos must credit Unsplash, the photographer,
     * and link to their profile with UTM parameters.
     */
    public function formatAttribution(array $attribution): string
    {
        $photographer = $attribution['photographer'] ?? 'Unknown';
        $photographerUrl = $this->addUtmParams($attribution['photographer_url'] ?? 'https://unsplash.com');
        $unsplashUrl = $this->addUtmParams('https://unsplash.com');

        return "Photo by [{$photographer}]({$photographerUrl}) on [Unsplash]({$unsplashUrl})";
    }

    /**
     * Format attribution as HTML for WordPress blocks.
     */
    public function formatAttributionHtml(array $attribution): string
    {
        $photographer = htmlspecialchars($attribution['photographer'] ?? 'Unknown');
        $photographerUrl = $this->addUtmParams($attribution['photographer_url'] ?? 'https://unsplash.com');
        $unsplashUrl = $this->addUtmParams('https://unsplash.com');

        return "Photo by <a href=\"{$photographerUrl}\" target=\"_blank\" rel=\"noopener\">{$photographer}</a> on <a href=\"{$unsplashUrl}\" target=\"_blank\" rel=\"noopener\">Unsplash</a>";
    }

    /**
     * Add required UTM parameters to Unsplash URLs.
     *
     * Per Unsplash API Guidelines: Links should include utm parameters.
     */
    protected function addUtmParams(string $url): string
    {
        if (empty($url)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $appName = config('app.name', 'zao-dash');

        return "{$url}{$separator}utm_source={$appName}&utm_medium=referral";
    }

    /**
     * Get the hotlinked URL for a photo at the specified size.
     *
     * Per Unsplash API Guidelines: All uses must use the hotlinked image URLs
     * returned by the API under the photo.urls properties.
     *
     * @param  array  $photo  Photo data from API
     * @param  string  $size  Size: 'raw', 'full', 'regular', 'small', 'thumb'
     */
    public function getHotlinkedUrl(array $photo, string $size = 'regular'): string
    {
        return $photo['urls'][$size] ?? $photo['urls']['regular'] ?? $photo['urls']['full'];
    }

    /**
     * Track a download (required when user selects image for use).
     *
     * Per Unsplash API Guidelines: When users select images for use,
     * applications must trigger the download endpoint.
     */
    public function trackDownload(array $photo): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $downloadLocation = $photo['links']['download_location'] ?? null;

        if ($downloadLocation) {
            Http::withHeaders([
                'Authorization' => "Client-ID {$this->accessKey}",
            ])->get($downloadLocation);

            Log::info('Unsplash download tracked', [
                'photo_id' => $photo['id'],
            ]);
        }
    }
}
