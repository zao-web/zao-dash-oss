<?php

namespace App\Mcp\Tools;

use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListLeadsTool extends Tool
{
    protected string $name = 'list-leads';

    protected string $title = 'List Leads';

    protected string $description = 'List all leads in the sales pipeline with optional filtering by stage.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $stage = $request->get('stage');
        $limit = $request->get('limit', 50);

        $query = Lead::with('assignee')->orderBy('stage')->orderBy('position');

        if ($stage && $stage !== 'all') {
            $query->where('stage', $stage);
        }

        $leads = $query->limit($limit)->get()->map(fn ($lead) => [
            'id' => $lead->id,
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'contact_email' => $lead->contact_email,
            'stage' => $lead->stage,
            'source' => $lead->source,
            'deal_value' => $lead->deal_value,
            'probability' => $lead->probability,
            'weighted_value' => ($lead->deal_value ?? 0) * (($lead->probability ?? 0) / 100),
            'expected_close_date' => $lead->expected_close_date?->format('Y-m-d'),
            'assignee' => $lead->assignee?->name,
            'last_contacted_at' => $lead->last_contacted_at?->diffForHumans(),
            'tags' => $lead->tags,
        ]);

        $pipelineValue = $leads->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->sum('deal_value');
        $weightedValue = $leads->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->sum('weighted_value');

        return Response::structured([
            'leads' => $leads,
            'total' => $leads->count(),
            'pipeline_value' => $pipelineValue,
            'weighted_value' => round($weightedValue, 2),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()
                ->enum(['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost', 'all'])
                ->description('Filter by pipeline stage'),
            'limit' => $schema->integer()->description('Maximum leads to return (default: 50)'),
        ];
    }
}
