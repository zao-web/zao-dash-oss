<?php

namespace App\Services\PersonalFinance;

use App\Models\Debt;
use App\Models\NetWorthSnapshot;
use App\Models\NorthStarGoal;
use App\Models\NorthStarMilestone;
use App\Models\NorthStarProgress;
use App\Models\PersonalAccount;

class NorthStarService
{
    /**
     * Get the active North Star goal with computed progress.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveGoal(int $userId): ?array
    {
        $goal = NorthStarGoal::where('user_id', $userId)
            ->where('status', 'active')
            ->with(['milestones' => fn ($q) => $q->orderBy('order')])
            ->first();

        if (! $goal) {
            return null;
        }

        $this->syncMilestoneProgress($goal);

        $milestones = $goal->milestones->map(fn ($m) => [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'order' => $m->order,
            'target_amount' => (float) $m->target_amount,
            'current_amount' => (float) $m->current_amount,
            'percent_complete' => $m->percentComplete(),
            'status' => $m->status,
            'target_date' => $m->target_date?->format('M Y'),
            'estimated_completion' => $m->estimated_completion?->format('M Y'),
            'completed_at' => $m->completed_at?->format('M d, Y'),
            'is_current' => $m->status === 'in_progress',
        ]);

        $overallProgress = $goal->overallProgress();
        $currentMilestone = $milestones->firstWhere('status', 'in_progress')
            ?? $milestones->firstWhere('status', 'pending');

        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'description' => $goal->description,
            'why' => $goal->why,
            'total_cost_estimate' => (float) $goal->total_cost_estimate,
            'target_date' => $goal->target_date?->format('M Y'),
            'overall_progress' => round($overallProgress, 1),
            'milestones' => $milestones,
            'current_milestone' => $currentMilestone,
            'estimated_completion' => $this->estimateOverallCompletion($goal),
            'days_on_journey' => $goal->created_at->diffInDays(now()),
            'velocity' => $this->calculateVelocity($goal),
        ];
    }

    /**
     * Sync milestone progress from real financial data.
     *
     * This is what makes milestones ALIVE — not manually updated but auto-computed.
     */
    protected function syncMilestoneProgress(NorthStarGoal $goal): void
    {
        foreach ($goal->milestones as $milestone) {
            $newAmount = match ($milestone->tracking_method) {
                'debt_total' => $this->calculateDebtProgress($milestone, $goal->user_id),
                'savings_total' => $this->calculateSavingsProgress($milestone, $goal->user_id),
                'net_worth' => $this->calculateNetWorthProgress($goal->user_id),
                default => (float) $milestone->current_amount,
            };

            if ($newAmount != (float) $milestone->current_amount) {
                $milestone->update(['current_amount' => $newAmount]);

                if ($milestone->target_amount > 0 && $newAmount >= $milestone->target_amount && $milestone->status !== 'completed') {
                    $milestone->markCompleted();

                    $next = NorthStarMilestone::where('north_star_goal_id', $goal->id)
                        ->where('order', '>', $milestone->order)
                        ->where('status', 'pending')
                        ->orderBy('order')
                        ->first();

                    if ($next) {
                        $next->update(['status' => 'in_progress']);
                    }
                }
            }
        }
    }

    /**
     * For debt milestones: progress = original debt - current debt (how much debt has been paid off).
     */
    protected function calculateDebtProgress(NorthStarMilestone $milestone, int $userId): float
    {
        $config = $milestone->tracking_config ?? [];
        $debtTypes = $config['debt_types'] ?? null;

        $query = Debt::where('user_id', $userId);
        if ($debtTypes) {
            $query->whereIn('debt_type', $debtTypes);
        }

        $totalOriginal = (float) (clone $query)->sum('original_amount');
        $totalCurrent = (float) $query->where('status', '!=', 'paid_off')->sum('current_balance');

        return max(0, $totalOriginal - $totalCurrent);
    }

    /**
     * For savings milestones: sum of matching account balances.
     */
    protected function calculateSavingsProgress(NorthStarMilestone $milestone, int $userId): float
    {
        $config = $milestone->tracking_config ?? [];
        $accountTypes = $config['account_types'] ?? ['savings', 'investment'];

        return (float) PersonalAccount::where('user_id', $userId)
            ->whereIn('account_type', $accountTypes)
            ->where('is_closed', false)
            ->sum('current_balance');
    }

    /**
     * For net worth milestones: latest net worth snapshot value.
     */
    protected function calculateNetWorthProgress(int $userId): float
    {
        $latest = NetWorthSnapshot::where('user_id', $userId)
            ->orderBy('snapshot_date', 'desc')
            ->first();

        return (float) ($latest?->net_worth ?? 0);
    }

    /**
     * Estimate when the overall goal will be complete based on velocity.
     */
    protected function estimateOverallCompletion(NorthStarGoal $goal): ?string
    {
        $progress = NorthStarProgress::where('north_star_goal_id', $goal->id)
            ->orderBy('snapshot_date', 'desc')
            ->limit(30)
            ->get();

        if ($progress->count() < 7) {
            return null;
        }

        $oldest = $progress->last();
        $newest = $progress->first();
        $daysBetween = $oldest->snapshot_date->diffInDays($newest->snapshot_date);

        if ($daysBetween < 1) {
            return null;
        }

        $progressPerDay = ($newest->overall_progress_percent - $oldest->overall_progress_percent) / $daysBetween;

        if ($progressPerDay <= 0) {
            return 'Not enough progress to estimate';
        }

        $remainingProgress = 100 - $newest->overall_progress_percent;
        $daysRemaining = (int) ceil($remainingProgress / $progressPerDay);

        return now()->addDays($daysRemaining)->format('M Y');
    }

    /**
     * Calculate 30-day velocity metrics.
     *
     * @return array{progress_last_30_days: float, direction: string}|null
     */
    protected function calculateVelocity(NorthStarGoal $goal): ?array
    {
        $thirtyDaysAgo = NorthStarProgress::where('north_star_goal_id', $goal->id)
            ->where('snapshot_date', '<=', now()->subDays(30))
            ->orderBy('snapshot_date', 'desc')
            ->first();

        $today = NorthStarProgress::where('north_star_goal_id', $goal->id)
            ->orderBy('snapshot_date', 'desc')
            ->first();

        if (! $thirtyDaysAgo || ! $today) {
            return null;
        }

        return [
            'progress_last_30_days' => round($today->overall_progress_percent - $thirtyDaysAgo->overall_progress_percent, 2),
            'direction' => $today->overall_progress_percent > $thirtyDaysAgo->overall_progress_percent ? 'improving' : 'declining',
        ];
    }

    /**
     * Capture a daily progress snapshot.
     */
    public function captureProgress(int $userId): void
    {
        $goal = NorthStarGoal::where('user_id', $userId)
            ->where('status', 'active')
            ->with('milestones')
            ->first();

        if (! $goal) {
            return;
        }

        $this->syncMilestoneProgress($goal);

        NorthStarProgress::updateOrCreate(
            ['north_star_goal_id' => $goal->id, 'snapshot_date' => now()->toDateString()],
            [
                'overall_progress_percent' => $goal->overallProgress(),
                'milestone_progress' => $goal->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'percent' => $m->percentComplete(),
                    'status' => $m->status,
                ])->toArray(),
            ]
        );
    }
}
