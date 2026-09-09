<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfpProposal extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'proposal_sections' => 'array',
        'pricing_breakdown' => 'array',
        'case_studies_used' => 'array',
        'testimonials_used' => 'array',
        'past_projects_cited' => 'array',
        'research_context' => 'array',
        'requirement_responses' => 'array',
        'critique_findings' => 'array',
        'critique_revised' => 'boolean',
        'total_price' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(RfpOpportunity::class, 'rfp_opportunity_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Sections with any leading "Executive Summary" entry removed.
     *
     * The proposal generator returns `executive_summary` as its own field AND
     * (historically) sometimes echoes it as the first section. PDFs and the
     * review surface should render the summary once. See: RFP_PIPELINE docs.
     *
     * @return array<int, array{title: string, content: string}>
     */
    public function renderableSections(): array
    {
        $sections = $this->proposal_sections ?? [];

        if (empty($sections) || ! $this->executive_summary) {
            return $sections;
        }

        return array_values(array_filter(
            $sections,
            fn (array $section) => ! $this->looksLikeExecutiveSummaryTitle($section['title'] ?? '')
        ));
    }

    /**
     * `full_content` markdown with a leading Executive Summary block stripped.
     *
     * Strips a leading `# Executive Summary` / `## Executive Summary` heading
     * and everything up to the next top-level heading. If no exec summary
     * heading is present, returns the content unchanged.
     */
    public function renderableFullContent(): ?string
    {
        if (! $this->full_content || ! $this->executive_summary) {
            return $this->full_content;
        }

        $pattern = '/\A\s*#{1,3}\s*executive\s+summary[^\n]*\n.*?(?=\n#{1,3}\s+|\z)/is';

        $stripped = preg_replace($pattern, '', $this->full_content);

        return ltrim($stripped ?? $this->full_content);
    }

    private function looksLikeExecutiveSummaryTitle(string $title): bool
    {
        return strtolower(trim($title)) === 'executive summary';
    }
}
