<?php

namespace App\Services\Rfp;

use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use App\Models\RfpOutcome;
use App\Models\RfpProposal;
use App\Services\AI\ClaudeCliService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RfpLearningService
{
    public function __construct(
        protected ClaudeCliService $claude,
    ) {}

    /**
     * Record an outcome for an RFP opportunity.
     */
    public function recordOutcome(
        RfpOpportunity $opportunity,
        string $outcome,
        ?string $feedbackRaw = null,
        array $options = []
    ): RfpOutcome {
        $latestProposal = $opportunity->proposals()
            ->whereIn('status', ['submitted', 'approved'])
            ->latest()
            ->first();

        $rfpOutcome = RfpOutcome::create([
            'rfp_opportunity_id' => $opportunity->id,
            'rfp_proposal_id' => $latestProposal?->id,
            'outcome' => $outcome,
            'feedback_raw' => $feedbackRaw,
            'awarded_to' => $options['awarded_to'] ?? null,
            'awarded_amount' => $options['awarded_amount'] ?? null,
        ]);

        if ($feedbackRaw) {
            $this->analyzeFeedback($rfpOutcome);
        }

        $opportunity->update(['status' => $outcome]);

        Log::info('RfpLearningService: outcome recorded', [
            'opportunity_id' => $opportunity->id,
            'outcome' => $outcome,
            'has_feedback' => ! empty($feedbackRaw),
        ]);

        return $rfpOutcome;
    }

    /**
     * AI-parse raw feedback text into structured insights.
     *
     * @return array{win_factors: array, loss_factors: array, competitor_info: array, price_comparison: array, lessons_learned: array}
     */
    public function analyzeFeedback(RfpOutcome $outcome): array
    {
        if (empty($outcome->feedback_raw)) {
            return [];
        }

        $prompt = <<<PROMPT
Analyze this RFP outcome feedback and extract structured insights.

Outcome: {$outcome->outcome}
Feedback:
{$outcome->feedback_raw}

Return a JSON object with these keys:
- "win_factors": array of strings describing why the proposal won (empty if lost)
- "loss_factors": array of strings describing why the proposal lost (empty if won)
- "competitor_info": array of objects with "name", "strength", and "weakness" keys
- "price_comparison": object with "our_price", "winning_price", "market_range", and "assessment" keys (use null for unknown values)
- "lessons_learned": array of strings with actionable takeaways

Return ONLY valid JSON, no markdown or explanation.
PROMPT;

        try {
            $result = $this->claude->message(
                prompt: $prompt,
                systemPrompt: 'You are an RFP outcome analyst. Extract structured data from feedback text. Always return valid JSON.',
                model: 'haiku',
                timeout: 60,
            );

            $text = $result['content'] ?? '';
            $structured = json_decode($text, true);

            if (! is_array($structured)) {
                Log::warning('RfpLearningService: failed to parse AI feedback analysis', [
                    'outcome_id' => $outcome->id,
                    'raw_response' => $text,
                ]);

                return [];
            }

            $outcome->update([
                'feedback_structured' => $structured,
                'win_factors' => $structured['win_factors'] ?? null,
                'loss_factors' => $structured['loss_factors'] ?? null,
                'competitor_info' => $structured['competitor_info'] ?? null,
                'price_comparison' => $structured['price_comparison'] ?? null,
                'lessons_learned' => $structured['lessons_learned'] ?? null,
            ]);

            Log::info('RfpLearningService: feedback analyzed', [
                'outcome_id' => $outcome->id,
                'win_factors_count' => count($structured['win_factors'] ?? []),
                'loss_factors_count' => count($structured['loss_factors'] ?? []),
            ]);

            return $structured;
        } catch (\Exception $e) {
            Log::error('RfpLearningService: feedback analysis failed', [
                'outcome_id' => $outcome->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get curated insights relevant to a specific opportunity.
     *
     * @return array<int, RfpLearningInsight>
     */
    public function getInsightsForProposal(RfpOpportunity $opportunity): array
    {
        $query = RfpLearningInsight::active()
            ->orderBy('confidence', 'desc');

        $insights = $query->get();

        $industry = strtolower($opportunity->organization_industry ?? '');
        $budgetMid = null;
        if ($opportunity->budget_min || $opportunity->budget_max) {
            $budgetMid = ($opportunity->budget_min + $opportunity->budget_max) / 2;
        }
        $sourceType = $opportunity->source_type;

        return $insights->sortByDesc(function (RfpLearningInsight $insight) use ($industry, $budgetMid, $sourceType) {
            $relevanceBoost = 0;

            $evidence = $insight->evidence ?? [];
            $evidenceText = strtolower(json_encode($evidence));

            if ($industry && str_contains($evidenceText, $industry)) {
                $relevanceBoost += 0.2;
            }

            if ($sourceType && str_contains($evidenceText, $sourceType)) {
                $relevanceBoost += 0.1;
            }

            if ($insight->impact_area === 'pricing' && $budgetMid !== null) {
                $relevanceBoost += 0.1;
            }

            return (float) $insight->confidence + $relevanceBoost;
        })->values()->all();
    }

    /**
     * Get historical win rate data.
     *
     * @return array<int, array{month: string, wins: int, losses: int, total: int, win_rate: float}>
     */
    public function getWinRateTrend(int $months = 6): array
    {
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();

        $outcomes = RfpOutcome::query()
            ->where('created_at', '>=', $startDate)
            ->whereIn('outcome', ['won', 'lost'])
            ->get();

        $trend = [];
        for ($i = 0; $i < $months; $i++) {
            $month = Carbon::now()->subMonths($months - 1 - $i)->startOfMonth();
            $monthKey = $month->format('Y-m');
            $monthLabel = $month->format('M Y');

            $monthOutcomes = $outcomes->filter(
                fn (RfpOutcome $o) => $o->created_at->format('Y-m') === $monthKey
            );

            $wins = $monthOutcomes->where('outcome', 'won')->count();
            $losses = $monthOutcomes->where('outcome', 'lost')->count();
            $total = $wins + $losses;

            $trend[] = [
                'month' => $monthLabel,
                'wins' => $wins,
                'losses' => $losses,
                'total' => $total,
                'win_rate' => $total > 0 ? round(($wins / $total) * 100, 1) : 0,
            ];
        }

        return $trend;
    }

    /**
     * Pre-submission quality check against learned patterns.
     *
     * @return array{score: int, max_score: int, checks: array, suggestions: array}
     */
    public function scoreProposalQuality(RfpProposal $proposal): array
    {
        $opportunity = $proposal->opportunity;
        $insights = $this->getInsightsForProposal($opportunity);

        $checks = [];
        $suggestions = [];
        $score = 0;
        $maxScore = 0;

        // Check pricing alignment
        $pricingInsights = collect($insights)->where('impact_area', 'pricing');
        if ($pricingInsights->isNotEmpty() && $proposal->total_price) {
            $maxScore += 25;
            $budgetMid = null;
            if ($opportunity->budget_min && $opportunity->budget_max) {
                $budgetMid = ($opportunity->budget_min + $opportunity->budget_max) / 2;
            }

            if ($budgetMid && abs($proposal->total_price - $budgetMid) / $budgetMid < 0.2) {
                $score += 25;
                $checks[] = ['label' => 'Pricing alignment', 'status' => 'pass', 'detail' => 'Price is within 20% of budget midpoint'];
            } elseif ($budgetMid) {
                $score += 10;
                $checks[] = ['label' => 'Pricing alignment', 'status' => 'warning', 'detail' => 'Price deviates significantly from budget midpoint'];
                $suggestions[] = 'Review pricing against budget range - historical data shows closer alignment improves win rates';
            } else {
                $score += 15;
                $checks[] = ['label' => 'Pricing alignment', 'status' => 'neutral', 'detail' => 'No budget range available for comparison'];
            }
        }

        // Check content completeness
        $contentInsights = collect($insights)->where('impact_area', 'content');
        $sections = $proposal->proposal_sections ?? [];
        $maxScore += 25;
        if (count($sections) >= 5) {
            $score += 25;
            $checks[] = ['label' => 'Content completeness', 'status' => 'pass', 'detail' => count($sections).' sections included'];
        } elseif (count($sections) >= 3) {
            $score += 15;
            $checks[] = ['label' => 'Content completeness', 'status' => 'warning', 'detail' => 'Only '.count($sections).' sections - winning proposals typically have 5+'];
            $suggestions[] = 'Add more proposal sections for a comprehensive response';
        } else {
            $score += 5;
            $checks[] = ['label' => 'Content completeness', 'status' => 'fail', 'detail' => 'Too few sections for a competitive proposal'];
            $suggestions[] = 'Significantly expand proposal content - winning proposals average 5+ sections';
        }

        // Check case studies and evidence
        $maxScore += 25;
        $caseStudies = $proposal->case_studies_used ?? [];
        $pastProjects = $proposal->past_projects_cited ?? [];
        $evidenceCount = count($caseStudies) + count($pastProjects);
        if ($evidenceCount >= 3) {
            $score += 25;
            $checks[] = ['label' => 'Evidence & social proof', 'status' => 'pass', 'detail' => $evidenceCount.' case studies/projects cited'];
        } elseif ($evidenceCount >= 1) {
            $score += 15;
            $checks[] = ['label' => 'Evidence & social proof', 'status' => 'warning', 'detail' => 'Only '.$evidenceCount.' evidence items'];
            $suggestions[] = 'Include more case studies and past project references';
        } else {
            $score += 0;
            $checks[] = ['label' => 'Evidence & social proof', 'status' => 'fail', 'detail' => 'No case studies or past projects cited'];
            $suggestions[] = 'Add relevant case studies and past project references - these significantly improve win rates';
        }

        // Apply content insight suggestions
        foreach ($contentInsights->take(3) as $insight) {
            if ($insight->actionable_recommendation) {
                $suggestions[] = $insight->actionable_recommendation;
            }
        }

        // Check executive summary
        $maxScore += 25;
        if (! empty($proposal->executive_summary) && strlen($proposal->executive_summary) >= 200) {
            $score += 25;
            $checks[] = ['label' => 'Executive summary', 'status' => 'pass', 'detail' => 'Substantive executive summary included'];
        } elseif (! empty($proposal->executive_summary)) {
            $score += 15;
            $checks[] = ['label' => 'Executive summary', 'status' => 'warning', 'detail' => 'Executive summary may be too brief'];
            $suggestions[] = 'Expand executive summary with specific value propositions and ROI metrics';
        } else {
            $score += 0;
            $checks[] = ['label' => 'Executive summary', 'status' => 'fail', 'detail' => 'Missing executive summary'];
            $suggestions[] = 'Add a compelling executive summary - this is often the most-read section';
        }

        return [
            'score' => $maxScore > 0 ? (int) round(($score / $maxScore) * 100) : 0,
            'max_score' => 100,
            'checks' => $checks,
            'suggestions' => array_unique($suggestions),
        ];
    }

    /**
     * Get competitive intelligence aggregated from outcomes.
     *
     * @return array{competitors: array, total_outcomes_with_competitor_data: int}
     */
    public function getCompetitiveIntelligence(): array
    {
        $outcomes = RfpOutcome::query()
            ->whereNotNull('competitor_info')
            ->get();

        $competitors = [];

        foreach ($outcomes as $outcome) {
            $competitorData = $outcome->competitor_info ?? [];

            foreach ($competitorData as $competitor) {
                $name = $competitor['name'] ?? 'Unknown';
                $normalizedName = strtolower(trim($name));

                if (! isset($competitors[$normalizedName])) {
                    $competitors[$normalizedName] = [
                        'name' => $name,
                        'encounters' => 0,
                        'wins_against_us' => 0,
                        'losses_against_us' => 0,
                        'strengths' => [],
                        'weaknesses' => [],
                    ];
                }

                $competitors[$normalizedName]['encounters']++;

                if ($outcome->outcome === 'lost' && ($outcome->awarded_to && strtolower(trim($outcome->awarded_to)) === $normalizedName)) {
                    $competitors[$normalizedName]['wins_against_us']++;
                } elseif ($outcome->outcome === 'won') {
                    $competitors[$normalizedName]['losses_against_us']++;
                }

                if (! empty($competitor['strength'])) {
                    $competitors[$normalizedName]['strengths'][] = $competitor['strength'];
                }

                if (! empty($competitor['weakness'])) {
                    $competitors[$normalizedName]['weaknesses'][] = $competitor['weakness'];
                }
            }
        }

        // Deduplicate strengths/weaknesses and sort by encounters
        $competitors = collect($competitors)->map(function (array $data) {
            $data['strengths'] = array_values(array_unique($data['strengths']));
            $data['weaknesses'] = array_values(array_unique($data['weaknesses']));

            return $data;
        })->sortByDesc('encounters')->values()->all();

        return [
            'competitors' => $competitors,
            'total_outcomes_with_competitor_data' => $outcomes->count(),
        ];
    }
}
