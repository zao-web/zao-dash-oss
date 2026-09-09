<?php

namespace App\Services\Rfp;

use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Services\AI\ClaudeCliService;
use App\Services\AI\MultiModelConsortium;
use Illuminate\Support\Facades\Log;

/**
 * Adversarial reviewer for draft RFP proposals.
 *
 * Reads the original RFP + draft proposal, returns structured findings keyed
 * by severity. Blocker findings trigger an automatic revision pass via the
 * MultiModelConsortium debate; warnings are surfaced to the user without
 * forcing a revision.
 */
class RfpProposalCritic
{
    public function __construct(
        protected ClaudeCliService $claude,
        protected MultiModelConsortium $consortium,
    ) {}

    /**
     * @return array{findings: array<int, array{severity: string, category: string, detail: string}>, blocker_count: int, warning_count: int, summary: string}
     */
    public function critique(RfpProposal $proposal, RfpOpportunity $opportunity): array
    {
        $prompt = $this->buildCritiquePrompt($proposal, $opportunity);

        $result = $this->claude->messageJson($prompt, $this->systemPrompt(), 'sonnet', 200);

        if (! $result || ! isset($result['findings'])) {
            Log::warning('RfpProposalCritic: critique returned no findings', [
                'proposal_id' => $proposal->id,
            ]);

            return $this->emptyResult('Critique returned no usable findings.');
        }

        $findings = collect($result['findings'])->map(fn ($f) => [
            'severity' => $f['severity'] ?? 'warning',
            'category' => $f['category'] ?? 'general',
            'detail' => $f['detail'] ?? '',
        ])->all();

        $blockerCount = collect($findings)->where('severity', 'blocker')->count();
        $warningCount = collect($findings)->where('severity', 'warning')->count();

        Log::info('RfpProposalCritic: critique complete', [
            'proposal_id' => $proposal->id,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
        ]);

        return [
            'findings' => $findings,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'summary' => $result['summary'] ?? 'Critique complete.',
        ];
    }

    /**
     * Apply critique findings as a revision pass. Uses the multi-model
     * consortium debate when available; falls back to a single Claude pass.
     *
     * Returns the revised proposal (the same model, updated in place).
     */
    public function revise(RfpProposal $proposal, RfpOpportunity $opportunity, array $critique): RfpProposal
    {
        $findings = $critique['findings'] ?? [];
        if (empty($findings)) {
            return $proposal;
        }

        $prompt = $this->buildRevisionPrompt($proposal, $opportunity, $findings);

        $useDebate = in_array('gemma4', $this->consortium->getAvailableProviders(), true)
            && in_array('llama', $this->consortium->getAvailableProviders(), true);

        if ($useDebate) {
            $debate = $this->consortium->generateWithDebate($prompt, $this->revisionSystemPrompt());
            $extractionPrompt = "Extract the revised proposal into the structured JSON format below:\n\n".
                $this->revisionJsonSchema()."\n\nProposal text:\n".$debate->content;
            $revised = $this->claude->messageJson($extractionPrompt, null, 'sonnet', 300);
        } else {
            $revised = $this->claude->messageJson($prompt, $this->revisionSystemPrompt().$this->revisionJsonSchema(), 'sonnet', 400);
        }

        if (! $revised || ! is_array($revised)) {
            Log::warning('RfpProposalCritic: revision returned no usable output', [
                'proposal_id' => $proposal->id,
            ]);

            return $proposal;
        }

        $proposal->update(array_filter([
            'executive_summary' => $revised['executive_summary'] ?? null,
            'full_content' => $revised['full_content'] ?? null,
            'proposal_sections' => $revised['sections'] ?? null,
            'pricing_breakdown' => $revised['pricing'] ?? null,
            'total_price' => $revised['total_price'] ?? null,
            'requirement_responses' => $revised['requirement_responses'] ?? null,
        ], fn ($v) => $v !== null));

        Log::info('RfpProposalCritic: revision applied', [
            'proposal_id' => $proposal->id,
            'findings_addressed' => count($findings),
        ]);

        return $proposal->refresh();
    }

    private function buildCritiquePrompt(RfpProposal $proposal, RfpOpportunity $opportunity): string
    {
        $rfpDetails = json_encode([
            'title' => $opportunity->title,
            'organization' => $opportunity->issuing_organization,
            'requirements' => $opportunity->requirements_summary,
            'tech_requirements' => $opportunity->tech_requirements,
            'evaluation_criteria' => $opportunity->evaluation_criteria,
            'budget_range' => $opportunity->budgetRange(),
            'deadline' => $opportunity->submission_deadline?->format('Y-m-d'),
        ], JSON_PRETTY_PRINT);

        $draftPayload = json_encode([
            'executive_summary' => $proposal->executive_summary,
            'sections' => $proposal->proposal_sections,
            'full_content_preview' => mb_substr($proposal->full_content ?? '', 0, 4000),
            'pricing' => $proposal->pricing_breakdown,
            'total_price' => $proposal->total_price,
            'requirement_responses' => $proposal->requirement_responses,
            'case_studies_used' => $proposal->case_studies_used,
            'testimonials_used' => $proposal->testimonials_used,
        ], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are reviewing a Zao agency proposal draft. Find issues that would embarrass us or hurt our win odds.

## Original RFP
{$rfpDetails}

## Draft Proposal
{$draftPayload}

Return a JSON object with this shape:
{
  "findings": [
    {"severity": "blocker"|"warning", "category": "duplicate"|"missing_requirement"|"hallucination"|"weak_claim"|"tone"|"pricing"|"scope_drift"|"generic", "detail": "specific, actionable description with quote when possible"}
  ],
  "summary": "one-sentence overall assessment"
}

Severities:
- "blocker": the proposal cannot ship as-is (duplicate sections, fabricated case study, RFP requirement unaddressed, \$0 pricing, sales-y boilerplate where they asked for technical detail)
- "warning": worth noting but doesn't block submission (could be sharper, minor tone issue, generic phrasing)

Be specific. Quote the offending text. If something is good, omit it — only report problems.
PROMPT;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an adversarial proposal reviewer for Zao, a web development agency
focused on tourism boards (DMOs), municipalities, and mission-driven orgs.
Your job is to find anything that would make Justin (owner) embarrassed to send.

============================================================
ZAO PROPOSAL RUBRIC — what makes a proposal embarrassing
============================================================

SUBSTANCE & TRUTHFULNESS
- Any specific metric ("47% lift", "3.2M annual visitors", "Sub-1-second LCP")
  that doesn't appear verbatim in the provided case studies, portfolio data,
  or research_context. Flag as `hallucination` severity blocker.
- Client names, project names, or testimonials not present in the context.
  Even a paraphrased "we worked with a major DMO" implies a real client and
  is a hallucination if no DMO is in the relevant_projects array.
- Capability claims outside Zao's stack: WordPress, Laravel, Vue, Inertia,
  Tailwind, WooCommerce, headless CMS, AI integration, SEO architecture,
  WCAG accessibility, performance optimization, migrations. Anything else
  (Salesforce dev, native mobile, Sitecore, AEM, Drupal expert) is a flag
  unless it's framed honestly as "we'd partner for that."
- Hedging language about whether we can deliver ("we hope to", "should be
  able to", "we believe we can") — proposals should commit or scope-out, not
  waffle. Flag as `weak_claim`.

TONE & VOICE
- Agency-speak: "synergize", "best-in-class", "world-class", "cutting-edge",
  "robust solution", "leverage our expertise", "passionate about excellence",
  "end-to-end", "thought leader". Each occurrence is a `tone` warning.
- Executive summary that opens with Zao instead of the buyer's problem.
  Bad: "Zao is a leading agency that...". Good: "The Travel Oregon site has
  not been rebuilt since 2017, and your team needs..."
- Generic openers that could apply to any RFP ("Thank you for the opportunity
  to submit this proposal. We are excited to..."). Flag as `generic`.
- Sales tone in places the RFP asked for technical depth — e.g. the buyer
  asked "describe your QA approach" and the response is "we are committed
  to quality at every stage." Flag as `weak_claim` blocker.

SPECIFICITY TO THIS RFP
- Doesn't reference the issuing organization by name in at least the exec
  summary and one section heading or opening sentence.
- Doesn't quote or paraphrase the RFP's actual language back to the buyer.
  If the RFP says "responsive mobile-first design with full WCAG 2.1 AA
  compliance," the proposal should use those exact phrases somewhere.
- Case study selection mismatched to the RFP's industry — pitching a SaaS
  case study to a tourism RFP, or an e-commerce case to a municipal RFP.
  Flag as `scope_drift`.
- No acknowledgment of the stated submission deadline or evaluation criteria
  when the RFP provided them.

PRICING DISCIPLINE
- Line items without a one-line rationale describing what's included.
- Line items priced in "ranges" or "TBD" — every item must have a fixed
  unit_price and total > 0. Flag as `pricing` blocker.
- Pricing total significantly outside the stated budget range (>20% over,
  >40% under) without explicit acknowledgment of why.
- Missing the assumptions/exclusions section — every fixed-price proposal
  needs explicit "this assumes X" and "this does not include Y."
- Hourly rates appearing without a corresponding hours-per-phase estimate.

WEB-DEV-AGENCY SPECIFICS
- Public-sector or government RFP without explicit WCAG 2.1 AA compliance
  language. For DMOs and municipalities, accessibility is table-stakes and
  unstated equals unaddressed. Flag as `missing_requirement` blocker.
- Promises specific Lighthouse / Core Web Vitals numbers without methodology
  or caveats ("we'll achieve LCP < 2.5s" — under what conditions?).
- CMS migrations without a content audit / mapping step in the plan.
- No mention of training, documentation, or handoff for the client team
  on a build that's clearly going to be maintained in-house.
- Hosting/maintenance scope ambiguous: who hosts post-launch, what's the
  support window, what happens after that.
- No security/backup/uptime stance on a build that handles user data or
  public-facing critical infrastructure.

STRUCTURAL
- Sections out of expected order (pricing before approach, team before
  understanding-of-requirements). Flag as `tone` warning.
- Wall-of-text sections with no headings, bullets, or visual structure for
  scannable reading. Procurement reviewers skim.
- Timeline with phases but no milestone dates or duration estimates.

============================================================
Universal blockers (always flag):
- Duplicate sections (e.g. Executive Summary appearing twice in any form)
- A stated RFP requirement with no corresponding response
- Pricing line item with $0, null, or missing total
- Reference to a project/client/metric/testimonial not present in provided context
- Wrong issuing organization name anywhere in the proposal
- Proposal opens with "Dear [Client Name]" or any unfilled template token

Output ONLY the JSON object specified by the user prompt. No commentary.
PROMPT;
    }

    private function buildRevisionPrompt(RfpProposal $proposal, RfpOpportunity $opportunity, array $findings): string
    {
        $findingsText = collect($findings)
            ->map(fn ($f) => "- [{$f['severity']}] {$f['category']}: {$f['detail']}")
            ->implode("\n");

        $rfpDetails = json_encode([
            'title' => $opportunity->title,
            'organization' => $opportunity->issuing_organization,
            'requirements' => $opportunity->requirements_summary,
            'tech_requirements' => $opportunity->tech_requirements,
            'budget_range' => $opportunity->budgetRange(),
        ], JSON_PRETTY_PRINT);

        $draft = json_encode([
            'executive_summary' => $proposal->executive_summary,
            'sections' => $proposal->proposal_sections,
            'pricing' => $proposal->pricing_breakdown,
            'requirement_responses' => $proposal->requirement_responses,
        ], JSON_PRETTY_PRINT);

        return <<<PROMPT
Revise this Zao proposal draft. Address every critique finding.

## RFP
{$rfpDetails}

## Current Draft
{$draft}

## Findings to address
{$findingsText}

Output the revised proposal. Do not reference the critique process.
PROMPT;
    }

    private function revisionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are revising an RFP proposal to address specific critique findings.
Preserve the proposal's overall structure and tone. Fix only what the
critique identifies. Do not introduce new fabricated facts.
PROMPT;
    }

    private function revisionJsonSchema(): string
    {
        return <<<'PROMPT'

Return JSON with:
- "executive_summary": revised exec summary (string)
- "full_content": full proposal markdown — MUST NOT repeat the executive_summary
- "sections": array of {title, content} — MUST NOT include an "Executive Summary" section
- "pricing": array of {item, description, quantity, unit_price, total} with non-zero values
- "total_price": numeric sum
- "requirement_responses": array of {requirement, response} covering every RFP requirement
PROMPT;
    }

    private function emptyResult(string $summary): array
    {
        return [
            'findings' => [],
            'blocker_count' => 0,
            'warning_count' => 0,
            'summary' => $summary,
        ];
    }
}
