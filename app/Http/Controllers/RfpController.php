<?php

namespace App\Http\Controllers;

use App\Jobs\EvaluateRfpJob;
use App\Jobs\GenerateRfpProposalJob;
use App\Mail\RfpProposalMail;
use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use App\Models\RfpOutcome;
use App\Models\RfpProposal;
use App\Models\RfpSource;
use App\Models\User;
use App\Services\Pdf\TailwindPdf;
use App\Services\Rfp\RfpDiscoveryService;
use App\Services\Rfp\RfpLearningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RfpController extends Controller
{
    public function index()
    {
        $opportunities = RfpOpportunity::with('assignee')
            ->orderBy('status')
            ->orderBy('fit_score', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $activeStatuses = ['discovered', 'evaluating', 'qualified', 'pursuing', 'proposal_drafting', 'proposal_review', 'submitted'];

        return Inertia::render('Rfp/Index', [
            'opportunities' => $opportunities->map(fn (RfpOpportunity $o) => [
                'id' => $o->id,
                'title' => $o->title,
                'slug' => $o->slug,
                'issuing_organization' => $o->issuing_organization,
                'description' => $o->description,
                'source_type' => $o->source_type,
                'budget_min' => $o->budget_min,
                'budget_max' => $o->budget_max,
                'budget_range' => $o->budgetRange(),
                'submission_deadline' => $o->submission_deadline?->toIso8601String(),
                'submission_deadline_display' => $o->submission_deadline?->format('M d, Y'),
                'contact_name' => $o->contact_name,
                'contact_email' => $o->contact_email,
                'status' => $o->status,
                'priority' => $o->priority,
                'fit_score' => $o->fit_score,
                'decline_reason' => $o->decline_reason,
                'assignee' => $o->assignee ? ['id' => $o->assignee->id, 'name' => $o->assignee->name] : null,
                'is_expired' => $o->isExpired(),
                'has_document' => (bool) ($o->full_document_path || $o->full_document_url),
                'has_requirements' => ! empty($o->requirements_summary),
                'tags' => $o->tags,
                'created_at' => $o->created_at->diffForHumans(),
            ]),
            'stats' => [
                'total_active' => $opportunities->whereIn('status', $activeStatuses)->count(),
                'proposals_in_progress' => $opportunities->whereIn('status', ['proposal_drafting', 'proposal_review'])->count(),
                'avg_fit_score' => $opportunities->whereIn('status', $activeStatuses)->avg('fit_score') ? round($opportunities->whereIn('status', $activeStatuses)->avg('fit_score')) : 0,
                'submitted_this_month' => $opportunities->where('status', 'submitted')->filter(fn ($o) => $o->updated_at?->isCurrentMonth())->count(),
                'win_rate' => $opportunities->whereIn('status', ['won', 'lost'])->count() > 0
                    ? round(($opportunities->where('status', 'won')->count() / $opportunities->whereIn('status', ['won', 'lost'])->count()) * 100)
                    : 0,
                'with_documents' => $opportunities->filter(fn ($o) => $o->full_document_path || $o->full_document_url)->count(),
                'with_requirements' => $opportunities->filter(fn ($o) => ! empty($o->requirements_summary))->count(),
                'pending_evaluation' => $opportunities->where('status', 'discovered')->count(),
            ],
        ]);
    }

    public function show(RfpOpportunity $rfp): \Inertia\Response
    {
        $rfp->load(['proposals' => fn ($q) => $q->orderBy('version', 'desc'), 'outcome', 'source', 'assignee']);

        return Inertia::render('Rfp/Show', [
            'opportunity' => [
                'id' => $rfp->id,
                'title' => $rfp->title,
                'slug' => $rfp->slug,
                'issuing_organization' => $rfp->issuing_organization,
                'organization_industry' => $rfp->organization_industry,
                'description' => $rfp->description,
                'source_type' => $rfp->source_type,
                'source_url' => $rfp->source_url,
                'budget_min' => $rfp->budget_min,
                'budget_max' => $rfp->budget_max,
                'budget_range' => $rfp->budgetRange(),
                'budget_line_items' => $rfp->budget_line_items,
                'submission_deadline' => $rfp->submission_deadline?->toIso8601String(),
                'submission_deadline_display' => $rfp->submission_deadline?->format('M d, Y'),
                'submission_method' => $rfp->submission_method,
                'submission_email' => $rfp->submission_email,
                'submission_portal_url' => $rfp->submission_portal_url,
                'contact_name' => $rfp->contact_name,
                'contact_email' => $rfp->contact_email,
                'contact_phone' => $rfp->contact_phone,
                'status' => $rfp->status,
                'priority' => $rfp->priority,
                'fit_score' => $rfp->fit_score,
                'fit_score_breakdown' => $rfp->fit_score_breakdown,
                'decline_reason' => $rfp->decline_reason,
                'full_document_url' => $rfp->full_document_url,
                'tech_requirements' => $rfp->tech_requirements,
                'requirements_summary' => $rfp->requirements_summary,
                'evaluation_criteria' => $rfp->evaluation_criteria,
                'timeline_requirements' => $rfp->timeline_requirements,
                'parsed_sections' => $rfp->parsed_sections,
                'tags' => $rfp->tags,
                'assignee' => $rfp->assignee ? ['id' => $rfp->assignee->id, 'name' => $rfp->assignee->name] : null,
                'source' => $rfp->source ? ['id' => $rfp->source->id, 'name' => $rfp->source->name, 'type' => $rfp->source->type] : null,
                'is_expired' => $rfp->isExpired(),
                'generation_stage' => $rfp->generation_stage,
                'generation_error' => $rfp->generation_error,
                'generation_started_at' => $rfp->generation_started_at?->diffForHumans(),
                'created_at' => $rfp->created_at->diffForHumans(),
                'updated_at' => $rfp->updated_at->diffForHumans(),
            ],
            'proposals' => $rfp->proposals->map(fn ($p) => [
                'id' => $p->id,
                'version' => $p->version,
                'title' => $p->title,
                'status' => $p->status,
                'executive_summary' => $p->executive_summary,
                'full_content' => $p->full_content,
                'proposal_sections' => $p->proposal_sections,
                'pricing_breakdown' => $p->pricing_breakdown,
                'total_price' => $p->total_price,
                'case_studies_used' => $p->case_studies_used,
                'testimonials_used' => $p->testimonials_used,
                'past_projects_cited' => $p->past_projects_cited,
                'tone_profile' => $p->tone_profile,
                'research_context' => $p->research_context,
                'requirement_responses' => $p->requirement_responses,
                'reviewed_at' => $p->reviewed_at?->diffForHumans(),
                'submitted_at' => $p->submitted_at?->diffForHumans(),
                'created_at' => $p->created_at->diffForHumans(),
            ]),
            'outcome' => $rfp->outcome ? [
                'id' => $rfp->outcome->id,
                'outcome' => $rfp->outcome->outcome,
                'feedback_raw' => $rfp->outcome->feedback_raw,
                'feedback_structured' => $rfp->outcome->feedback_structured,
                'win_factors' => $rfp->outcome->win_factors,
                'loss_factors' => $rfp->outcome->loss_factors,
                'competitor_info' => $rfp->outcome->competitor_info,
                'lessons_learned' => $rfp->outcome->lessons_learned,
                'awarded_to' => $rfp->outcome->awarded_to,
                'awarded_amount' => $rfp->outcome->awarded_amount,
                'score_received' => $rfp->outcome->score_received,
                'organization_would_bid_again' => $rfp->outcome->organization_would_bid_again,
                'created_at' => $rfp->outcome->created_at->diffForHumans(),
            ] : null,
        ]);
    }

    public function evaluateAll(): \Illuminate\Http\RedirectResponse
    {
        $count = RfpOpportunity::where('status', 'discovered')->count();

        if ($count === 0) {
            return redirect()->back()->with('error', 'No discovered opportunities to evaluate.');
        }

        \App\Jobs\EvaluateRfpJob::dispatch();

        return redirect()->back()->with('success', "Evaluating {$count} discovered opportunities. Scores will appear shortly.");
    }

    public function retrieveAllDocuments(): \Illuminate\Http\RedirectResponse
    {
        $opportunities = RfpOpportunity::query()
            ->whereIn('status', ['qualified', 'evaluating', 'discovered'])
            ->whereNull('full_document_path')
            ->whereNull('full_document_url')
            ->get();

        if ($opportunities->isEmpty()) {
            return redirect()->back()->with('error', 'No opportunities need document retrieval.');
        }

        foreach ($opportunities as $opp) {
            \App\Jobs\RetrieveRfpDocumentJob::dispatch($opp->id);
        }

        return redirect()->back()->with('success', "Searching for full RFP documents for {$opportunities->count()} opportunities...");
    }

    public function scanGmail(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|string|max:255',
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        $from = $validated['from'] ?? null;
        $days = $validated['days'] ?? 14;

        \App\Jobs\ScanRfpEmailsJob::dispatch($from, $days);

        $message = $from
            ? "Scanning Gmail for emails from \"{$from}\" (last {$days} days)..."
            : "Scanning Gmail for RFP emails from all configured sources (last {$days} days)...";

        return redirect()->back()->with('success', $message);
    }

    public function generateProposal(RfpOpportunity $rfp): \Illuminate\Http\RedirectResponse
    {
        // Auto-transition to pursuing if not already there
        if (! in_array($rfp->status, ['pursuing', 'proposal_drafting', 'proposal_review'])) {
            $rfp->update(['status' => 'pursuing']);
        }

        GenerateRfpProposalJob::dispatch($rfp->id);

        return redirect()->back()->with('success', 'Proposal generation started. This may take a few minutes — the system is researching the organization and writing a full proposal.');
    }

    public function store(Request $request)
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
            'contact_email' => 'nullable|email|max:255',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ]);

        $validated['source_type'] = $validated['source_type'] ?? 'manual';

        // Check for duplicates before creating
        $discoveryService = app(RfpDiscoveryService::class);
        if ($discoveryService->isDuplicate($validated['title'], $validated['issuing_organization'])) {
            return redirect()->back()->with('error', 'A similar RFP opportunity already exists for this organization. Check the pipeline for duplicates.');
        }

        $validated['slug'] = Str::slug($validated['title']).'-'.Str::random(6);

        RfpOpportunity::create($validated);

        return redirect()->back()->with('success', 'RFP opportunity created successfully.');
    }

    public function uploadDocument(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'file' => 'required_without:url|nullable|file|max:20480|mimes:pdf',
            'url' => 'required_without:file|nullable|url',
        ]);

        $documentContent = null;
        $storagePath = null;
        $sourceUrl = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $storagePath = $file->store('rfp-documents/uploads', 'local');

            try {
                $parser = new \Smalot\PdfParser\Parser;
                $pdf = $parser->parseFile(Storage::disk('local')->path($storagePath));
                $documentContent = $pdf->getText();
            } catch (\Exception $e) {
                return redirect()->back()->with('error', 'Failed to parse PDF: '.$e->getMessage());
            }
        } elseif ($request->url) {
            $sourceUrl = $request->url;

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(30)->get($sourceUrl);
                if (! $response->successful()) {
                    return redirect()->back()->with('error', 'Failed to fetch URL: HTTP '.$response->status());
                }

                $contentType = $response->header('Content-Type');
                if (str_contains($contentType, 'pdf')) {
                    $tempPath = tempnam(sys_get_temp_dir(), 'rfp_');
                    file_put_contents($tempPath, $response->body());
                    $parser = new \Smalot\PdfParser\Parser;
                    $pdf = $parser->parseFile($tempPath);
                    $documentContent = $pdf->getText();
                    unlink($tempPath);
                } else {
                    $documentContent = strip_tags($response->body());
                }
            } catch (\Exception $e) {
                return redirect()->back()->with('error', 'Failed to fetch URL: '.$e->getMessage());
            }
        }

        if (! $documentContent || strlen(trim($documentContent)) < 50) {
            return redirect()->back()->with('error', 'Could not extract meaningful content from the document.');
        }

        // Use AI to extract RFP details
        try {
            $anthropic = app(\App\Services\AI\AnthropicService::class);
            $prompt = "Extract RFP details from this document. Return ONLY valid JSON:\n".
                '{"title":"RFP title","issuing_organization":"Organization name","description":"Brief 2-3 sentence summary",'.
                '"budget_min":null,"budget_max":null,"submission_deadline":null,"contact_name":null,"contact_email":null,'.
                '"tech_requirements":[],"tags":[]}'.
                "\n\nDocument content (first 8000 chars):\n".substr($documentContent, 0, 8000);

            $response = $anthropic->message($prompt, null, [], 'haiku');
            $text = is_array($response) ? ($response['content'][0]['text'] ?? $response['text'] ?? '') : (string) $response;

            // Extract JSON from response
            preg_match('/\{[\s\S]*\}/', $text, $matches);
            $parsed = json_decode($matches[0] ?? '{}', true);
        } catch (\Exception $e) {
            // Fallback: create with minimal info
            $parsed = [
                'title' => $request->hasFile('file') ? pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME) : 'Imported RFP',
                'issuing_organization' => 'Unknown',
            ];
        }

        // Check for duplicates before creating
        $parsedTitle = $parsed['title'] ?? 'Imported RFP';
        $parsedOrg = $parsed['issuing_organization'] ?? 'Unknown';
        $discoveryService = app(RfpDiscoveryService::class);
        if ($discoveryService->isDuplicate($parsedTitle, $parsedOrg, $sourceUrl)) {
            return redirect()->back()->with('error', "A similar RFP opportunity already exists: \"{$parsedTitle}\" from {$parsedOrg}. Check the pipeline for duplicates.");
        }

        $opportunity = RfpOpportunity::create([
            'title' => $parsedTitle,
            'slug' => Str::slug($parsedTitle).'-'.Str::random(6),
            'issuing_organization' => $parsedOrg,
            'description' => $parsed['description'] ?? null,
            'source_type' => 'manual',
            'source_url' => $sourceUrl,
            'budget_min' => $parsed['budget_min'] ?? null,
            'budget_max' => $parsed['budget_max'] ?? null,
            'submission_deadline' => isset($parsed['submission_deadline']) ? \Carbon\Carbon::parse($parsed['submission_deadline']) : null,
            'contact_name' => $parsed['contact_name'] ?? null,
            'contact_email' => $parsed['contact_email'] ?? null,
            'tech_requirements' => $parsed['tech_requirements'] ?? [],
            'tags' => $parsed['tags'] ?? [],
            'full_document_path' => $storagePath,
            'full_document_url' => $sourceUrl,
            'status' => 'discovered',
            'priority' => 'medium',
        ]);

        // Parse the full document for detailed requirements
        if ($documentContent) {
            $docService = app(\App\Services\Rfp\RfpDocumentService::class);
            $parsedDoc = $docService->parseDocument($opportunity, $documentContent);

            // Only update columns that exist on the model
            $fillable = [
                'requirements_summary', 'evaluation_criteria', 'timeline_requirements',
                'tech_requirements', 'submission_method', 'submission_email',
                'submission_portal_url', 'contact_name', 'contact_email', 'contact_phone',
                'budget_min', 'budget_max', 'budget_line_items',
            ];
            $opportunity->update(array_filter(
                array_intersect_key($parsedDoc, array_flip($fillable))
            ));
        }

        // Auto-evaluate
        EvaluateRfpJob::dispatch($opportunity->id);

        return redirect()->route('rfp.show', $opportunity)
            ->with('success', "RFP imported: \"{$opportunity->title}\". Evaluation started.");
    }

    public function update(Request $request, RfpOpportunity $rfp)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'issuing_organization' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'source_type' => 'nullable|string|in:email_teaser,sam_gov,rfp_board,web_scrape,manual',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'submission_deadline' => 'nullable|date',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ]);

        $rfp->update($validated);

        return redirect()->back()->with('success', 'RFP opportunity updated successfully.');
    }

    public function updateStatus(Request $request, RfpOpportunity $rfp)
    {
        $validated = $request->validate([
            'status' => 'required|in:discovered,evaluating,qualified,pursuing,proposal_drafting,proposal_review,submitted,won,lost',
            'position' => 'nullable|integer|min:0',
            'decline_reason' => 'nullable|string|max:255',
        ]);

        $updateData = [
            'status' => $validated['status'],
        ];

        if (isset($validated['position'])) {
            $updateData['position'] = $validated['position'];
        }

        if (in_array($validated['status'], ['won', 'lost'])) {
            if ($validated['status'] === 'lost' && isset($validated['decline_reason'])) {
                $updateData['decline_reason'] = $validated['decline_reason'];
            }
        }

        $rfp->update($updateData);

        // When an RFP is won, create a cash flow forecast entry for expected revenue
        if ($validated['status'] === 'won' && $rfp->budget_max) {
            \App\Models\CashFlowForecast::create([
                'user_id' => auth()->id(),
                'forecast_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'type' => 'income',
                'description' => "RFP Won: {$rfp->title} ({$rfp->issuing_organization})",
                'projected_amount' => (float) $rfp->budget_max,
                'is_recurring' => false,
                'source' => 'manual',
                'confidence' => 'confirmed',
            ]);
            \Illuminate\Support\Facades\Log::info('[RFP] Created cash flow forecast for won RFP', [
                'rfp_id' => $rfp->id,
                'amount' => $rfp->budget_max,
            ]);
        }

        $message = match ($validated['status']) {
            'won' => 'RFP marked as won!',
            'lost' => 'RFP marked as lost.',
            default => 'RFP status updated successfully.',
        };

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Request $request, RfpOpportunity $rfp)
    {
        $validated = $request->validate([
            'decline_category' => 'required|string|max:50',
            'decline_notes' => 'nullable|string|max:1000',
        ]);

        $categoryLabels = [
            'wrong_industry' => 'Wrong industry',
            'budget_too_low' => 'Budget too low',
            'budget_too_high' => 'Budget too high / too complex',
            'wrong_tech_stack' => 'Wrong technology stack',
            'scope_mismatch' => 'Scope mismatch',
            'too_competitive' => 'Too competitive',
            'timeline_unrealistic' => 'Timeline unrealistic',
            'not_qualified' => 'Not qualified',
            'already_awarded' => 'Already awarded',
            'geographic' => 'Geographic mismatch',
            'other' => 'Other',
        ];

        $categoryLabel = $categoryLabels[$validated['decline_category']] ?? $validated['decline_category'];
        $reason = $validated['decline_notes']
            ? "{$categoryLabel}: {$validated['decline_notes']}"
            : $categoryLabel;

        // Store the reason before deleting so it's preserved for learning
        $rfp->update([
            'status' => 'declined',
            'decline_reason' => $reason,
        ]);

        // Record as a learning insight for future evaluation scoring
        \App\Models\RfpLearningInsight::create([
            'insight_type' => 'decline_pattern',
            'title' => "Declined: {$categoryLabel}",
            'description' => "Declined \"{$rfp->title}\" from {$rfp->issuing_organization}. Reason: {$reason}",
            'evidence' => [
                'rfp_id' => $rfp->id,
                'category' => $validated['decline_category'],
                'notes' => $validated['decline_notes'],
                'organization' => $rfp->issuing_organization,
                'organization_industry' => $rfp->organization_industry,
                'budget_min' => $rfp->budget_min,
                'budget_max' => $rfp->budget_max,
                'source_type' => $rfp->source_type,
                'tech_requirements' => $rfp->tech_requirements,
                'tags' => $rfp->tags,
            ],
            'confidence' => 1.00,
            'impact_area' => 'targeting',
            'actionable_recommendation' => "Avoid similar {$categoryLabel} opportunities from {$rfp->issuing_organization} or similar organizations.",
            'is_active' => true,
        ]);

        $rfp->delete();

        return redirect()->back()->with('success', 'RFP declined. Your feedback will improve future recommendations.');
    }

    public function recordOutcome(Request $request, RfpOpportunity $rfp): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => 'required|in:won,lost,no_response,withdrawn',
            'feedback_raw' => 'nullable|string',
            'awarded_to' => 'nullable|string|max:255',
            'awarded_amount' => 'nullable|numeric|min:0',
        ]);

        $learningService = app(RfpLearningService::class);
        $learningService->recordOutcome($rfp, $validated['outcome'], $validated['feedback_raw'], $validated);

        return redirect()->back()->with('success', 'Outcome recorded. Feedback will be analyzed for insights.');
    }

    public function learning(): \Inertia\Response
    {
        $learningService = app(RfpLearningService::class);

        return Inertia::render('Rfp/Learning', [
            'insights' => RfpLearningInsight::active()->orderBy('confidence', 'desc')->get(),
            'winRateTrend' => $learningService->getWinRateTrend(),
            'competitiveIntel' => $learningService->getCompetitiveIntelligence(),
            'outcomeBreakdown' => RfpOutcome::selectRaw('outcome, COUNT(*) as count')
                ->groupBy('outcome')->get(),
        ]);
    }

    public function storeInsight(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'insight_type' => 'required|string|in:win_pattern,loss_pattern,decline_pattern,pricing_insight,industry_trend,content_improvement,manual_feedback',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'impact_area' => 'required|string|in:pricing,content,targeting,process,presentation',
            'confidence' => 'required|numeric|min:0|max:1',
            'actionable_recommendation' => 'nullable|string|max:2000',
        ]);

        RfpLearningInsight::create([
            'insight_type' => $validated['insight_type'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'impact_area' => $validated['impact_area'],
            'confidence' => $validated['confidence'],
            'actionable_recommendation' => $validated['actionable_recommendation'] ?? null,
            'evidence' => ['source' => 'manual_entry', 'entered_at' => now()->toIso8601String()],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Learning insight added successfully.');
    }

    public function sources(): \Inertia\Response
    {
        $sources = RfpSource::orderBy('name')->get();

        return Inertia::render('Rfp/Sources', [
            'sources' => $sources->map(fn (RfpSource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'type' => $s->type,
                'config' => $s->config,
                'filters' => $s->filters,
                'is_active' => $s->is_active,
                'check_frequency_minutes' => $s->check_frequency_minutes,
                'last_checked_at' => $s->last_checked_at?->diffForHumans(),
                'total_opportunities_found' => $s->total_opportunities_found,
                'created_at' => $s->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function storeSource(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:email_sender,government_api,rfp_board,rss_feed,web_scrape',
            'config' => 'required|array',
            'filters' => 'nullable|array',
            'check_frequency_minutes' => 'nullable|integer|min:15|max:1440',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']).'-'.Str::random(4);
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['check_frequency_minutes'] = $validated['check_frequency_minutes'] ?? 60;

        RfpSource::create($validated);

        return redirect()->back()->with('success', 'RFP source created.');
    }

    public function updateSource(Request $request, RfpSource $source): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'config' => 'sometimes|array',
            'filters' => 'nullable|array',
            'check_frequency_minutes' => 'nullable|integer|min:15|max:1440',
            'is_active' => 'nullable|boolean',
        ]);

        $source->update($validated);

        return redirect()->back()->with('success', 'RFP source updated.');
    }

    public function destroySource(RfpSource $source): \Illuminate\Http\RedirectResponse
    {
        $source->delete();

        return redirect()->back()->with('success', 'RFP source deleted.');
    }

    public function evaluate(RfpOpportunity $rfp): \Illuminate\Http\RedirectResponse
    {
        \App\Jobs\EvaluateRfpJob::dispatch($rfp->id);

        return redirect()->back()->with('success', 'Evaluating opportunity...');
    }

    public function retrieveDocument(RfpOpportunity $rfp): \Illuminate\Http\RedirectResponse
    {
        \App\Jobs\RetrieveRfpDocumentJob::dispatch($rfp->id);

        return redirect()->back()->with('success', 'Searching for full RFP document...');
    }

    /**
     * Web-based proposal review page.
     */
    public function proposalReview(RfpOpportunity $rfp, RfpProposal $proposal): \Inertia\Response
    {
        $proposal->load('opportunity');

        return Inertia::render('Rfp/ProposalReview', [
            'rfp' => $rfp->only([
                'id', 'slug', 'title', 'issuing_organization', 'contact_name',
                'contact_email', 'submission_deadline', 'budget_min', 'budget_max',
                'status', 'fit_score', 'requirements_summary', 'evaluation_criteria',
            ]),
            'proposal' => [
                'id' => $proposal->id,
                'version' => $proposal->version,
                'status' => $proposal->status,
                'title' => $proposal->title,
                'executive_summary' => $proposal->executive_summary,
                'full_content' => $proposal->renderableFullContent(),
                'proposal_sections' => $proposal->renderableSections(),
                'pricing_breakdown' => $proposal->pricing_breakdown ?? [],
                'total_price' => $proposal->total_price,
                'requirement_responses' => $proposal->requirement_responses ?? [],
                'case_studies_used' => $proposal->case_studies_used ?? [],
                'testimonials_used' => $proposal->testimonials_used ?? [],
                'tone_profile' => $proposal->tone_profile,
                'review_notes' => $proposal->review_notes,
                'debate_metadata' => isset($proposal->research_context['debate_rounds']) ? [
                    'rounds' => $proposal->research_context['debate_rounds'],
                    'providers' => $proposal->research_context['providers_used'] ?? [],
                    'confidence' => $proposal->research_context['debate_confidence'] ?? null,
                ] : null,
                'pdf_url' => route('rfp.proposal.pdf.preview', [$rfp, $proposal]),
                'download_url' => route('rfp.proposal.pdf.download', [$rfp, $proposal]),
                'corporate_pdf_url' => route('rfp.proposal.pdf.corporate', [$rfp, $proposal]),
                'google_doc_url' => route('rfp.proposal.googleDoc', [$rfp, $proposal]),
                'send_email_url' => route('rfp.proposal.sendEmail', [$rfp, $proposal]),
                'google_drive_id' => $proposal->google_drive_id,
                'created_at' => $proposal->created_at?->toIso8601String(),
            ],
            'all_versions' => RfpProposal::where('rfp_opportunity_id', $rfp->id)
                ->orderByDesc('version')
                ->get(['id', 'version', 'status', 'created_at', 'total_price'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'version' => $p->version,
                    'status' => $p->status,
                    'total_price' => $p->total_price,
                    'created_at' => $p->created_at?->diffForHumans(),
                    'review_url' => route('rfp.proposal.review', [$rfp, $p]),
                ]),
        ]);
    }

    /**
     * Download the proposal as a PDF.
     */
    public function downloadProposalPdf(RfpOpportunity $rfp, RfpProposal $proposal)
    {
        $storagePath = $this->generateProposalPdf($rfp, $proposal);

        $filename = sprintf(
            'Proposal-%s-v%d.pdf',
            Str::slug($rfp->issuing_organization),
            $proposal->version
        );

        return Storage::download($storagePath, $filename);
    }

    /**
     * Preview the proposal PDF in browser.
     */
    public function previewProposalPdf(Request $request, RfpOpportunity $rfp, RfpProposal $proposal)
    {
        $forceRegenerate = $request->boolean('regenerate');
        $storagePath = $this->generateProposalPdf($rfp, $proposal, $forceRegenerate);

        $filename = sprintf(
            'Proposal-%s-v%d.pdf',
            Str::slug($rfp->issuing_organization),
            $proposal->version
        );

        return response(Storage::get($storagePath), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Send the proposal via email with PDF attached.
     */
    public function sendProposalEmail(Request $request, RfpOpportunity $rfp, RfpProposal $proposal): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'to_email' => 'required|email|max:255',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
            'is_test' => 'nullable|boolean',
        ]);

        $isTest = $validated['is_test'] ?? false;

        // Generate PDF if it doesn't exist
        $this->generateProposalPdf($rfp, $proposal);

        // For test emails, send to the authenticated user's email
        $recipientEmail = $isTest ? auth()->user()->email : $validated['to_email'];
        $recipientName = $isTest ? auth()->user()->name : ($validated['to_name'] ?? null);

        Mail::to($recipientEmail, $recipientName)
            ->send(new RfpProposalMail(
                proposal: $proposal,
                opportunity: $rfp,
                emailSubject: $isTest ? "[TEST] {$validated['subject']}" : $validated['subject'],
                emailBody: $validated['body'],
            ));

        if ($isTest) {
            return redirect()->back()->with('success', "Test email sent to {$recipientEmail}.");
        }

        // Mark proposal as submitted (only for real sends)
        $proposal->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_via' => 'email',
        ]);

        // Update opportunity status if not already further along
        if (in_array($rfp->status, ['qualified', 'pursuing', 'proposal_drafting', 'proposal_review'])) {
            $rfp->update(['status' => 'submitted']);
        }

        return redirect()->back()->with('success', "Proposal emailed to {$validated['to_email']}.");
    }

    /**
     * Download the corporate/government-style PDF variant (Times New Roman, formal).
     */
    public function downloadProposalPdfCorporate(RfpOpportunity $rfp, RfpProposal $proposal): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $storagePath = "rfp-proposals/{$rfp->id}/{$proposal->id}-corporate.pdf";

        if (! Storage::exists($storagePath)) {
            $company = [
                'name' => config('app.company_name', 'Zao'),
                'email' => config('mail.from.address', 'billing@example.com'),
                'website' => 'https://example.com',
                'phone' => config('app.company_phone'),
                'contact_name' => config('app.company_contact_name'),
            ];

            TailwindPdf::view('pdf.rfp-proposal-corporate', [
                'proposal' => $proposal,
                'opportunity' => $rfp,
                'company' => $company,
            ])->save($storagePath);
        }

        $filename = sprintf('Proposal-%s-v%d-Corporate.pdf', Str::slug($rfp->issuing_organization), $proposal->version);

        return Storage::download($storagePath, $filename);
    }

    /**
     * Export the proposal to Google Docs and return the doc URL.
     */
    public function exportToGoogleDoc(RfpOpportunity $rfp, RfpProposal $proposal): \Illuminate\Http\JsonResponse
    {
        try {
            $user = User::first();
            $docsService = app(\App\Services\Google\GoogleDocsService::class);

            $docUrl = $docsService->createProposalDoc($user, $rfp, $proposal);

            $proposal->update(['google_drive_id' => $docUrl]);

            return response()->json(['url' => $docUrl, 'message' => 'Google Doc created successfully.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Generate the proposal PDF and store it, returning the storage path.
     */
    protected function generateProposalPdf(RfpOpportunity $rfp, RfpProposal $proposal, bool $forceRegenerate = false): string
    {
        $storagePath = "rfp-proposals/{$rfp->id}/{$proposal->id}.pdf";

        if (! $forceRegenerate && Storage::exists($storagePath)) {
            return $storagePath;
        }

        // Delete old cached PDF if regenerating
        if ($forceRegenerate && Storage::exists($storagePath)) {
            Storage::delete($storagePath);
        }

        $company = [
            'name' => config('app.company_name', 'Zao'),
            'email' => config('mail.from.address', 'billing@example.com'),
            'website' => 'https://example.com',
            'phone' => config('app.company_phone'),
            'contact_name' => config('app.company_contact_name'),
        ];

        TailwindPdf::view('pdf.rfp-proposal', [
            'proposal' => $proposal,
            'opportunity' => $rfp,
            'company' => $company,
        ])->save($storagePath);

        // Store the PDF path on the proposal
        $proposal->updateQuietly(['pdf_path' => $storagePath]);

        return $storagePath;
    }
}
