<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Analyze an existing website for migration.
 *
 * Detects platform, crawls structure, and inventories content.
 */
class OllieAnalyzeSiteTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Analyze Existing Site';
    }

    public function description(): string
    {
        return 'Analyze an existing website to detect its platform (WordPress, Joomla, static), crawl its structure, and inventory content for migration to Ollie.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the website to analyze',
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['shallow', 'deep'],
                    'description' => 'Analysis depth - shallow for quick scan, deep for full crawl',
                ],
            ],
            'required' => ['url'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'url' => 'required|url',
            'depth' => 'nullable|in:shallow,deep',
        ];
    }

    public function execute(array $params): array
    {
        $url = rtrim($params['url'], '/');
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

        // Get homepage content
        $html = null;
        try {
            $response = Http::timeout(30)->get($url);
            if ($response->successful()) {
                $html = $response->body();
                $analysis['structure'] = $this->analyzeStructure($html, $url);
                $analysis['tech_stack'] = $this->detectTechStack($html, $response->headers());

                // Extract site name intelligently
                $analysis['site_name'] = $this->extractSiteName($html, $url);

                // Extract brand colors
                $analysis['brand_colors'] = $this->extractBrandColors($html, $url);
            }
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not fetch homepage: '.$e->getMessage();
        }

        // Try to import sitemap first, fall back to nav parsing
        $analysis['sitemap'] = $this->importSitemap($url);
        if (empty($analysis['sitemap']['pages']) && $html) {
            $analysis['sitemap'] = $this->parseNavigation($html, $url);
            $analysis['sitemap']['source'] = 'navigation';
        }

        // For WordPress sites, try REST API
        if ($analysis['platform']['type'] === 'wordpress') {
            $analysis['wordpress'] = $this->analyzeWordPress($url);
        }

        // For Joomla sites
        if ($analysis['platform']['type'] === 'joomla') {
            $analysis['joomla'] = $this->analyzeJoomla($url);
        }

        // Deep crawl if requested
        if ($depth === 'deep' && ! empty($analysis['structure']['internal_links'])) {
            $analysis['pages'] = $this->crawlPages($analysis['structure']['internal_links'], $url);
        }

        return $analysis;
    }

    private function detectPlatform(string $url): array
    {
        $platform = [
            'type' => 'unknown',
            'version' => null,
            'confidence' => 0,
        ];

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return $platform;
            }

            $html = $response->body();
            $headers = $response->headers();

            // WordPress detection
            if (
                str_contains($html, 'wp-content') ||
                str_contains($html, 'wp-includes') ||
                isset($headers['X-Powered-By']) && str_contains(implode('', $headers['X-Powered-By']), 'WordPress')
            ) {
                $platform['type'] = 'wordpress';
                $platform['confidence'] = 90;

                // Try to get version from generator meta
                if (preg_match('/WordPress\s+([\d.]+)/i', $html, $matches)) {
                    $platform['version'] = $matches[1];
                }
            }
            // Joomla detection
            elseif (
                str_contains($html, '/media/jui/') ||
                str_contains($html, '/templates/') && str_contains($html, 'Joomla') ||
                str_contains($html, 'com_content')
            ) {
                $platform['type'] = 'joomla';
                $platform['confidence'] = 85;

                if (preg_match('/Joomla!\s*([\d.]+)/i', $html, $matches)) {
                    $platform['version'] = $matches[1];
                }
            }
            // Squarespace
            elseif (str_contains($html, 'squarespace.com') || str_contains($html, 'static.squarespace')) {
                $platform['type'] = 'squarespace';
                $platform['confidence'] = 95;
            }
            // Wix
            elseif (str_contains($html, 'wix.com') || str_contains($html, 'wixsite.com')) {
                $platform['type'] = 'wix';
                $platform['confidence'] = 95;
            }
            // Static HTML
            else {
                $platform['type'] = 'static';
                $platform['confidence'] = 50;
            }
        } catch (\Exception $e) {
            $platform['error'] = $e->getMessage();
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

        // Extract title
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $structure['title'] = trim($matches[1]);
        }

        // Extract meta description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $matches)) {
            $structure['meta_description'] = trim($matches[1]);
        }

        // Extract links
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/', $html, $linkMatches);
        $parsedBase = parse_url($baseUrl);
        $baseHost = $parsedBase['host'] ?? '';

        foreach ($linkMatches[1] as $link) {
            if (str_starts_with($link, '#') || str_starts_with($link, 'javascript:') || str_starts_with($link, 'mailto:')) {
                continue;
            }

            // Normalize relative URLs
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

        // Count images
        preg_match_all('/<img[^>]+>/', $html, $imgMatches);
        $structure['images'] = count($imgMatches[0]);

        // Extract headings
        preg_match_all('/<h([1-6])[^>]*>([^<]+)<\/h\1>/i', $html, $headingMatches, PREG_SET_ORDER);
        foreach (array_slice($headingMatches, 0, 20) as $heading) {
            $structure['headings'][] = [
                'level' => (int) $heading[1],
                'text' => trim(strip_tags($heading[2])),
            ];
        }

        return $structure;
    }

    private function detectTechStack(string $html, array $headers): array
    {
        $stack = [];

        // Check headers
        if (isset($headers['Server'])) {
            $stack['server'] = implode(', ', (array) $headers['Server']);
        }
        if (isset($headers['X-Powered-By'])) {
            $stack['powered_by'] = implode(', ', (array) $headers['X-Powered-By']);
        }

        // Detect JS frameworks
        if (str_contains($html, 'react') || str_contains($html, 'React')) {
            $stack['js_framework'] = 'React';
        } elseif (str_contains($html, 'vue') || str_contains($html, 'Vue')) {
            $stack['js_framework'] = 'Vue';
        }

        // Detect CSS frameworks
        if (str_contains($html, 'bootstrap')) {
            $stack['css_framework'] = 'Bootstrap';
        } elseif (str_contains($html, 'tailwind')) {
            $stack['css_framework'] = 'Tailwind';
        }

        return $stack;
    }

    private function analyzeWordPress(string $url): array
    {
        $wp = [
            'rest_api' => false,
            'posts' => [],
            'pages' => [],
            'theme' => null,
        ];

        // Try REST API
        try {
            $response = Http::timeout(15)->get("{$url}/wp-json/wp/v2/posts?per_page=5");
            if ($response->successful()) {
                $wp['rest_api'] = true;
                $posts = $response->json();
                foreach ($posts as $post) {
                    $wp['posts'][] = [
                        'id' => $post['id'],
                        'title' => $post['title']['rendered'] ?? '',
                        'slug' => $post['slug'],
                    ];
                }
            }

            // Get pages
            $response = Http::timeout(15)->get("{$url}/wp-json/wp/v2/pages?per_page=10");
            if ($response->successful()) {
                $pages = $response->json();
                foreach ($pages as $page) {
                    $wp['pages'][] = [
                        'id' => $page['id'],
                        'title' => $page['title']['rendered'] ?? '',
                        'slug' => $page['slug'],
                    ];
                }
            }

            // Try to get theme info
            $response = Http::timeout(15)->get("{$url}/wp-json/");
            if ($response->successful()) {
                $info = $response->json();
                $wp['name'] = $info['name'] ?? null;
                $wp['description'] = $info['description'] ?? null;
            }
        } catch (\Exception $e) {
            $wp['api_error'] = $e->getMessage();
        }

        return $wp;
    }

    private function analyzeJoomla(string $url): array
    {
        // Basic Joomla detection - would need database access for full analysis
        return [
            'detected' => true,
            'note' => 'Full Joomla analysis requires database access or codebase checkout',
        ];
    }

    private function crawlPages(array $urls, string $baseUrl): array
    {
        $pages = [];
        $limit = min(count($urls), 20); // Limit to 20 pages

        for ($i = 0; $i < $limit; $i++) {
            try {
                $response = Http::timeout(10)->get($urls[$i]);
                if ($response->successful()) {
                    $html = $response->body();
                    $title = '';
                    if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
                        $title = trim($matches[1]);
                    }

                    $pages[] = [
                        'url' => $urls[$i],
                        'title' => $title,
                        'status' => $response->status(),
                    ];
                }
            } catch (\Exception $e) {
                // Skip failed pages
            }
        }

        return $pages;
    }

    /**
     * Decode HTML entities from extracted text
     */
    private function decodeText(string $text): string
    {
        return html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extract site name from various sources
     */
    private function extractSiteName(string $html, string $url): array
    {
        $candidates = [];

        // 1. From og:site_name meta tag (most reliable)
        if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']/', $html, $matches) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:site_name["\']/', $html, $matches)) {
            $candidates[] = ['source' => 'og:site_name', 'value' => $this->decodeText($matches[1]), 'confidence' => 95];
        }

        // 2. From application-name meta tag
        if (preg_match('/<meta[^>]+name=["\']application-name["\'][^>]+content=["\']([^"\']+)["\']/', $html, $matches)) {
            $candidates[] = ['source' => 'application-name', 'value' => $this->decodeText($matches[1]), 'confidence' => 90];
        }

        // 3. From title tag - extract site name portion (after | or -)
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = $this->decodeText($matches[1]);
            // Common patterns: "Page Title | Site Name" or "Page Title - Site Name"
            if (preg_match('/[|\-–—]\s*([^|\-–—]+)$/', $title, $siteMatch)) {
                $siteName = trim($siteMatch[1]);
                if (strlen($siteName) > 2 && strlen($siteName) < 50) {
                    $candidates[] = ['source' => 'title_suffix', 'value' => $siteName, 'confidence' => 80];
                }
            }
            // If homepage, the full title might be the site name
            if (! str_contains($title, '|') && ! str_contains($title, '-') && strlen($title) < 40) {
                $candidates[] = ['source' => 'title_full', 'value' => $title, 'confidence' => 60];
            }
        }

        // 4. From schema.org Organization/WebSite
        if (preg_match('/"@type"\s*:\s*"(?:Organization|WebSite)"[^}]*"name"\s*:\s*"([^"]+)"/', $html, $matches)) {
            $candidates[] = ['source' => 'schema.org', 'value' => $this->decodeText($matches[1]), 'confidence' => 92];
        }

        // 5. From URL hostname as fallback
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $hostName = preg_replace('/^www\./', '', $host);
        $hostName = explode('.', $hostName)[0]; // Get first part of domain
        $hostName = ucfirst($hostName);
        $candidates[] = ['source' => 'hostname', 'value' => $hostName, 'confidence' => 40];

        // Sort by confidence and return best match with all candidates
        usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return [
            'name' => $candidates[0]['value'] ?? null,
            'confidence' => $candidates[0]['confidence'] ?? 0,
            'source' => $candidates[0]['source'] ?? null,
            'alternatives' => array_slice($candidates, 1, 3),
        ];
    }

    /**
     * Extract brand colors from the website using comprehensive CSS analysis
     */
    private function extractBrandColors(string $html, string $url): array
    {
        $debug = ['url' => $url, 'sources' => []];
        $allColors = [];

        // 1. High-priority: Meta tags (intentional brand colors)
        $metaColors = $this->extractMetaColors($html);
        $debug['sources']['meta'] = $metaColors;
        foreach ($metaColors as $color) {
            // Meta colors get extra weight (count them 10x)
            for ($i = 0; $i < 10; $i++) {
                $allColors[] = $color;
            }
        }

        // 2. Extract inline <style> tags
        $inlineStyleColors = $this->extractColorsFromInlineStyles($html);
        $debug['sources']['inline_styles'] = ['count' => count($inlineStyleColors)];
        $allColors = array_merge($allColors, $inlineStyleColors);

        // 3. Extract from ALL linked stylesheets
        $stylesheetUrls = $this->extractStylesheetUrls($html, $url);
        $debug['sources']['stylesheets'] = ['urls' => $stylesheetUrls];
        foreach ($stylesheetUrls as $cssUrl) {
            $cssColors = $this->extractAllColorsFromCSS($cssUrl);
            $allColors = array_merge($allColors, $cssColors);
        }

        // 4. Extract inline style attributes
        $inlineAttrColors = $this->extractInlineAttributeColors($html);
        $debug['sources']['inline_attributes'] = ['count' => count($inlineAttrColors)];
        $allColors = array_merge($allColors, $inlineAttrColors);

        // 5. Normalize all colors to hex
        $normalizedColors = [];
        foreach ($allColors as $color) {
            $normalized = $this->normalizeColor($color);
            if ($normalized && strlen($normalized) >= 4) {
                // Expand 3-char hex to 6-char
                if (strlen($normalized) === 4) {
                    $normalized = '#'.$normalized[1].$normalized[1].$normalized[2].$normalized[2].$normalized[3].$normalized[3];
                }
                $normalizedColors[] = strtolower($normalized);
            }
        }

        // 6. Count frequency
        $frequency = array_count_values($normalizedColors);
        arsort($frequency);
        $debug['total_colors_found'] = count($normalizedColors);
        $debug['unique_colors'] = count($frequency);

        // 7. Filter out non-brand colors (white, black, grays, transparent, low saturation)
        $brandColors = [];
        foreach ($frequency as $hex => $count) {
            if ($this->isBrandWorthy($hex)) {
                $brandColors[$hex] = $count;
            }
        }
        $debug['after_filtering'] = count($brandColors);

        // 8. Cluster similar colors (Delta E < 15)
        $clustered = $this->clusterSimilarColors($brandColors);
        $debug['after_clustering'] = count($clustered);

        // 9. Get top colors
        $topColors = array_slice($clustered, 0, 10, true);

        // 10. Build suggestions
        $colorKeys = array_keys($topColors);
        $suggestions = [];
        if (count($colorKeys) >= 1) {
            $suggestions['primary'] = $colorKeys[0];
        }
        if (count($colorKeys) >= 2) {
            $suggestions['secondary'] = $colorKeys[1];
        }
        if (count($colorKeys) >= 3) {
            $suggestions['accent'] = $colorKeys[2];
        }

        Log::info('OllieAnalyzeSiteTool: Brand color extraction complete', [
            'url' => $url,
            'suggestions' => $suggestions,
            'top_colors' => $topColors,
            'debug' => $debug,
        ]);

        return [
            'suggested' => $suggestions,
            'palette' => array_keys($topColors),
            'frequency' => $topColors,
            'debug' => $debug,
        ];
    }

    /**
     * Extract colors from meta tags
     */
    private function extractMetaColors(string $html): array
    {
        $colors = [];

        // theme-color
        if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']theme-color["\']/i', $html, $m)) {
            $colors[] = $m[1];
        }

        // msapplication-TileColor
        if (preg_match('/<meta[^>]+name=["\']msapplication-TileColor["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']msapplication-TileColor["\']/i', $html, $m)) {
            $colors[] = $m[1];
        }

        return $colors;
    }

    /**
     * Extract all stylesheet URLs from HTML
     */
    private function extractStylesheetUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        // Match all link[rel=stylesheet] tags
        preg_match_all('/<link[^>]+>/i', $html, $linkTags);
        foreach ($linkTags[0] as $tag) {
            if (preg_match('/rel=["\']stylesheet["\']/i', $tag) && preg_match('/href=["\']([^"\']+)["\']/i', $tag, $m)) {
                $urls[] = $this->resolveUrl($m[1], $baseUrl);
            }
        }

        // Limit to first 5 stylesheets to avoid timeouts
        return array_slice(array_unique($urls), 0, 5);
    }

    /**
     * Extract colors from inline <style> tags
     */
    private function extractColorsFromInlineStyles(string $html): array
    {
        $colors = [];

        preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styleTags);
        foreach ($styleTags[1] as $css) {
            $colors = array_merge($colors, $this->parseColorsFromCSS($css));
        }

        return $colors;
    }

    /**
     * Extract colors from inline style attributes
     */
    private function extractInlineAttributeColors(string $html): array
    {
        $colors = [];

        preg_match_all('/style=["\']([^"\']+)["\']/i', $html, $styleAttrs);
        foreach ($styleAttrs[1] as $style) {
            $colors = array_merge($colors, $this->parseColorsFromCSS($style));
        }

        return $colors;
    }

    /**
     * Fetch and extract ALL colors from a CSS file
     */
    private function extractAllColorsFromCSS(string $cssUrl): array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Accept' => 'text/css,*/*;q=0.1',
                'User-Agent' => 'Mozilla/5.0 (compatible; OllieBot/1.0)',
            ])->get($cssUrl);

            if ($response->successful()) {
                return $this->parseColorsFromCSS($response->body());
            }
        } catch (\Exception $e) {
            Log::debug('OllieAnalyzeSiteTool: Failed to fetch CSS', ['url' => $cssUrl, 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Parse all color values from CSS text
     */
    private function parseColorsFromCSS(string $css): array
    {
        $colors = [];

        // Hex colors: #fff, #ffffff, #ffffffff
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $hexMatches);
        $colors = array_merge($colors, $hexMatches[0]);

        // RGB/RGBA: rgb(255, 255, 255), rgba(255, 255, 255, 0.5)
        preg_match_all('/rgba?\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)/', $css, $rgbMatches);
        $colors = array_merge($colors, $rgbMatches[0]);

        // HSL/HSLA: hsl(360, 100%, 50%), hsla(360, 100%, 50%, 0.5)
        preg_match_all('/hsla?\s*\(\s*[\d.]+\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(?:,\s*[\d.]+\s*)?\)/', $css, $hslMatches);
        $colors = array_merge($colors, $hslMatches[0]);

        // CSS variables that look like colors (give them extra weight)
        preg_match_all('/--[a-zA-Z0-9_-]*(?:color|primary|secondary|accent|brand|bg|background|text|border)[a-zA-Z0-9_-]*:\s*([^;}\n]+)/i', $css, $varMatches);
        foreach ($varMatches[1] as $value) {
            $value = trim($value);
            if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\s*\(|hsla?\s*\()/', $value)) {
                // CSS variable colors get extra weight (5x)
                for ($i = 0; $i < 5; $i++) {
                    $colors[] = $value;
                }
            }
        }

        return $colors;
    }

    /**
     * Normalize any color format to hex
     */
    private function normalizeColor(?string $color): ?string
    {
        if (! $color) {
            return null;
        }
        $color = trim($color);

        // Already a hex color
        if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $color, $m)) {
            return '#'.strtolower($m[1]);
        }

        // RGB/RGBA
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $color, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        // HSL/HSLA - convert to RGB then hex
        if (preg_match('/hsla?\s*\(\s*([\d.]+)\s*,\s*([\d.]+)%?\s*,\s*([\d.]+)%?/', $color, $m)) {
            $h = floatval($m[1]) / 360;
            $s = floatval($m[2]) / 100;
            $l = floatval($m[3]) / 100;
            $rgb = $this->hslToRgb($h, $s, $l);

            return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
        }

        return null;
    }

    /**
     * Convert HSL to RGB
     */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s == 0) {
            $r = $g = $b = $l * 255;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hueToRgb($p, $q, $h + 1 / 3) * 255;
            $g = $this->hueToRgb($p, $q, $h) * 255;
            $b = $this->hueToRgb($p, $q, $h - 1 / 3) * 255;
        }

        return [(int) round($r), (int) round($g), (int) round($b)];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }

    /**
     * Check if a color is brand-worthy (not white, black, gray, or transparent)
     */
    private function isBrandWorthy(string $hex): bool
    {
        $rgb = $this->hexToRgb($hex);
        if (! $rgb) {
            return false;
        }

        [$r, $g, $b] = $rgb;

        // Filter out near-white (all channels > 240)
        if ($r > 240 && $g > 240 && $b > 240) {
            return false;
        }

        // Filter out near-black (all channels < 20)
        if ($r < 20 && $g < 20 && $b < 20) {
            return false;
        }

        // Calculate saturation to filter grays
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2 / 255;

        if ($max === $min) {
            $s = 0; // Achromatic (gray)
        } else {
            $d = ($max - $min) / 255;
            $s = $l > 0.5 ? $d / (2 - ($max + $min) / 255) : $d / (($max + $min) / 255);
        }

        // Filter out low saturation colors (grays) - require at least 10% saturation
        if ($s < 0.10) {
            return false;
        }

        // Filter out very light pastels with low saturation
        if ($l > 0.9 && $s < 0.3) {
            return false;
        }

        return true;
    }

    /**
     * Convert hex to RGB array
     */
    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Cluster similar colors using simplified Delta E
     */
    private function clusterSimilarColors(array $colorFrequency, float $threshold = 15): array
    {
        if (empty($colorFrequency)) {
            return [];
        }

        $colors = array_keys($colorFrequency);
        $clustered = [];
        $used = [];

        foreach ($colors as $color) {
            if (in_array($color, $used)) {
                continue;
            }

            $clusterCount = $colorFrequency[$color];

            // Find similar colors and merge their counts
            foreach ($colors as $otherColor) {
                if ($color === $otherColor || in_array($otherColor, $used)) {
                    continue;
                }

                $deltaE = $this->calculateDeltaE($color, $otherColor);
                if ($deltaE < $threshold) {
                    $clusterCount += $colorFrequency[$otherColor];
                    $used[] = $otherColor;
                }
            }

            $clustered[$color] = $clusterCount;
            $used[] = $color;
        }

        arsort($clustered);

        return $clustered;
    }

    /**
     * Calculate simplified Delta E (CIE76) between two hex colors
     */
    private function calculateDeltaE(string $hex1, string $hex2): float
    {
        $lab1 = $this->hexToLab($hex1);
        $lab2 = $this->hexToLab($hex2);

        if (! $lab1 || ! $lab2) {
            return 100;
        } // Max difference if conversion fails

        $dL = $lab1['L'] - $lab2['L'];
        $da = $lab1['a'] - $lab2['a'];
        $db = $lab1['b'] - $lab2['b'];

        return sqrt($dL * $dL + $da * $da + $db * $db);
    }

    /**
     * Convert hex to LAB color space
     */
    private function hexToLab(string $hex): ?array
    {
        $rgb = $this->hexToRgb($hex);
        if (! $rgb) {
            return null;
        }

        // RGB to XYZ
        $r = $rgb[0] / 255;
        $g = $rgb[1] / 255;
        $b = $rgb[2] / 255;

        $r = $r > 0.04045 ? pow(($r + 0.055) / 1.055, 2.4) : $r / 12.92;
        $g = $g > 0.04045 ? pow(($g + 0.055) / 1.055, 2.4) : $g / 12.92;
        $b = $b > 0.04045 ? pow(($b + 0.055) / 1.055, 2.4) : $b / 12.92;

        $x = ($r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375) / 0.95047;
        $y = ($r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750) / 1.00000;
        $z = ($r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041) / 1.08883;

        // XYZ to LAB
        $x = $x > 0.008856 ? pow($x, 1 / 3) : (7.787 * $x) + 16 / 116;
        $y = $y > 0.008856 ? pow($y, 1 / 3) : (7.787 * $y) + 16 / 116;
        $z = $z > 0.008856 ? pow($z, 1 / 3) : (7.787 * $z) + 16 / 116;

        return [
            'L' => (116 * $y) - 16,
            'a' => 500 * ($x - $y),
            'b' => 200 * ($y - $z),
        ];
    }

    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            $parsed = parse_url($baseUrl);

            return ($parsed['scheme'] ?? 'https').':'.$href;
        }
        $parsed = parse_url($baseUrl);
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        if (str_starts_with($href, '/')) {
            return $base.$href;
        }
        // Relative URL
        $path = $parsed['path'] ?? '/';
        $dir = dirname($path);

        return $base.($dir === '/' ? '/' : $dir.'/').$href;
    }

    /**
     * Import sitemap.xml
     */
    private function importSitemap(string $url): array
    {
        $result = [
            'source' => 'sitemap.xml',
            'pages' => [],
            'found' => false,
        ];

        // Common sitemap locations
        $sitemapUrls = [
            "{$url}/sitemap.xml",
            "{$url}/sitemap_index.xml",
            "{$url}/wp-sitemap.xml",  // WordPress
            "{$url}/sitemap/sitemap.xml",
        ];

        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $response = Http::timeout(15)->get($sitemapUrl);
                if ($response->successful() && str_contains($response->body(), '<urlset') || str_contains($response->body(), '<sitemapindex')) {
                    $result['found'] = true;
                    $result['url'] = $sitemapUrl;
                    $result['pages'] = $this->parseSitemapXml($response->body(), $url);

                    // If it's a sitemap index, fetch child sitemaps
                    if (str_contains($response->body(), '<sitemapindex')) {
                        $result['pages'] = $this->parseSitemapIndex($response->body(), $url);
                    }
                    break;
                }
            } catch (\Exception $e) {
                // Try next location
            }
        }

        // Also check robots.txt for sitemap location
        if (! $result['found']) {
            try {
                $robotsResponse = Http::timeout(10)->get("{$url}/robots.txt");
                if ($robotsResponse->successful()) {
                    if (preg_match('/Sitemap:\s*(.+)/i', $robotsResponse->body(), $matches)) {
                        $sitemapUrl = trim($matches[1]);
                        $response = Http::timeout(15)->get($sitemapUrl);
                        if ($response->successful()) {
                            $result['found'] = true;
                            $result['url'] = $sitemapUrl;
                            $result['pages'] = $this->parseSitemapXml($response->body(), $url);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        return $result;
    }

    private function parseSitemapXml(string $xml, string $baseUrl): array
    {
        $pages = [];

        // Extract URLs from sitemap
        preg_match_all('/<url>\s*<loc>([^<]+)<\/loc>(?:\s*<lastmod>([^<]+)<\/lastmod>)?/s', $xml, $matches, PREG_SET_ORDER);

        $parsed = parse_url($baseUrl);
        $baseHost = $parsed['host'] ?? '';

        foreach ($matches as $match) {
            $pageUrl = trim($match[1]);
            $lastMod = isset($match[2]) ? trim($match[2]) : null;

            // Only include pages from the same domain
            $pageHost = parse_url($pageUrl, PHP_URL_HOST);
            if ($pageHost !== $baseHost) {
                continue;
            }

            // Generate page name from URL
            $path = parse_url($pageUrl, PHP_URL_PATH) ?? '/';
            $pageName = $this->generatePageName($path);

            $pages[] = [
                'url' => $pageUrl,
                'path' => $path,
                'name' => $pageName,
                'lastmod' => $lastMod,
            ];
        }

        // Limit to 100 pages
        return array_slice($pages, 0, 100);
    }

    private function parseSitemapIndex(string $xml, string $baseUrl): array
    {
        $allPages = [];

        // Get child sitemaps
        preg_match_all('/<sitemap>\s*<loc>([^<]+)<\/loc>/s', $xml, $matches);

        // Only process first 3 child sitemaps to avoid timeout
        $sitemaps = array_slice($matches[1], 0, 3);

        foreach ($sitemaps as $sitemapUrl) {
            try {
                $response = Http::timeout(10)->get(trim($sitemapUrl));
                if ($response->successful()) {
                    $pages = $this->parseSitemapXml($response->body(), $baseUrl);
                    $allPages = array_merge($allPages, $pages);
                }
            } catch (\Exception $e) {
                // Skip failed sitemaps
            }
        }

        return array_slice($allPages, 0, 100);
    }

    /**
     * Parse navigation structure as sitemap fallback
     */
    private function parseNavigation(string $html, string $url): array
    {
        $pages = [];

        $parsed = parse_url($url);
        $baseHost = $parsed['host'] ?? '';
        $baseScheme = $parsed['scheme'] ?? 'https';

        // Look for navigation elements
        $navPatterns = [
            '/<nav[^>]*>(.+?)<\/nav>/is',
            '/<header[^>]*>(.+?)<\/header>/is',
            '/<[^>]+class=["\'][^"\']*(?:nav|menu|navigation)[^"\']*["\'][^>]*>(.+?)<\/(?:div|ul|nav)>/is',
        ];

        $navHtml = '';
        foreach ($navPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $navHtml .= $matches[1];
            }
        }

        if (empty($navHtml)) {
            return ['source' => 'navigation', 'pages' => [], 'note' => 'No navigation found'];
        }

        // Extract links from navigation
        preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>([^<]+)</i', $navHtml, $matches, PREG_SET_ORDER);

        $seen = [];
        foreach ($matches as $match) {
            $href = trim($match[1]);
            $text = $this->decodeText(strip_tags($match[2]));

            if (empty($text) || strlen($text) > 50) {
                continue;
            }
            if (str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            // Normalize URL
            if (str_starts_with($href, '/')) {
                $href = "{$baseScheme}://{$baseHost}{$href}";
            } elseif (! str_starts_with($href, 'http')) {
                continue; // Skip relative URLs without path
            }

            // Only same-domain links
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost !== $baseHost) {
                continue;
            }

            $path = parse_url($href, PHP_URL_PATH) ?? '/';

            // Dedupe
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $pages[] = [
                'url' => $href,
                'path' => $path,
                'name' => $text,
                'source' => 'navigation',
            ];
        }

        return [
            'source' => 'navigation',
            'pages' => $pages,
            'note' => 'Extracted from site navigation (sitemap.xml not found)',
        ];
    }

    /**
     * Generate a readable page name from URL path
     */
    private function generatePageName(string $path): string
    {
        if ($path === '/' || $path === '') {
            return 'Home';
        }

        // Remove leading/trailing slashes and file extension
        $path = trim($path, '/');
        $path = preg_replace('/\.(html?|php|aspx?)$/i', '', $path);

        // Get last segment
        $segments = explode('/', $path);
        $name = end($segments);

        // Convert to readable format
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);

        return $name ?: 'Page';
    }
}
