<?php

namespace App\Services\Rfp;

use App\Models\Document;
use App\Models\Project;
use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Services\AI\ClaudeCliService;
use App\Services\AI\MultiModelConsortium;
use App\Services\Google\DriveService;
use Illuminate\Support\Facades\Log;

class RfpProposalService
{
    public function __construct(
        protected ClaudeCliService $claude,
        protected DriveService $driveService,
        protected MultiModelConsortium $consortium,
    ) {}

    /**
     * Generate a full proposal for an RFP opportunity.
     */
    public function generateProposal(RfpOpportunity $opportunity, array $options = []): RfpProposal
    {
        $onStage = $options['on_stage'] ?? null;

        if ($onStage) {
            $onStage('gathering_context', 'Gathering project history and references...');
        }

        $context = $this->buildProposalContext($opportunity);

        $model = 'sonnet';
        if (isset($options['model_override'])) {
            $model = $options['model_override'];
        }

        $useDebate = in_array('gemma4', $this->consortium->getAvailableProviders(), true)
            && in_array('llama', $this->consortium->getAvailableProviders(), true);

        if ($onStage) {
            $message = $useDebate
                ? 'Writing proposal with multi-model debate (Gemma 4 → Llama critic → Gemma 4 revision → Claude judge)...'
                : "Generating proposal with Claude ({$model})...";
            $onStage('generating_content', $message);
        }

        $content = $this->generateContent($opportunity, $context, $model);

        if ($onStage) {
            $onStage('saving_proposal', 'Saving proposal and creating PDF...');
        }

        $version = RfpProposal::where('rfp_opportunity_id', $opportunity->id)->max('version') + 1;

        $proposal = RfpProposal::create([
            'rfp_opportunity_id' => $opportunity->id,
            'version' => $version ?: 1,
            'title' => "Proposal: {$opportunity->title}",
            'executive_summary' => $content['executive_summary'] ?? null,
            'full_content' => $content['full_content'] ?? null,
            'proposal_sections' => $content['sections'] ?? null,
            'pricing_breakdown' => $content['pricing'] ?? null,
            'total_price' => $content['total_price'] ?? null,
            'case_studies_used' => $content['case_studies_used'] ?? null,
            'testimonials_used' => $content['testimonials_used'] ?? null,
            'past_projects_cited' => $content['past_projects_cited'] ?? null,
            'tone_profile' => $content['tone_profile'] ?? 'professional',
            'research_context' => $content['research_context'] ?? null,
            'requirement_responses' => $content['requirement_responses'] ?? null,
            'status' => 'draft',
        ]);

        Log::info('RfpProposalService: proposal generated', [
            'proposal_id' => $proposal->id,
            'opportunity_id' => $opportunity->id,
            'version' => $proposal->version,
            'model' => $model,
            'sections_count' => count($content['sections'] ?? []),
            'total_price' => $content['total_price'] ?? null,
        ]);

        return $proposal;
    }

    /**
     * Build context from past projects, documents, and agency capabilities.
     *
     * @return array{relevant_projects: array, reference_documents: array, agency_capabilities: array}
     */
    protected function buildProposalContext(RfpOpportunity $opportunity): array
    {
        $industry = $opportunity->organization_industry;
        $techReqs = $opportunity->tech_requirements ?? [];

        // Try industry-matched completed projects first
        $relevantProjects = Project::query()
            ->with('client')
            ->where('status', 'completed')
            ->when($industry, fn ($q) => $q->whereHas('client', fn ($cq) => $cq->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($industry).'%'])
            ))
            ->limit(5)
            ->get();

        // If no industry match, get any completed projects
        if ($relevantProjects->isEmpty()) {
            $relevantProjects = Project::query()
                ->with('client')
                ->where('status', 'completed')
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get();
        }

        $driveDocuments = Document::query()
            ->whereIn('document_type', ['proposal', 'sow'])
            ->whereNotNull('client_id')
            ->orderBy('google_modified_at', 'desc')
            ->limit(5)
            ->get();

        // Fetch real portfolio/case studies from the website
        $websitePortfolio = $this->fetchWebsitePortfolio();

        return [
            'relevant_projects' => $relevantProjects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client' => $p->client?->name,
                'description' => $p->description,
                'industry' => $p->client?->description,
            ])->toArray(),
            'reference_documents' => $driveDocuments->map(fn (Document $d) => [
                'id' => $d->id,
                'filename' => $d->filename,
                'type' => $d->document_type,
                'client' => $d->extracted_client_name,
                'excerpt' => $d->content_excerpt,
            ])->toArray(),
            'website_portfolio' => $websitePortfolio,
            'agency_capabilities' => $this->getAgencyCapabilities(),
        ];
    }

    /**
     * Fetch case studies and blog posts from example.com WordPress REST API.
     *
     * @return array{case_studies: array, blog_posts: array}
     */
    protected function fetchWebsitePortfolio(): array
    {
        $portfolio = ['case_studies' => [], 'blog_posts' => []];

        try {
            // Fetch case studies
            $caseStudies = \Illuminate\Support\Facades\Http::timeout(10)
                ->get('https://example.com/wp-json/wp/v2/case-studies', [
                    'per_page' => 20,
                    '_fields' => 'id,title,excerpt,link,acf,content',
                ]);

            if ($caseStudies->successful()) {
                foreach ($caseStudies->json() as $study) {
                    $portfolio['case_studies'][] = [
                        'title' => strip_tags($study['title']['rendered'] ?? ''),
                        'url' => $study['link'] ?? '',
                        'excerpt' => strip_tags($study['excerpt']['rendered'] ?? ''),
                        'content_summary' => substr(strip_tags($study['content']['rendered'] ?? ''), 0, 500),
                        'acf' => $study['acf'] ?? [],
                    ];
                }
            }

            // Fetch recent blog posts for additional context
            $posts = \Illuminate\Support\Facades\Http::timeout(10)
                ->get('https://example.com/wp-json/wp/v2/posts', [
                    'per_page' => 10,
                    '_fields' => 'id,title,excerpt,link',
                ]);

            if ($posts->successful()) {
                foreach ($posts->json() as $post) {
                    $portfolio['blog_posts'][] = [
                        'title' => strip_tags($post['title']['rendered'] ?? ''),
                        'url' => $post['link'] ?? '',
                        'excerpt' => strip_tags($post['excerpt']['rendered'] ?? ''),
                    ];
                }
            }

            Log::info('RfpProposalService: fetched website portfolio', [
                'case_studies' => count($portfolio['case_studies']),
                'blog_posts' => count($portfolio['blog_posts']),
            ]);
        } catch (\Exception $e) {
            Log::warning('RfpProposalService: failed to fetch website portfolio', [
                'error' => $e->getMessage(),
            ]);
        }

        return $portfolio;
    }

    /**
     * Generate proposal content via Claude.
     *
     * @return array<string, mixed>
     */
    protected function generateContent(RfpOpportunity $opportunity, array $context, string $model): array
    {
        $rfpDetails = [
            'title' => $opportunity->title,
            'organization' => $opportunity->issuing_organization,
            'industry' => $opportunity->organization_industry,
            'description' => $opportunity->description,
            'budget_range' => $opportunity->budgetRange(),
            'budget_line_items' => $opportunity->budget_line_items,
            'requirements' => $opportunity->requirements_summary,
            'tech_requirements' => $opportunity->tech_requirements,
            'evaluation_criteria' => $opportunity->evaluation_criteria,
            'timeline' => $opportunity->timeline_requirements,
            'deadline' => $opportunity->submission_deadline?->format('Y-m-d'),
        ];

        // Inject active learning insights into context so the proposal reflects past feedback
        $insights = RfpLearningInsight::query()
            ->where('is_active', true)
            ->whereIn('impact_area', ['content', 'pricing', 'presentation'])
            ->orderByDesc('confidence')
            ->limit(10)
            ->get(['insight_type', 'title', 'actionable_recommendation']);

        if ($insights->isNotEmpty()) {
            $context['learning_insights'] = $insights->map(fn ($i) => [
                'type' => $i->insight_type,
                'insight' => $i->title,
                'recommendation' => $i->actionable_recommendation,
            ])->toArray();
        }

        // Use multi-model debate when Gemma 4 + Llama are configured
        if ($this->consortium->getAvailableProviders() !== [] &&
            in_array('gemma4', $this->consortium->getAvailableProviders(), true) &&
            in_array('llama', $this->consortium->getAvailableProviders(), true)) {
            return $this->generateContentWithDebate($opportunity, $rfpDetails, $context);
        }

        return $this->generateContentWithClaude($opportunity, $rfpDetails, $context, $model);
    }

    /**
     * Generate proposal prose via 4-round debate, then extract structured JSON via Claude.
     */
    protected function generateContentWithDebate(RfpOpportunity $opportunity, array $rfpDetails, array $context): array
    {
        $proseSystemPrompt = <<<'PROMPT'
You are an expert proposal writer for Zao (https://example.com), a web development agency specializing in WordPress, Laravel, and AI-powered solutions.

Write a complete, persuasive RFP proposal as flowing prose (not JSON). Include the Executive Summary as the FIRST section and ONLY there — subsequent extraction will separate it from the rest of the document, so do not restate the executive summary later in the proposal. Sections:
1. Executive Summary — lead with their problem and how Zao solves it uniquely
2. Understanding of Requirements — demonstrate you read every requirement carefully
3. Proposed Solution — detailed approach, methodology, and technical stack
4. Team & Experience — relevant past work (ONLY from provided context)
5. Pricing — clear breakdown with rationale
6. Timeline — realistic phased schedule
7. Why Zao — differentiated value, not generic agency boilerplate

CRITICAL: Only reference projects/clients explicitly listed in the context. Never fabricate metrics or testimonials.
PROMPT;

        $prosePrompt = "Write a complete RFP proposal for the following opportunity.\n\n".
            "RFP Details:\n".json_encode($rfpDetails, JSON_PRETTY_PRINT).
            "\n\nContext (past work, documents, capabilities):\n".json_encode($context, JSON_PRETTY_PRINT);

        Log::info('RfpProposalService: generating proposal via multi-model debate', [
            'opportunity_id' => $opportunity->id,
        ]);

        $debateResult = $this->consortium->generateWithDebate($prosePrompt, $proseSystemPrompt);
        $proposalProse = $debateResult->content;

        Log::info('RfpProposalService: debate complete, extracting JSON structure', [
            'opportunity_id' => $opportunity->id,
            'confidence' => $debateResult->confidence,
            'providers_used' => $debateResult->providers,
        ]);

        // Extract structured JSON from the final polished prose
        $extractionPrompt = "Extract the following from this proposal and return as JSON:\n\n".
            $this->buildGenerationPrompt().
            "\n\nProposal text:\n".$proposalProse;

        $result = $this->claude->messageJson($extractionPrompt, null, 'sonnet', 300);

        if (! $result || empty($result['full_content']) && empty($result['sections'])) {
            // Fallback: wrap the prose directly
            return [
                'executive_summary' => mb_substr($proposalProse, 0, 1000),
                'full_content' => $proposalProse,
                'sections' => [['title' => 'Proposal', 'content' => $proposalProse]],
                'pricing' => [],
                'total_price' => null,
                'requirement_responses' => [],
                'research_context' => ['debate_confidence' => $debateResult->confidence],
            ];
        }

        $existingContext = $result['research_context'] ?? [];
        if (is_string($existingContext)) {
            $existingContext = json_decode($existingContext, true) ?? [];
        }

        $result['research_context'] = array_merge($existingContext, [
            'debate_rounds' => 4,
            'providers_used' => $debateResult->providers,
            'debate_confidence' => $debateResult->confidence,
        ]);

        return $result;
    }

    /**
     * Generate proposal directly via Claude (fallback when consortium isn't fully configured).
     */
    protected function generateContentWithClaude(RfpOpportunity $opportunity, array $rfpDetails, array $context, string $model): array
    {
        $systemPrompt = $this->buildGenerationPrompt();

        $prompt = "Generate a comprehensive RFP proposal.\n\n".
            "RFP Details:\n".json_encode($rfpDetails, JSON_PRETTY_PRINT).
            "\n\nContext:\n".json_encode($context, JSON_PRETTY_PRINT);

        Log::info('RfpProposalService: calling Claude for proposal generation', [
            'opportunity_id' => $opportunity->id,
            'model' => $model,
            'prompt_length' => strlen($prompt),
        ]);

        $result = $this->claude->messageJson($prompt, $systemPrompt, $model, 600);

        if (! $result || ! is_array($result)) {
            Log::error('RfpProposalService: Claude returned null or invalid response', [
                'opportunity_id' => $opportunity->id,
                'result_type' => gettype($result),
            ]);

            throw new \RuntimeException('Proposal generation failed: Claude returned no usable content. Check ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN.');
        }

        if (empty($result['full_content']) && empty($result['sections'])) {
            Log::error('RfpProposalService: Claude returned empty proposal', [
                'opportunity_id' => $opportunity->id,
                'result_keys' => array_keys($result),
            ]);

            throw new \RuntimeException('Proposal generation returned empty content.');
        }

        return $result;
    }

    /**
     * Build the system prompt for proposal generation.
     */
    protected function buildGenerationPrompt(): string
    {
        return <<<'PROMPT'
You are an expert proposal writer for Zao (https://example.com), a web development agency.
Generate a complete, submission-ready RFP proposal in JSON format.

CRITICAL RULES — ABSOLUTELY NO FABRICATION:
- You may ONLY reference projects, clients, case studies, and testimonials that are
  explicitly provided in the "Context" section below. Do NOT invent projects, client names,
  metrics, testimonials, or case studies.
- If no relevant past projects are provided, focus on capabilities, approach, and methodology
  instead of case studies. Say "our experience includes" rather than naming fake clients.
- If a metric or result is not in the provided data, do NOT fabricate numbers.
  Use qualitative descriptions instead (e.g., "significant improvement" not "47% increase").
- Every claim must be grounded in the provided context or be a general capability statement.

Return a JSON object with these fields:
- "executive_summary": 2-3 paragraph executive summary leading with their problem
- "full_content": The complete proposal as formatted markdown — MUST NOT repeat the executive_summary. Start with "## Understanding of Requirements" or the first non-summary section. The exec summary is rendered separately by the PDF/web view.
- "sections": Array of {title, content} for each proposal section — MUST NOT include an "Executive Summary" section. First section should be "Understanding of Requirements" or similar.
- "pricing": Array of {item, description, quantity, unit_price, total} line items
- "total_price": Numeric total price
- "requirement_responses": Array of {requirement, response} mapping each RFP requirement to our response
- "case_studies_used": Array of project names/IDs referenced (ONLY from provided context)
- "testimonials_used": Array of any testimonials used (ONLY from provided context)
- "past_projects_cited": Array of {project_id, project_name, relevance} (ONLY from provided context)
- "tone_profile": "professional" or "consultative" or "technical"
- "research_context": Summary of organizational research used

PRICING RULES (critical - do not generate $0 values):
- Every line item in "pricing" MUST have a non-zero unit_price and total
- Price within the stated budget range (budget_min to budget_max)
- If no budget range is stated, estimate based on scope ($30K-$150K typical for Zao)
- Break into phases: Discovery, Design, Development, QA, Launch, Training, etc.
- Each item: {item, description, quantity (number), unit_price (number > 0), total (number > 0)}
- total_price MUST equal sum of line item totals and MUST be greater than 0
- If budget_line_items are provided, align pricing to those categories

Guidelines:
- Lead with THEIR needs, not our capabilities
- Mirror the RFP's language and terminology
- Include concrete timelines with milestones
- Every RFP requirement must be explicitly addressed
- Professional tone - not salesy, not generic
- Our website is https://example.com - use this for any agency URL references, never use any other domain
- If relevant past projects exist in context, use 2-3 as case studies
- If no past projects match, omit case studies section entirely - do NOT fabricate them
PROMPT;
    }

    /**
     * Get Zao's agency capabilities for context.
     *
     * @return array{core_services: array, technologies: array, specialties: array, industries: array}
     */
    protected function getAgencyCapabilities(): array
    {
        return [
            'core_services' => ['WordPress Development', 'Laravel Applications', 'Custom CMS', 'E-Commerce', 'Web Applications', 'Digital Strategy'],
            'technologies' => ['WordPress', 'Laravel', 'PHP', 'Vue.js', 'React', 'Inertia.js', 'Tailwind CSS', 'WooCommerce', 'Shopify'],
            'specialties' => ['Enterprise WordPress', 'Headless CMS', 'AI Integration', 'SEO Architecture', 'Accessibility (WCAG)', 'Performance Optimization', 'Migration (Joomla, Drupal, etc.)'],
            'industries' => ['Tourism & Destination Marketing', 'Government & Municipal', 'Healthcare', 'Higher Education', 'Nonprofit', 'SaaS'],
        ];
    }

    /**
     * Prepare a Gmail draft for proposal submission.
     *
     * @return array{to: string, subject: string, body: string, proposal_id: int}|null
     */
    public function prepareSubmissionDraft(RfpProposal $proposal): ?array
    {
        $opportunity = $proposal->opportunity;

        if (! $opportunity->submission_email) {
            return null;
        }

        $subject = "Proposal: {$opportunity->title} — Zao";
        $body = "Dear {$opportunity->contact_name},\n\n".
            "Please find attached our proposal for {$opportunity->title}.\n\n".
            "{$proposal->executive_summary}\n\n".
            "We would welcome the opportunity to discuss our approach in more detail.\n\n".
            "Best regards,\nOwner User\nZao\nhttps://example.com";

        return [
            'to' => $opportunity->submission_email,
            'subject' => $subject,
            'body' => $body,
            'proposal_id' => $proposal->id,
        ];
    }
}
