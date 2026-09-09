<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WebsiteBuilderAnalyzeRepoTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Analyze Repository';
    }

    public function description(): string
    {
        return 'Deep analysis of any GitHub repository to understand its architecture, patterns, and functionality. Stores analysis in project repo_analysis field.';
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
                    'description' => 'Analysis depth',
                ],
            ],
            'required' => ['project_id', 'repo_url'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'repo_url' => 'required|url|regex:/github\.com/',
            'branch' => 'nullable|string|max:100',
            'depth' => 'nullable|in:quick,standard,deep',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $repoUrl = $params['repo_url'];
        $branch = $params['branch'] ?? null;
        $depth = $params['depth'] ?? 'standard';
        $token = config('services.github.token');

        $parsed = $this->parseGitHubUrl($repoUrl);
        if (! $parsed) {
            return ['success' => false, 'error' => 'Invalid GitHub URL format'];
        }

        $owner = $parsed['owner'];
        $repo = $parsed['repo'];

        $repoInfo = $this->getRepoInfo($owner, $repo, $token);
        if (isset($repoInfo['error'])) {
            return $repoInfo;
        }

        $branch = $branch ?? $repoInfo['default_branch'];

        $tree = $this->getRepoTree($owner, $repo, $branch, $token);
        if (isset($tree['error'])) {
            return $tree;
        }

        $structure = $this->analyzeStructure($tree);
        $platform = $this->detectPlatform($structure, $tree);

        $keyFiles = [];
        if ($depth !== 'quick') {
            $keyFiles = $this->readKeyFiles($owner, $repo, $branch, $structure, $platform, $depth, $token);
        }

        $functionality = $this->extractFunctionality($keyFiles, $platform);
        $wordpressMapping = $this->mapToWordPress($functionality, $platform);
        $recommendations = $this->generateRecommendations($functionality, $wordpressMapping, $platform);

        $analysis = [
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

        $project->update([
            'repo_analysis' => $analysis,
        ]);

        return [
            'success' => true,
            'project_id' => $project->id,
            'analysis' => $analysis,
        ];
    }

    private function parseGitHubUrl(string $url): ?array
    {
        if (preg_match('/github\.com\/([^\/]+)\/([^\/\?#]+)/', $url, $matches)) {
            return ['owner' => $matches[1], 'repo' => rtrim($matches[2], '.git')];
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
        $headers = ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'Zao-Dash-WebsiteBuilder'];

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

        $configPatterns = ['composer.json', 'package.json', 'wp-config.php', 'configuration.php'];

        foreach ($tree as $item) {
            if ($item['type'] === 'blob') {
                $structure['total_files']++;

                $ext = pathinfo($item['path'], PATHINFO_EXTENSION);
                if ($ext) {
                    $structure['file_types'][$ext] = ($structure['file_types'][$ext] ?? 0) + 1;
                }

                $filename = basename($item['path']);
                if (in_array($filename, $configPatterns)) {
                    $structure['config_files'][] = $item['path'];
                }
            } else {
                $parts = explode('/', $item['path']);
                if (count($parts) === 1) {
                    $structure['directories'][] = $item['path'];
                }
            }
        }

        return $structure;
    }

    private function detectPlatform(array $structure, array $tree): array
    {
        $files = array_column($tree, 'path');

        if (in_array('wp-config.php', $files)) {
            return ['type' => 'wordpress', 'framework' => 'WordPress', 'confidence' => 95];
        }

        if (in_array('style.css', $files) && in_array('functions.php', $files) && in_array('index.php', $files)) {
            return ['type' => 'wordpress-theme', 'framework' => 'WordPress Theme', 'confidence' => 90];
        }

        if (in_array('configuration.php', $files)) {
            return ['type' => 'joomla', 'framework' => 'Joomla', 'confidence' => 90];
        }

        if (in_array('artisan', $files) && in_array('composer.json', $files)) {
            return ['type' => 'laravel', 'framework' => 'Laravel', 'confidence' => 95];
        }

        if (in_array('next.config.js', $files) || in_array('next.config.mjs', $files)) {
            return ['type' => 'nextjs', 'framework' => 'Next.js', 'confidence' => 95];
        }

        if (isset($structure['file_types']['php']) && $structure['file_types']['php'] > 5) {
            return ['type' => 'php', 'framework' => 'Custom PHP', 'confidence' => 60];
        }

        return ['type' => 'unknown', 'framework' => null, 'confidence' => 0];
    }

    private function readKeyFiles(string $owner, string $repo, string $branch, array $structure, array $platform, string $depth, ?string $token): array
    {
        $filesToRead = array_merge($structure['config_files'], $this->getPlatformKeyFiles($platform['type']));
        $maxFiles = $depth === 'deep' ? 50 : 20;
        $filesToRead = array_unique(array_slice($filesToRead, 0, $maxFiles));

        $contents = [];
        foreach ($filesToRead as $path) {
            $content = $this->readFile($owner, $repo, $branch, $path, $token);
            if ($content !== null) {
                $contents[$path] = $content;
            }
        }

        return $contents;
    }

    private function getPlatformKeyFiles(string $platformType): array
    {
        return match ($platformType) {
            'wordpress', 'wordpress-theme' => ['functions.php', 'style.css', 'index.php', 'header.php', 'footer.php'],
            'joomla' => ['configuration.php'],
            'laravel' => ['routes/web.php', 'routes/api.php'],
            default => [],
        };
    }

    private function readFile(string $owner, string $repo, string $branch, string $path, ?string $token): ?string
    {
        $cacheKey = 'github_file_'.md5("{$owner}_{$repo}_{$branch}_{$path}");

        return Cache::remember($cacheKey, 300, function () use ($owner, $repo, $branch, $path, $token) {
            $response = Http::withHeaders($this->getHeaders($token))
                ->get("https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return isset($data['content']) ? base64_decode($data['content']) : null;
        });
    }

    private function extractFunctionality(array $files, array $platform): array
    {
        $functionality = [
            'routes' => [],
            'components' => [],
            'hooks' => [],
            'shortcodes' => [],
            'custom_post_types' => [],
            'api_endpoints' => [],
            'integrations' => [],
        ];

        foreach ($files as $path => $content) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            if ($ext === 'php') {
                if (preg_match_all('/add_action\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $hook) {
                        $functionality['hooks'][] = ['type' => 'action', 'name' => $hook, 'file' => $path];
                    }
                }

                if (preg_match_all('/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $shortcode) {
                        $functionality['shortcodes'][] = ['name' => $shortcode, 'file' => $path];
                    }
                }

                if (preg_match_all('/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $cpt) {
                        $functionality['custom_post_types'][] = ['name' => $cpt, 'file' => $path];
                    }
                }
            }
        }

        return $functionality;
    }

    private function mapToWordPress(array $functionality, array $platform): array
    {
        $mapping = [
            'blocks' => [],
            'patterns' => [],
            'plugins_needed' => [],
        ];

        foreach ($functionality['shortcodes'] as $shortcode) {
            $mapping['blocks'][] = [
                'source' => "[{$shortcode['name']}]",
                'suggested_block' => 'custom-block',
                'notes' => 'Convert shortcode to Gutenberg block',
            ];
        }

        foreach ($functionality['custom_post_types'] as $cpt) {
            $mapping['plugins_needed'][] = [
                'purpose' => "Register CPT: {$cpt['name']}",
                'options' => ['Custom code in theme', 'CPT UI plugin'],
            ];
        }

        return $mapping;
    }

    private function generateRecommendations(array $functionality, array $mapping, array $platform): array
    {
        $componentCount = count($functionality['components']);
        $hookCount = count($functionality['hooks']);
        $customizations = $componentCount + $hookCount + count($functionality['shortcodes']);

        if ($customizations < 5) {
            return [
                'approach' => 'Simple migration - minimal custom code needed',
                'effort_estimate' => 'Low (1-2 days)',
                'next_steps' => ['Review content to migrate', 'Select Ollie patterns', 'Build pages'],
            ];
        } elseif ($customizations < 20) {
            return [
                'approach' => 'Moderate migration - some custom blocks/patterns needed',
                'effort_estimate' => 'Medium (1-2 weeks)',
                'next_steps' => ['Map components to blocks', 'Create custom blocks if needed', 'Plan content structure'],
            ];
        } else {
            return [
                'approach' => 'Complex migration - significant custom development required',
                'effort_estimate' => 'High (2-4 weeks)',
                'next_steps' => ['Detailed component analysis', 'Custom block development', 'Phased migration plan'],
            ];
        }
    }
}
