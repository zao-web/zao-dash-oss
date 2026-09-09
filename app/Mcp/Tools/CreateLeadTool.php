<?php

namespace App\Mcp\Tools;

use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateLeadTool extends Tool
{
    protected string $name = 'create-lead';

    protected string $title = 'Create Lead';

    protected string $description = 'Create a new lead in the sales pipeline.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'source' => 'nullable|string',
            'deal_value' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $validated['stage'] = 'new';
        $maxPosition = Lead::where('stage', 'new')->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;

        $lead = Lead::create($validated);

        return Response::structured([
            'id' => $lead->id,
            'company_name' => $lead->company_name,
            'stage' => $lead->stage,
            'deal_value' => $lead->deal_value,
            'message' => "Lead '{$lead->company_name}' created successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_name' => $schema->string()->required()->description('Company name'),
            'contact_name' => $schema->string()->description('Primary contact name'),
            'contact_email' => $schema->string()->format('email')->description('Contact email'),
            'website' => $schema->string()->format('uri')->description('Company website'),
            'description' => $schema->string()->description('Notes about the lead'),
            'source' => $schema->string()->description('Lead source (e.g., referral, website, cold outreach)'),
            'deal_value' => $schema->number()->description('Potential deal value'),
            'probability' => $schema->integer()->description('Win probability (0-100)'),
            'expected_close_date' => $schema->string()->format('date')->description('Expected close date'),
            'assignee_id' => $schema->integer()->description('User ID to assign lead to'),
        ];
    }
}
