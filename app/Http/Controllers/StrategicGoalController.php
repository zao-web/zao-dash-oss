<?php

namespace App\Http\Controllers;

use App\Models\BusinessGoal;
use App\Models\FunnelMetrics;
use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StrategicGoalController extends Controller
{
    public function __construct(
        protected BusinessIntelligenceService $biService
    ) {}

    /**
     * List all strategic goals.
     */
    public function index()
    {
        $goals = StrategicGoal::with('yearlyPeriod')
            ->orderByDesc('fiscal_year')
            ->get()
            ->map(fn ($goal) => [
                'id' => $goal->id,
                'fiscal_year' => $goal->fiscal_year,
                'name' => $goal->name,
                'revenue_target' => $goal->revenue_target,
                'revenue_actual' => $goal->yearlyPeriod?->revenue_actual ?? 0,
                'progress_percent' => $goal->progress_percent,
                'status' => $goal->status,
                'is_on_track' => $goal->is_on_track,
            ]);

        // Get simple KPI goals
        $businessGoals = BusinessGoal::active()
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'type' => $g->type,
                'period' => $g->period,
                'label' => $g->display_label,
                'target' => $g->target,
                'current' => $g->current_value,
                'progress' => $g->progress,
                'is_achieved' => $g->is_achieved,
            ]);

        return Inertia::render('Goals/Index', [
            'goals' => $goals,
            'businessGoals' => $businessGoals,
        ]);
    }

    /**
     * Show goal creation form.
     */
    public function create()
    {
        // Get latest funnel metrics for default assumptions
        $latestMetrics = FunnelMetrics::latestOfType(FunnelMetrics::TYPE_MONTHLY)->first();

        return Inertia::render('Goals/Create', [
            'defaultAssumptions' => [
                'avg_deal_size' => $latestMetrics?->avg_deal_size ?? 25000,
                'win_rate' => $latestMetrics?->overall_win_rate ?? 25,
                'sales_cycle_days' => $latestMetrics?->avg_sales_cycle_days ?? 45,
            ],
            'currentYear' => now()->year,
        ]);
    }

    /**
     * Store a new strategic goal.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2024|max:2030',
            'name' => 'nullable|string|max:255',
            'revenue_target' => 'required|numeric|min:1000',
            'margin_target_pct' => 'nullable|numeric|min:0|max:100',
            'assumptions' => 'nullable|array',
            'assumptions.avg_deal_size' => 'nullable|numeric|min:100',
            'assumptions.win_rate' => 'nullable|numeric|min:1|max:100',
            'assumptions.sales_cycle_days' => 'nullable|integer|min:1|max:365',
        ]);

        $goal = StrategicGoal::create([
            'user_id' => auth()->id(),
            'fiscal_year' => $validated['fiscal_year'],
            'name' => $validated['name'] ?? "FY{$validated['fiscal_year']} Goal",
            'revenue_target' => $validated['revenue_target'],
            'margin_target_pct' => $validated['margin_target_pct'] ?? 0,
            'profit_target' => ($validated['revenue_target'] * ($validated['margin_target_pct'] ?? 0)) / 100,
            'assumptions' => $validated['assumptions'] ?? [],
            'status' => StrategicGoal::STATUS_ACTIVE,
        ]);

        // Generate all period breakdowns
        $goal->generatePeriods($validated['assumptions'] ?? []);

        // Update actuals immediately
        $this->biService->updateGoalActuals($goal);

        return redirect()->route('goals.show', $goal)
            ->with('success', 'Strategic goal created and periods generated.');
    }

    /**
     * Show a single goal with full analytics.
     */
    public function show(StrategicGoal $goal)
    {
        $goal->load(['yearlyPeriod', 'quarters', 'months']);

        // Get progress
        $progress = $this->biService->calculateProgress($goal);

        // Get requirements
        $requirements = $this->biService->calculateRequiredLeadsPerWeek($goal);

        // Get levers
        $levers = $this->biService->identifyLevers($goal);

        // Get forecast
        $forecast = $this->biService->forecastRevenue($goal);

        // Get capacity
        $capacity = $this->biService->analyzeCapacity($goal);

        // Current periods
        $currentQuarter = $goal->currentQuarter();
        $currentMonth = $goal->currentMonth();
        $currentWeek = $goal->currentWeek();

        return Inertia::render('Goals/Show', [
            'goal' => [
                'id' => $goal->id,
                'fiscal_year' => $goal->fiscal_year,
                'name' => $goal->name,
                'revenue_target' => $goal->revenue_target,
                'margin_target_pct' => $goal->margin_target_pct,
                'profit_target' => $goal->profit_target,
                'harvest_mrr' => $goal->harvest_mrr,
                'manual_mrr_override' => $goal->monthly_recurring_revenue,
                'effective_mrr' => $goal->effective_mrr,
                'mrr_months' => $goal->mrr_months,
                'annualized_recurring' => $goal->annualized_recurring_revenue,
                'active_retainer_count' => $goal->active_retainer_projects->count(),
                'status' => $goal->status,
                'assumptions' => $goal->assumptions,
                'notes' => $goal->notes,
            ],
            'progress' => $progress,
            'requirements' => $requirements,
            'levers' => $levers,
            'forecast' => $forecast,
            'capacity' => $capacity,
            'periods' => [
                'yearly' => $goal->yearlyPeriod ? $this->formatPeriod($goal->yearlyPeriod) : null,
                'current_quarter' => $currentQuarter ? $this->formatPeriod($currentQuarter) : null,
                'current_month' => $currentMonth ? $this->formatPeriod($currentMonth) : null,
                'current_week' => $currentWeek ? $this->formatPeriod($currentWeek) : null,
                'quarters' => $goal->quarters->map(fn ($p) => $this->formatPeriod($p)),
                'months' => $goal->months->map(fn ($p) => $this->formatPeriod($p)),
            ],
        ]);
    }

    /**
     * Update a goal.
     */
    public function update(Request $request, StrategicGoal $goal)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'revenue_target' => 'nullable|numeric|min:1000',
            'margin_target_pct' => 'nullable|numeric|min:0|max:100',
            'monthly_recurring_revenue' => 'nullable|numeric|min:0',
            'mrr_months' => 'nullable|integer|min:1|max:12',
            'assumptions' => 'nullable|array',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,achieved,missed,archived',
        ]);

        $goal->update($validated);

        // Regenerate periods if targets changed
        if (isset($validated['revenue_target']) || isset($validated['assumptions'])) {
            $goal->generatePeriods($validated['assumptions'] ?? []);
            $this->biService->updateGoalActuals($goal);
        }

        return back()->with('success', 'Goal updated.');
    }

    /**
     * Delete a goal.
     */
    public function destroy(StrategicGoal $goal)
    {
        $goal->periods()->delete();
        $goal->delete();

        return redirect()->route('goals.index')
            ->with('success', 'Goal deleted.');
    }

    /**
     * Get progress data for a goal (API).
     */
    public function progress(StrategicGoal $goal)
    {
        return response()->json([
            'progress' => $this->biService->calculateProgress($goal),
            'requirements' => $this->biService->calculateRequiredLeadsPerWeek($goal),
        ]);
    }

    /**
     * Get levers for a goal (API).
     */
    public function levers(StrategicGoal $goal)
    {
        return response()->json($this->biService->identifyLevers($goal));
    }

    /**
     * Get forecast for a goal (API).
     */
    public function forecast(StrategicGoal $goal)
    {
        return response()->json($this->biService->forecastRevenue($goal));
    }

    /**
     * Get funnel metrics (API).
     */
    public function funnelMetrics(Request $request)
    {
        $type = $request->input('type', FunnelMetrics::TYPE_MONTHLY);
        $limit = $request->input('limit', 12);

        $metrics = FunnelMetrics::where('period_type', $type)
            ->orderByDesc('period_end')
            ->limit($limit)
            ->get();

        $averages = FunnelMetrics::averageRates($type, $limit);

        return response()->json([
            'snapshots' => $metrics,
            'averages' => $averages,
            'trends' => [
                'win_rate' => FunnelMetrics::trend('overall_win_rate', $type, 4),
                'avg_deal_size' => FunnelMetrics::trend('avg_deal_size', $type, 4),
                'cycle_days' => FunnelMetrics::trend('avg_sales_cycle_days', $type, 4),
            ],
        ]);
    }

    /**
     * Format a period for API/frontend.
     */
    protected function formatPeriod($period): array
    {
        return [
            'id' => $period->id,
            'type' => $period->period_type,
            'label' => $period->period_label,
            'start' => $period->period_start->format('Y-m-d'),
            'end' => $period->period_end->format('Y-m-d'),
            'is_current' => $period->is_current,
            'elapsed_percent' => $period->elapsed_percent,
            'status' => $period->status,
            'revenue' => [
                'target' => $period->revenue_target,
                'actual' => $period->revenue_actual,
                'progress' => $period->revenue_progress,
            ],
            'leads' => [
                'target' => $period->leads_target,
                'actual' => $period->leads_actual,
                'progress' => $period->leads_progress,
            ],
            'deals' => [
                'target' => $period->closed_deals_target,
                'actual' => $period->closed_deals_actual,
                'progress' => $period->deals_progress,
            ],
            'pipeline' => [
                'target' => $period->pipeline_target,
                'actual' => $period->pipeline_actual,
                'progress' => $period->pipeline_progress,
            ],
            'variance_pct' => $period->variance_pct,
        ];
    }
}
