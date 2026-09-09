<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxStrategy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'estimated_savings' => 'decimal:2',
        'requirements' => 'array',
        'action_items' => 'array',
        'implemented_at' => 'datetime',
    ];

    // Strategy type constants
    public const TYPE_SALARY_OPTIMIZATION = 'salary_optimization';

    public const TYPE_RETIREMENT_CONTRIBUTION = 'retirement_contribution';

    public const TYPE_SECTION_179 = 'section_179';

    public const TYPE_HEALTH_INSURANCE = 'health_insurance';

    public const TYPE_HOME_OFFICE = 'home_office';

    public const TYPE_VEHICLE_EXPENSES = 'vehicle_expenses';

    public const TYPE_INCOME_TIMING = 'income_timing';

    public const TYPE_EXPENSE_TIMING = 'expense_timing';

    public const TYPE_ENTITY_STRUCTURE = 'entity_structure';

    // Timing sensitivity
    public const TIMING_URGENT = 'urgent';

    public const TIMING_YEAR_END = 'year_end';

    public const TIMING_QUARTERLY = 'quarterly';

    public const TIMING_FLEXIBLE = 'flexible';

    // Complexity
    public const COMPLEXITY_LOW = 'low';

    public const COMPLEXITY_MEDIUM = 'medium';

    public const COMPLEXITY_HIGH = 'high';

    // Status constants
    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_IMPLEMENTING = 'implementing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    public function qboConnection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    // Scopes
    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_IDENTIFIED, self::STATUS_IMPLEMENTING]);
    }

    public function scopeUrgent($query)
    {
        return $query->where('timing_sensitivity', self::TIMING_URGENT);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('strategy_type', $type);
    }

    public function scopeHighValue($query, float $threshold = 1000)
    {
        return $query->where('estimated_savings', '>=', $threshold);
    }

    // Status helpers
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_IDENTIFIED, self::STATUS_IMPLEMENTING]);
    }

    public function isUrgent(): bool
    {
        return $this->timing_sensitivity === self::TIMING_URGENT;
    }

    // Actions
    public function startImplementing(): self
    {
        $this->update(['status' => self::STATUS_IMPLEMENTING]);

        return $this;
    }

    public function markCompleted(): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'implemented_at' => now(),
        ]);

        return $this;
    }

    public function dismiss(string $reason): self
    {
        $this->update([
            'status' => self::STATUS_DISMISSED,
            'dismissal_reason' => $reason,
        ]);

        return $this;
    }

    // Badge/display helpers
    public function getTimingBadgeAttribute(): string
    {
        return match ($this->timing_sensitivity) {
            self::TIMING_URGENT => 'danger',
            self::TIMING_YEAR_END => 'warning',
            self::TIMING_QUARTERLY => 'info',
            default => 'secondary',
        };
    }

    public function getComplexityBadgeAttribute(): string
    {
        return match ($this->complexity) {
            self::COMPLEXITY_LOW => 'success',
            self::COMPLEXITY_MEDIUM => 'warning',
            self::COMPLEXITY_HIGH => 'danger',
            default => 'secondary',
        };
    }

    // Strategy templates
    public static function getStrategyTemplates(): array
    {
        return [
            self::TYPE_SALARY_OPTIMIZATION => [
                'title' => 'S-Corp Salary Optimization',
                'description' => 'Optimize owner salary to minimize self-employment taxes while meeting reasonable compensation requirements. The IRS requires S-Corp owners who perform services to receive reasonable compensation.',
                'requirements' => ['S-Corp entity type', 'Owner performs services'],
                'complexity' => self::COMPLEXITY_MEDIUM,
            ],
            self::TYPE_RETIREMENT_CONTRIBUTION => [
                'title' => 'Maximize Retirement Contributions',
                'description' => 'Contribute to Solo 401(k) or SEP-IRA to reduce taxable income. For 2024, Solo 401(k) allows up to $69,000 total contributions ($23,000 employee + employer match).',
                'requirements' => ['Self-employed or S-Corp owner', 'Sufficient income'],
                'complexity' => self::COMPLEXITY_LOW,
            ],
            self::TYPE_SECTION_179 => [
                'title' => 'Section 179 Equipment Deduction',
                'description' => 'Deduct the full cost of qualifying equipment and software purchases in the year purchased instead of depreciating over time. 2024 limit is $1,160,000.',
                'requirements' => ['Equipment purchases needed', 'Sufficient income'],
                'complexity' => self::COMPLEXITY_LOW,
            ],
            self::TYPE_HEALTH_INSURANCE => [
                'title' => 'Self-Employed Health Insurance Deduction',
                'description' => 'Deduct 100% of health insurance premiums paid for yourself, spouse, and dependents. This is an above-the-line deduction.',
                'requirements' => ['Self-employed', 'Pay own health insurance'],
                'complexity' => self::COMPLEXITY_LOW,
            ],
            self::TYPE_HOME_OFFICE => [
                'title' => 'Home Office Deduction',
                'description' => 'Deduct a portion of home expenses based on business use percentage. Can use simplified method ($5/sq ft up to 300 sq ft) or actual expense method.',
                'requirements' => ['Dedicated home office space', 'Regular and exclusive business use'],
                'complexity' => self::COMPLEXITY_MEDIUM,
            ],
            self::TYPE_VEHICLE_EXPENSES => [
                'title' => 'Vehicle Expense Optimization',
                'description' => 'Choose between standard mileage rate (67 cents/mile for 2024) or actual expenses method based on which provides larger deduction.',
                'requirements' => ['Business use of vehicle', 'Mileage tracking'],
                'complexity' => self::COMPLEXITY_MEDIUM,
            ],
        ];
    }
}
