<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashWaterfallAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\CashWaterfallAllocationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'income_amount' => 'decimal:2',
        'operating_reserve' => 'decimal:2',
        'tax_reserve' => 'decimal:2',
        'irs_installment' => 'decimal:2',
        'debt_payments' => 'decimal:2',
        'sinking_funds' => 'decimal:2',
        'owner_draw' => 'decimal:2',
        'investment' => 'decimal:2',
        'allocation_details' => 'array',
        'is_simulation' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
