<?php

namespace App\Mcp\Tools;

use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateLeadStageTool extends Tool
{
    protected string $name = 'update-lead-stage';

    protected string $title = 'Update Lead Stage';

    protected string $description = 'Move a lead to a different stage in the pipeline.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:leads,id',
            'stage' => 'required|in:new,qualified,proposal,negotiation,won,lost',
        ]);

        $lead = Lead::findOrFail($request->get('id'));
        $oldStage = $lead->stage;
        $newStage = $request->get('stage');

        $lead->stage = $newStage;

        if ($newStage === 'won') {
            $lead->converted_at = now();
        }

        $maxPosition = Lead::where('stage', $newStage)->max('position') ?? 0;
        $lead->position = $maxPosition + 1;

        $lead->save();

        return Response::structured([
            'id' => $lead->id,
            'company_name' => $lead->company_name,
            'old_stage' => $oldStage,
            'new_stage' => $lead->stage,
            'message' => "Lead '{$lead->company_name}' moved from {$oldStage} to {$newStage}.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Lead ID'),
            'stage' => $schema->string()
                ->required()
                ->enum(['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])
                ->description('New pipeline stage'),
        ];
    }
}
