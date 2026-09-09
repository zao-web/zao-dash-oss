<?php

namespace App\Mcp\Tools;

use App\Jobs\EvaluateRfpJob;
use App\Models\RfpOpportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerRfpEvaluationTool extends Tool
{
    protected string $name = 'trigger-rfp-evaluation';

    protected string $title = 'Trigger RFP Evaluation';

    protected string $description = 'Evaluate one or all discovered RFP opportunities. Scores each against the agency rubric (tech stack, budget, industry, timeline, win probability) and automatically qualifies high-fit opportunities, declines low-fit ones, and flags medium-fit ones for manual review. Qualified opportunities automatically trigger proposal generation.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'rfp_opportunity_id' => 'nullable|integer',
        ]);

        if (! empty($validated['rfp_opportunity_id'])) {
            $opportunity = RfpOpportunity::find($validated['rfp_opportunity_id']);

            if (! $opportunity) {
                return Response::structured(['error' => 'Opportunity not found.']);
            }

            EvaluateRfpJob::dispatch($opportunity->id);

            return Response::structured([
                'message' => "Evaluation queued for '{$opportunity->title}'.",
                'opportunity_id' => $opportunity->id,
                'current_status' => $opportunity->status,
            ]);
        }

        // Batch: evaluate all discovered opportunities
        $count = RfpOpportunity::where('status', 'discovered')->count();

        EvaluateRfpJob::dispatch();

        return Response::structured([
            'message' => "Batch evaluation queued for {$count} discovered opportunities. Qualified ones will auto-trigger proposal generation. You'll receive Slack notifications as results come in.",
            'opportunities_queued' => $count,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rfp_opportunity_id' => $schema->integer()->description('Evaluate a single opportunity by ID. Omit to batch-evaluate all discovered opportunities.'),
        ];
    }
}
