<?php

namespace App\Services\Rfp;

use App\Models\Client;
use App\Models\Project;
use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RfpEvaluationService
{
    /**
     * Agency capabilities used for tech stack matching.
     *
     * @var array<int, string>
     */
    protected array $agencyCapabilities = [
        'wordpress', 'laravel', 'php', 'vue', 'react', 'inertia', 'tailwind',
        'ai', 'machine learning', 'seo', 'accessibility', 'wcag', 'crm',
        'api', 'rest', 'graphql', 'shopify', 'woocommerce', 'drupal',
        'joomla', 'migration', 'cms', 'digital', 'web design', 'web development',
    ];

    /**
     * Budget range constants for the agency.
     */
    protected const BUDGET_MIN = 20000;

    protected const BUDGET_MAX = 350000;

    protected const BUDGET_SWEET_SPOT_MIN = 30000;

    protected const BUDGET_SWEET_SPOT_MAX = 150000;

    protected const BUDGET_TOO_LOW = 15000;

    /**
     * Evaluate an RFP opportunity across all scoring dimensions.
     *
     * @return array{fit_score: int, fit_score_breakdown: array, recommended_status: string, recommended_priority: string}
     */
    public function evaluate(RfpOpportunity $opportunity): array
    {
        $techResult = $this->scoreTechStack($opportunity);
        $industryResult = $this->scoreIndustryExperience($opportunity);
        $budgetResult = $this->scoreBudgetAlignment($opportunity);
        $timelineResult = $this->scoreTimeline($opportunity);
        $winProbResult = $this->scoreWinProbability($opportunity);
        $declineResult = $this->scoreDeclinePatterns($opportunity);

        $totalScore = $techResult['score'] + $industryResult['score'] + $budgetResult['score']
            + $timelineResult['score'] + $winProbResult['score'] + $declineResult['score'];

        // Clamp total to 0-100
        $totalScore = max(0, min(100, $totalScore));

        $breakdown = [
            'tech_stack' => [
                'score' => $techResult['score'],
                'max' => 25,
                'details' => $techResult['details'],
            ],
            'industry' => [
                'score' => $industryResult['score'],
                'max' => 25,
                'details' => $industryResult['details'],
            ],
            'budget' => [
                'score' => $budgetResult['score'],
                'max' => 20,
                'details' => $budgetResult['details'],
            ],
            'timeline' => [
                'score' => $timelineResult['score'],
                'max' => 15,
                'details' => $timelineResult['details'],
            ],
            'win_probability' => [
                'score' => $winProbResult['score'],
                'max' => 15,
                'details' => $winProbResult['details'],
            ],
            'decline_history' => [
                'score' => $declineResult['score'],
                'max' => 0,
                'details' => $declineResult['details'],
            ],
        ];

        // Auto-decline if the submission deadline has already passed
        $isExpired = $opportunity->submission_deadline && $opportunity->submission_deadline->isPast();

        $recommendedStatus = $isExpired ? 'declined' : $this->determineStatus($totalScore);
        $recommendedPriority = $isExpired ? 'low' : $this->determinePriority($totalScore);

        Log::info('RfpEvaluationService: evaluated opportunity', [
            'opportunity_id' => $opportunity->id,
            'title' => $opportunity->title,
            'fit_score' => $totalScore,
            'recommended_status' => $recommendedStatus,
            'recommended_priority' => $recommendedPriority,
        ]);

        return [
            'fit_score' => $totalScore,
            'fit_score_breakdown' => $breakdown,
            'recommended_status' => $recommendedStatus,
            'recommended_priority' => $recommendedPriority,
        ];
    }

    /**
     * Score tech stack match (0-25).
     *
     * @return array{score: int, details: string}
     */
    protected function scoreTechStack(RfpOpportunity $opportunity): array
    {
        $techRequirements = $opportunity->tech_requirements ?? [];

        if (empty($techRequirements)) {
            return [
                'score' => 15,
                'details' => 'No tech requirements specified; default neutral score applied.',
            ];
        }

        $normalizedRequirements = array_map(fn (string $req) => strtolower(trim($req)), $techRequirements);
        $matchedCapabilities = [];
        $unmatchedRequirements = [];

        foreach ($normalizedRequirements as $requirement) {
            $matched = false;
            foreach ($this->agencyCapabilities as $capability) {
                if (str_contains($requirement, $capability) || str_contains($capability, $requirement)) {
                    $matchedCapabilities[] = $requirement;
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $unmatchedRequirements[] = $requirement;
            }
        }

        $totalRequirements = count($normalizedRequirements);
        $matchCount = count($matchedCapabilities);
        $matchRatio = $matchCount / $totalRequirements;
        $score = (int) round($matchRatio * 25);

        $matchedList = implode(', ', $matchedCapabilities);
        $unmatchedList = ! empty($unmatchedRequirements) ? implode(', ', $unmatchedRequirements) : 'none';

        return [
            'score' => $score,
            'details' => "Matched {$matchCount}/{$totalRequirements} requirements ({$matchedList}). Unmatched: {$unmatchedList}.",
        ];
    }

    /**
     * Score industry experience (0-25).
     *
     * @return array{score: int, details: string}
     */
    protected function scoreIndustryExperience(RfpOpportunity $opportunity): array
    {
        $industry = strtolower($opportunity->organization_industry ?? '');

        if (empty($industry)) {
            return [
                'score' => 10,
                'details' => 'Industry not specified for the issuing organization; default neutral score applied.',
            ];
        }

        // Search for projects with clients whose description mentions the industry
        $projectCount = Project::whereHas('client', function ($q) use ($industry) {
            $q->whereRaw('LOWER(description) LIKE ?', ["%{$industry}%"]);
        })->count();

        // Also check client names for industry keywords
        $clientCount = Client::query()
            ->where(function ($q) use ($industry) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$industry}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$industry}%"]);
            })->count();

        // Also check project descriptions for industry keywords
        $projectDescCount = Project::query()
            ->whereRaw('LOWER(description) LIKE ?', ["%{$industry}%"])
            ->count();

        $totalRelevant = max($projectCount, $projectDescCount);

        if ($totalRelevant >= 3) {
            $score = 25;
            $details = "Strong industry experience: found {$totalRelevant} past projects and {$clientCount} clients in {$industry}.";
        } elseif ($totalRelevant === 2) {
            $score = 20;
            $details = "Good industry experience: found {$totalRelevant} past projects and {$clientCount} clients in {$industry}.";
        } elseif ($totalRelevant === 1) {
            $score = 15;
            $details = "Some industry experience: found {$totalRelevant} past project and {$clientCount} clients in {$industry}.";
        } elseif ($clientCount > 0) {
            $score = 10;
            $details = "Adjacent industry experience: found {$clientCount} clients related to {$industry}, but no direct project matches.";
        } else {
            $score = 5;
            $details = "No prior experience found in {$industry}.";
        }

        return [
            'score' => $score,
            'details' => $details,
        ];
    }

    /**
     * Score budget alignment (0-20).
     *
     * @return array{score: int, details: string}
     */
    protected function scoreBudgetAlignment(RfpOpportunity $opportunity): array
    {
        $budgetMin = $opportunity->budget_min ? (float) $opportunity->budget_min : null;
        $budgetMax = $opportunity->budget_max ? (float) $opportunity->budget_max : null;

        if ($budgetMin === null && $budgetMax === null) {
            return [
                'score' => 12,
                'details' => 'Budget not specified; default neutral score applied.',
            ];
        }

        // Use the midpoint if both are available, otherwise use whichever exists
        $representativeBudget = match (true) {
            $budgetMin !== null && $budgetMax !== null => ($budgetMin + $budgetMax) / 2,
            $budgetMax !== null => $budgetMax,
            default => $budgetMin,
        };

        $budgetDisplay = '$'.number_format($representativeBudget, 0);

        if ($representativeBudget >= self::BUDGET_SWEET_SPOT_MIN && $representativeBudget <= self::BUDGET_SWEET_SPOT_MAX) {
            return [
                'score' => 20,
                'details' => "Budget ({$budgetDisplay}) is in the sweet spot range (\$30K-\$150K).",
            ];
        }

        if ($representativeBudget >= self::BUDGET_MIN && $representativeBudget <= self::BUDGET_MAX) {
            return [
                'score' => 15,
                'details' => "Budget ({$budgetDisplay}) is within agency range (\$20K-\$350K) but outside the sweet spot.",
            ];
        }

        if ($representativeBudget > self::BUDGET_MAX) {
            return [
                'score' => 10,
                'details' => "Budget ({$budgetDisplay}) exceeds typical agency range (\$350K+). Possible but would stretch resources.",
            ];
        }

        if ($representativeBudget < self::BUDGET_TOO_LOW) {
            return [
                'score' => 5,
                'details' => "Budget ({$budgetDisplay}) is below minimum threshold (\$15K). Likely not profitable.",
            ];
        }

        // Between $15K and $20K
        return [
            'score' => 10,
            'details' => "Budget ({$budgetDisplay}) is marginally below typical agency range but may still be viable.",
        ];
    }

    /**
     * Score timeline feasibility (0-15).
     *
     * @return array{score: int, details: string}
     */
    protected function scoreTimeline(RfpOpportunity $opportunity): array
    {
        if (! $opportunity->submission_deadline) {
            return [
                'score' => 10,
                'details' => 'No submission deadline specified; default neutral score applied.',
            ];
        }

        $deadline = Carbon::parse($opportunity->submission_deadline);
        $now = Carbon::now();

        if ($deadline->isPast()) {
            return [
                'score' => 0,
                'details' => "Deadline already passed ({$deadline->toDateString()}). Opportunity expired.",
            ];
        }

        $weeksUntilDeadline = $now->diffInWeeks($deadline);
        $daysUntilDeadline = $now->diffInDays($deadline);

        if ($weeksUntilDeadline >= 4) {
            return [
                'score' => 15,
                'details' => "Deadline is {$daysUntilDeadline} days away ({$deadline->toDateString()}). Ample time for preparation.",
            ];
        }

        if ($weeksUntilDeadline >= 2) {
            return [
                'score' => 10,
                'details' => "Deadline is {$daysUntilDeadline} days away ({$deadline->toDateString()}). Tight but feasible.",
            ];
        }

        return [
            'score' => 5,
            'details' => "Deadline is only {$daysUntilDeadline} days away ({$deadline->toDateString()}). Very tight timeline.",
        ];
    }

    /**
     * Score win probability (0-15).
     *
     * @return array{score: int, details: string}
     */
    protected function scoreWinProbability(RfpOpportunity $opportunity): array
    {
        $score = 8; // Default baseline
        $factors = [];

        // Source type assessment
        $sourceType = $opportunity->source_type ?? '';
        $sourceScore = match ($sourceType) {
            'manual' => 4,
            'email_teaser' => 3, // Curated digest (e.g. Folyo) — higher signal than board scraping
            'rfp_board' => 1,
            'web_scrape' => 0,
            default => 1,
        };
        $score = $sourceScore + 5; // Base 5 + source bonus

        if ($sourceScore >= 3) {
            $factors[] = 'Direct/manual source increases win likelihood';
        } elseif ($sourceScore <= 1) {
            $factors[] = 'Aggregator/board source suggests higher competition';
        }

        // Check for existing relationship with organization
        $orgName = strtolower($opportunity->issuing_organization ?? '');
        if (! empty($orgName)) {
            $existingClient = Client::query()
                ->whereRaw('LOWER(name) LIKE ?', ["%{$orgName}%"])
                ->exists();

            if ($existingClient) {
                $score = min(15, $score + 5);
                $factors[] = "Existing client relationship found with {$opportunity->issuing_organization}";
            }
        }

        // Government/municipal RFPs tend to have more competing bidders
        $isGovernment = str_contains(strtolower($orgName), 'government')
            || str_contains(strtolower($orgName), 'state of')
            || str_contains(strtolower($orgName), 'city of')
            || str_contains(strtolower($orgName), 'county')
            || str_contains(strtolower($orgName), 'department');

        if ($isGovernment) {
            $score = max(3, $score - 2);
            $factors[] = 'Government RFP likely has many competing bidders';
        }

        $score = max(0, min(15, $score));
        $factorsSummary = ! empty($factors) ? implode('. ', $factors).'.' : 'Insufficient data for detailed win probability assessment.';

        return [
            'score' => $score,
            'details' => $factorsSummary,
        ];
    }

    /**
     * Score based on past decline patterns (0 to -20 penalty).
     *
     * Checks if this opportunity matches patterns from previously declined RFPs:
     * same organization, same industry decline category, or similar characteristics.
     *
     * @return array{score: int, details: string}
     */
    protected function scoreDeclinePatterns(RfpOpportunity $opportunity): array
    {
        $insights = RfpLearningInsight::query()
            ->where('insight_type', 'decline_pattern')
            ->where('is_active', true)
            ->get();

        if ($insights->isEmpty()) {
            return [
                'score' => 0,
                'details' => 'No decline history to reference.',
            ];
        }

        $penalty = 0;
        $reasons = [];
        $orgName = strtolower($opportunity->issuing_organization ?? '');
        $industry = strtolower($opportunity->organization_industry ?? '');

        foreach ($insights as $insight) {
            $evidence = $insight->evidence ?? [];

            // Exact org match — strong signal
            $declinedOrg = strtolower($evidence['organization'] ?? '');
            if (! empty($orgName) && ! empty($declinedOrg) && str_contains($orgName, $declinedOrg)) {
                $penalty -= 10;
                $reasons[] = "Previously declined RFP from {$evidence['organization']}";

                continue;
            }

            // Same industry + same decline category
            $declinedIndustry = strtolower($evidence['organization_industry'] ?? '');
            $category = $evidence['category'] ?? '';

            if (! empty($industry) && ! empty($declinedIndustry) && $industry === $declinedIndustry) {
                if (in_array($category, ['wrong_industry', 'scope_mismatch', 'not_qualified'])) {
                    $penalty -= 5;
                    $reasons[] = "Similar {$declinedIndustry} industry declined ({$category})";
                }
            }

            // Budget pattern matching
            if ($category === 'budget_too_low' && $opportunity->budget_max) {
                $declinedMax = $evidence['budget_max'] ?? null;
                if ($declinedMax && (float) $opportunity->budget_max <= (float) $declinedMax) {
                    $penalty -= 3;
                    $reasons[] = 'Budget similar to previously declined (too low)';
                }
            }
        }

        // Cap the penalty at -20
        $penalty = max(-20, $penalty);

        if ($penalty === 0) {
            return [
                'score' => 0,
                'details' => 'No matching decline patterns found.',
            ];
        }

        return [
            'score' => $penalty,
            'details' => implode('. ', array_unique($reasons)).'.',
        ];
    }

    /**
     * Determine the recommended status based on total score.
     */
    protected function determineStatus(int $totalScore): string
    {
        if ($totalScore >= 50) {
            return 'qualified';
        }

        if ($totalScore >= 35) {
            return 'evaluating';
        }

        return 'declined';
    }

    /**
     * Determine the recommended priority based on total score.
     */
    protected function determinePriority(int $totalScore): string
    {
        if ($totalScore >= 80) {
            return 'high';
        }

        if ($totalScore >= 60) {
            return 'medium';
        }

        return 'low';
    }
}
