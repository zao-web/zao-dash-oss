<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    public const TYPE_TIME = 'time';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_TAX = 'tax';

    protected $fillable = [
        'invoice_id',
        'type',
        'description',
        'details',
        'quantity',
        'unit',
        'unit_price',
        'amount',
        'taxable',
        'time_entry_id',
        'project_id',
        'service_date',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'taxable' => 'boolean',
        'service_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (InvoiceLine $line) {
            // Auto-calculate amount
            $line->amount = $line->quantity * $line->unit_price;

            // Discounts are negative
            if ($line->type === self::TYPE_DISCOUNT && $line->amount > 0) {
                $line->amount = -abs($line->amount);
            }
        });

        static::saved(function (InvoiceLine $line) {
            $line->invoice->recalculateTotals();
        });

        static::deleted(function (InvoiceLine $line) {
            $line->invoice->recalculateTotals();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
