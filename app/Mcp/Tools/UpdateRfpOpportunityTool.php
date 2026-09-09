<?php

namespace App\Mcp\Tools;

use App\Models\RfpOpportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateRfpOpportunityTool extends Tool
{
    protected string $name = 'update-rfp-opportunity';

    protected string $title = 'Update RFP Opportunity';

    protected string $description = 'Update an existing RFP opportunity (status, priority, details, etc.).';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:rfp_opportunities,id',
            'title' => 'nullable|string|max:255',
            'issuing_organization' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:discovered,evaluating,qualified,pursuing,proposal_drafting,proposal_review,submitted,won,lost',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'submission_deadline' => 'nullable|date',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'decline_reason' => 'nullable|string|max:255',
            'fit_score' => 'nullable|integer|min:0|max:100',
        ]);

        $opportunity = RfpOpportunity::findOrFail($validated['id']);

        $updateData = collect($validated)
            ->except('id')
            ->filter(fn ($value) => $value !== null)
            ->toArray();

        $opportunity->update($updateData);

        return Response::structured([
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'status' => $opportunity->status,
            'priority' => $opportunity->priority,
            'fit_score' => $opportunity->fit_score,
            'message' => "RFP opportunity '{$opportunity->title}' updated successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('RFP opportunity ID'),
            'title' => $schema->string()->description('Updated title'),
            'issuing_organization' => $schema->string()->description('Updated organization name'),
            'description' => $schema->string()->description('Updated description'),
            'status' => $schema->string()
                ->enum(['discovered', 'evaluating', 'qualified', 'pursuing', 'proposal_drafting', 'proposal_review', 'submitted', 'won', 'lost'])
                ->description('Pipeline status'),
            'priority' => $schema->string()
                ->enum(['low', 'medium', 'high', 'critical'])
                ->description('Priority level'),
            'budget_min' => $schema->number()->description('Minimum budget'),
            'budget_max' => $schema->number()->description('Maximum budget'),
            'submission_deadline' => $schema->string()->format('date')->description('Submission deadline'),
            'contact_name' => $schema->string()->description('Contact name'),
            'contact_email' => $schema->string()->format('email')->description('Contact email'),
            'decline_reason' => $schema->string()->description('Reason for declining (when marking as lost)'),
            'fit_score' => $schema->integer()->description('Fit score (0-100)'),
        ];
    }
}
