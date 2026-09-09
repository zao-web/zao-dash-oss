<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCalendarEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'quarter' => 'integer',
        'due_date' => 'date',
        'reminder_date' => 'date',
        'estimated_amount' => 'decimal:2',
    ];

    // Event type constants
    public const TYPE_QUARTERLY_ESTIMATE = 'quarterly_estimate';

    public const TYPE_1099_FILING = '1099_filing';

    public const TYPE_ANNUAL_RETURN = 'annual_return';

    public const TYPE_EXTENSION = 'extension';

    public const TYPE_STATE_ESTIMATE = 'state_estimate';

    // Status constants
    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_REMINDER_SENT = 'reminder_sent';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_OVERDUE = 'overdue';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('due_date', '>=', now())
            ->whereIn('status', [self::STATUS_UPCOMING, self::STATUS_REMINDER_SENT])
            ->orderBy('due_date');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED]);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    public function scopeFederal($query)
    {
        return $query->where('filing_jurisdiction', 'federal');
    }

    public function scopeState($query, ?string $stateCode = null)
    {
        $q = $query->where('filing_jurisdiction', 'state');
        if ($stateCode) {
            $q->where('state_code', $stateCode);
        }

        return $q;
    }

    // Helpers
    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && $this->status !== self::STATUS_COMPLETED;
    }

    public function daysUntilDue(): int
    {
        return (int) now()->diffInDays($this->due_date, false);
    }

    public function isUrgent(): bool
    {
        return $this->daysUntilDue() <= 7 && $this->status !== self::STATUS_COMPLETED;
    }

    public function markCompleted(): self
    {
        $this->update(['status' => self::STATUS_COMPLETED]);

        return $this;
    }

    public function markReminderSent(): self
    {
        $this->update(['status' => self::STATUS_REMINDER_SENT]);

        return $this;
    }

    // Factory methods for standard IRS deadlines
    public static function createQuarterlyEstimateEvents(int $userId, int $taxYear): array
    {
        $deadlines = [
            1 => "{$taxYear}-04-15",
            2 => "{$taxYear}-06-15",
            3 => "{$taxYear}-09-15",
            4 => ($taxYear + 1).'-01-15',
        ];

        $events = [];
        foreach ($deadlines as $quarter => $dueDate) {
            $events[] = static::create([
                'user_id' => $userId,
                'event_type' => self::TYPE_QUARTERLY_ESTIMATE,
                'tax_year' => $taxYear,
                'quarter' => $quarter,
                'due_date' => $dueDate,
                'reminder_date' => now()->parse($dueDate)->subDays(14),
                'form_type' => '1040-ES',
                'filing_jurisdiction' => 'federal',
                'status' => self::STATUS_UPCOMING,
            ]);
        }

        return $events;
    }

    public static function create1099FilingEvent(int $userId, int $taxYear): self
    {
        return static::create([
            'user_id' => $userId,
            'event_type' => self::TYPE_1099_FILING,
            'tax_year' => $taxYear,
            'due_date' => ($taxYear + 1).'-01-31',
            'reminder_date' => ($taxYear + 1).'-01-15',
            'form_type' => '1099-NEC',
            'filing_jurisdiction' => 'federal',
            'status' => self::STATUS_UPCOMING,
        ]);
    }
}
