<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use Illuminate\Support\Str;

class WebsiteBuilderComposePageTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Compose Page';
    }

    public function description(): string
    {
        return 'Compose a WordPress page by combining Ollie patterns. Stores the page in the project pages field.';
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
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID',
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
                    'items' => ['type' => 'string'],
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
            'project_id' => 'required|integer|exists:website_projects,id',
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
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $title = $params['page_title'];
        $slug = $params['page_slug'] ?? Str::slug($title);
        $patterns = $params['patterns'];
        $replacements = $params['content_replacements'] ?? [];
        $template = $params['template'] ?? 'page-no-title';

        $content = $this->buildPageContent($patterns, $replacements);

        if ($template !== 'default') {
            $content = "<!-- wp:template-part {\"slug\":\"{$template}\"} /-->\n\n".$content;
        }

        $pageData = [
            'title' => $title,
            'slug' => $slug,
            'template' => $template,
            'patterns' => $patterns,
            'content' => $content,
            'created_at' => now()->toIso8601String(),
        ];

        $pages = $project->pages ?? [];
        $pages[$slug] = $pageData;

        $phaseProgress = $project->phase_progress ?? [];
        $phaseProgress['pages_completed'] = count($pages);

        $project->update([
            'pages' => $pages,
            'phase_progress' => $phaseProgress,
            'status' => 'building',
        ]);

        return [
            'success' => true,
            'project_id' => $project->id,
            'page' => [
                'title' => $title,
                'slug' => $slug,
                'template' => $template,
                'pattern_count' => count($patterns),
            ],
            'content_preview' => substr($content, 0, 500).'...',
            'pages_total' => count($pages),
        ];
    }

    private function buildPageContent(array $patterns, array $replacements): string
    {
        $blocks = [];

        foreach ($patterns as $index => $patternSlug) {
            if (! str_starts_with($patternSlug, 'ollie/')) {
                $patternSlug = 'ollie/'.$patternSlug;
            }

            $patternBlock = "<!-- wp:pattern {\"slug\":\"{$patternSlug}\"} /-->";
            $blocks[] = $patternBlock;

            if ($index < count($patterns) - 1) {
                $blocks[] = "\n";
            }
        }

        return implode("\n", $blocks);
    }
}
