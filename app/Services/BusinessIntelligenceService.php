<?php

namespace App\Services;

use App\Models\FunnelMetrics;
use App\Models\Lead;
use App\Models\QboInvoice;
use App\Models\StrategicGoal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BusinessIntelligenceService
{
    /**
     * Lead stages in funnel order.
     */
    protected const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    /**
     * Stage probability weights.
     */
    protected const STAGE_WEIGHTS = [
        'new' => 0.10,
        'qualified' => 0.25,
        'proposal' => 0.50,
        'negotiation' => 0.75,
    ];

    /**
     * Calculate funnel conversion metrics for a date range.
     */
    public function calculateFunnelMetrics(Carbon $startDate, Carbon $endDate): array
    {
        // Get lead stage transitions (track via updated_at and stage)
        $leads = Lead::whereBetween('created_at', [$startDate, $endDate])->get();
        $wonLeads = Lead::where('stage', 'won')->whereBetween('converted_at', [$startDate, $endDate])->get();
        $lostLeads = Lead::where('stage', 'lost')->whereBetween('updated_at', [$startDate, $endDate])->get();

        // Count leads by stage (snapshot)
        $byStage = Lead::whereNotIn('stage', ['won', 'lost'])
            ->selectRaw('stage, COUNT(*) as count')
            ->groupBy('stage')
            ->pluck('count', 'stage');

        // Historical conversions - use created leads as baseline
        $totalLeads = Lead::whereBetween('created_at', [$startDate, $endDate])->count();
        $qualifiedLeads = Lead::whereIn('stage', ['qualified', 'proposal', 'negotiation', 'won'])
            ->whereBetween('created_at', [$startDate, $endDate])->count();
        $proposalLeads = Lead::whereIn('stage', ['proposal', 'negotiation', 'won'])
            ->whereBetween('created_at', [$startDate, $endDate])->count();
        $negotiationLeads = Lead::whereIn('stage', ['negotiation', 'won'])
            ->whereBetween('created_at', [$startDate, $endDate])->count();

        // Calculate conversion rates
        $newToQualified = $totalLeads > 0 ? ($qualifiedLeads / $totalLeads) * 100 : 0;
        $qualifiedToProposal = $qualifiedLeads > 0 ? ($proposalLeads / $qualifiedLeads) * 100 : 0;
        $proposalToNegotiation = $proposalLeads > 0 ? ($negotiationLeads / $proposalLeads) * 100 : 0;
        $negotiationToWon = $negotiationLeads > 0 ? ($wonLeads->count() / $negotiationLeads) * 100 : 0;

        // Overall win rate
        $closed = $wonLeads->count() + $lostLeads->count();
        $winRate = $closed > 0 ? ($wonLeads->count() / $closed) * 100 : 0;

        // Average deal size (from won leads)
        $avgDealSize = $wonLeads->avg('deal_value') ?? 0;

        // Average sales cycle (days from created to won)
        $avgCycleDays = $wonLeads->count() > 0
            ? round($wonLeads->avg(fn ($l) => $l->created_at->diffInDays($l->converted_at ?? now())))
            : 0;

        // Pipeline values
        $pipelineValue = Lead::whereNotIn('stage', ['won', 'lost'])->sum('deal_value');
        $weightedPipeline = Lead::whereNotIn('stage', ['won', 'lost'])
            ->get()
            ->sum(fn ($l) => ($l->deal_value ?? 0) * (self::STAGE_WEIGHTS[$l->stage] ?? 0));

        return [
            'period_start' => $startDate->toDateString(),
            'period_end' => $endDate->toDateString(),
            'new_to_qualified_rate' => round($newToQualified, 2),
            'qualified_to_proposal_rate' => round($qualifiedToProposal, 2),
            'proposal_to_negotiation_rate' => round($proposalToNegotiation, 2),
            'negotiation_to_won_rate' => round($negotiationToWon, 2),
            'overall_win_rate' => round($winRate, 2),
            'avg_deal_size' => round($avgDealSize, 2),
            'avg_sales_cycle_days' => $avgCycleDays,
            'leads_created' => $totalLeads,
            'leads_qualified' => $qualifiedLeads,
            'proposals_sent' => $proposalLeads,
            'deals_won' => $wonLeads->count(),
            'deals_lost' => $lostLeads->count(),
            'revenue_won' => $wonLeads->sum('deal_value'),
            'revenue_lost' => $lostLeads->sum('deal_value'),
            'pipeline_value' => $pipelineValue,
            'weighted_pipeline' => $weightedPipeline,
            'by_stage' => $byStage,
        ];
    }

    /**
     * Store a funnel metrics snapshot.
     */
    public function storeFunnelSnapshot(string $periodType, Carbon $periodStart, Carbon $periodEnd): FunnelMetrics
    {
        $metrics = $this->calculateFunnelMetrics($periodStart, $periodEnd);

        return FunnelMetrics::updateOrCreate(
            ['period_type' => $periodType, 'period_start' => $periodStart->toDateString()],
            [
                'period_end' => $periodEnd->toDateString(),
                'new_to_qualified_rate' => $metrics['new_to_qualified_rate'],
                'qualified_to_proposal_rate' => $metrics['qualified_to_proposal_rate'],
                'proposal_to_negotiation_rate' => $metrics['proposal_to_negotiation_rate'],
                'negotiation_to_won_rate' => $metrics['negotiation_to_won_rate'],
                'overall_win_rate' => $metrics['overall_win_rate'],
                'avg_deal_size' => $metrics['avg_deal_size'],
                'avg_sales_cycle_days' => $metrics['avg_sales_cycle_days'],
                'leads_created' => $metrics['leads_created'],
                'leads_qualified' => $metrics['leads_qualified'],
                'proposals_sent' => $metrics['proposals_sent'],
                'deals_won' => $metrics['deals_won'],
                'deals_lost' => $metrics['deals_lost'],
                'revenue_won' => $metrics['revenue_won'],
                'revenue_lost' => $metrics['revenue_lost'],
                'pipeline_value' => $metrics['pipeline_value'],
                'weighted_pipeline' => $metrics['weighted_pipeline'],
            ]
        );
    }

    /**
     * Calculate required leads per week to hit a goal.
     */
    public function calculateRequiredLeadsPerWeek(StrategicGoal $goal): array
    {
        // Get historical conversion rates (last 12 months or available)
        $rates = FunnelMetrics::averageRates(FunnelMetrics::TYPE_MONTHLY, 12);

        // Fallback defaults if no history
        $winRate = $rates['overall_win_rate'] > 0 ? $rates['overall_win_rate'] : 25;
        $avgDealSize = $rates['avg_deal_size'] > 0 ? $rates['avg_deal_size'] : ($goal->getAssumption('avg_deal_size', 25000));
        $avgCycleDays = $rates['avg_sales_cycle_days'] > 0 ? $rates['avg_sales_cycle_days'] : ($goal->getAssumption('sales_cycle_days', 45));

        // Remaining revenue needed (include prorated recurring revenue)
        $currentPeriod = $goal->yearlyPeriod;
        $invoiceRevenue = $currentPeriod?->revenue_actual ?? 0;
        $recurringRevenue = $goal->prorated_recurring_revenue;
        $revenueActual = $invoiceRevenue + $recurringRevenue;
        $revenueRemaining = max(0, $goal->revenue_target - $revenueActual);

        // Remaining time
        $now = now();
        $yearEnd = Carbon::create($goal->fiscal_year, 12, 31);
        $weeksRemaining = max(1, $now->diffInWeeks($yearEnd));

        // Calculate requirements
        $dealsNeeded = ceil($revenueRemaining / $avgDealSize);
        $leadsNeeded = ceil($dealsNeeded / ($winRate / 100));
        $leadsPerWeek = ceil($leadsNeeded / $weeksRemaining);

        // Calculate based on overall funnel conversion
        $overallConversion = ($rates['new_to_qualified_rate'] / 100)
            * ($rates['qualified_to_proposal_rate'] / 100)
            * ($rates['proposal_to_negotiation_rate'] / 100)
            * ($rates['negotiation_to_won_rate'] / 100);

        $leadsForConversion = $overallConversion > 0 ? ceil($dealsNeeded / $overallConversion) : $leadsNeeded;

        return [
            'revenue_target' => $goal->revenue_target,
            'revenue_actual' => $revenueActual,
            'revenue_remaining' => $revenueRemaining,
            'weeks_remaining' => $weeksRemaining,
            'deals_needed' => $dealsNeeded,
            'leads_needed' => $leadsNeeded,
            'leads_per_week' => $leadsPerWeek,
            'leads_for_conversion_adjusted' => ceil($leadsForConversion / $weeksRemaining),
            'assumptions' => [
                'win_rate' => $winRate,
                'avg_deal_size' => $avgDealSize,
                'avg_cycle_days' => $avgCycleDays,
                'overall_conversion' => round($overallConversion * 100, 2),
            ],
        ];
    }

    /**
     * Calculate progress for a goal (actuals vs targets).
     */
    public function calculateProgress(StrategicGoal $goal): array
    {
        // Get actual revenue from QuickBooks
        $yearStart = Carbon::create($goal->fiscal_year, 1, 1);
        $yearEnd = Carbon::create($goal->fiscal_year, 12, 31);

        $invoiceRevenue = QboInvoice::paid()
            ->whereBetween('txn_date', [$yearStart, $yearEnd])
            ->sum('total_amount');

        // Add prorated recurring/retainer revenue
        $recurringRevenue = $goal->prorated_recurring_revenue;
        $revenueActual = $invoiceRevenue + $recurringRevenue;

        // Get leads and deals
        $leadsActual = Lead::whereYear('created_at', $goal->fiscal_year)->count();
        $dealsWon = Lead::where('stage', 'won')
            ->whereYear('converted_at', $goal->fiscal_year)
            ->count();
        $revenueFromDeals = Lead::where('stage', 'won')
            ->whereYear('converted_at', $goal->fiscal_year)
            ->sum('deal_value');

        // Current pipeline
        $pipelineActual = Lead::whereNotIn('stage', ['won', 'lost'])->sum('deal_value');

        // Update yearly period
        if ($yearlyPeriod = $goal->yearlyPeriod) {
            $yearlyPeriod->update([
                'revenue_actual' => $revenueActual,
                'leads_actual' => $leadsActual,
                'closed_deals_actual' => $dealsWon,
                'pipeline_actual' => $pipelineActual,
            ]);
            $yearlyPeriod->calculateVariance();
            $yearlyPeriod->updateStatus();
            $yearlyPeriod->save();
        }

        // Calculate variance
        $variance = $goal->revenue_target > 0
            ? (($revenueActual - $goal->revenue_target) / $goal->revenue_target) * 100
            : 0;

        // Expected at this point in year
        $timeElapsed = $goal->time_elapsed_percent / 100;
        $expectedRevenue = $goal->revenue_target * $timeElapsed;
        $paceVariance = $expectedRevenue > 0
            ? (($revenueActual - $expectedRevenue) / $expectedRevenue) * 100
            : 0;

        return [
            'revenue' => [
                'target' => $goal->revenue_target,
                'actual' => $revenueActual,
                'from_invoices' => $invoiceRevenue,
                'from_recurring' => $recurringRevenue,
                'from_deals' => $revenueFromDeals,
                'progress_pct' => $goal->progress_percent,
                'variance_pct' => round($variance, 1),
            ],
            'recurring' => [
                'harvest_mrr' => $goal->harvest_mrr,
                'manual_override' => (float) $goal->monthly_recurring_revenue,
                'effective_mrr' => $goal->effective_mrr,
                'mrr_months' => (int) ($goal->mrr_months ?? 12),
                'annualized' => $goal->annualized_recurring_revenue,
                'ytd_prorated' => $recurringRevenue,
                'active_retainer_count' => $goal->active_retainer_projects->count(),
            ],
            'pace' => [
                'time_elapsed_pct' => round($goal->time_elapsed_percent, 1),
                'expected_revenue' => round($expectedRevenue, 2),
                'pace_variance_pct' => round($paceVariance, 1),
                'is_on_track' => $goal->is_on_track,
                'status' => $paceVariance >= 10 ? 'ahead' : ($paceVariance >= -10 ? 'on_track' : ($paceVariance >= -25 ? 'behind' : 'critical')),
            ],
            'leads' => [
                'target' => $goal->yearlyPeriod?->leads_target ?? 0,
                'actual' => $leadsActual,
                'progress_pct' => $goal->yearlyPeriod?->leads_progress ?? 0,
            ],
            'deals' => [
                'target' => $goal->yearlyPeriod?->closed_deals_target ?? 0,
                'actual' => $dealsWon,
                'progress_pct' => $goal->yearlyPeriod?->deals_progress ?? 0,
            ],
            'pipeline' => [
                'target' => $goal->yearlyPeriod?->pipeline_target ?? 0,
                'actual' => $pipelineActual,
                'coverage' => $goal->revenue_target > 0 ? round($pipelineActual / $goal->revenue_target, 2) : 0,
            ],
        ];
    }

    /**
     * Identify levers to pull when behind on goal.
     */
    public function identifyLevers(StrategicGoal $goal): array
    {
        $progress = $this->calculateProgress($goal);
        $requirements = $this->calculateRequiredLeadsPerWeek($goal);
        $rates = FunnelMetrics::averageRates(FunnelMetrics::TYPE_MONTHLY, 6);

        $levers = [];
        $paceStatus = $progress['pace']['status'];

        // Not behind - no levers needed
        if ($paceStatus === 'ahead' || $paceStatus === 'on_track') {
            return [
                'status' => 'on_track',
                'message' => 'You are on track to hit your goal.',
                'levers' => [],
            ];
        }

        // Calculate impact of each lever
        $revenueGap = $requirements['revenue_remaining'];
        $avgDealSize = $requirements['assumptions']['avg_deal_size'];
        $winRate = $requirements['assumptions']['win_rate'];

        // Lever 1: Increase lead volume
        $currentLeadsPerWeek = $progress['leads']['actual'] > 0
            ? $progress['leads']['actual'] / max(1, now()->weekOfYear)
            : 0;
        $leadsGap = max(0, $requirements['leads_per_week'] - $currentLeadsPerWeek);

        if ($leadsGap > 0) {
            $levers[] = [
                'lever' => 'increase_leads',
                'title' => 'Increase Lead Generation',
                'description' => "Generate {$leadsGap} more leads per week",
                'current' => round($currentLeadsPerWeek, 1).' leads/week',
                'target' => $requirements['leads_per_week'].' leads/week',
                'impact_score' => 80,
                'actions' => [
                    'Launch lead generation agent',
                    'Activate outreach campaigns',
                    'Run LinkedIn prospecting',
                ],
            ];
        }

        // Lever 2: Improve win rate
        if ($winRate < 35) {
            $improvedWinRate = min(40, $winRate * 1.25);
            $impactDeals = ceil($revenueGap / $avgDealSize) - ceil($revenueGap / $avgDealSize / ($improvedWinRate / $winRate));

            $levers[] = [
                'lever' => 'improve_win_rate',
                'title' => 'Improve Win Rate',
                'description' => "Increase win rate from {$winRate}% to ".round($improvedWinRate).'%',
                'current' => $winRate.'%',
                'target' => round($improvedWinRate).'%',
                'impact_score' => 70,
                'actions' => [
                    'Review lost deals for patterns',
                    'Improve proposal quality',
                    'Accelerate follow-up timing',
                    'Better qualify leads earlier',
                ],
            ];
        }

        // Lever 3: Increase deal size
        $targetDealSize = $avgDealSize * 1.2;
        $dealSizeImpact = $revenueGap / $targetDealSize - $revenueGap / $avgDealSize;

        $levers[] = [
            'lever' => 'increase_deal_size',
            'title' => 'Increase Average Deal Size',
            'description' => 'Target larger opportunities or add upsells',
            'current' => '$'.number_format($avgDealSize),
            'target' => '$'.number_format($targetDealSize),
            'impact_score' => 60,
            'actions' => [
                'Target enterprise segments',
                'Bundle services for larger packages',
                'Add retainer components',
                'Upsell existing pipeline',
            ],
        ];

        // Lever 4: Accelerate sales cycle
        $cycleDays = $requirements['assumptions']['avg_cycle_days'];
        if ($cycleDays > 30) {
            $improvedCycle = max(21, $cycleDays * 0.75);

            $levers[] = [
                'lever' => 'accelerate_cycle',
                'title' => 'Shorten Sales Cycle',
                'description' => "Reduce cycle from {$cycleDays} to ".round($improvedCycle).' days',
                'current' => $cycleDays.' days',
                'target' => round($improvedCycle).' days',
                'impact_score' => 50,
                'actions' => [
                    'Streamline proposal process',
                    'Faster qualification calls',
                    'Remove approval bottlenecks',
                    'Offer signing incentives',
                ],
            ];
        }

        // Lever 5: Reactivate lost deals
        $lostDealsRecoverable = Lead::where('stage', 'lost')
            ->whereYear('updated_at', $goal->fiscal_year)
            ->where('deal_value', '>', $avgDealSize * 0.5)
            ->count();

        if ($lostDealsRecoverable > 3) {
            $levers[] = [
                'lever' => 'reactivate_lost',
                'title' => 'Reactivate Lost Opportunities',
                'description' => "{$lostDealsRecoverable} lost deals worth revisiting",
                'current' => $lostDealsRecoverable.' opportunities',
                'target' => ceil($lostDealsRecoverable * 0.15).' re-closed',
                'impact_score' => 40,
                'actions' => [
                    'Review why deals were lost',
                    'Reach out with new angle',
                    'Offer special terms',
                ],
            ];
        }

        // Sort by impact score
        usort($levers, fn ($a, $b) => $b['impact_score'] - $a['impact_score']);

        return [
            'status' => $paceStatus,
            'message' => $paceStatus === 'critical'
                ? 'Urgent action needed to recover toward goal.'
                : 'You are behind pace. Consider these strategies.',
            'revenue_gap' => $revenueGap,
            'weeks_remaining' => $requirements['weeks_remaining'],
            'levers' => $levers,
        ];
    }

    /**
     * Analyze team capacity against requirements.
     */
    public function analyzeCapacity(StrategicGoal $goal): array
    {
        $requirements = $this->calculateRequiredLeadsPerWeek($goal);

        // Get current pipeline load
        $activePipeline = Lead::whereNotIn('stage', ['won', 'lost'])->count();
        $pipelineValue = Lead::whereNotIn('stage', ['won', 'lost'])->sum('deal_value');

        // Estimate team capacity (you could make this configurable)
        $maxLeadsPerWeek = 20; // Configurable
        $maxActivePipeline = 50; // Configurable

        $leadCapacityUsed = ($requirements['leads_per_week'] / $maxLeadsPerWeek) * 100;
        $pipelineCapacityUsed = ($activePipeline / $maxActivePipeline) * 100;

        return [
            'lead_generation' => [
                'required_per_week' => $requirements['leads_per_week'],
                'max_capacity' => $maxLeadsPerWeek,
                'capacity_used_pct' => round($leadCapacityUsed, 1),
                'is_overloaded' => $leadCapacityUsed > 100,
            ],
            'pipeline_management' => [
                'active_leads' => $activePipeline,
                'max_capacity' => $maxActivePipeline,
                'capacity_used_pct' => round($pipelineCapacityUsed, 1),
                'is_overloaded' => $pipelineCapacityUsed > 100,
            ],
            'recommendation' => $this->getCapacityRecommendation($leadCapacityUsed, $pipelineCapacityUsed),
        ];
    }

    /**
     * Get capacity recommendation.
     */
    protected function getCapacityRecommendation(float $leadCapacity, float $pipelineCapacity): string
    {
        if ($leadCapacity > 100 && $pipelineCapacity > 100) {
            return 'Critical: Both lead generation and pipeline management are over capacity. Consider adding resources or adjusting targets.';
        }

        if ($leadCapacity > 100) {
            return 'Lead generation requirements exceed capacity. Prioritize automation or adjust expectations.';
        }

        if ($pipelineCapacity > 100) {
            return 'Pipeline is overloaded. Focus on closing existing opportunities before adding more leads.';
        }

        if ($leadCapacity > 80 || $pipelineCapacity > 80) {
            return 'Capacity utilization is high. Monitor closely and prepare contingencies.';
        }

        return 'Capacity levels are healthy. Good balance between requirements and resources.';
    }

    /**
     * Forecast revenue for upcoming periods.
     */
    public function forecastRevenue(StrategicGoal $goal, int $days = 90): array
    {
        $rates = FunnelMetrics::averageRates(FunnelMetrics::TYPE_MONTHLY, 6);
        $progress = $this->calculateProgress($goal);

        // Current pipeline by stage
        $pipeline = Lead::whereNotIn('stage', ['won', 'lost'])
            ->select('stage', DB::raw('SUM(deal_value) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        // Weighted forecast based on stage probabilities
        $weightedForecast = $pipeline->sum(fn ($p) => ($p->value ?? 0) * (self::STAGE_WEIGHTS[$p->stage] ?? 0));

        // Time-based forecast
        $avgCycle = $rates['avg_sales_cycle_days'] ?: 45;
        $revenueVelocity = $progress['revenue']['actual'] / max(1, now()->dayOfYear); // $/day

        // 30/60/90 day forecasts
        $forecasts = [];
        foreach ([30, 60, 90] as $period) {
            // Base: continue at current velocity
            $velocityForecast = $progress['revenue']['actual'] + ($revenueVelocity * $period);

            // Pipeline-based: what should close within this period
            $pipelineInPeriod = $pipeline->filter(function ($p) use ($avgCycle, $period) {
                $stageToClose = match ($p->stage) {
                    'negotiation' => $avgCycle * 0.25,
                    'proposal' => $avgCycle * 0.5,
                    'qualified' => $avgCycle * 0.75,
                    default => $avgCycle,
                };

                return $stageToClose <= $period;
            })->sum(fn ($p) => ($p->value ?? 0) * (self::STAGE_WEIGHTS[$p->stage] ?? 0) * ($rates['overall_win_rate'] / 100 ?: 0.25));

            $forecasts[$period] = [
                'velocity_based' => round($velocityForecast, 2),
                'pipeline_based' => round($progress['revenue']['actual'] + $pipelineInPeriod, 2),
                'blended' => round($progress['revenue']['actual'] + (($velocityForecast - $progress['revenue']['actual'] + $pipelineInPeriod) / 2), 2),
            ];
        }

        // End of year forecast
        $daysToYearEnd = now()->diffInDays(Carbon::create($goal->fiscal_year, 12, 31));
        $yearEndForecast = $progress['revenue']['actual'] + ($revenueVelocity * $daysToYearEnd);
        $yearEndPipelineForecast = $progress['revenue']['actual'] + $weightedForecast * ($rates['overall_win_rate'] / 100 ?: 0.25);

        return [
            'current_revenue' => $progress['revenue']['actual'],
            'target_revenue' => $goal->revenue_target,
            'weighted_pipeline' => round($weightedForecast, 2),
            'daily_velocity' => round($revenueVelocity, 2),
            'forecasts' => $forecasts,
            'year_end' => [
                'days_remaining' => $daysToYearEnd,
                'velocity_forecast' => round($yearEndForecast, 2),
                'pipeline_forecast' => round($yearEndPipelineForecast, 2),
                'will_hit_target' => $yearEndForecast >= $goal->revenue_target || $yearEndPipelineForecast >= $goal->revenue_target,
                'gap' => round($goal->revenue_target - max($yearEndForecast, $yearEndPipelineForecast), 2),
            ],
        ];
    }

    /**
     * Update actuals for all periods of a goal.
     */
    public function updateGoalActuals(StrategicGoal $goal): void
    {
        foreach ($goal->periods as $period) {
            // Get revenue for this period
            $revenue = QboInvoice::paid()
                ->whereBetween('txn_date', [$period->period_start, $period->period_end])
                ->sum('total_amount');

            // Get leads created in this period
            $leads = Lead::whereBetween('created_at', [$period->period_start, $period->period_end])->count();

            // Get deals closed in this period
            $deals = Lead::where('stage', 'won')
                ->whereBetween('converted_at', [$period->period_start, $period->period_end])
                ->count();

            // Get pipeline as of period end
            $pipeline = Lead::whereNotIn('stage', ['won', 'lost'])
                ->where('created_at', '<=', $period->period_end)
                ->sum('deal_value');

            $period->update([
                'revenue_actual' => $revenue,
                'leads_actual' => $leads,
                'closed_deals_actual' => $deals,
                'pipeline_actual' => $pipeline,
            ]);

            $period->calculateVariance();
            $period->updateStatus();
            $period->save();
        }
    }
}
