<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\WordPressPost;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $siteId = null,
        public bool $syncPosts = true,
        public bool $syncPages = true,
        public bool $syncCategories = true,
        public bool $syncTags = true
    ) {}

    public function handle(WordPressService $wpService): void
    {
        $sites = $this->siteId
            ? WordPressSite::where('id', $this->siteId)->get()
            : WordPressSite::all();

        foreach ($sites as $site) {
            try {
                $this->syncSite($site, $wpService);
            } catch (\Exception $e) {
                Log::error('WordPress sync failed', [
                    'site_id' => $site->id,
                    'url' => $site->url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncSite(WordPressSite $site, WordPressService $wpService): void
    {
        $this->initSyncTracking($site);

        try {
            Log::info('Syncing WordPress site', ['url' => $site->url]);

            if ($this->syncCategories) {
                $this->syncCategoriesData($site, $wpService);
                $this->updateSyncProgress(15, 'categories');
            }

            if ($this->syncTags) {
                $this->syncTagsData($site, $wpService);
                $this->updateSyncProgress(30, 'tags');
            }

            if ($this->syncPosts) {
                $this->syncPostsData($site, $wpService, 'post');
                $this->updateSyncProgress(60, 'posts');
            }

            if ($this->syncPages) {
                $this->syncPostsData($site, $wpService, 'page');
                $this->updateSyncProgress(90, 'pages');
            }

            $site->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncPostsData(WordPressSite $site, WordPressService $wpService, string $postType): void
    {
        Log::info("Syncing WordPress {$postType}s", ['site' => $site->url]);

        $posts = $wpService->getPosts($site, [
            'type' => $postType,
            'per_page' => 100,
            'status' => 'any',
        ]);

        foreach ($posts as $postData) {
            WordPressPost::updateOrCreate(
                [
                    'wordpress_site_id' => $site->id,
                    'wp_post_id' => $postData['id'],
                ],
                [
                    'title' => $this->sanitizeUtf8($postData['title']['rendered'] ?? ''),
                    'slug' => $postData['slug'] ?? '',
                    'status' => $postData['status'] ?? 'draft',
                    'type' => $postData['type'] ?? $postType,
                    'excerpt' => $this->sanitizeUtf8(strip_tags($postData['excerpt']['rendered'] ?? '')),
                    'content_preview' => $this->sanitizeUtf8(strip_tags($postData['content']['rendered'] ?? ''), 500),
                    'author_name' => $this->resolveAuthorName($postData),
                    'categories' => $postData['categories'] ?? [],
                    'tags' => $postData['tags'] ?? [],
                    'featured_image_url' => $this->resolveFeaturedImage($site, $postData, $wpService),
                    'url' => $postData['link'] ?? '',
                    'published_at' => isset($postData['date'])
                        ? Carbon::parse($postData['date'])
                        : null,
                    'modified_at' => isset($postData['modified'])
                        ? Carbon::parse($postData['modified'])
                        : null,
                    'synced_at' => now(),
                ]
            );
        }
    }

    protected function syncCategoriesData(WordPressSite $site, WordPressService $wpService): void
    {
        Log::info('Syncing WordPress categories', ['site' => $site->url]);

        $categories = $wpService->getCategories($site);

        $site->update([
            'categories' => collect($categories)->map(fn ($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'slug' => $c['slug'],
                'description' => $c['description'] ?? '',
                'parent' => $c['parent'] ?? 0,
                'count' => $c['count'] ?? 0,
            ])->toArray(),
        ]);
    }

    protected function syncTagsData(WordPressSite $site, WordPressService $wpService): void
    {
        Log::info('Syncing WordPress tags', ['site' => $site->url]);

        $tags = $wpService->getTags($site);

        $site->update([
            'tags' => collect($tags)->map(fn ($t) => [
                'id' => $t['id'],
                'name' => $t['name'],
                'slug' => $t['slug'],
                'description' => $t['description'] ?? '',
                'count' => $t['count'] ?? 0,
            ])->toArray(),
        ]);
    }

    protected function resolveAuthorName(array $postData): ?string
    {
        if (isset($postData['_embedded']['author'][0]['name'])) {
            return $postData['_embedded']['author'][0]['name'];
        }

        return null;
    }

    protected function resolveFeaturedImage(WordPressSite $site, array $postData, WordPressService $wpService): ?string
    {
        if (isset($postData['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            return $postData['_embedded']['wp:featuredmedia'][0]['source_url'];
        }

        if (! empty($postData['featured_media'])) {
            try {
                $media = $wpService->getMedia($site, $postData['featured_media']);

                return $media['source_url'] ?? null;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Sanitize string for UTF-8 compatibility with PostgreSQL.
     * Decodes HTML entities, removes invalid UTF-8, and optionally truncates safely.
     */
    protected function sanitizeUtf8(string $text, ?int $maxLength = null): string
    {
        // Decode HTML entities (&#8230; -> …)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove null bytes and other problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        // Truncate safely using multibyte-aware function
        if ($maxLength !== null) {
            $text = mb_substr($text, 0, $maxLength, 'UTF-8');
        }

        return trim($text);
    }
}
