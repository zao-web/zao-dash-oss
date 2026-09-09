<?php

namespace App\Mcp\Tools;

use App\Models\SeoPage;
use App\Services\Seo\SeoQualityGateService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ValidateSeoQualityGateTool extends Tool
{
    protected string $name = 'validate-seo-quality-gate';

    protected string $title = 'Validate SEO Quality Gate';

    protected string $description = 'Run quality gate validation on an SEO page to determine if it meets publishing requirements. Checks word count, humanization score, internal links, schema, proprietary data, meta tags, featured image, and CTA.';

    public function __construct(
        private SeoQualityGateService $qualityGateService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'seo_page_id' => 'required|exists:seo_pages,id',
        ]);

        $page = SeoPage::findOrFail($validated['seo_page_id']);

        // Run quality gate validation
        $result = $this->qualityGateService->validate($page);
        $canPublish = $this->qualityGateService->canPublish($page);

        // Format checks for readability
        $checksSummary = [];
        foreach ($result['checks'] as $checkName => $check) {
            $checksSummary[$checkName] = [
                'passed' => $check['passed'],
                'message' => $check['message'],
                'weight' => $check['weight'],
            ];
        }

        return Response::structured([
            'seo_page_id' => $page->id,
            'title' => $page->title,
            'playbook' => $page->playbook,
            'status' => $page->status->value ?? $page->status,
            'can_publish' => $canPublish,
            'score' => $result['score'],
            'max_score' => $result['max_score'],
            'checks' => $checksSummary,
            'summary' => [
                'passed' => collect($result['checks'])->where('passed', true)->count(),
                'failed' => collect($result['checks'])->where('passed', false)->count(),
                'total' => count($result['checks']),
            ],
            'message' => $canPublish
                ? "Page '{$page->title}' passes all quality gates and is ready to publish (score: {$result['score']}/{$result['max_score']})"
                : "Page '{$page->title}' does not meet quality requirements (score: {$result['score']}/{$result['max_score']})",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_page_id' => $schema->integer()->required()->description('ID of the SEO page to validate'),
        ];
    }
}
