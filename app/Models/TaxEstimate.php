<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxEstimate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'quarter' => 'integer',
        'calculation_date' => 'date',
        'ytd_gross_income' => 'decimal:2',
        'ytd_deductions' => 'decimal:2',
        'ytd_net_income' => 'decimal:2',
        'projected_annual_income' => 'decimal:2',
        'projected_annual_tax' => 'decimal:2',
        'quarterly_payment_due' => 'decimal:2',
        'ytd_payments_made' => 'decimal:2',
        'calculation_breakdown' => 'array',
        'effective_tax_rate' => 'decimal:2',
        'self_employment_tax' => 'decimal:2',
        'state_tax_estimate' => 'decimal:2',
    ];

    // Entity type constants
    public const ENTITY_S_CORP = 's_corp';

    public const ENTITY_LLC = 'llc';

    public const ENTITY_SOLE_PROP = 'sole_prop';

    public const ENTITY_C_CORP = 'c_corp';

    // 2024 Federal tax brackets (single filer)
    public const TAX_BRACKETS = [
        ['min' => 0, 'max' => 11600, 'rate' => 0.10],
        ['min' => 11600, 'max' => 47150, 'rate' => 0.12],
        ['min' => 47150, 'max' => 100525, 'rate' => 0.22],
        ['min' => 100525, 'max' => 191950, 'rate' => 0.24],
        ['min' => 191950, 'max' => 243725, 'rate' => 0.32],
        ['min' => 243725, 'max' => 609350, 'rate' => 0.35],
        ['min' => 609350, 'max' => PHP_INT_MAX, 'rate' => 0.37],
    ];

    // Self-employment tax rate (Social Security + Medicare)
    public const SE_TAX_RATE = 0.153;

    public const SE_TAX_DEDUCTION_RATE = 0.5; // Deduct half of SE tax

    public function qboConnection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
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

    public function scopeLatest($query)
    {
        return $query->orderByDesc('calculation_date');
    }

    // Calculation helpers
    public static function calculateFederalTax(float $taxableIncome): float
    {
        $tax = 0;
        $previousMax = 0;

        foreach (self::TAX_BRACKETS as $bracket) {
            if ($taxableIncome <= $bracket['min']) {
                break;
            }

            $taxableInBracket = min($taxableIncome, $bracket['max']) - $bracket['min'];
            $tax += $taxableInBracket * $bracket['rate'];
        }

        return round($tax, 2);
    }

    public static function calculateSelfEmploymentTax(float $netIncome): array
    {
        // SE tax is on 92.35% of net self-employment income
        $seBase = $netIncome * 0.9235;
        $seTax = $seBase * self::SE_TAX_RATE;

        // Can deduct half of SE tax from income
        $seDeduction = $seTax * self::SE_TAX_DEDUCTION_RATE;

        return [
            'base' => round($seBase, 2),
            'tax' => round($seTax, 2),
            'deduction' => round($seDeduction, 2),
        ];
    }

    public function getRemainingPaymentAttribute(): float
    {
        return max(0, $this->quarterly_payment_due - $this->ytd_payments_made);
    }

    public function getEffectiveRateAttribute(): float
    {
        if ($this->projected_annual_income <= 0) {
            return 0;
        }

        return round(($this->projected_annual_tax / $this->projected_annual_income) * 100, 2);
    }

    public function getSummaryAttribute(): array
    {
        return [
            'ytd_income' => $this->ytd_net_income,
            'projected_annual' => $this->projected_annual_income,
            'projected_tax' => $this->projected_annual_tax,
            'quarterly_due' => $this->quarterly_payment_due,
            'remaining' => $this->remaining_payment,
            'effective_rate' => $this->effective_rate.'%',
        ];
    }
}
