<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Analyze a GitHub repository to understand its architecture and customizations.
 *
 * Platform-agnostic deep analysis that maps any codebase to WordPress/Ollie equivalents.
 */
class OllieAnalyzeRepoTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Analyze Repository';
    }

    public function description(): string
    {
        return 'Deep analysis of any GitHub repository to understand its architecture, patterns, and functionality. Maps findings to WordPress/Ollie equivalents for migration planning.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repo_url' => [
                    'type' => 'string',
                    'description' => 'GitHub repository URL (e.g., https://github.com/owner/repo)',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Branch to analyze (default: main or master)',
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['quick', 'standard', 'deep'],
                    'description' => 'Analysis depth: quick (structure only), standard (structure + key files), deep (full analysis)',
                ],
                'focus_paths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific paths to focus analysis on (optional)',
                ],
                'github_token' => [
                    'type' => 'string',
                    'description' => 'GitHub personal access token for private repos (optional)',
                ],
            ],
            'required' => ['repo_url'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'repo_url' => 'required|url|regex:/github\.com/',
            'branch' => 'nullable|string|max:100',
            'depth' => 'nullable|in:quick,standard,deep',
            'focus_paths' => 'nullable|array',
            'focus_paths.*' => 'string',
            'github_token' => 'nullable|string',
        ];
    }

    public function execute(array $params): array
    {
        $repoUrl = $params['repo_url'];
        $branch = $params['branch'] ?? null;
        $depth = $params['depth'] ?? 'standard';
        $focusPaths = $params['focus_paths'] ?? [];
        $token = $params['github_token'] ?? config('services.github.token');

        // Parse GitHub URL
        $parsed = $this->parseGitHubUrl($repoUrl);
        if (! $parsed) {
            return ['error' => 'Invalid GitHub URL format'];
        }

        $owner = $parsed['owner'];
        $repo = $parsed['repo'];

        // Get repo info and default branch
        $repoInfo = $this->getRepoInfo($owner, $repo, $token);
        if (isset($repoInfo['error'])) {
            return $repoInfo;
        }

        $branch = $branch ?? $repoInfo['default_branch'];

        // Get repository tree
        $tree = $this->getRepoTree($owner, $repo, $branch, $token);
        if (isset($tree['error'])) {
            return $tree;
        }

        // Analyze structure
        $structure = $this->analyzeStructure($tree);

        // Detect platform/framework
        $platform = $this->detectPlatform($structure, $tree);

        // Read key files based on depth
        $keyFiles = [];
        if ($depth !== 'quick') {
            $keyFiles = $this->readKeyFiles($owner, $repo, $branch, $structure, $platform, $depth, $focusPaths, $token);
        }

        // Extract functionality
        $functionality = $this->extractFunctionality($keyFiles, $platform);

        // Map to WordPress equivalents
        $wordpressMapping = $this->mapToWordPress($functionality, $platform);

        // Generate migration recommendations
        $recommendations = $this->generateRecommendations($functionality, $wordpressMapping, $platform);

        return [
            'repository' => [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
                'url' => $repoUrl,
                'description' => $repoInfo['description'] ?? null,
                'language' => $repoInfo['language'] ?? null,
            ],
            'platform' => $platform,
            'structure' => $structure,
            'functionality' => $functionality,
            'wordpress_mapping' => $wordpressMapping,
            'recommendations' => $recommendations,
            'files_analyzed' => count($keyFiles),
        ];
    }

    private function parseGitHubUrl(string $url): ?array
    {
        // Handle various GitHub URL formats
        $patterns = [
            '/github\.com\/([^\/]+)\/([^\/\?#]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return [
                    'owner' => $matches[1],
                    'repo' => rtrim($matches[2], '.git'),
                ];
            }
        }

        return null;
    }

    private function getRepoInfo(string $owner, string $repo, ?string $token): array
    {
        $cacheKey = "github_repo_{$owner}_{$repo}";

        return Cache::remember($cacheKey, 300, function () use ($owner, $repo, $token) {
            $response = Http::withHeaders($this->getHeaders($token))
                ->get("https://api.github.com/repos/{$owner}/{$repo}");

            if (! $response->successful()) {
                return ['error' => 'Failed to fetch repository: '.$response->status()];
            }

            $data = $response->json();

            return [
                'default_branch' => $data['default_branch'] ?? 'main',
                'description' => $data['description'] ?? null,
                'language' => $data['language'] ?? null,
                'topics' => $data['topics'] ?? [],
                'size' => $data['size'] ?? 0,
            ];
        });
    }

    private function getRepoTree(string $owner, string $repo, string $branch, ?string $token): array
    {
        $cacheKey = "github_tree_{$owner}_{$repo}_{$branch}";

        return Cache::remember($cacheKey, 300, function () use ($owner, $repo, $branch, $token) {
            $response = Http::withHeaders($this->getHeaders($token))
                ->get("https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$branch}?recursive=1");

            if (! $response->successful()) {
                return ['error' => 'Failed to fetch repository tree: '.$response->status()];
            }

            return $response->json()['tree'] ?? [];
        });
    }

    private function getHeaders(?string $token): array
    {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Zao-Dash-Ollie-Analyzer',
        ];

        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    private function analyzeStructure(array $tree): array
    {
        $structure = [
            'directories' => [],
            'file_types' => [],
            'total_files' => 0,
            'key_files' => [],
            'config_files' => [],
        ];

        $configPatterns = [
            'composer.json', 'package.json', 'requirements.txt', 'Gemfile',
            'configuration.php', 'wp-config.php', 'settings.php', 'config.php',
            '.env.example', 'docker-compose.yml', 'Dockerfile',
        ];

        foreach ($tree as $item) {
            if ($item['type'] === 'blob') {
                $structure['total_files']++;

                // Track file extensions
                $ext = pathinfo($item['path'], PATHINFO_EXTENSION);
                if ($ext) {
                    $structure['file_types'][$ext] = ($structure['file_types'][$ext] ?? 0) + 1;
                }

                // Track config files
                $filename = basename($item['path']);
                if (in_array($filename, $configPatterns)) {
                    $structure['config_files'][] = $item['path'];
                }

                // Track key files
                if ($this->isKeyFile($item['path'])) {
                    $structure['key_files'][] = $item['path'];
                }
            } else {
                // Track top-level directories
                $parts = explode('/', $item['path']);
                if (count($parts) === 1) {
                    $structure['directories'][] = $item['path'];
                }
            }
        }

        return $structure;
    }

    private function isKeyFile(string $path): bool
    {
        $keyPatterns = [
            // Entry points
            'index.php', 'index.html', 'app.php', 'main.py', 'app.py',
            // Config
            'composer.json', 'package.json', 'configuration.php',
            // WordPress
            'functions.php', 'style.css', 'template-parts/',
            // Joomla
            'administrator/', 'components/', 'modules/', 'plugins/', 'templates/',
            // Drupal
            '*.module', '*.theme', '*.info.yml',
            // Laravel
            'routes/', 'app/Http/', 'resources/views/',
            // Generic
            'src/', 'lib/', 'includes/', 'classes/',
        ];

        foreach ($keyPatterns as $pattern) {
            if (Str::contains($path, $pattern) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }

    private function detectPlatform(array $structure, array $tree): array
    {
        $platform = [
            'type' => 'unknown',
            'framework' => null,
            'version' => null,
            'confidence' => 0,
            'indicators' => [],
        ];

        $files = array_column($tree, 'path');

        // WordPress detection
        if (in_array('wp-config.php', $files) || in_array('wp-includes', array_column(array_filter($tree, fn ($t) => $t['type'] === 'tree'), 'path'))) {
            $platform = ['type' => 'wordpress', 'framework' => 'WordPress', 'confidence' => 95, 'indicators' => ['wp-config.php or wp-includes found']];
        }
        // WordPress theme
        elseif (in_array('style.css', $files) && in_array('functions.php', $files) && in_array('index.php', $files)) {
            $platform = ['type' => 'wordpress-theme', 'framework' => 'WordPress Theme', 'confidence' => 90, 'indicators' => ['style.css + functions.php + index.php']];
        }
        // WordPress plugin
        elseif ($this->hasWordPressPluginHeader($files)) {
            $platform = ['type' => 'wordpress-plugin', 'framework' => 'WordPress Plugin', 'confidence' => 85, 'indicators' => ['Plugin header detected']];
        }
        // Joomla detection
        elseif (in_array('configuration.php', $files) || $this->hasPath($files, 'administrator/')) {
            $platform = ['type' => 'joomla', 'framework' => 'Joomla', 'confidence' => 90, 'indicators' => ['configuration.php or administrator/ found']];
        }
        // Joomla extension
        elseif ($this->hasPath($files, 'com_') || $this->hasPath($files, 'mod_') || $this->hasPath($files, 'plg_')) {
            $platform = ['type' => 'joomla-extension', 'framework' => 'Joomla Extension', 'confidence' => 85, 'indicators' => ['Joomla extension prefix detected']];
        }
        // Drupal detection
        elseif (in_array('sites/default/settings.php', $files) || $this->hasPath($files, '.module')) {
            $platform = ['type' => 'drupal', 'framework' => 'Drupal', 'confidence' => 90, 'indicators' => ['Drupal settings or module files found']];
        }
        // Laravel detection
        elseif (in_array('artisan', $files) && in_array('composer.json', $files)) {
            $platform = ['type' => 'laravel', 'framework' => 'Laravel', 'confidence' => 95, 'indicators' => ['artisan + composer.json']];
        }
        // Symfony detection
        elseif ($this->hasPath($files, 'symfony.lock') || $this->hasPath($files, 'config/bundles.php')) {
            $platform = ['type' => 'symfony', 'framework' => 'Symfony', 'confidence' => 90, 'indicators' => ['Symfony files detected']];
        }
        // React/Next.js
        elseif (in_array('next.config.js', $files) || in_array('next.config.mjs', $files)) {
            $platform = ['type' => 'nextjs', 'framework' => 'Next.js', 'confidence' => 95, 'indicators' => ['next.config found']];
        }
        // Vue/Nuxt
        elseif (in_array('nuxt.config.js', $files) || in_array('nuxt.config.ts', $files)) {
            $platform = ['type' => 'nuxt', 'framework' => 'Nuxt.js', 'confidence' => 95, 'indicators' => ['nuxt.config found']];
        }
        // Generic PHP
        elseif (isset($structure['file_types']['php']) && $structure['file_types']['php'] > 5) {
            $platform = ['type' => 'php', 'framework' => 'Custom PHP', 'confidence' => 60, 'indicators' => ['Multiple PHP files']];
        }
        // Static HTML
        elseif (isset($structure['file_types']['html']) && $structure['file_types']['html'] > 0) {
            $platform = ['type' => 'static', 'framework' => 'Static HTML', 'confidence' => 70, 'indicators' => ['HTML files present']];
        }

        return $platform;
    }

    private function hasPath(array $files, string $pattern): bool
    {
        foreach ($files as $file) {
            if (Str::contains($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasWordPressPluginHeader(array $files): bool
    {
        // Check for common plugin file patterns
        foreach ($files as $file) {
            if (preg_match('/^[^\/]+\.php$/', $file)) {
                return true; // Root-level PHP file (potential plugin main file)
            }
        }

        return false;
    }

    private function readKeyFiles(string $owner, string $repo, string $branch, array $structure, array $platform, string $depth, array $focusPaths, ?string $token): array
    {
        $filesToRead = [];
        $maxFiles = $depth === 'deep' ? 50 : 20;

        // Always read config files
        foreach ($structure['config_files'] as $file) {
            $filesToRead[] = $file;
        }

        // Add focus paths
        foreach ($focusPaths as $path) {
            $filesToRead[] = $path;
        }

        // Add platform-specific files
        $filesToRead = array_merge($filesToRead, $this->getPlatformKeyFiles($platform['type'], $structure));

        // Limit and dedupe
        $filesToRead = array_unique(array_slice($filesToRead, 0, $maxFiles));

        // Read files
        $contents = [];
        foreach ($filesToRead as $path) {
            $content = $this->readFile($owner, $repo, $branch, $path, $token);
            if ($content !== null) {
                $contents[$path] = $content;
            }
        }

        return $contents;
    }

    private function getPlatformKeyFiles(string $platformType, array $structure): array
    {
        $files = [];

        switch ($platformType) {
            case 'wordpress':
            case 'wordpress-theme':
                $files = ['functions.php', 'style.css', 'index.php', 'header.php', 'footer.php', 'single.php', 'page.php'];
                break;
            case 'wordpress-plugin':
                // Find main plugin file
                foreach ($structure['key_files'] as $file) {
                    if (preg_match('/^[^\/]+\.php$/', $file)) {
                        $files[] = $file;
                    }
                }
                break;
            case 'joomla':
            case 'joomla-extension':
                $files = ['configuration.php'];
                foreach ($structure['key_files'] as $file) {
                    if (Str::contains($file, ['components/', 'modules/', 'plugins/', 'templates/'])) {
                        $files[] = $file;
                    }
                }
                break;
            case 'drupal':
                foreach ($structure['key_files'] as $file) {
                    if (Str::endsWith($file, ['.module', '.theme', '.info.yml'])) {
                        $files[] = $file;
                    }
                }
                break;
            case 'laravel':
                $files = ['routes/web.php', 'routes/api.php', 'app/Http/Controllers'];
                break;
            default:
                $files = array_slice($structure['key_files'], 0, 10);
        }

        return $files;
    }

    private function readFile(string $owner, string $repo, string $branch, string $path, ?string $token): ?string
    {
        $cacheKey = "github_file_{$owner}_{$repo}_{$branch}_".md5($path);

        return Cache::remember($cacheKey, 300, function () use ($owner, $repo, $branch, $path, $token) {
            $response = Http::withHeaders($this->getHeaders($token))
                ->get("https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (isset($data['content'])) {
                return base64_decode($data['content']);
            }

            return null;
        });
    }

    private function extractFunctionality(array $files, array $platform): array
    {
        $functionality = [
            'routes' => [],
            'templates' => [],
            'components' => [],
            'hooks' => [],
            'shortcodes' => [],
            'custom_post_types' => [],
            'taxonomies' => [],
            'api_endpoints' => [],
            'database_tables' => [],
            'forms' => [],
            'integrations' => [],
            'custom_logic' => [],
        ];

        foreach ($files as $path => $content) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            if ($ext === 'php') {
                $this->extractPhpFunctionality($path, $content, $functionality, $platform);
            } elseif (in_array($ext, ['js', 'ts', 'jsx', 'tsx'])) {
                $this->extractJsFunctionality($path, $content, $functionality);
            } elseif ($ext === 'json' && basename($path) === 'composer.json') {
                $this->extractComposerDependencies($content, $functionality);
            } elseif ($ext === 'json' && basename($path) === 'package.json') {
                $this->extractNpmDependencies($content, $functionality);
            }
        }

        return $functionality;
    }

    private function extractPhpFunctionality(string $path, string $content, array &$functionality, array $platform): void
    {
        // WordPress hooks
        if (preg_match_all('/add_action\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $hook) {
                $functionality['hooks'][] = ['type' => 'action', 'name' => $hook, 'file' => $path];
            }
        }
        if (preg_match_all('/add_filter\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $hook) {
                $functionality['hooks'][] = ['type' => 'filter', 'name' => $hook, 'file' => $path];
            }
        }

        // WordPress shortcodes
        if (preg_match_all('/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $shortcode) {
                $functionality['shortcodes'][] = ['name' => $shortcode, 'file' => $path];
            }
        }

        // Custom Post Types
        if (preg_match_all('/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $cpt) {
                $functionality['custom_post_types'][] = ['name' => $cpt, 'file' => $path];
            }
        }

        // Taxonomies
        if (preg_match_all('/register_taxonomy\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $tax) {
                $functionality['taxonomies'][] = ['name' => $tax, 'file' => $path];
            }
        }

        // REST API endpoints
        if (preg_match_all('/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $route) {
                $functionality['api_endpoints'][] = ['namespace' => $route, 'file' => $path];
            }
        }

        // Database tables (dbDelta or CREATE TABLE)
        if (preg_match_all('/CREATE\s+TABLE\s+[`\'"]*(\w+)/i', $content, $matches)) {
            foreach ($matches[1] as $table) {
                $functionality['database_tables'][] = ['name' => $table, 'file' => $path];
            }
        }

        // Class definitions (components)
        if (preg_match_all('/class\s+(\w+)(?:\s+extends\s+(\w+))?/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $functionality['components'][] = [
                    'name' => $match[1],
                    'extends' => $match[2] ?? null,
                    'file' => $path,
                ];
            }
        }

        // Form handling
        if (preg_match('/\$_POST|\$_GET|wp_nonce|check_admin_referer/', $content)) {
            $functionality['forms'][] = ['file' => $path, 'type' => 'form_handler'];
        }

        // Third-party integrations
        $integrations = ['stripe', 'paypal', 'mailchimp', 'sendgrid', 'twilio', 'aws', 'google', 'facebook'];
        foreach ($integrations as $integration) {
            if (stripos($content, $integration) !== false) {
                $functionality['integrations'][] = ['name' => $integration, 'file' => $path];
            }
        }
    }

    private function extractJsFunctionality(string $path, string $content, array &$functionality): void
    {
        // React components
        if (preg_match_all('/(?:function|const)\s+(\w+).*?(?:return\s*\(|=>).*?</', $content, $matches)) {
            foreach ($matches[1] as $component) {
                if (ctype_upper($component[0])) {
                    $functionality['components'][] = ['name' => $component, 'type' => 'react', 'file' => $path];
                }
            }
        }

        // Vue components
        if (Str::contains($content, 'export default')) {
            $functionality['components'][] = ['name' => basename($path, '.vue'), 'type' => 'vue', 'file' => $path];
        }

        // API calls
        if (preg_match_all('/fetch\s*\(\s*[\'"`]([^\'"`]+)/', $content, $matches)) {
            foreach ($matches[1] as $endpoint) {
                $functionality['api_endpoints'][] = ['url' => $endpoint, 'file' => $path, 'type' => 'client'];
            }
        }
    }

    private function extractComposerDependencies(string $content, array &$functionality): void
    {
        $json = json_decode($content, true);
        if (! $json) {
            return;
        }

        $deps = array_merge($json['require'] ?? [], $json['require-dev'] ?? []);
        foreach ($deps as $package => $version) {
            if (Str::startsWith($package, ['php', 'ext-'])) {
                continue;
            }
            $functionality['integrations'][] = ['name' => $package, 'version' => $version, 'type' => 'composer'];
        }
    }

    private function extractNpmDependencies(string $content, array &$functionality): void
    {
        $json = json_decode($content, true);
        if (! $json) {
            return;
        }

        $deps = array_merge($json['dependencies'] ?? [], $json['devDependencies'] ?? []);
        foreach ($deps as $package => $version) {
            $functionality['integrations'][] = ['name' => $package, 'version' => $version, 'type' => 'npm'];
        }
    }

    private function mapToWordPress(array $functionality, array $platform): array
    {
        $mapping = [
            'blocks' => [],
            'patterns' => [],
            'theme_functions' => [],
            'plugins_needed' => [],
            'custom_fields' => [],
            'templates' => [],
        ];

        // Map components to blocks
        foreach ($functionality['components'] as $component) {
            $mapping['blocks'][] = [
                'source' => $component['name'],
                'suggested_block' => $this->suggestBlockType($component['name']),
                'complexity' => $this->assessComplexity($component),
            ];
        }

        // Map shortcodes to blocks
        foreach ($functionality['shortcodes'] as $shortcode) {
            $mapping['blocks'][] = [
                'source' => "[{$shortcode['name']}]",
                'suggested_block' => 'custom-block',
                'notes' => 'Convert shortcode to Gutenberg block',
            ];
        }

        // Map CPTs
        foreach ($functionality['custom_post_types'] as $cpt) {
            $mapping['plugins_needed'][] = [
                'purpose' => "Register CPT: {$cpt['name']}",
                'options' => ['Custom code in theme', 'CPT UI plugin', 'ACF'],
            ];
        }

        // Map forms
        if (! empty($functionality['forms'])) {
            $mapping['plugins_needed'][] = [
                'purpose' => 'Form handling',
                'options' => ['Gravity Forms', 'WPForms', 'Contact Form 7', 'Custom blocks'],
            ];
        }

        // Map integrations
        $integrationPlugins = [
            'stripe' => 'WooCommerce Stripe Gateway',
            'paypal' => 'WooCommerce PayPal',
            'mailchimp' => 'MC4WP: Mailchimp for WordPress',
            'google' => 'Site Kit by Google',
        ];

        foreach ($functionality['integrations'] as $integration) {
            if (isset($integrationPlugins[$integration['name']])) {
                $mapping['plugins_needed'][] = [
                    'purpose' => ucfirst($integration['name']).' integration',
                    'suggested' => $integrationPlugins[$integration['name']],
                ];
            }
        }

        return $mapping;
    }

    private function suggestBlockType(string $componentName): string
    {
        $name = strtolower($componentName);

        $blockMappings = [
            'header' => 'core/site-header',
            'footer' => 'core/site-footer',
            'nav' => 'core/navigation',
            'menu' => 'core/navigation',
            'hero' => 'ollie/hero-*',
            'slider' => 'custom-block (carousel)',
            'carousel' => 'custom-block (carousel)',
            'gallery' => 'core/gallery',
            'image' => 'core/image',
            'video' => 'core/video',
            'form' => 'custom-block (form)',
            'contact' => 'custom-block (contact form)',
            'testimonial' => 'ollie/testimonial-*',
            'pricing' => 'ollie/pricing-*',
            'feature' => 'ollie/feature-*',
            'cta' => 'ollie/cta-*',
            'card' => 'ollie/card-*',
            'button' => 'core/button',
            'list' => 'core/list',
            'table' => 'core/table',
            'accordion' => 'custom-block (accordion)',
            'tab' => 'custom-block (tabs)',
            'modal' => 'custom-block (modal)',
            'map' => 'custom-block (map)',
            'social' => 'core/social-links',
        ];

        foreach ($blockMappings as $keyword => $block) {
            if (Str::contains($name, $keyword)) {
                return $block;
            }
        }

        return 'custom-block';
    }

    private function assessComplexity(array $component): string
    {
        // Simple heuristic based on component type
        if (isset($component['extends']) && $component['extends']) {
            return 'medium';
        }

        return 'low';
    }

    private function generateRecommendations(array $functionality, array $mapping, array $platform): array
    {
        $recommendations = [
            'approach' => null,
            'priority_items' => [],
            'effort_estimate' => null,
            'risks' => [],
            'next_steps' => [],
        ];

        // Determine approach
        $componentCount = count($functionality['components']);
        $hookCount = count($functionality['hooks']);
        $customizations = $componentCount + $hookCount + count($functionality['shortcodes']);

        if ($customizations < 5) {
            $recommendations['approach'] = 'Simple migration - minimal custom code needed';
            $recommendations['effort_estimate'] = 'Low (1-2 days)';
        } elseif ($customizations < 20) {
            $recommendations['approach'] = 'Moderate migration - some custom blocks/patterns needed';
            $recommendations['effort_estimate'] = 'Medium (1-2 weeks)';
        } else {
            $recommendations['approach'] = 'Complex migration - significant custom development required';
            $recommendations['effort_estimate'] = 'High (2-4 weeks)';
        }

        // Priority items
        if (! empty($functionality['custom_post_types'])) {
            $recommendations['priority_items'][] = 'Set up Custom Post Types first';
        }
        if (! empty($functionality['database_tables'])) {
            $recommendations['priority_items'][] = 'Plan database migration strategy';
            $recommendations['risks'][] = 'Custom database tables require migration scripts';
        }
        if (! empty($functionality['api_endpoints'])) {
            $recommendations['priority_items'][] = 'Map API endpoints to WordPress REST API';
        }

        // Next steps
        $recommendations['next_steps'] = [
            'Review suggested block mappings',
            'Identify content that can use existing Ollie patterns',
            'List custom blocks needed',
            'Plan plugin requirements',
            'Create migration checklist',
        ];

        return $recommendations;
    }
}
