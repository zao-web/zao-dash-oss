<?php

namespace App\Mcp\Tools;

use App\Models\RfpProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateRfpProposalTool extends Tool
{
    protected string $name = 'update-rfp-proposal';

    protected string $title = 'Update RFP Proposal';

    protected string $description = 'Update the content of an RFP proposal. Can update the executive summary, full content, specific named sections, pricing, requirement responses, or add review notes. Use get-rfp-proposal first to see the current content.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'proposal_id' => 'required|integer',
            'executive_summary' => 'nullable|string',
            'full_content' => 'nullable|string',
            'section_title' => 'nullable|string',
            'section_content' => 'nullable|string|required_with:section_title',
            'pricing_breakdown' => 'nullable|array',
            'total_price' => 'nullable|numeric',
            'requirement_responses' => 'nullable|array',
            'review_notes' => 'nullable|string',
            'tone_profile' => 'nullable|string|in:professional,casual,technical,persuasive,formal',
        ]);

        $proposal = RfpProposal::find($validated['proposal_id']);

        if (! $proposal) {
            return Response::structured(['error' => 'Proposal not found.']);
        }

        $updates = [];
        $changes = [];

        if (isset($validated['executive_summary'])) {
            $updates['executive_summary'] = $validated['executive_summary'];
            $changes[] = 'executive_summary';
        }

        if (isset($validated['full_content'])) {
            $updates['full_content'] = $validated['full_content'];
            $changes[] = 'full_content';
        }

        if (isset($validated['section_title']) && isset($validated['section_content'])) {
            $sections = $proposal->proposal_sections ?? [];
            $found = false;
            foreach ($sections as &$section) {
                if (strtolower($section['title'] ?? '') === strtolower($validated['section_title'])) {
                    $section['content'] = $validated['section_content'];
                    $found = true;
                    break;
                }
            }
            unset($section);
            if (! $found) {
                $sections[] = ['title' => $validated['section_title'], 'content' => $validated['section_content']];
            }
            $updates['proposal_sections'] = $sections;
            $changes[] = "section:{$validated['section_title']}";
        }

        if (isset($validated['pricing_breakdown'])) {
            $updates['pricing_breakdown'] = $validated['pricing_breakdown'];
            $changes[] = 'pricing_breakdown';
        }

        if (isset($validated['total_price'])) {
            $updates['total_price'] = $validated['total_price'];
            $changes[] = 'total_price';
        }

        if (isset($validated['requirement_responses'])) {
            $updates['requirement_responses'] = $validated['requirement_responses'];
            $changes[] = 'requirement_responses';
        }

        if (isset($validated['review_notes'])) {
            $updates['review_notes'] = $validated['review_notes'];
            $updates['reviewed_at'] = now();
            $changes[] = 'review_notes';
        }

        if (isset($validated['tone_profile'])) {
            $updates['tone_profile'] = $validated['tone_profile'];
            $changes[] = 'tone_profile';
        }

        if (empty($updates)) {
            return Response::structured(['error' => 'No fields provided to update.']);
        }

        // Clear cached PDF when content changes
        if (array_intersect($changes, ['executive_summary', 'full_content', 'pricing_breakdown', 'requirement_responses']) !== []) {
            $updates['pdf_path'] = null;
        }

        $proposal->update($updates);

        return Response::structured([
            'proposal_id' => $proposal->id,
            'updated_fields' => $changes,
            'pdf_cleared' => ($updates['pdf_path'] ?? null) === null && isset($updates['pdf_path']),
            'message' => 'Proposal updated. PDF cache cleared — next download will regenerate.',
            'review_url' => config('app.url')."/rfp/{$proposal->opportunity->slug}/proposals/{$proposal->id}/review",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->integer()->required()->description('Proposal ID to update (get from get-rfp-proposal)'),
            'executive_summary' => $schema->string()->description('Replace the executive summary text'),
            'full_content' => $schema->string()->description('Replace the full proposal body (markdown)'),
            'section_title' => $schema->string()->description('Name of a specific section to update (e.g. "Proposed Solution")'),
            'section_content' => $schema->string()->description('New content for the named section'),
            'pricing_breakdown' => $schema->array()->description('Replace pricing line items: [{item, description, total}]'),
            'total_price' => $schema->number()->description('Update the total price'),
            'requirement_responses' => $schema->array()->description('Replace requirement responses: [{requirement, response, met}]'),
            'review_notes' => $schema->string()->description('Add internal review notes (not shown in PDF)'),
            'tone_profile' => $schema->string()->enum(['professional', 'casual', 'technical', 'persuasive', 'formal'])->description('Update the tone profile'),
        ];
    }
}
