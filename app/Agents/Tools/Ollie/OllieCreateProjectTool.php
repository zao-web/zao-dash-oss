<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Create an Ollie project workspace.
 *
 * Initializes the child theme structure and project files.
 */
class OllieCreateProjectTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Create Ollie Project';
    }

    public function description(): string
    {
        return 'Create a new Ollie site-building project. Initializes the child theme workspace, sets up project structure, and prepares for page building.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Project/site name',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL-safe project slug (generated from name if not provided)',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['new', 'migration', 'redesign'],
                    'description' => 'Type of project',
                ],
                'environment' => [
                    'type' => 'string',
                    'enum' => ['staging', 'production'],
                    'description' => 'Target environment',
                ],
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'Associated client ID (optional)',
                ],
            ],
            'required' => ['name', 'type'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100',
            'type' => 'required|in:new,migration,redesign',
            'environment' => 'nullable|in:staging,production',
            'client_id' => 'nullable|integer|exists:clients,id',
        ];
    }

    public function execute(array $params): array
    {
        $name = $params['name'];
        $slug = $params['slug'] ?? Str::slug($name);
        $type = $params['type'];
        $environment = $params['environment'] ?? 'staging';
        $clientId = $params['client_id'] ?? null;

        $projectId = $slug.'-'.Str::random(6);
        $basePath = "ollie-projects/{$projectId}";

        // Create project structure
        $structure = [
            'theme.json' => $this->getBaseThemeJson($name),
            'style.css' => $this->getStyleCss($name, $slug),
            'functions.php' => $this->getFunctionsPhp($slug),
            'pages/.gitkeep' => '',
            'patterns/.gitkeep' => '',
            'blocks/.gitkeep' => '',
            'assets/.gitkeep' => '',
        ];

        foreach ($structure as $file => $content) {
            Storage::disk('local')->put("{$basePath}/{$file}", $content);
        }

        // Create project manifest
        $manifest = [
            'id' => $projectId,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'environment' => $environment,
            'client_id' => $clientId,
            'status' => 'initialized',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'agents' => [
                'brief_analyzer' => null,
                'design_system' => null,
                'page_builder' => null,
                'migration' => null,
                'block_creator' => null,
            ],
        ];

        Storage::disk('local')->put("{$basePath}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'project' => [
                'id' => $projectId,
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
                'environment' => $environment,
                'path' => $basePath,
            ],
            'structure' => array_keys($structure),
        ];
    }

    private function getBaseThemeJson(string $name): string
    {
        $themeJson = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'settings' => [
                'appearanceTools' => true,
            ],
            'styles' => [],
        ];

        return json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function getStyleCss(string $name, string $slug): string
    {
        return <<<CSS
/*
Theme Name: {$name}
Theme URI: https://example.com
Description: Custom Ollie child theme for {$name}
Author: Zao
Author URI: https://example.com
Template: ollie
Version: 1.0.0
Text Domain: {$slug}
*/
CSS;
    }

    private function getFunctionsPhp(string $slug): string
    {
        $textDomain = str_replace('-', '_', $slug);

        return <<<PHP
<?php
/**
 * {$slug} Child Theme Functions
 */

// Enqueue parent and child theme styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('ollie-parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), ['ollie-parent-style']);
});

// Register custom blocks
add_action('init', function() {
    \$blocks_dir = get_stylesheet_directory() . '/blocks';

    if (is_dir(\$blocks_dir)) {
        foreach (glob(\$blocks_dir . '/*/block.json') as \$block_json) {
            register_block_type(dirname(\$block_json));
        }
    }
});

// Register custom patterns
add_action('init', function() {
    \$patterns_dir = get_stylesheet_directory() . '/patterns';

    if (is_dir(\$patterns_dir)) {
        foreach (glob(\$patterns_dir . '/*.php') as \$pattern_file) {
            require_once \$pattern_file;
        }
    }
});
PHP;
    }
}
