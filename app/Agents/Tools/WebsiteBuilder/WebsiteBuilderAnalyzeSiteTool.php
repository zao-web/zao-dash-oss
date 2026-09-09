<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\Retryable;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebsiteBuilderAnalyzeSiteTool extends BaseTool
{
    use Retryable;

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Analyze Existing Site';
    }

    public function description(): string
    {
        return 'Analyze an existing website to detect its platform, crawl its structure, and inventory content for migration. Stores analysis in project site_analysis field.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the website to analyze (optional if project has domain)',
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['shallow', 'deep'],
                    'description' => 'Analysis depth - shallow for quick scan, deep for full crawl',
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'url' => 'nullable|url',
            'depth' => 'nullable|in:shallow,deep',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $url = $params['url'] ?? ($project->domain ? 'https://'.$project->domain : null);

        if (! $url) {
            return ['success' => false, 'error' => 'No URL provided and project has no domain'];
        }

        $url = rtrim($url, '/');
        $depth = $params['depth'] ?? 'shallow';

        $analysis = [
            'url' => $url,
            'platform' => $this->detectPlatform($url),
            'site_name' => null,
            'brand_colors' => [],
            'structure' => [],
            'sitemap' => [],
            'content_inventory' => [],
            'tech_stack' => [],
        ];

        try {
            $response = Http::timeout(30)->get($url);
            if ($response->successful()) {
                $html = $response->body();
                $analysis['structure'] = $this->analyzeStructure($html, $url);
                $analysis['tech_stack'] = $this->detectTechStack($html, $response->headers());
                $analysis['site_name'] = $this->extractSiteName($html, $url);
                $analysis['brand_colors'] = $this->extractBrandColors($html, $url);
            }
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not fetch homepage: '.$e->getMessage();
        }

        $analysis['sitemap'] = $this->importSitemap($url);

        if ($analysis['platform']['type'] === 'wordpress') {
            $analysis['wordpress'] = $this->analyzeWordPress($url);
        }

        if ($depth === 'deep' && ! empty($analysis['structure']['internal_links'])) {
            $analysis['pages'] = $this->crawlPages($analysis['structure']['internal_links'], $url);
        }

        $project->update([
            'site_analysis' => $analysis,
            'status' => 'analyzing',
        ]);

        return [
            'success' => true,
            'project_id' => $project->id,
            'analysis' => $analysis,
        ];
    }

    private function detectPlatform(string $url): array
    {
        $platform = ['type' => 'unknown', 'version' => null, 'confidence' => 0];

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return $platform;
            }

            $html = $response->body();
            $headers = $response->headers();

            if (str_contains($html, 'wp-content') || str_contains($html, 'wp-includes')) {
                $platform = ['type' => 'wordpress', 'confidence' => 90, 'version' => null];
                if (preg_match('/WordPress\s+([\d.]+)/i', $html, $matches)) {
                    $platform['version'] = $matches[1];
                }
            } elseif (str_contains($html, '/media/jui/') || str_contains($html, 'com_content')) {
                $platform = ['type' => 'joomla', 'confidence' => 85, 'version' => null];
            } elseif (str_contains($html, 'squarespace.com')) {
                $platform = ['type' => 'squarespace', 'confidence' => 95, 'version' => null];
            } elseif (str_contains($html, 'wix.com')) {
                $platform = ['type' => 'wix', 'confidence' => 95, 'version' => null];
            } else {
                $platform = ['type' => 'static', 'confidence' => 50, 'version' => null];
            }
        } catch (\Exception $e) {
            Log::warning('Platform detection failed', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return $platform;
    }

    private function analyzeStructure(string $html, string $baseUrl): array
    {
        $structure = [
            'title' => '',
            'meta_description' => '',
            'internal_links' => [],
            'external_links' => [],
            'images' => 0,
            'headings' => [],
        ];

        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $structure['title'] = trim($matches[1]);
        }

        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $matches)) {
            $structure['meta_description'] = trim($matches[1]);
        }

        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/', $html, $linkMatches);
        $parsedBase = parse_url($baseUrl);
        $baseHost = $parsedBase['host'] ?? '';

        foreach ($linkMatches[1] as $link) {
            if (str_starts_with($link, '#') || str_starts_with($link, 'javascript:') || str_starts_with($link, 'mailto:')) {
                continue;
            }

            if (str_starts_with($link, '/')) {
                $link = $parsedBase['scheme'].'://'.$baseHost.$link;
            }

            $parsedLink = parse_url($link);
            $linkHost = $parsedLink['host'] ?? '';

            if ($linkHost === $baseHost || empty($linkHost)) {
                $structure['internal_links'][] = $link;
            } else {
                $structure['external_links'][] = $link;
            }
        }

        $structure['internal_links'] = array_unique(array_slice($structure['internal_links'], 0, 50));
        $structure['external_links'] = array_unique(array_slice($structure['external_links'], 0, 20));

        preg_match_all('/<img[^>]+>/', $html, $imgMatches);
        $structure['images'] = count($imgMatches[0]);

        preg_match_all('/<h([1-6])[^>]*>([^<]+)<\/h\1>/i', $html, $headingMatches, PREG_SET_ORDER);
        foreach (array_slice($headingMatches, 0, 20) as $heading) {
            $structure['headings'][] = ['level' => (int) $heading[1], 'text' => trim(strip_tags($heading[2]))];
        }

        return $structure;
    }

    private function detectTechStack(string $html, array $headers): array
    {
        $stack = [];

        if (isset($headers['Server'])) {
            $stack['server'] = implode(', ', (array) $headers['Server']);
        }

        if (str_contains($html, 'react') || str_contains($html, 'React')) {
            $stack['js_framework'] = 'React';
        } elseif (str_contains($html, 'vue') || str_contains($html, 'Vue')) {
            $stack['js_framework'] = 'Vue';
        }

        if (str_contains($html, 'bootstrap')) {
            $stack['css_framework'] = 'Bootstrap';
        } elseif (str_contains($html, 'tailwind')) {
            $stack['css_framework'] = 'Tailwind';
        }

        return $stack;
    }

    private function extractSiteName(string $html, string $url): array
    {
        $candidates = [];

        if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']/', $html, $matches)) {
            $candidates[] = ['source' => 'og:site_name', 'value' => html_entity_decode(trim($matches[1])), 'confidence' => 95];
        }

        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]));
            if (preg_match('/[|\-–—]\s*([^|\-–—]+)$/', $title, $siteMatch)) {
                $siteName = trim($siteMatch[1]);
                if (strlen($siteName) > 2 && strlen($siteName) < 50) {
                    $candidates[] = ['source' => 'title_suffix', 'value' => $siteName, 'confidence' => 80];
                }
            }
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $hostName = ucfirst(preg_replace('/^www\./', '', explode('.', $host)[0]));
        $candidates[] = ['source' => 'hostname', 'value' => $hostName, 'confidence' => 40];

        usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return [
            'name' => $candidates[0]['value'] ?? null,
            'confidence' => $candidates[0]['confidence'] ?? 0,
            'source' => $candidates[0]['source'] ?? null,
        ];
    }

    private function extractBrandColors(string $html, string $url): array
    {
        $colors = [];

        if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $colors[] = $m[1];
        }

        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})\b/', $html, $hexMatches);
        $colors = array_merge($colors, $hexMatches[0]);

        $colors = array_unique($colors);

        $brandColors = array_filter($colors, function ($hex) {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (strlen($hex) !== 6) {
                return false;
            }

            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            if ($r > 240 && $g > 240 && $b > 240) {
                return false;
            }
            if ($r < 20 && $g < 20 && $b < 20) {
                return false;
            }

            return true;
        });

        return [
            'palette' => array_slice(array_values($brandColors), 0, 10),
            'suggested' => [
                'primary' => $brandColors[0] ?? null,
                'secondary' => $brandColors[1] ?? null,
            ],
        ];
    }

    private function importSitemap(string $url): array
    {
        $result = ['source' => 'sitemap.xml', 'pages' => [], 'found' => false];

        $sitemapUrls = [
            "{$url}/sitemap.xml",
            "{$url}/wp-sitemap.xml",
            "{$url}/sitemap_index.xml",
        ];

        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $response = Http::timeout(15)->get($sitemapUrl);
                if ($response->successful() && str_contains($response->body(), '<urlset')) {
                    $result['found'] = true;
                    $result['url'] = $sitemapUrl;
                    preg_match_all('/<loc>([^<]+)<\/loc>/', $response->body(), $matches);
                    $result['pages'] = array_slice($matches[1], 0, 100);

                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $result;
    }

    private function analyzeWordPress(string $url): array
    {
        $wp = ['rest_api' => false, 'posts' => [], 'pages' => []];

        try {
            $response = Http::timeout(15)->get("{$url}/wp-json/wp/v2/posts?per_page=5");
            if ($response->successful()) {
                $wp['rest_api'] = true;
                foreach ($response->json() as $post) {
                    $wp['posts'][] = ['id' => $post['id'], 'title' => $post['title']['rendered'] ?? '', 'slug' => $post['slug']];
                }
            }

            $response = Http::timeout(15)->get("{$url}/wp-json/wp/v2/pages?per_page=10");
            if ($response->successful()) {
                foreach ($response->json() as $page) {
                    $wp['pages'][] = ['id' => $page['id'], 'title' => $page['title']['rendered'] ?? '', 'slug' => $page['slug']];
                }
            }
        } catch (\Exception $e) {
            $wp['api_error'] = $e->getMessage();
        }

        return $wp;
    }

    private function crawlPages(array $urls, string $baseUrl): array
    {
        $pages = [];
        $limit = min(count($urls), 20);

        for ($i = 0; $i < $limit; $i++) {
            try {
                $response = Http::timeout(10)->get($urls[$i]);
                if ($response->successful()) {
                    $html = $response->body();
                    $title = '';
                    if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
                        $title = trim($matches[1]);
                    }

                    $pages[] = ['url' => $urls[$i], 'title' => $title, 'status' => $response->status()];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $pages;
    }
}
