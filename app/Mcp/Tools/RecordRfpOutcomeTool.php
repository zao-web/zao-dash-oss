<?php

namespace App\Mcp\Tools;

use App\Models\RfpOpportunity;
use App\Models\RfpOutcome;
use App\Services\Rfp\RfpLearningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RecordRfpOutcomeTool extends Tool
{
    protected string $name = 'record-rfp-outcome';

    protected string $title = 'Record RFP Outcome';

    protected string $description = 'Record the win or loss outcome for a submitted RFP proposal. This feeds into the learning layer to improve future proposals and targeting.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'rfp_opportunity_id' => 'required|integer',
            'result' => 'required|string|in:won,lost,no_decision,withdrawn',
            'feedback' => 'nullable|string',
            'awarded_amount' => 'nullable|numeric',
            'competitor_name' => 'nullable|string',
            'loss_reason' => 'nullable|string',
            'would_bid_again' => 'nullable|boolean',
            'score_received' => 'nullable|numeric|min:0|max:100',
        ]);

        $opportunity = RfpOpportunity::find($validated['rfp_opportunity_id']);

        if (! $opportunity) {
            return Response::structured(['error' => 'RFP opportunity not found.']);
        }

        $statusMap = [
            'won' => 'won',
            'lost' => 'lost',
            'no_decision' => 'submitted',
            'withdrawn' => 'declined',
        ];

        $outcome = RfpOutcome::updateOrCreate(
            ['rfp_opportunity_id' => $opportunity->id],
            [
                'result' => $validated['result'],
                'feedback_text' => $validated['feedback'] ?? null,
                'awarded_amount' => $validated['awarded_amount'] ?? null,
                'competitor_info' => $validated['competitor_name']
                    ? ['name' => $validated['competitor_name']]
                    : null,
                'loss_factors' => $validated['loss_reason']
                    ? [$validated['loss_reason']]
                    : null,
                'organization_would_bid_again' => $validated['would_bid_again'] ?? null,
                'score_received' => $validated['score_received'] ?? null,
            ]
        );

        $opportunity->update(['status' => $statusMap[$validated['result']] ?? $opportunity->status]);

        // Trigger learning analysis
        try {
            app(RfpLearningService::class)->recordOutcome($opportunity, $outcome);
        } catch (\Exception $e) {
            // Non-critical — outcome recorded, learning analysis can run later
        }

        return Response::structured([
            'outcome_id' => $outcome->id,
            'rfp_opportunity_id' => $opportunity->id,
            'result' => $validated['result'],
            'opportunity_status' => $opportunity->fresh()->status,
            'message' => "Outcome recorded as '{$validated['result']}'. Learning insights will be generated.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rfp_opportunity_id' => $schema->integer()->required()->description('RFP opportunity ID'),
            'result' => $schema->string()->required()
                ->enum(['won', 'lost', 'no_decision', 'withdrawn'])
                ->description('The outcome: won (we got the contract), lost (another vendor won), no_decision (not yet decided), withdrawn (we pulled out)'),
            'feedback' => $schema->string()->description('Any feedback received from the organization'),
            'awarded_amount' => $schema->number()->description('Contract value if won'),
            'competitor_name' => $schema->string()->description('Name of winning competitor if lost'),
            'loss_reason' => $schema->string()->description('Primary reason we lost (e.g. "price too high", "no local presence")'),
            'would_bid_again' => $schema->boolean()->description('Whether we would bid on similar opportunities from this org again'),
            'score_received' => $schema->number()->description('Score or ranking we received in evaluation if provided'),
        ];
    }
}
