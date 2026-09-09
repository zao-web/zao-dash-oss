<?php

namespace App\Services\MetaAds;

use App\Models\ABTestGroup;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdSet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AdOptimizationService
{
    /**
     * Identify underperforming ads that should be paused
     */
    public function identifyLosers(AdCampaign $campaign): Collection
    {
        $losers = collect();

        foreach ($campaign->adSets as $adSet) {
            foreach ($adSet->ads as $ad) {
                if ($this->shouldPauseAd($ad)) {
                    $losers->push([
                        'ad' => $ad,
                        'reason' => $this->getLoseReason($ad),
                        'performance' => $ad->getLatestPerformance(),
                    ]);
                }
            }
        }

        return $losers;
    }

    /**
     * Identify high-performing ads that should be scaled
     */
    public function identifyWinners(AdCampaign $campaign): Collection
    {
        $winners = collect();

        foreach ($campaign->adSets as $adSet) {
            foreach ($adSet->ads as $ad) {
                if ($this->shouldScaleAd($ad, $campaign)) {
                    $winners->push([
                        'ad' => $ad,
                        'reason' => $this->getWinReason($ad, $campaign),
                        'performance' => $ad->getLatestPerformance(),
                        'scale_factor' => $this->calculateScaleFactor($ad),
                    ]);
                }
            }
        }

        return $winners;
    }

    /**
     * Calculate performance score for an ad (0-100)
     */
    public function calculatePerformanceScore(Ad $ad, array $weights = []): float
    {
        $defaultWeights = [
            'ctr' => 0.3,
            'cpa' => 0.4,
            'conversions' => 0.2,
            'roas' => 0.1,
        ];

        $weights = array_merge($defaultWeights, $weights);
        $performance = $ad->getLatestPerformance();

        if (! $performance) {
            return 0;
        }

        $score = 0;

        // CTR score (normalize to 0-10% range)
        if ($performance->ctr) {
            $ctrScore = min(($performance->ctr / 10) * 100, 100);
            $score += $ctrScore * $weights['ctr'];
        }

        // CPA score (inverse - lower is better, normalize to $0-$100 range)
        if ($performance->cpa) {
            $cpaScore = max(0, 100 - ($performance->cpa / 100) * 100);
            $score += $cpaScore * $weights['cpa'];
        }

        // Conversions score (normalize to 0-100 conversions range)
        $conversionScore = min(($performance->conversions / 100) * 100, 100);
        $score += $conversionScore * $weights['conversions'];

        // ROAS score (normalize to 0-10 range)
        if ($performance->roas) {
            $roasScore = min(($performance->roas / 10) * 100, 100);
            $score += $roasScore * $weights['roas'];
        }

        return round($score, 2);
    }

    /**
     * Reallocate campaign budget based on ad set performance
     */
    public function reallocateBudget(AdCampaign $campaign): array
    {
        $adSets = $campaign->adSets()->where('status', 'active')->get();

        if ($adSets->isEmpty()) {
            return [];
        }

        // Calculate performance scores for each ad set
        $scores = [];
        $totalScore = 0;

        foreach ($adSets as $adSet) {
            $adSetScore = 0;
            $adCount = 0;

            foreach ($adSet->ads as $ad) {
                $adSetScore += $this->calculatePerformanceScore($ad);
                $adCount++;
            }

            $averageScore = $adCount > 0 ? $adSetScore / $adCount : 0;
            $scores[$adSet->id] = $averageScore;
            $totalScore += $averageScore;
        }

        if ($totalScore == 0) {
            return [];
        }

        // Allocate budget proportionally to performance
        $totalBudget = $campaign->daily_budget;
        $allocations = [];

        foreach ($adSets as $adSet) {
            $proportion = $scores[$adSet->id] / $totalScore;
            $newBudget = $totalBudget * $proportion;

            // Apply constraints
            $newBudget = max($newBudget, 10); // Minimum $10/day
            $newBudget = min($newBudget, $totalBudget * 0.5); // Maximum 50% of campaign budget

            $allocations[$adSet->id] = [
                'current_budget' => $adSet->daily_budget,
                'new_budget' => round($newBudget, 2),
                'change_percent' => round((($newBudget - $adSet->daily_budget) / $adSet->daily_budget) * 100, 2),
                'performance_score' => $scores[$adSet->id],
            ];
        }

        return $allocations;
    }

    /**
     * Calculate optimal budget for an ad set based on target CPA
     */
    public function calculateOptimalBudget(AdSet $adSet, float $targetCPA): float
    {
        $performance = $adSet->performance()->latest('date')->first();

        if (! $performance || ! $performance->cpa) {
            return $adSet->daily_budget;
        }

        // If performing better than target, increase budget
        if ($performance->cpa < $targetCPA) {
            $scaleFactor = 1 + (($targetCPA - $performance->cpa) / $targetCPA);
            $scaleFactor = min($scaleFactor, 1.5); // Cap at 50% increase per adjustment

            return round($adSet->daily_budget * $scaleFactor, 2);
        }

        // If performing worse than target, decrease budget
        if ($performance->cpa > $targetCPA) {
            $scaleFactor = 1 - (($performance->cpa - $targetCPA) / $performance->cpa);
            $scaleFactor = max($scaleFactor, 0.7); // Cap at 30% decrease per adjustment

            return round($adSet->daily_budget * $scaleFactor, 2);
        }

        return $adSet->daily_budget;
    }

    /**
     * Scale a winning ad
     */
    public function scaleWinner(Ad $ad, float $scaleFactor = 1.2): bool
    {
        $adSet = $ad->adSet;
        $newBudget = round($adSet->daily_budget * $scaleFactor, 2);

        // Don't scale beyond campaign budget constraints
        $campaign = $adSet->adCampaign;
        $maxBudget = $campaign->daily_budget * 0.5;
        $newBudget = min($newBudget, $maxBudget);

        $adSet->update(['daily_budget' => $newBudget]);

        Log::info("Scaled winner ad #{$ad->id}, increased budget from \${$adSet->daily_budget} to \${$newBudget}");

        return true;
    }

    /**
     * Analyze A/B test results
     */
    public function analyzeABTest(ABTestGroup $testGroup): array
    {
        if (! $testGroup->isRunning()) {
            return ['status' => 'not_running'];
        }

        // Get control and variant performance
        $variants = $testGroup->adCampaign->adSets()
            ->with('ads.performance')
            ->get();

        if ($variants->count() < 2) {
            return ['status' => 'insufficient_variants'];
        }

        $control = null;
        $variantAds = [];

        foreach ($variants as $variant) {
            foreach ($variant->ads as $ad) {
                $creativeVariant = $ad->creativeVariants()->where('ab_test_group_id', $testGroup->id)->first();
                if ($creativeVariant) {
                    if ($creativeVariant->is_control) {
                        $control = $ad;
                    } else {
                        $variantAds[] = $ad;
                    }
                }
            }
        }

        if (! $control || empty($variantAds)) {
            return ['status' => 'incomplete_test'];
        }

        $results = [];

        foreach ($variantAds as $variantAd) {
            $controlPerf = $control->getLatestPerformance();
            $variantPerf = $variantAd->getLatestPerformance();

            if (! $controlPerf || ! $variantPerf) {
                continue;
            }

            // Calculate statistical significance
            $pValue = $this->calculateStatisticalSignificance(
                [
                    'impressions' => $controlPerf->impressions,
                    'clicks' => $controlPerf->clicks,
                    'conversions' => $controlPerf->conversions,
                ],
                [
                    'impressions' => $variantPerf->impressions,
                    'clicks' => $variantPerf->clicks,
                    'conversions' => $variantPerf->conversions,
                ]
            );

            $results[] = [
                'variant_ad_id' => $variantAd->id,
                'control_ctr' => $controlPerf->ctr,
                'variant_ctr' => $variantPerf->ctr,
                'ctr_lift' => $this->calculateLift($controlPerf->ctr, $variantPerf->ctr),
                'control_cpa' => $controlPerf->cpa,
                'variant_cpa' => $variantPerf->cpa,
                'cpa_improvement' => $this->calculateImprovement($controlPerf->cpa, $variantPerf->cpa),
                'p_value' => $pValue,
                'is_significant' => $pValue < 0.05,
                'confidence_level' => (1 - $pValue) * 100,
            ];
        }

        return [
            'status' => 'analyzed',
            'test_group_id' => $testGroup->id,
            'results' => $results,
        ];
    }

    /**
     * Calculate statistical significance using chi-square test
     */
    public function calculateStatisticalSignificance(
        array $control,
        array $variant
    ): float {
        $controlImpressions = $control['impressions'];
        $controlClicks = $control['clicks'];
        $variantImpressions = $variant['impressions'];
        $variantClicks = $variant['clicks'];

        // Need minimum sample size
        if ($controlImpressions < 500 || $variantImpressions < 500) {
            return 1.0; // Not significant
        }

        $totalImpressions = $controlImpressions + $variantImpressions;
        $totalClicks = $controlClicks + $variantClicks;

        $expectedControlClicks = ($totalClicks * $controlImpressions) / $totalImpressions;
        $expectedVariantClicks = ($totalClicks * $variantImpressions) / $totalImpressions;

        // Avoid division by zero
        if ($expectedControlClicks == 0 || $expectedVariantClicks == 0) {
            return 1.0;
        }

        $chiSquare =
            pow($controlClicks - $expectedControlClicks, 2) / $expectedControlClicks +
            pow($variantClicks - $expectedVariantClicks, 2) / $expectedVariantClicks;

        // Convert chi-square to p-value (approximation)
        $pValue = $this->chiSquareToPValue($chiSquare, 1);

        return $pValue;
    }

    /**
     * Declare winner of A/B test
     */
    public function declareWinner(ABTestGroup $testGroup): ?Ad
    {
        $analysis = $this->analyzeABTest($testGroup);

        if ($analysis['status'] !== 'analyzed') {
            return null;
        }

        $bestResult = null;
        $bestVariantId = null;

        foreach ($analysis['results'] as $result) {
            if (! $result['is_significant']) {
                continue;
            }

            // Prefer lower CPA if significant
            if ($result['cpa_improvement'] > 0) {
                if (! $bestResult || $result['variant_cpa'] < $bestResult['variant_cpa']) {
                    $bestResult = $result;
                    $bestVariantId = $result['variant_ad_id'];
                }
            }
        }

        if ($bestVariantId) {
            $winnerAd = Ad::find($bestVariantId);
            $testGroup->markAsCompleted($bestVariantId);

            Log::info('A/B test winner declared', [
                'test_group_id' => $testGroup->id,
                'winner_ad_id' => $bestVariantId,
                'confidence' => $bestResult['confidence_level'],
            ]);

            return $winnerAd;
        }

        return null;
    }

    /**
     * Check if an ad should be paused
     */
    public function shouldPauseAd(Ad $ad): bool
    {
        $performance = $ad->getLatestPerformance();

        if (! $performance) {
            return false;
        }

        // Spent >$20 with 0 conversions
        if ($performance->spend > 20 && $performance->conversions == 0) {
            return true;
        }

        // CTR <0.5%
        if ($performance->ctr < 0.5 && $performance->impressions > 1000) {
            return true;
        }

        // Frequency >5 (ad fatigue)
        if ($performance->frequency && $performance->frequency > 5) {
            return true;
        }

        // CPA >2x campaign target (if set)
        $campaign = $ad->adSet->adCampaign;
        if ($campaign->performance_goal && isset($campaign->performance_goal['target_cpa'])) {
            $targetCPA = $campaign->performance_goal['target_cpa'];
            if ($performance->cpa && $performance->cpa > ($targetCPA * 2) && $performance->conversions > 50) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an ad should be scaled
     */
    public function shouldScaleAd(Ad $ad, AdCampaign $campaign): bool
    {
        $performance = $ad->getLatestPerformance();

        if (! $performance) {
            return false;
        }

        // Need minimum conversions for confidence
        if ($performance->conversions < 30) {
            return false;
        }

        $targetCPA = $campaign->performance_goal['target_cpa'] ?? null;
        $targetROAS = $campaign->performance_goal['target_roas'] ?? null;

        // CPA <80% of target with good volume
        if ($targetCPA && $performance->cpa && $performance->cpa < ($targetCPA * 0.8)) {
            return true;
        }

        // ROAS >target with good spend
        if ($targetROAS && $performance->roas && $performance->roas > $targetROAS && $performance->spend > 100) {
            return true;
        }

        // CTR >2% with good conversion rate
        if ($performance->ctr > 2.0 && $performance->conversions > 50) {
            return true;
        }

        return false;
    }

    /**
     * Check if ad set bid should be adjusted
     */
    public function shouldAdjustBid(AdSet $adSet): ?float
    {
        $performance = $adSet->performance()->latest('date')->first();

        if (! $performance || ! $performance->cpc) {
            return null;
        }

        $currentBid = $adSet->bid_amount ?? 0;

        // If CPC is trending down, we can afford to bid lower
        $recentPerformance = $adSet->performance()
            ->orderBy('date', 'desc')
            ->limit(7)
            ->get();

        if ($recentPerformance->count() < 7) {
            return null;
        }

        $avgCPC = $recentPerformance->avg('cpc');
        $latestCPC = $performance->cpc;

        // CPC trending down significantly (>20%)
        if ($latestCPC < ($avgCPC * 0.8)) {
            return round($currentBid * 0.9, 2); // Reduce bid by 10%
        }

        // CPC trending up significantly (>20%)
        if ($latestCPC > ($avgCPC * 1.2)) {
            return round($currentBid * 1.1, 2); // Increase bid by 10%
        }

        return null;
    }

    /**
     * Get reason why ad is a loser
     */
    protected function getLoseReason(Ad $ad): string
    {
        $performance = $ad->getLatestPerformance();

        if ($performance->spend > 20 && $performance->conversions == 0) {
            return "Spent \${$performance->spend} with zero conversions";
        }

        if ($performance->ctr < 0.5 && $performance->impressions > 1000) {
            return "CTR too low ({$performance->ctr}%)";
        }

        if ($performance->frequency && $performance->frequency > 5) {
            return "Ad fatigue (frequency: {$performance->frequency})";
        }

        return 'Underperforming';
    }

    /**
     * Get reason why ad is a winner
     */
    protected function getWinReason(Ad $ad, AdCampaign $campaign): string
    {
        $performance = $ad->getLatestPerformance();
        $targetCPA = $campaign->performance_goal['target_cpa'] ?? null;

        if ($targetCPA && $performance->cpa && $performance->cpa < ($targetCPA * 0.8)) {
            $improvement = round((($targetCPA - $performance->cpa) / $targetCPA) * 100);

            return "CPA {$improvement}% better than target (\${$performance->cpa} vs \${$targetCPA})";
        }

        if ($performance->ctr > 2.0) {
            return "Exceptional CTR ({$performance->ctr}%)";
        }

        return 'High performer';
    }

    /**
     * Calculate scale factor based on performance
     */
    protected function calculateScaleFactor(Ad $ad): float
    {
        $performance = $ad->getLatestPerformance();

        // Base scale factor
        $scaleFactor = 1.2; // 20% increase

        // Increase more for exceptional performance
        if ($performance->ctr > 3.0) {
            $scaleFactor = 1.5; // 50% increase
        }

        if ($performance->ctr > 5.0) {
            $scaleFactor = 2.0; // 100% increase
        }

        return $scaleFactor;
    }

    /**
     * Calculate lift percentage
     */
    protected function calculateLift(float $control, float $variant): float
    {
        if ($control == 0) {
            return 0;
        }

        return round((($variant - $control) / $control) * 100, 2);
    }

    /**
     * Calculate improvement (inverse for CPA - lower is better)
     */
    protected function calculateImprovement(float $control, float $variant): float
    {
        if ($control == 0) {
            return 0;
        }

        return round((($control - $variant) / $control) * 100, 2);
    }

    /**
     * Convert chi-square statistic to p-value (approximation)
     */
    protected function chiSquareToPValue(float $chiSquare, int $degreesOfFreedom): float
    {
        // Simplified approximation for 1 degree of freedom
        if ($degreesOfFreedom !== 1) {
            return 0.5; // Default to inconclusive for other cases
        }

        // Critical values for common significance levels
        $criticalValues = [
            0.05 => 3.841, // 95% confidence
            0.01 => 6.635, // 99% confidence
            0.001 => 10.828, // 99.9% confidence
        ];

        if ($chiSquare >= $criticalValues[0.001]) {
            return 0.001;
        }

        if ($chiSquare >= $criticalValues[0.01]) {
            return 0.01;
        }

        if ($chiSquare >= $criticalValues[0.05]) {
            return 0.05;
        }

        // Not significant
        return 0.5;
    }
}
