<?php

namespace App\Http\Controllers;

use App\Models\SeoKeyword;
use App\Models\SeoPage;
use App\Services\Seo\SeoPerformanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SeoController extends Controller
{
    public function __construct(
        protected SeoPerformanceService $performanceService
    ) {}

    /**
     * Display the SEO dashboard.
     */
    public function dashboard()
    {
        $kpis = $this->performanceService->getDashboardKpis();

        // Get recent orchestrator runs with their generated pages
        $recentOrchestratorRuns = \App\Models\AgentRun::where('agent_id', function ($query) {
            $query->select('id')
                ->from('agents')
                ->where('slug', 'programmatic-seo')
                ->limit(1);
        })
            ->with(['agent'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($run) {
                $pages = SeoPage::where('orchestrator_run_id', $run->id)->get();

                return [
                    'id' => $run->id,
                    'status' => $run->status,
                    'task' => $run->task,
                    'started_at' => $run->started_at,
                    'completed_at' => $run->completed_at,
                    'pages' => $pages,
                    'pages_count' => $pages->count(),
                    'pages_completed' => $pages->where('status', 'draft')->count() + $pages->where('status', 'published')->count(),
                ];
            });

        return Inertia::render('Seo/Dashboard', [
            'kpis' => $kpis,
            'recent_orchestrator_runs' => $recentOrchestratorRuns,
        ]);
    }

    /**
     * Display all SEO pages.
     */
    public function pages(Request $request)
    {
        $query = SeoPage::query()
            ->orderByDesc('total_revenue')
            ->orderByDesc('total_leads');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by page type
        if ($request->filled('page_type')) {
            $query->where('page_type', $request->page_type);
        }

        // Filter by generation method
        if ($request->filled('generated_by')) {
            $query->where('generated_by_agent', $request->generated_by === 'agent');
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('page_url', 'like', "%{$search}%")
                    ->orWhere('target_keyword', 'like', "%{$search}%")
                    ->orWhere('meta_title', 'like', "%{$search}%");
            });
        }

        $pages = $query->paginate(20);

        return Inertia::render('Seo/Pages', [
            'pages' => $pages,
            'filters' => $request->only(['status', 'page_type', 'generated_by', 'search']),
        ]);
    }

    /**
     * Display a single SEO page with details.
     */
    public function showPage(SeoPage $page)
    {
        $page->load('keywords');

        // Get related leads and projects
        $leads = \App\Models\Lead::where('first_touch_page_url', $page->page_url)->get();
        $projects = \App\Models\Project::where('source_page', $page->page_url)->get();

        return Inertia::render('Seo/PageDetails', [
            'page' => $page,
            'leads' => $leads,
            'projects' => $projects,
        ]);
    }

    /**
     * Delete an SEO page.
     */
    public function deletePage(SeoPage $page)
    {
        $page->delete();

        return redirect()->route('seo.pages')->with('success', 'SEO page deleted successfully.');
    }

    /**
     * Display all keywords.
     */
    public function keywords(Request $request)
    {
        $query = SeoKeyword::query()
            ->with('seoPage')
            ->orderBy('current_position');

        // Filter by intent
        if ($request->filled('intent')) {
            $query->where('intent', $request->intent);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Only show keywords in top 20
        if ($request->boolean('top_only')) {
            $query->where('current_position', '<=', 20);
        }

        // Search
        if ($request->filled('search')) {
            $query->where('keyword', 'like', "%{$request->search}%");
        }

        $keywords = $query->paginate(50);

        return Inertia::render('Seo/Keywords', [
            'keywords' => $keywords,
            'filters' => $request->only(['intent', 'status', 'top_only', 'search']),
        ]);
    }

    /**
     * Display performance analytics.
     */
    public function performance(Request $request)
    {
        // Allow custom date range
        $startDate = $request->filled('start_date')
            ? \Carbon\Carbon::parse($request->start_date)
            : now()->startOfMonth();

        $endDate = $request->filled('end_date')
            ? \Carbon\Carbon::parse($request->end_date)
            : now();

        $heroMetrics = $this->performanceService->getHeroMetrics($startDate);
        $conversionFunnel = $this->performanceService->getConversionFunnel($startDate);
        $topPages = $this->performanceService->getTopPerformingPages(10, $startDate);
        $topKeywords = $this->performanceService->getKeywordPerformance(10, $startDate);
        $contentComparison = $this->performanceService->getContentComparison($startDate);
        $programmaticVsManual = $this->performanceService->getProgrammaticVsManual($startDate);

        return Inertia::render('Seo/Performance', [
            'hero_metrics' => $heroMetrics,
            'conversion_funnel' => $conversionFunnel,
            'top_pages' => $topPages,
            'top_keywords' => $topKeywords,
            'content_comparison' => $contentComparison,
            'programmatic_vs_manual' => $programmaticVsManual,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ]);
    }

    /**
     * Trigger programmatic SEO content generation agent.
     */
    public function generateContent(Request $request)
    {
        $request->validate([
            'goal' => 'required|string|min:10|max:1000',
        ]);

        // Find the Programmatic SEO agent
        $agent = \App\Models\Agent::where('slug', 'programmatic-seo')->firstOrFail();

        if ($agent->status !== 'active') {
            return back()->withErrors(['goal' => 'The Programmatic SEO agent is not active. Please activate it first.']);
        }

        // Create the agent run with extended timeout (30 minutes)
        $run = $agent->runs()->create([
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => 'Generate programmatic SEO content strategy and pages',
            'context' => [
                'goal' => $request->goal,
                'requested_by' => auth()->user()->name,
                'requested_at' => now()->toIso8601String(),
                'timeout_seconds' => 1800, // 30 minutes for complex SEO research and content generation
            ],
            'invocation_source' => \App\Models\AgentRun::SOURCE_MANUAL,
            'invoked_by' => auth()->user()->name,
            'started_at' => now(),
        ]);

        // Dispatch the job to execute the agent
        \App\Jobs\RunAgentJob::dispatch($run);

        return back()->with([
            'success' => 'SEO content generation started! The agent will research competitors, create a strategy, and generate pages. Check back soon for results.',
            'run_id' => $run->id,
        ]);
    }
}
