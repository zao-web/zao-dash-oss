<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimatedTaxPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PersonalTransaction::class, 'personal_transaction_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FinancialDocument::class, 'financial_document_id');
    }

    // Scopes

    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    public function scopeForQuarter($query, int $quarter)
    {
        return $query->where('quarter', $quarter);
    }

    public function scopeForJurisdiction($query, string $jurisdiction)
    {
        return $query->where('jurisdiction', $jurisdiction);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePendingConfirmation($query)
    {
        return $query->whereIn('status', ['pending_confirmation', 'auto_detected']);
    }

    public function scopeFederal($query)
    {
        return $query->where('jurisdiction', 'federal');
    }

    public function scopeState($query)
    {
        return $query->where('jurisdiction', 'state_or');
    }

    // Helpers

    public function confirm(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    public function getJurisdictionLabelAttribute(): string
    {
        return match ($this->jurisdiction) {
            'federal' => 'Federal (IRS)',
            'state_or' => 'Oregon',
            'local_portland' => 'Portland',
            'local_multnomah' => 'Multnomah County',
            default => $this->jurisdiction,
        };
    }

    /**
     * Get total confirmed payments for a user/year/jurisdiction.
     */
    public static function ytdPayments(int $userId, int $year, string $jurisdiction = 'federal'): float
    {
        return (float) static::where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('jurisdiction', $jurisdiction)
            ->where('status', 'confirmed')
            ->sum('amount');
    }

    /**
     * Get total confirmed payments for a user/year across all jurisdictions.
     *
     * @return array{federal: float, state_or: float, local_portland: float, local_multnomah: float, total: float}
     */
    public static function ytdPaymentsByJurisdiction(int $userId, int $year): array
    {
        $payments = static::where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('status', 'confirmed')
            ->selectRaw('jurisdiction, SUM(amount) as total')
            ->groupBy('jurisdiction')
            ->pluck('total', 'jurisdiction')
            ->toArray();

        return [
            'federal' => (float) ($payments['federal'] ?? 0),
            'state_or' => (float) ($payments['state_or'] ?? 0),
            'local_portland' => (float) ($payments['local_portland'] ?? 0),
            'local_multnomah' => (float) ($payments['local_multnomah'] ?? 0),
            'total' => array_sum(array_map('floatval', $payments)),
        ];
    }
}
