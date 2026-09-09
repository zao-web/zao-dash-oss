<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateRfpProposalJob;
use App\Models\RfpOpportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateRfpProposalTool extends Tool
{
    protected string $name = 'create-rfp-proposal';

    protected string $title = 'Create RFP Proposal';

    protected string $description = 'Create or trigger generation of a proposal for an RFP opportunity. Dispatches an AI-powered proposal generation job.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $opportunityId = $request->get('rfp_opportunity_id');
        $autoGenerate = $request->get('auto_generate', true);

        $opportunity = RfpOpportunity::find($opportunityId);

        if (! $opportunity) {
            return Response::structured([
                'error' => "RFP opportunity with ID {$opportunityId} not found.",
            ]);
        }

        if ($opportunity->isExpired()) {
            return Response::structured([
                'error' => "RFP opportunity '{$opportunity->title}' has an expired deadline and cannot accept proposals.",
            ]);
        }

        if ($autoGenerate) {
            if (! in_array($opportunity->status, ['qualified', 'pursuing', 'proposal_drafting'])) {
                $opportunity->update(['status' => 'pursuing']);
            }

            GenerateRfpProposalJob::dispatch($opportunity->id);

            return Response::structured([
                'message' => "Proposal generation started for '{$opportunity->title}'. The job has been queued and will generate a comprehensive proposal.",
                'opportunity_id' => $opportunity->id,
                'opportunity_title' => $opportunity->title,
                'status' => $opportunity->fresh()->status,
                'queue' => 'agents',
            ]);
        }

        return Response::structured([
            'message' => "RFP opportunity '{$opportunity->title}' is ready for manual proposal creation.",
            'opportunity_id' => $opportunity->id,
            'opportunity_title' => $opportunity->title,
            'status' => $opportunity->status,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rfp_opportunity_id' => $schema->integer()->required()->description('The ID of the RFP opportunity to generate a proposal for'),
            'auto_generate' => $schema->boolean()->description('Whether to auto-generate the proposal via AI (default: true)'),
        ];
    }
}
