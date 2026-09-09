<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxOptimizationScenario extends Model
{
    /** @use HasFactory<\Database\Factories\TaxOptimizationScenarioFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'gross_income' => 'decimal:2',
        's_corp_salary' => 'decimal:2',
        'distributions' => 'decimal:2',
        'retirement_contributions' => 'array',
        'real_estate_deductions' => 'array',
        'rd_credit_amount' => 'decimal:2',
        'deductions' => 'array',
        'qbi_deduction' => 'decimal:2',
        'total_taxable_income' => 'decimal:2',
        'federal_tax' => 'decimal:2',
        'state_tax' => 'decimal:2',
        'se_tax' => 'decimal:2',
        'fica_tax' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'effective_rate' => 'decimal:2',
        'strategies_applied' => 'array',
        'savings_vs_baseline' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comparisonBaseline(): BelongsTo
    {
        return $this->belongsTo(self::class, 'comparison_baseline_id');
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('tax_year', $year);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('scenario_type', $type);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('scenario_type', 'current');
    }

    public function scopeOptimized(Builder $query): Builder
    {
        return $query->where('scenario_type', 'optimized');
    }
}
