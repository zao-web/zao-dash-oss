<?php

namespace App\Mcp\Tools;

use App\Models\RfpOpportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateRfpOpportunityTool extends Tool
{
    protected string $name = 'create-rfp-opportunity';

    protected string $title = 'Create RFP Opportunity';

    protected string $description = 'Create a new RFP opportunity in the pipeline.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'issuing_organization' => 'required|string|max:255',
            'description' => 'nullable|string',
            'source_type' => 'nullable|string|in:email_teaser,sam_gov,rfp_board,web_scrape,manual',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'submission_deadline' => 'nullable|date',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ]);

        $validated['source_type'] = $validated['source_type'] ?? 'manual';
        $validated['slug'] = Str::slug($validated['title']).'-'.Str::random(6);

        $opportunity = RfpOpportunity::create($validated);

        return Response::structured([
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'issuing_organization' => $opportunity->issuing_organization,
            'status' => $opportunity->status,
            'budget_range' => $opportunity->budgetRange(),
            'message' => "RFP opportunity '{$opportunity->title}' created successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('RFP title'),
            'issuing_organization' => $schema->string()->required()->description('Organization issuing the RFP'),
            'description' => $schema->string()->description('Description of the opportunity'),
            'source_type' => $schema->string()
                ->enum(['email_teaser', 'sam_gov', 'rfp_board', 'web_scrape', 'manual'])
                ->description('How this opportunity was discovered'),
            'budget_min' => $schema->number()->description('Minimum budget amount'),
            'budget_max' => $schema->number()->description('Maximum budget amount'),
            'submission_deadline' => $schema->string()->format('date')->description('Submission deadline'),
            'contact_name' => $schema->string()->description('Primary contact name'),
            'contact_email' => $schema->string()->format('email')->description('Contact email'),
            'priority' => $schema->string()
                ->enum(['low', 'medium', 'high', 'critical'])
                ->description('Priority level'),
        ];
    }
}
