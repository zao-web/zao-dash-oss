<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\ContentValidatorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ValidateSeoContentTool extends Tool
{
    protected string $name = 'validate-seo-content';

    protected string $title = 'Validate SEO Content';

    protected string $description = 'Validate SEO content against playbook-specific rules. Checks word count, AI patterns, meta tags, links, headings, and more.';

    public function __construct(
        private ContentValidatorService $validator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page_id' => 'nullable|exists:seo_pages,id',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'playbook' => 'nullable|string',
        ]);

        // If page_id is provided, load the page content
        if ($pageId = $validated['page_id'] ?? null) {
            $page = SeoPage::findOrFail($pageId);
            $content = [
                'content' => $page->content ?? '',
                'meta_title' => $page->meta_title ?? '',
                'meta_description' => $page->meta_description ?? '',
            ];
            $playbook = $page->playbook;
        } else {
            $content = [
                'content' => $validated['content'] ?? '',
                'meta_title' => $validated['meta_title'] ?? '',
                'meta_description' => $validated['meta_description'] ?? '',
            ];
            $playbook = $validated['playbook'] ?? 'Persona';

            if (empty($content['content'])) {
                return Response::error('Either page_id or content must be provided.');
            }
        }

        $result = $this->validator->validate($content, $playbook);

        return Response::structured([
            'valid' => $result['valid'],
            'score' => $result['score'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'metadata' => $result['metadata'],
            'playbook' => $playbook,
            'message' => $result['valid']
                ? "Content validation passed with score {$result['score']}/100"
                : 'Content validation failed with '.count($result['errors']).' errors',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->description('SEO page ID to validate (alternative to providing content directly)'),
            'content' => $schema->string()->description('HTML content to validate (if not using page_id)'),
            'meta_title' => $schema->string()->description('Meta title to validate'),
            'meta_description' => $schema->string()->description('Meta description to validate'),
            'playbook' => $schema->string()->enum([
                'Templates', 'Tools', 'Curation', 'Rankings', 'Converters', 'Calculators',
                'Comparisons', 'Examples', 'Galleries', 'Location', 'Persona', 'Vertical',
                'Integration', 'Glossary', 'Educational', 'Translations', 'Directory',
                'Listings', 'Case Study', 'Profile',
            ])->description('Playbook to validate against (required if not using page_id)'),
        ];
    }
}
