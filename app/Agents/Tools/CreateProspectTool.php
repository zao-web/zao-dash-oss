<?php

namespace App\Agents\Tools;

use App\Models\Prospect;

/**
 * Create a new prospect from research.
 */
class CreateProspectTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Prospect';
    }

    public function description(): string
    {
        return 'Create a new prospect with company and contact information. Automatically calculates ICP score if an ICP is specified.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'company_name' => [
                    'type' => 'string',
                    'description' => 'Company name (required)',
                ],
                'company_website' => [
                    'type' => 'string',
                    'description' => 'Company website URL',
                ],
                'company_linkedin' => [
                    'type' => 'string',
                    'description' => 'Company LinkedIn URL',
                ],
                'industry' => [
                    'type' => 'string',
                    'description' => 'Industry category',
                ],
                'company_size' => [
                    'type' => 'string',
                    'description' => 'Company size range (e.g., "10-50", "51-200")',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Company location',
                ],
                'contact_name' => [
                    'type' => 'string',
                    'description' => 'Primary contact name',
                ],
                'contact_title' => [
                    'type' => 'string',
                    'description' => 'Contact job title',
                ],
                'contact_email' => [
                    'type' => 'string',
                    'description' => 'Contact email address',
                ],
                'contact_linkedin' => [
                    'type' => 'string',
                    'description' => 'Contact LinkedIn URL',
                ],
                'tech_stack' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Technologies used by the company',
                ],
                'signals' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Buying signals identified (e.g., "recently_funded", "hiring")',
                ],
                'research_notes' => [
                    'type' => 'string',
                    'description' => 'Additional research notes',
                ],
                'icp_id' => [
                    'type' => 'integer',
                    'description' => 'Ideal Customer Profile to match against',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'Where this prospect was found (e.g., "linkedin", "web_search")',
                ],
                'source_url' => [
                    'type' => 'string',
                    'description' => 'URL where prospect was found',
                ],
            ],
            'required' => ['company_name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'company_website' => 'nullable|url|max:500',
            'company_linkedin' => 'nullable|url|max:500',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_title' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_linkedin' => 'nullable|url|max:500',
            'tech_stack' => 'nullable|array',
            'signals' => 'nullable|array',
            'research_notes' => 'nullable|string|max:2000',
            'icp_id' => 'nullable|integer|exists:ideal_customer_profiles,id',
            'source' => 'nullable|string|max:100',
            'source_url' => 'nullable|url|max:500',
        ];
    }

    public function execute(array $params): array
    {
        // Check for duplicates
        $existing = Prospect::where('company_name', $params['company_name'])
            ->orWhere('company_website', $params['company_website'] ?? null)
            ->first();

        if ($existing) {
            return [
                'success' => false,
                'error' => 'duplicate_prospect',
                'message' => "Prospect already exists: {$existing->company_name}",
                'existing_prospect_id' => $existing->id,
            ];
        }

        // Prepare research notes
        $notes = [];
        if (! empty($params['research_notes'])) {
            $notes[] = [
                'date' => now()->toDateString(),
                'note' => $params['research_notes'],
                'source' => 'agent_research',
            ];
        }

        $prospect = Prospect::create([
            'company_name' => $params['company_name'],
            'company_website' => $params['company_website'] ?? null,
            'company_linkedin' => $params['company_linkedin'] ?? null,
            'industry' => $params['industry'] ?? null,
            'company_size' => $params['company_size'] ?? null,
            'location' => $params['location'] ?? null,
            'contact_name' => $params['contact_name'] ?? null,
            'contact_title' => $params['contact_title'] ?? null,
            'contact_email' => $params['contact_email'] ?? null,
            'contact_linkedin' => $params['contact_linkedin'] ?? null,
            'tech_stack' => $params['tech_stack'] ?? [],
            'signals' => $params['signals'] ?? [],
            'research_notes' => $notes,
            'icp_id' => $params['icp_id'] ?? null,
            'source' => $params['source'] ?? 'agent_research',
            'source_url' => $params['source_url'] ?? null,
            'status' => 'new',
        ]);

        // Calculate ICP score if ICP assigned
        $scoreResult = null;
        if ($prospect->icp_id) {
            $scoreResult = $prospect->calculateScore();
        }

        return [
            'success' => true,
            'prospect' => [
                'id' => $prospect->id,
                'company_name' => $prospect->company_name,
                'contact_name' => $prospect->contact_name,
                'contact_email' => $prospect->contact_email,
                'icp_score' => $prospect->icp_score,
                'qualification_status' => $prospect->qualification_status,
                'status' => $prospect->status,
            ],
            'score_result' => $scoreResult,
        ];
    }
}
