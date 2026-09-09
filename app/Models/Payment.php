<?php

namespace App\Models;

use App\Jobs\SyncPaymentToQuickBooksJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHOD_PAYPAL = 'paypal';

    public const METHOD_ACH = 'ach';

    public const METHOD_CHECK = 'check';

    public const METHOD_WIRE = 'wire';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'invoice_id',
        'amount',
        'method',
        'transaction_id',
        'reference',
        'payment_date',
        'notes',
        'status',
        'metadata',
        'qbo_payment_id',
        'qbo_synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'metadata' => 'array',
        'qbo_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (Payment $payment) {
            $payment->invoice->recalculateTotals();

            // Sync completed payments to QuickBooks
            if ($payment->isCompleted() && ! $payment->qbo_payment_id) {
                SyncPaymentToQuickBooksJob::dispatch($payment);
            }
        });

        static::deleted(function (Payment $payment) {
            $payment->invoice->recalculateTotals();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getMethodLabelAttribute(): string
    {
        return match ($this->method) {
            self::METHOD_PAYPAL => 'PayPal',
            self::METHOD_ACH => 'ACH Transfer',
            self::METHOD_CHECK => 'Check',
            self::METHOD_WIRE => 'Wire Transfer',
            self::METHOD_CREDIT_CARD => 'Credit Card',
            default => 'Other',
        };
    }
}
