<?php

namespace App\Mcp\Tools;

use App\Models\RfpLearningInsight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateRfpLearningInsightTool extends Tool
{
    protected string $name = 'create-rfp-learning-insight';

    protected string $title = 'Create RFP Learning Insight';

    protected string $description = 'Create a new learning insight from RFP outcome analysis.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'insight_type' => 'required|string|in:win_pattern,loss_pattern,pricing_insight,industry_trend,content_improvement',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'impact_area' => 'required|string|in:pricing,content,targeting,process,presentation',
            'confidence' => 'required|numeric|min:0|max:1',
            'evidence' => 'nullable|array',
            'actionable_recommendation' => 'nullable|string',
        ]);

        $insight = RfpLearningInsight::create([
            'insight_type' => $validated['insight_type'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'impact_area' => $validated['impact_area'],
            'confidence' => $validated['confidence'],
            'evidence' => $validated['evidence'] ?? [],
            'actionable_recommendation' => $validated['actionable_recommendation'] ?? null,
            'is_active' => true,
        ]);

        return Response::structured([
            'id' => $insight->id,
            'insight_type' => $insight->insight_type,
            'title' => $insight->title,
            'impact_area' => $insight->impact_area,
            'confidence' => (float) $insight->confidence,
            'message' => "Learning insight '{$insight->title}' created successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'insight_type' => $schema->string()
                ->required()
                ->enum(['win_pattern', 'loss_pattern', 'pricing_insight', 'industry_trend', 'content_improvement'])
                ->description('Type of insight'),
            'title' => $schema->string()->required()->description('Short title for the insight'),
            'description' => $schema->string()->required()->description('Detailed description of the insight'),
            'impact_area' => $schema->string()
                ->required()
                ->enum(['pricing', 'content', 'targeting', 'process', 'presentation'])
                ->description('Area of proposal process this insight impacts'),
            'confidence' => $schema->number()->required()->description('Confidence score from 0.0 to 1.0'),
            'evidence' => $schema->array()->description('Array of evidence items supporting this insight'),
            'actionable_recommendation' => $schema->string()->description('Specific actionable recommendation based on this insight'),
        ];
    }
}
