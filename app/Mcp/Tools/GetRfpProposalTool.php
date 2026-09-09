<?php

namespace App\Mcp\Tools;

use App\Models\RfpProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRfpProposalTool extends Tool
{
    protected string $name = 'get-rfp-proposal';

    protected string $title = 'Get RFP Proposal';

    protected string $description = 'Get the full content of an RFP proposal including all sections, pricing, and requirement responses. Use this to review a proposal before revision or submission.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'rfp_opportunity_id' => 'required_without:proposal_id|nullable|integer',
            'proposal_id' => 'required_without:rfp_opportunity_id|nullable|integer',
            'version' => 'nullable|integer',
        ]);

        if (! empty($validated['proposal_id'])) {
            $proposal = RfpProposal::with('opportunity')->find($validated['proposal_id']);
        } else {
            $query = RfpProposal::with('opportunity')
                ->where('rfp_opportunity_id', $validated['rfp_opportunity_id']);

            if (! empty($validated['version'])) {
                $query->where('version', $validated['version']);
            } else {
                $query->orderByDesc('version');
            }

            $proposal = $query->first();
        }

        if (! $proposal) {
            return Response::structured(['error' => 'Proposal not found.']);
        }

        $opportunity = $proposal->opportunity;

        return Response::structured([
            'proposal_id' => $proposal->id,
            'rfp_opportunity_id' => $proposal->rfp_opportunity_id,
            'version' => $proposal->version,
            'status' => $proposal->status,
            'opportunity' => [
                'title' => $opportunity?->title,
                'organization' => $opportunity?->issuing_organization,
                'budget_range' => $opportunity?->budgetRange(),
                'submission_deadline' => $opportunity?->submission_deadline?->format('Y-m-d'),
                'contact_name' => $opportunity?->contact_name,
                'contact_email' => $opportunity?->contact_email,
            ],
            'executive_summary' => $proposal->executive_summary,
            'full_content' => $proposal->full_content,
            'sections' => $proposal->proposal_sections,
            'pricing_breakdown' => $proposal->pricing_breakdown,
            'total_price' => $proposal->total_price,
            'requirement_responses' => $proposal->requirement_responses,
            'case_studies_used' => $proposal->case_studies_used,
            'testimonials_used' => $proposal->testimonials_used,
            'tone_profile' => $proposal->tone_profile,
            'review_notes' => $proposal->review_notes,
            'debate_metadata' => $proposal->research_context['debate_rounds'] ?? null
                ? [
                    'rounds' => $proposal->research_context['debate_rounds'],
                    'providers' => $proposal->research_context['providers_used'] ?? [],
                    'confidence' => $proposal->research_context['debate_confidence'] ?? null,
                ]
                : null,
            'pdf_url' => $proposal->pdf_path
                ? config('app.url')."/rfp/{$opportunity?->slug}/proposals/{$proposal->id}/pdf"
                : null,
            'corporate_pdf_url' => config('app.url')."/rfp/{$opportunity?->slug}/proposals/{$proposal->id}/pdf/corporate",
            'google_doc_url' => $proposal->google_drive_id
                ? "https://docs.google.com/document/d/{$proposal->google_drive_id}/edit"
                : null,
            'review_url' => config('app.url')."/rfp/{$opportunity?->slug}/proposals/{$proposal->id}/review",
            'created_at' => $proposal->created_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rfp_opportunity_id' => $schema->integer()->description('RFP opportunity ID — returns latest proposal for this opportunity'),
            'proposal_id' => $schema->integer()->description('Specific proposal ID (overrides rfp_opportunity_id)'),
            'version' => $schema->integer()->description('Specific version number (defaults to latest)'),
        ];
    }
}
