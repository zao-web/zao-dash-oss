<?php

namespace App\Mcp\Tools;

use App\Models\RfpLearningInsight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRfpLearningInsightsTool extends Tool
{
    protected string $name = 'get-rfp-learning-insights';

    protected string $title = 'Get RFP Learning Insights';

    protected string $description = 'Get active learning insights from past RFP outcomes. Useful for improving proposal generation.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $impactArea = $request->get('impact_area');
        $limit = $request->get('limit', 10);

        $query = RfpLearningInsight::active()
            ->orderBy('confidence', 'desc');

        if ($impactArea) {
            $query->byImpactArea($impactArea);
        }

        $insights = $query->limit($limit)->get()->map(fn (RfpLearningInsight $insight) => [
            'id' => $insight->id,
            'insight_type' => $insight->insight_type,
            'title' => $insight->title,
            'description' => $insight->description,
            'confidence' => (float) $insight->confidence,
            'impact_area' => $insight->impact_area,
            'actionable_recommendation' => $insight->actionable_recommendation,
            'evidence' => $insight->evidence,
            'applied_to_proposals' => $insight->applied_to_proposals,
            'created_at' => $insight->created_at->toDateTimeString(),
            'updated_at' => $insight->updated_at->toDateTimeString(),
        ]);

        return Response::structured([
            'insights' => $insights,
            'total' => $insights->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'impact_area' => $schema->string()
                ->enum(['pricing', 'content', 'targeting', 'process', 'presentation'])
                ->description('Filter by impact area'),
            'limit' => $schema->integer()->description('Maximum results to return (default: 10)'),
        ];
    }
}
