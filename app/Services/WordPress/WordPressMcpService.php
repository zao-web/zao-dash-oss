<?php

namespace App\Services\WordPress;

use App\Models\WordPressPost;
use App\Models\WordPressSite;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WordPressMcpService
{
    protected function client(WordPressSite $site): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $site->auth_header,
            'Content-Type' => 'application/json',
        ])->timeout(30);
    }

    /**
     * Prove REST auth works and persist rest_url. Does not create or publish a post.
     *
     * @return array{
     *     success: bool,
     *     dry_run: bool,
     *     published: bool,
     *     rest_url: string,
     *     site: string,
     *     site_url: string,
     *     authenticated_user: string,
     *     user_id: int|null,
     *     namespaces: array<int, string>,
     *     message: string
     * }
     */
    public function handshake(WordPressSite $site): array
    {
        $restUrl = rtrim($site->url, '/').'/wp-json';

        $index = $this->client($site)->get($restUrl.'/');

        if (! $index->successful()) {
            throw new \Exception('WordPress REST index failed: '.$index->body());
        }

        $me = $this->client($site)->get($restUrl.'/wp/v2/users/me');

        if (! $me->successful()) {
            throw new \Exception('WordPress authentication failed: '.$me->body());
        }

        $user = $me->json() ?? [];

        $site->forceFill([
            'rest_url' => $restUrl,
            'last_connected_at' => now(),
        ])->save();

        return [
            'success' => true,
            'dry_run' => true,
            'published' => false,
            'rest_url' => $restUrl,
            'site' => $site->name,
            'site_url' => $site->url,
            'authenticated_user' => $user['name'] ?? $user['slug'] ?? $site->username,
            'user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'namespaces' => $index->json('namespaces') ?? [],
            'message' => 'WordPress REST handshake succeeded. No post was created.',
        ];
    }

    public function testConnection(WordPressSite $site): bool
    {
        try {
            $this->handshake($site);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function defaultSite(): ?WordPressSite
    {
        return WordPressSite::query()->where('is_primary', true)->first()
            ?? WordPressSite::query()->first();
    }

    public function discoverCapabilities(WordPressSite $site): array
    {
        if (! $site->mcp_enabled) {
            return [];
        }

        try {
            $response = $this->client($site)->post($site->mcp_endpoint, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $tools = $data['result']['tools'] ?? [];

            $site->update([
                'capabilities' => $tools,
                'last_connected_at' => now(),
            ]);

            return $tools;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function invokeTool(WordPressSite $site, string $tool, array $params = []): array
    {
        $response = $this->client($site)->post($site->mcp_endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $params,
            ],
        ]);

        if (! $response->successful()) {
            throw new \Exception('MCP tool invocation failed: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('MCP error: '.($data['error']['message'] ?? 'Unknown error'));
        }

        return $data['result'] ?? [];
    }

    // Fallback to REST API if MCP not available
    public function getPosts(WordPressSite $site, array $params = []): array
    {
        $defaults = [
            'per_page' => 100,
            'status' => 'any',
        ];

        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/posts',
            array_merge($defaults, $params)
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get posts: '.$response->body());
        }

        return $response->json();
    }

    public function createPost(WordPressSite $site, array $data): array
    {
        // Try MCP first
        if ($site->mcp_enabled && in_array('create_post', array_column($site->capabilities ?? [], 'name'))) {
            return $this->invokeTool($site, 'create_post', $data);
        }

        // Fallback to REST API
        $response = $this->client($site)->post(
            rtrim($site->url, '/').'/wp-json/wp/v2/posts',
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to create post: '.$response->body());
        }

        return $response->json();
    }

    public function updatePost(WordPressSite $site, int $postId, array $data): array
    {
        $response = $this->client($site)->put(
            rtrim($site->url, '/')."/wp-json/wp/v2/posts/{$postId}",
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update post: '.$response->body());
        }

        return $response->json();
    }

    public function syncPosts(WordPressSite $site): int
    {
        $posts = $this->getPosts($site);
        $count = 0;

        foreach ($posts as $post) {
            $this->upsertPost($site, $post);
            $count++;
        }

        return $count;
    }

    protected function upsertPost(WordPressSite $site, array $post): WordPressPost
    {
        return WordPressPost::updateOrCreate(
            [
                'wordpress_site_id' => $site->id,
                'wp_post_id' => $post['id'],
            ],
            [
                'title' => $post['title']['rendered'] ?? '',
                'slug' => $post['slug'] ?? '',
                'status' => $post['status'] ?? 'draft',
                'type' => $post['type'] ?? 'post',
                'excerpt' => strip_tags($post['excerpt']['rendered'] ?? ''),
                'content_preview' => substr(strip_tags($post['content']['rendered'] ?? ''), 0, 500),
                'author_name' => null, // Would need separate API call
                'categories' => $post['categories'] ?? [],
                'tags' => $post['tags'] ?? [],
                'featured_image_url' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null,
                'published_at' => isset($post['date']) ? \Carbon\Carbon::parse($post['date']) : null,
                'modified_at' => isset($post['modified']) ? \Carbon\Carbon::parse($post['modified']) : null,
                'url' => $post['link'] ?? '',
                'synced_at' => now(),
            ]
        );
    }

    public function getCategories(WordPressSite $site): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/categories',
            ['per_page' => 100]
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get categories: '.$response->body());
        }

        return $response->json();
    }

    public function getTags(WordPressSite $site): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/tags',
            ['per_page' => 100]
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get tags: '.$response->body());
        }

        return $response->json();
    }

    // Page operations for Website Builder

    public function getPages(WordPressSite $site, array $params = []): array
    {
        $defaults = [
            'per_page' => 100,
            'status' => 'any',
        ];

        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/pages',
            array_merge($defaults, $params)
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get pages: '.$response->body());
        }

        return $response->json();
    }

    public function getPageBySlug(WordPressSite $site, string $slug): ?array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/pages',
            ['slug' => $slug, 'status' => 'any']
        );

        if (! $response->successful()) {
            return null;
        }

        $pages = $response->json();

        return $pages[0] ?? null;
    }

    public function createPage(WordPressSite $site, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/').'/wp-json/wp/v2/pages',
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to create page: '.$response->body());
        }

        return $response->json();
    }

    public function updatePage(WordPressSite $site, int $pageId, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/')."/wp-json/wp/v2/pages/{$pageId}",
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update page: '.$response->body());
        }

        return $response->json();
    }

    public function deletePage(WordPressSite $site, int $pageId, bool $force = false): bool
    {
        $response = $this->client($site)->delete(
            rtrim($site->url, '/')."/wp-json/wp/v2/pages/{$pageId}",
            ['force' => $force]
        );

        return $response->successful();
    }

    public function upsertPage(WordPressSite $site, string $slug, array $data): array
    {
        $existing = $this->getPageBySlug($site, $slug);

        if ($existing) {
            return $this->updatePage($site, $existing['id'], $data);
        }

        $data['slug'] = $slug;

        return $this->createPage($site, $data);
    }

    // Media operations

    public function uploadMedia(WordPressSite $site, string $filePath, string $filename, ?string $altText = null): array
    {
        if (! is_readable($filePath)) {
            throw new \Exception("Media file is not readable: {$filePath}");
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $safeFilename = $this->sanitizeContentDispositionFilename($filename);

        $response = $this->mediaClient($site)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
                'Content-Type' => $mimeType,
            ])
            ->withBody((string) file_get_contents($filePath), $mimeType)
            ->post(rtrim($site->url, '/').'/wp-json/wp/v2/media');

        if (! $response->successful()) {
            throw new \Exception('Failed to upload media: '.$response->body());
        }

        $media = $response->json();

        if ($altText && isset($media['id'])) {
            $this->client($site)->post(
                rtrim($site->url, '/')."/wp-json/wp/v2/media/{$media['id']}",
                ['alt_text' => $altText]
            );
        }

        return $media;
    }

    protected function sanitizeContentDispositionFilename(string $filename): string
    {
        $name = basename($filename);
        $name = str_replace(['"', "'", '\\'], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'upload.bin';
        }

        return $name;
    }

    protected function mediaClient(WordPressSite $site): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $site->auth_header,
        ])->timeout(60);
    }

    // Site settings (for homepage, etc.)

    public function getSiteSettings(WordPressSite $site): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/settings'
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get settings: '.$response->body());
        }

        return $response->json();
    }

    public function updateSiteSettings(WordPressSite $site, array $settings): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/').'/wp-json/wp/v2/settings',
            $settings
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update settings: '.$response->body());
        }

        return $response->json();
    }

    public function setHomepage(WordPressSite $site, int $pageId): array
    {
        return $this->updateSiteSettings($site, [
            'show_on_front' => 'page',
            'page_on_front' => $pageId,
        ]);
    }

    public function setBlogPage(WordPressSite $site, int $pageId): array
    {
        return $this->updateSiteSettings($site, [
            'page_for_posts' => $pageId,
        ]);
    }

    // Navigation/Menu operations (if site supports)

    public function getNavigationMenus(WordPressSite $site): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/navigation'
        );

        if (! $response->successful()) {
            // Navigation endpoint may not exist on older sites
            return [];
        }

        return $response->json();
    }

    public function createNavigationMenu(WordPressSite $site, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/').'/wp-json/wp/v2/navigation',
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to create navigation menu: '.$response->body());
        }

        return $response->json();
    }

    public function updateNavigationMenu(WordPressSite $site, int $menuId, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/')."/wp-json/wp/v2/navigation/{$menuId}",
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update navigation menu: '.$response->body());
        }

        return $response->json();
    }

    // Template Part operations (WordPress 5.9+)

    public function getTemplateParts(WordPressSite $site, array $params = []): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/template-parts',
            $params
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get template parts: '.$response->body());
        }

        return $response->json();
    }

    public function getTemplatePartBySlug(WordPressSite $site, string $slug, string $theme = 'ollie'): ?array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/')."/wp-json/wp/v2/template-parts/{$theme}//{$slug}",
            ['context' => 'edit']
        );

        if (! $response->successful()) {
            // Try with theme prefix in different format
            $response = $this->client($site)->get(
                rtrim($site->url, '/').'/wp-json/wp/v2/template-parts',
                ['slug' => $slug, 'context' => 'edit']
            );

            if (! $response->successful()) {
                return null;
            }

            $parts = $response->json();

            return $parts[0] ?? null;
        }

        return $response->json();
    }

    public function updateTemplatePart(WordPressSite $site, string $id, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/')."/wp-json/wp/v2/template-parts/{$id}",
            array_merge($data, ['context' => 'edit'])
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update template part: '.$response->body());
        }

        return $response->json();
    }

    public function createTemplatePart(WordPressSite $site, array $data): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/').'/wp-json/wp/v2/template-parts',
            $data
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to create template part: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Upsert a template part - update if exists (user-modified), create custom if not.
     *
     * Template parts in WordPress block themes work as follows:
     * - Theme provides default template parts (e.g., ollie//header, ollie//footer)
     * - User modifications create a "custom" template part that overrides the theme default
     * - The REST API ID format is "{theme}//{slug}" (e.g., "ollie//header")
     */
    public function upsertTemplatePart(WordPressSite $site, string $slug, string $content, string $area = 'uncategorized', string $theme = 'ollie'): array
    {
        $id = "{$theme}//{$slug}";

        // Check if a user-modified version exists
        $existing = $this->getTemplatePartBySlug($site, $slug, $theme);

        $data = [
            'content' => $content,
            'status' => 'publish',
        ];

        if ($existing && isset($existing['id'])) {
            // Update existing (user-modified or theme default)
            return $this->updateTemplatePart($site, $existing['id'], $data);
        }

        // Create new custom template part
        $data['slug'] = $slug;
        $data['theme'] = $theme;
        $data['area'] = $area;
        $data['title'] = ucwords(str_replace(['-', '_'], ' ', $slug));

        return $this->createTemplatePart($site, $data);
    }

    // Global Styles operations (WordPress 5.9+)

    public function getGlobalStyles(WordPressSite $site): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/').'/wp-json/wp/v2/global-styles'
        );

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }

    public function updateGlobalStyles(WordPressSite $site, int $styleId, array $styles): array
    {
        $response = $this->client($site)->post(
            rtrim($site->url, '/')."/wp-json/wp/v2/global-styles/{$styleId}",
            ['styles' => $styles]
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to update global styles: '.$response->body());
        }

        return $response->json();
    }
}
