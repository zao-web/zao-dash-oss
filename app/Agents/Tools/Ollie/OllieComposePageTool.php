<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Storage;

/**
 * Compose a page using Ollie patterns.
 *
 * Combines multiple patterns into a complete page layout.
 */
class OllieComposePageTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Compose Page';
    }

    public function description(): string
    {
        return 'Compose a WordPress page by combining Ollie patterns. Specify patterns in order and optionally customize content within each pattern.';
    }

    public function requiresApproval(): bool
    {
        return true; // Creates content
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
                'project_id' => [
                    'type' => 'string',
                    'description' => 'Project identifier',
                ],
                'page_title' => [
                    'type' => 'string',
                    'description' => 'Title of the page',
                ],
                'page_slug' => [
                    'type' => 'string',
                    'description' => 'URL slug for the page',
                ],
                'patterns' => [
                    'type' => 'array',
                    'description' => 'Array of pattern slugs in order',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'content_replacements' => [
                    'type' => 'object',
                    'description' => 'Content replacements keyed by pattern index, then placeholder name',
                ],
                'template' => [
                    'type' => 'string',
                    'enum' => ['page-no-title', 'page-with-sidebar', 'default'],
                    'description' => 'Page template to use',
                ],
            ],
            'required' => ['project_id', 'page_title', 'patterns'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|string',
            'page_title' => 'required|string|max:255',
            'page_slug' => 'nullable|string|max:255',
            'patterns' => 'required|array|min:1',
            'patterns.*' => 'string',
            'content_replacements' => 'nullable|array',
            'template' => 'nullable|in:page-no-title,page-with-sidebar,default',
        ];
    }

    public function execute(array $params): array
    {
        $projectId = $params['project_id'];
        $title = $params['page_title'];
        $slug = $params['page_slug'] ?? \Illuminate\Support\Str::slug($title);
        $patterns = $params['patterns'];
        $replacements = $params['content_replacements'] ?? [];
        $template = $params['template'] ?? 'page-no-title';

        // Build the page content
        $content = $this->buildPageContent($patterns, $replacements);

        // Add template comment if not default
        if ($template !== 'default') {
            $content = "<!-- wp:template-part {\"slug\":\"{$template}\"} /-->\n\n".$content;
        }

        // Save to project storage
        $pagePath = "ollie-projects/{$projectId}/pages/{$slug}.html";
        Storage::disk('local')->put($pagePath, $content);

        // Also save metadata
        $metaPath = "ollie-projects/{$projectId}/pages/{$slug}.json";
        Storage::disk('local')->put($metaPath, json_encode([
            'title' => $title,
            'slug' => $slug,
            'template' => $template,
            'patterns' => $patterns,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'page' => [
                'title' => $title,
                'slug' => $slug,
                'template' => $template,
                'pattern_count' => count($patterns),
            ],
            'content_path' => $pagePath,
            'content_preview' => substr($content, 0, 500).'...',
        ];
    }

    private function buildPageContent(array $patterns, array $replacements): string
    {
        $blocks = [];

        foreach ($patterns as $index => $patternSlug) {
            // Normalize slug (add ollie/ prefix if missing)
            if (! str_starts_with($patternSlug, 'ollie/')) {
                $patternSlug = 'ollie/'.$patternSlug;
            }

            // Create pattern reference
            $patternBlock = "<!-- wp:pattern {\"slug\":\"{$patternSlug}\"} /-->";

            $blocks[] = $patternBlock;

            // Add spacing between patterns (except after last)
            if ($index < count($patterns) - 1) {
                $blocks[] = "\n";
            }
        }

        return implode("\n", $blocks);
    }
}
