<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StrategicGoal extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'revenue_target' => 'decimal:2',
        'margin_target_pct' => 'decimal:2',
        'profit_target' => 'decimal:2',
        'monthly_recurring_revenue' => 'decimal:2',
        'mrr_months' => 'integer',
        'assumptions' => 'array',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACHIEVED = 'achieved';

    public const STATUS_MISSED = 'missed';

    public const STATUS_ARCHIVED = 'archived';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(GoalPeriod::class);
    }

    public function yearlyPeriod(): HasOne
    {
        return $this->hasOne(GoalPeriod::class)->where('period_type', 'yearly');
    }

    public function quarters(): HasMany
    {
        return $this->hasMany(GoalPeriod::class)->where('period_type', 'quarterly')->orderBy('period_start');
    }

    public function months(): HasMany
    {
        return $this->hasMany(GoalPeriod::class)->where('period_type', 'monthly')->orderBy('period_start');
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(GoalPeriod::class)->where('period_type', 'weekly')->orderBy('period_start');
    }

    public function currentQuarter(): ?GoalPeriod
    {
        return $this->periods()
            ->where('period_type', 'quarterly')
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->first();
    }

    public function currentMonth(): ?GoalPeriod
    {
        return $this->periods()
            ->where('period_type', 'monthly')
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->first();
    }

    public function currentWeek(): ?GoalPeriod
    {
        return $this->periods()
            ->where('period_type', 'weekly')
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->first();
    }

    /**
     * Calculate implied profit target from revenue and margin.
     */
    public function getCalculatedProfitAttribute(): float
    {
        return (float) $this->revenue_target * ((float) $this->margin_target_pct / 100);
    }

    /**
     * Calculate MRR from active Harvest retainer projects.
     * This is the source of truth for recurring revenue.
     */
    public function getHarvestMrrAttribute(): float
    {
        return (float) HarvestProject::where('budget_is_monthly', true)
            ->where('is_active', true)
            ->sum('budget');
    }

    /**
     * Get effective MRR - uses Harvest data unless manually overridden.
     */
    public function getEffectiveMrrAttribute(): float
    {
        // If manual override is set and > 0, use it; otherwise use Harvest
        if ($this->monthly_recurring_revenue && $this->monthly_recurring_revenue > 0) {
            return (float) $this->monthly_recurring_revenue;
        }

        return $this->harvest_mrr;
    }

    /**
     * Calculate annualized recurring revenue.
     */
    public function getAnnualizedRecurringRevenueAttribute(): float
    {
        return $this->effective_mrr * (int) ($this->mrr_months ?? 12);
    }

    /**
     * Calculate prorated recurring revenue based on time elapsed in fiscal year.
     */
    public function getProratedRecurringRevenueAttribute(): float
    {
        $monthsElapsed = $this->getMonthsElapsedInFiscalYear();

        return $this->effective_mrr * min($monthsElapsed, $this->mrr_months ?? 12);
    }

    /**
     * Get the number of months elapsed in the fiscal year.
     */
    protected function getMonthsElapsedInFiscalYear(): int
    {
        $yearStart = Carbon::create($this->fiscal_year, 1, 1);
        $now = now();

        if ($now->lt($yearStart)) {
            return 0;
        }
        if ($now->year > $this->fiscal_year) {
            return 12;
        }

        return $now->month;
    }

    /**
     * Get active retainer projects from Harvest.
     */
    public function getActiveRetainerProjectsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        return HarvestProject::where('budget_is_monthly', true)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get assumption value with fallback default.
     */
    public function getAssumption(string $key, mixed $default = null): mixed
    {
        return $this->assumptions[$key] ?? $default;
    }

    /**
     * Calculate percentage complete based on revenue actual vs target.
     */
    public function getProgressPercentAttribute(): float
    {
        if (! $this->yearlyPeriod || $this->revenue_target <= 0) {
            return 0;
        }

        return min(100, ($this->yearlyPeriod->revenue_actual / $this->revenue_target) * 100);
    }

    /**
     * Calculate percentage of year elapsed.
     */
    public function getTimeElapsedPercentAttribute(): float
    {
        $yearStart = Carbon::create($this->fiscal_year, 1, 1);
        $yearEnd = Carbon::create($this->fiscal_year, 12, 31);
        $now = now();

        if ($now->lt($yearStart)) {
            return 0;
        }
        if ($now->gt($yearEnd)) {
            return 100;
        }

        return ($now->diffInDays($yearStart) / $yearStart->diffInDays($yearEnd)) * 100;
    }

    /**
     * Are we on track? Revenue progress >= time elapsed.
     */
    public function getIsOnTrackAttribute(): bool
    {
        return $this->progress_percent >= ($this->time_elapsed_percent * 0.9); // 10% buffer
    }

    /**
     * Generate all periods for this goal.
     */
    public function generatePeriods(array $assumptions = []): void
    {
        $defaults = [
            'avg_deal_size' => 25000,
            'win_rate' => 25, // percent
            'sales_cycle_days' => 45,
        ];
        $assumptions = array_merge($defaults, $this->assumptions ?? [], $assumptions);

        // Calculate required deals and leads for the year
        $requiredDeals = ceil($this->revenue_target / $assumptions['avg_deal_size']);
        $requiredLeads = ceil($requiredDeals / ($assumptions['win_rate'] / 100));

        // Yearly
        $this->periods()->updateOrCreate(
            ['period_type' => 'yearly', 'period_start' => "{$this->fiscal_year}-01-01"],
            [
                'period_label' => (string) $this->fiscal_year,
                'period_end' => "{$this->fiscal_year}-12-31",
                'revenue_target' => $this->revenue_target,
                'leads_target' => $requiredLeads,
                'closed_deals_target' => $requiredDeals,
                'pipeline_target' => $this->revenue_target * 3, // 3x pipeline coverage
            ]
        );

        // Quarters
        for ($q = 1; $q <= 4; $q++) {
            $qStart = Carbon::create($this->fiscal_year, ($q - 1) * 3 + 1, 1);
            $qEnd = $qStart->copy()->addMonths(3)->subDay();

            $this->periods()->updateOrCreate(
                ['period_type' => 'quarterly', 'period_start' => $qStart->toDateString()],
                [
                    'period_label' => "Q{$q}",
                    'period_end' => $qEnd->toDateString(),
                    'revenue_target' => $this->revenue_target / 4,
                    'leads_target' => ceil($requiredLeads / 4),
                    'closed_deals_target' => ceil($requiredDeals / 4),
                    'pipeline_target' => ($this->revenue_target / 4) * 3,
                ]
            );
        }

        // Months
        for ($m = 1; $m <= 12; $m++) {
            $mStart = Carbon::create($this->fiscal_year, $m, 1);
            $mEnd = $mStart->copy()->endOfMonth();

            $this->periods()->updateOrCreate(
                ['period_type' => 'monthly', 'period_start' => $mStart->toDateString()],
                [
                    'period_label' => $mStart->format('M'),
                    'period_end' => $mEnd->toDateString(),
                    'revenue_target' => $this->revenue_target / 12,
                    'leads_target' => ceil($requiredLeads / 12),
                    'closed_deals_target' => ceil($requiredDeals / 12),
                    'pipeline_target' => ($this->revenue_target / 12) * 3,
                ]
            );
        }

        // Weeks (52 weeks)
        $weekStart = Carbon::create($this->fiscal_year, 1, 1)->startOfWeek(Carbon::MONDAY);
        for ($w = 1; $w <= 52; $w++) {
            $wEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

            // Only include weeks that fall within the fiscal year
            if ($weekStart->year == $this->fiscal_year || $wEnd->year == $this->fiscal_year) {
                $this->periods()->updateOrCreate(
                    ['period_type' => 'weekly', 'period_start' => $weekStart->toDateString()],
                    [
                        'period_label' => "W{$w}",
                        'period_end' => $wEnd->toDateString(),
                        'revenue_target' => $this->revenue_target / 52,
                        'leads_target' => max(1, ceil($requiredLeads / 52)),
                        'closed_deals_target' => max(1, ceil($requiredDeals / 52)),
                        'pipeline_target' => ($this->revenue_target / 52) * 3,
                    ]
                );
            }
            $weekStart->addWeek();
        }

        // Update assumptions
        $this->update(['assumptions' => $assumptions]);
    }
}
