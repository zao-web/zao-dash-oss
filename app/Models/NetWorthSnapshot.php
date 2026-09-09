<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetWorthSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\NetWorthSnapshotFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_assets' => 'decimal:2',
        'total_liabilities' => 'decimal:2',
        'net_worth' => 'decimal:2',
        'personal_cash' => 'decimal:2',
        'business_cash' => 'decimal:2',
        'investment_value' => 'decimal:2',
        'total_debt' => 'decimal:2',
        'breakdown' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForPeriod(Builder $query, string $start, string $end): Builder
    {
        return $query->whereBetween('snapshot_date', [$start, $end]);
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('snapshot_date');
    }

    /**
     * Capture a point-in-time net worth snapshot from current account and debt data.
     */
    public static function captureSnapshot(int $userId): self
    {
        $personalCash = (float) PersonalAccount::where('user_id', $userId)
            ->active()
            ->personal()
            ->whereIn('account_type', ['checking', 'savings'])
            ->sum('current_balance');

        $businessCash = (float) PersonalAccount::where('user_id', $userId)
            ->active()
            ->business()
            ->whereIn('account_type', ['checking', 'savings'])
            ->sum('current_balance');

        $investmentValue = (float) PersonalAccount::where('user_id', $userId)
            ->active()
            ->byType('investment')
            ->sum('current_balance');

        $totalDebt = (float) Debt::where('user_id', $userId)
            ->active()
            ->sum('current_balance');

        $totalAssets = $personalCash + $businessCash + $investmentValue;
        $totalLiabilities = $totalDebt;

        $accountBreakdown = PersonalAccount::where('user_id', $userId)
            ->active()
            ->get(['id', 'name', 'account_type', 'current_balance', 'is_business'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->account_type,
                'balance' => (float) $a->current_balance,
                'is_business' => $a->is_business,
            ])
            ->toArray();

        $debtBreakdown = Debt::where('user_id', $userId)
            ->active()
            ->get(['id', 'name', 'debt_type', 'current_balance'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'type' => $d->debt_type,
                'balance' => (float) $d->current_balance,
            ])
            ->toArray();

        return static::create([
            'user_id' => $userId,
            'snapshot_date' => now()->toDateString(),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => $totalAssets - $totalLiabilities,
            'personal_cash' => $personalCash,
            'business_cash' => $businessCash,
            'investment_value' => $investmentValue,
            'total_debt' => $totalDebt,
            'breakdown' => [
                'accounts' => $accountBreakdown,
                'debts' => $debtBreakdown,
            ],
        ]);
    }
}
