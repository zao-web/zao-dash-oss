<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\SchemaGeneratorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GenerateSeoSchemaTool extends Tool
{
    protected string $name = 'generate-seo-schema';

    protected string $title = 'Generate SEO Schema';

    protected string $description = 'Generate JSON-LD structured data schema for an SEO page based on its playbook type.';

    public function __construct(
        private SchemaGeneratorService $schemaGenerator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page_id' => 'required|exists:seo_pages,id',
            'include_organization' => 'nullable|boolean',
            'as_script_tag' => 'nullable|boolean',
        ]);

        $page = SeoPage::findOrFail($validated['page_id']);
        $includeOrganization = $validated['include_organization'] ?? true;
        $asScriptTag = $validated['as_script_tag'] ?? false;

        if ($includeOrganization) {
            $schema = $this->schemaGenerator->generateForPage($page);
        } else {
            $pageSchema = $this->schemaGenerator->getSchemaForPlaybook($page);
            $schema = $pageSchema ? ['@context' => 'https://schema.org', ...$pageSchema] : null;
        }

        if (! $schema) {
            return Response::error("Could not generate schema for page with playbook '{$page->playbook}'");
        }

        $output = $asScriptTag
            ? $this->schemaGenerator->toScriptTag($schema)
            : json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return Response::structured([
            'page_id' => $page->id,
            'page_title' => $page->meta_title,
            'playbook' => $page->playbook,
            'schema_type' => $schema['@type'] ?? ($schema['@graph'][1]['@type'] ?? 'Unknown'),
            'schema' => $asScriptTag ? null : $schema,
            'script_tag' => $asScriptTag ? $output : null,
            'message' => "Generated {$page->playbook} schema for '{$page->meta_title}'",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->required()->description('SEO page ID to generate schema for'),
            'include_organization' => $schema->boolean()->description('Include organization schema in @graph (default: true)'),
            'as_script_tag' => $schema->boolean()->description('Return as HTML script tag instead of JSON (default: false)'),
        ];
    }
}
