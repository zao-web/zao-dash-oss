<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessGoal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target' => 'decimal:2',
        'current_value' => 'decimal:2',
    ];

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_PIPELINE = 'pipeline';

    public const TYPE_CLIENTS = 'clients';

    public const TYPE_WIN_RATE = 'win_rate';

    public const TYPE_LEADS = 'leads';

    public const TYPE_DEALS = 'deals';

    public const PERIOD_MTD = 'mtd';

    public const PERIOD_QTD = 'qtd';

    public const PERIOD_YTD = 'ytd';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_CUSTOM = 'custom';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get progress as percentage.
     */
    public function getProgressAttribute(): float
    {
        if ($this->target <= 0) {
            return 0;
        }

        return min(100, ($this->current_value / $this->target) * 100);
    }

    /**
     * Get amount remaining to hit target.
     */
    public function getRemainingAttribute(): float
    {
        return max(0, $this->target - $this->current_value);
    }

    /**
     * Is target achieved?
     */
    public function getIsAchievedAttribute(): bool
    {
        return $this->current_value >= $this->target;
    }

    /**
     * Get date range for period type.
     */
    public function getDateRange(): array
    {
        return match ($this->period) {
            self::PERIOD_MTD => [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ],
            self::PERIOD_QTD => [
                Carbon::now()->startOfQuarter(),
                Carbon::now()->endOfQuarter(),
            ],
            self::PERIOD_YTD => [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear(),
            ],
            self::PERIOD_WEEKLY => [
                Carbon::now()->startOfWeek(Carbon::MONDAY),
                Carbon::now()->endOfWeek(Carbon::SUNDAY),
            ],
            default => [
                $this->period_start ?? Carbon::now()->startOfMonth(),
                $this->period_end ?? Carbon::now()->endOfMonth(),
            ],
        };
    }

    /**
     * Scope: Active goals.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: By type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Create or update a simple KPI goal.
     */
    public static function setGoal(string $type, string $period, float $target, ?int $userId = null): self
    {
        return static::updateOrCreate(
            [
                'type' => $type,
                'period' => $period,
                'user_id' => $userId,
            ],
            [
                'target' => $target,
                'status' => 'active',
            ]
        );
    }

    /**
     * Get formatted label for display.
     */
    public function getDisplayLabelAttribute(): string
    {
        $typeLabels = [
            self::TYPE_REVENUE => 'Revenue',
            self::TYPE_PIPELINE => 'Pipeline',
            self::TYPE_CLIENTS => 'New Clients',
            self::TYPE_WIN_RATE => 'Win Rate',
            self::TYPE_LEADS => 'Leads',
            self::TYPE_DEALS => 'Deals Closed',
        ];

        $periodLabels = [
            self::PERIOD_MTD => 'this month',
            self::PERIOD_QTD => 'this quarter',
            self::PERIOD_YTD => 'this year',
            self::PERIOD_WEEKLY => 'this week',
        ];

        return ($typeLabels[$this->type] ?? $this->type).' '.($periodLabels[$this->period] ?? '');
    }
}
