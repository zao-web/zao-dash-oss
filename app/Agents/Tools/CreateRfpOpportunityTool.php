<?php

namespace App\Agents\Tools;

use App\Services\Rfp\RfpDiscoveryService;

/**
 * Create a new RFP opportunity in the pipeline.
 */
class CreateRfpOpportunityTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create RFP Opportunity';
    }

    public function description(): string
    {
        return 'Create a new RFP/bid opportunity. Checks for duplicates before creating. Use this when you discover a new opportunity from email teasers or other sources.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Opportunity title (organization + project name)',
                ],
                'issuing_organization' => [
                    'type' => 'string',
                    'description' => 'The company, government agency, or entity issuing the RFP',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'What they need - brief summary of the work',
                ],
                'source_type' => [
                    'type' => 'string',
                    'enum' => ['email_teaser', 'sam_gov', 'rfp_board', 'web_scrape', 'manual'],
                    'description' => 'How the opportunity was discovered',
                ],
                'budget_min' => [
                    'type' => 'number',
                    'description' => 'Minimum budget estimate in dollars',
                ],
                'budget_max' => [
                    'type' => 'number',
                    'description' => 'Maximum budget estimate in dollars',
                ],
                'submission_deadline' => [
                    'type' => 'string',
                    'description' => 'Submission deadline in YYYY-MM-DD format',
                ],
                'contact_name' => [
                    'type' => 'string',
                    'description' => 'Point of contact name',
                ],
                'contact_email' => [
                    'type' => 'string',
                    'description' => 'Point of contact email',
                ],
                'tech_requirements' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Technology requirements or keywords (WordPress, CMS, CRM, etc.)',
                ],
                'source_url' => [
                    'type' => 'string',
                    'description' => 'URL where the opportunity was found',
                ],
            ],
            'required' => ['title', 'issuing_organization'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'title' => 'required|string|max:500',
            'issuing_organization' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'source_type' => 'nullable|in:email_teaser,sam_gov,rfp_board,web_scrape,manual',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'submission_deadline' => 'nullable|date',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'tech_requirements' => 'nullable|array',
            'source_url' => 'nullable|url|max:500',
        ];
    }

    public function execute(array $params): array
    {
        $discoveryService = app(RfpDiscoveryService::class);

        // Check for duplicates
        if ($discoveryService->isDuplicate($params['title'], $params['issuing_organization'])) {
            return [
                'success' => false,
                'error' => 'duplicate_opportunity',
                'message' => "An opportunity with a similar title and organization already exists: {$params['title']} ({$params['issuing_organization']})",
            ];
        }

        $opportunity = $discoveryService->createFromTeaser(
            [
                'title' => $params['title'],
                'organization' => $params['issuing_organization'],
                'description' => $params['description'] ?? null,
                'budget_min' => $params['budget_min'] ?? null,
                'budget_max' => $params['budget_max'] ?? null,
                'url' => $params['source_url'] ?? null,
                'tech_keywords' => $params['tech_requirements'] ?? [],
                'submission_deadline' => $params['submission_deadline'] ?? null,
                'contact_name' => $params['contact_name'] ?? null,
                'contact_email' => $params['contact_email'] ?? null,
            ],
            $params['source_type'] ?? 'manual',
        );

        return [
            'success' => true,
            'message' => "Created RFP opportunity: {$opportunity->title}",
            'opportunity' => [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'slug' => $opportunity->slug,
                'issuing_organization' => $opportunity->issuing_organization,
                'status' => $opportunity->status,
                'budget_range' => $opportunity->budgetRange(),
                'source_type' => $opportunity->source_type,
                'created_at' => $opportunity->created_at->toDateTimeString(),
            ],
        ];
    }
}
