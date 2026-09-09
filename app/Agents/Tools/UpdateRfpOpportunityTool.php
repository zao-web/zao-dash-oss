<?php

namespace App\Agents\Tools;

use App\Models\RfpOpportunity;

/**
 * Update an existing RFP opportunity in the pipeline.
 */
class UpdateRfpOpportunityTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Update RFP Opportunity';
    }

    public function description(): string
    {
        return 'Update an RFP opportunity status, fit score, priority, or other fields. Use after evaluating an opportunity or receiving new information.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'ID of the RFP opportunity to update',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['discovered', 'evaluating', 'pursuing', 'proposal_draft', 'proposal_review', 'submitted', 'won', 'lost', 'declined', 'expired'],
                    'description' => 'New pipeline status',
                ],
                'fit_score' => [
                    'type' => 'integer',
                    'description' => 'Fit score (0-100) based on evaluation',
                ],
                'fit_score_breakdown' => [
                    'type' => 'object',
                    'description' => 'Breakdown of fit score by category (e.g., {"tech_match": 80, "budget_fit": 70, "timeline": 60})',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'urgent'],
                    'description' => 'Priority level',
                ],
                'tech_requirements' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Updated technology requirements list',
                ],
                'requirements_summary' => [
                    'type' => 'object',
                    'description' => 'Structured summary of RFP requirements',
                ],
                'decline_reason' => [
                    'type' => 'string',
                    'description' => 'Reason for declining (when status is set to declined)',
                ],
            ],
            'required' => ['id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'id' => 'required|integer|exists:rfp_opportunities,id',
            'status' => 'nullable|in:discovered,evaluating,pursuing,proposal_draft,proposal_review,submitted,won,lost,declined,expired',
            'fit_score' => 'nullable|integer|min:0|max:100',
            'fit_score_breakdown' => 'nullable|array',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'tech_requirements' => 'nullable|array',
            'requirements_summary' => 'nullable|array',
            'decline_reason' => 'nullable|string|max:1000',
        ];
    }

    public function execute(array $params): array
    {
        $opportunity = RfpOpportunity::find($params['id']);

        if (! $opportunity) {
            return [
                'success' => false,
                'error' => 'RFP opportunity not found',
            ];
        }

        $updates = [];
        $updatableFields = [
            'status',
            'fit_score',
            'fit_score_breakdown',
            'priority',
            'tech_requirements',
            'requirements_summary',
            'decline_reason',
        ];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $params)) {
                $updates[$field] = $params[$field];
            }
        }

        if (empty($updates)) {
            return [
                'success' => false,
                'error' => 'No updates provided',
            ];
        }

        $opportunity->update($updates);
        $opportunity->refresh();

        return [
            'success' => true,
            'message' => "Updated RFP opportunity: {$opportunity->title}",
            'opportunity' => [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'issuing_organization' => $opportunity->issuing_organization,
                'status' => $opportunity->status,
                'fit_score' => $opportunity->fit_score,
                'priority' => $opportunity->priority,
                'budget_range' => $opportunity->budgetRange(),
                'tech_requirements' => $opportunity->tech_requirements,
                'decline_reason' => $opportunity->decline_reason,
                'updated_at' => $opportunity->updated_at->toDateTimeString(),
            ],
        ];
    }
}
