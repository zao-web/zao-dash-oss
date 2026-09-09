<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetainerPeriod extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hours_included' => 'decimal:2',
            'hours_used' => 'decimal:2',
            'rollover_hours' => 'decimal:2',
            'overage_rate' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'monthly_amount' => 'decimal:2',
            'internal_hourly_rate' => 'decimal:2',
            'ai_equivalent_hourly_rate' => 'decimal:2',
            'agent_cost_usd' => 'decimal:4',
            'effective_margin_percent' => 'decimal:2',
            'included_services' => 'array',
            'last_client_activity_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * UTC instant the reporting window opens. The period dates are calendar
     * days in the agency's local timezone, so midnight-local is converted to
     * UTC for querying the (UTC-stored) evidence columns. Without this, a unit
     * of work logged at 11pm Pacific lands in the next UTC day and can fall
     * into the wrong period.
     */
    public function windowStart(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse(
            $this->period_start->toDateString(),
            config('app.display_timezone'),
        )->startOfDay()->utc();
    }

    /**
     * UTC instant the reporting window closes (inclusive end-of-day, local).
     */
    public function windowEnd(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse(
            $this->period_end->toDateString(),
            config('app.display_timezone'),
        )->endOfDay()->utc();
    }

    public function getTotalHoursAttribute(): float
    {
        return $this->hours_included + $this->rollover_hours;
    }

    public function getRemainingHoursAttribute(): float
    {
        return max(0, $this->total_hours - $this->hours_used);
    }

    public function getUsagePercentAttribute(): float
    {
        if ($this->total_hours == 0) {
            return 0;
        }

        return round(($this->hours_used / $this->total_hours) * 100, 1);
    }

    public function isOverage(): bool
    {
        return $this->hours_used > $this->total_hours;
    }

    public function getOverageHoursAttribute(): float
    {
        if (! $this->isOverage()) {
            return 0;
        }

        return $this->hours_used - $this->total_hours;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' &&
            $this->period_start->isPast() &&
            $this->period_end->isFuture();
    }

    public static function currentForClient(int $clientId): ?self
    {
        return static::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->first();
    }

    public function getAgentEquivalentHoursAttribute(): float
    {
        $rate = (float) $this->ai_equivalent_hourly_rate;
        if ($rate === 0.0) {
            return 0.0;
        }

        return round((float) $this->agent_cost_usd / $rate, 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeWithHealthSummary(Builder $query): Builder
    {
        return $query
            ->with(['client', 'client.contacts'])
            ->active()
            ->orderByRaw("CASE health_status
                WHEN 'critical' THEN 1
                WHEN 'warning' THEN 2
                WHEN 'silent' THEN 3
                WHEN 'healthy' THEN 4
                ELSE 5 END");
    }
}
