<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialSnapshot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_income' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'accounts_receivable' => 'decimal:2',
        'accounts_payable' => 'decimal:2',
        'cash_on_hand' => 'decimal:2',
        'top_expense_categories' => 'array',
        'top_income_sources' => 'array',
        'insights' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    public function getProfitMarginAttribute(): ?float
    {
        if ($this->total_income == 0) {
            return null;
        }

        return round(($this->net_profit / $this->total_income) * 100, 1);
    }

    public function getExpenseRatioAttribute(): ?float
    {
        if ($this->total_income == 0) {
            return null;
        }

        return round(($this->total_expenses / $this->total_income) * 100, 1);
    }

    public function scopeDaily($query)
    {
        return $query->where('period_type', 'daily');
    }

    public function scopeWeekly($query)
    {
        return $query->where('period_type', 'weekly');
    }

    public function scopeMonthly($query)
    {
        return $query->where('period_type', 'monthly');
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }

    public static function latest(string $periodType = 'monthly'): ?self
    {
        return static::where('period_type', $periodType)
            ->orderBy('period_end', 'desc')
            ->first();
    }
}
