<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_VIEWED = 'viewed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_VIEWED,
        self::STATUS_PARTIAL,
        self::STATUS_OVERDUE,
    ];

    protected $fillable = [
        'client_id',
        'project_id',
        'number',
        'subject',
        'notes',
        'internal_notes',
        'status',
        'is_recurring',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'amount_paid',
        'amount_due',
        'issue_date',
        'due_date',
        'sent_at',
        'viewed_at',
        'paid_at',
        'payment_terms',
        'currency',
        'po_number',
        'recipient_email',
        'harvest_invoice_id',
        'paypal_invoice_id',
        'qbo_invoice_id',
        'qbo_synced_at',
        'reminders_disabled',
        'pending_review',
        'pending_review_reason',
        'retainer_period_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'is_recurring' => 'boolean',
        'reminders_disabled' => 'boolean',
        'pending_review' => 'boolean',
        'issue_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'paid_at' => 'datetime',
        'qbo_synced_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function retainerPeriod(): BelongsTo
    {
        return $this->belongsTo(RetainerPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(InvoiceActivity::class)->orderBy('created_at', 'desc');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status !== self::STATUS_PAID
            && $this->status !== self::STATUS_CANCELLED
            && $this->due_date
            && $this->due_date->isPast();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now());
    }

    /**
     * Get days from issue to payment (null if unpaid).
     */
    public function getDaysToPayAttribute(): ?int
    {
        if (! $this->paid_at || ! $this->issue_date) {
            return null;
        }

        return (int) $this->issue_date->diffInDays($this->paid_at);
    }

    /**
     * Get days from sent to payment (null if unpaid or not sent).
     */
    public function getDaysFromSentToPayAttribute(): ?int
    {
        if (! $this->paid_at || ! $this->sent_at) {
            return null;
        }

        return (int) $this->sent_at->diffInDays($this->paid_at);
    }

    /**
     * Generate a secure token for public invoice access.
     */
    public function getPublicTokenAttribute(): string
    {
        return hash_hmac('sha256', $this->id.$this->number, config('app.key'));
    }

    /**
     * Get the public URL for this invoice.
     */
    public function getPublicUrlAttribute(): string
    {
        return route('invoices.public', [
            'invoice' => $this->number,
            'token' => $this->public_token,
        ]);
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->lines()->sum('amount');
        $taxAmount = $this->tax_rate > 0
            ? $subtotal * ($this->tax_rate / 100)
            : 0;
        $total = $subtotal + $taxAmount;
        $amountPaid = $this->payments()->where('status', 'completed')->sum('amount');
        $amountDue = max(0, $total - $amountPaid);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => $amountPaid,
            'amount_due' => $amountDue,
        ]);

        // Update status based on payments
        if ($amountDue <= 0 && $total > 0) {
            $this->update(['status' => self::STATUS_PAID, 'paid_at' => now()]);
        } elseif ($amountPaid > 0 && $amountDue > 0) {
            $this->update(['status' => self::STATUS_PARTIAL]);
        }
    }

    public static function generateNumber(): string
    {
        // Continue from highest invoice number across both systems
        // Harvest used simple numeric IDs (e.g., 1978)
        // We continue that sequence for client continuity

        $lastNativeNumber = static::max('number');
        $lastHarvestNumber = HarvestInvoice::max('number');

        // Extract numeric portion (handles both "1978" and potential "ZAO-2026-001" formats)
        $nativeMax = $lastNativeNumber ? (int) preg_replace('/\D/', '', $lastNativeNumber) : 0;
        $harvestMax = $lastHarvestNumber ? (int) preg_replace('/\D/', '', $lastHarvestNumber) : 0;

        // Safety check: if we get an absurdly large number (> 100000), something is wrong
        // Fall back to timestamp-based unique number to avoid perpetuating bad data
        $maxReasonable = 100000;
        if ($nativeMax > $maxReasonable || $harvestMax > $maxReasonable) {
            \Illuminate\Support\Facades\Log::warning('Invoice number generation found unreasonable max', [
                'native_max' => $nativeMax,
                'harvest_max' => $harvestMax,
                'last_native_number' => $lastNativeNumber,
                'last_harvest_number' => $lastHarvestNumber,
            ]);

            // Use a reasonable starting point: find max of numbers under the threshold
            $reasonableNativeMax = static::whereRaw('CAST(number AS BIGINT) < ?', [$maxReasonable])->max('number');
            $nativeMax = $reasonableNativeMax ? (int) preg_replace('/\D/', '', $reasonableNativeMax) : 0;

            $reasonableHarvestMax = HarvestInvoice::whereRaw('CAST(number AS BIGINT) < ?', [$maxReasonable])->max('number');
            $harvestMax = $reasonableHarvestMax ? (int) preg_replace('/\D/', '', $reasonableHarvestMax) : 0;
        }

        $nextNumber = max($nativeMax, $harvestMax, 1978) + 1;

        return (string) $nextNumber;
    }

    /**
     * Create an invoice with retry logic for unique constraint violations.
     *
     * This handles race conditions where multiple concurrent requests
     * might generate the same invoice number.
     *
     * Uses savepoints (nested transactions) to allow retry within an existing
     * transaction, since PostgreSQL aborts the entire transaction on constraint
     * violation without savepoints.
     *
     * @param  array  $attributes  Invoice attributes (number will be auto-generated if not provided)
     * @param  int  $maxRetries  Maximum retry attempts on unique constraint violation
     *
     * @throws \Illuminate\Database\QueryException If creation fails after all retries
     */
    public static function createWithUniqueNumber(array $attributes, int $maxRetries = 5): static
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxRetries) {
            try {
                // Use a savepoint (nested transaction) so PostgreSQL can recover
                // from constraint violations without aborting the outer transaction
                return \Illuminate\Support\Facades\DB::transaction(function () use ($attributes) {
                    $attributes['number'] = static::generateNumber();

                    return static::create($attributes);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                $lastException = $e;
                $attempts++;

                // Check if this is a unique constraint violation (PostgreSQL: 23505, MySQL: 1062)
                $isUniqueViolation = str_contains($e->getMessage(), '23505')
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), 'unique constraint');

                if (! $isUniqueViolation || $attempts >= $maxRetries) {
                    throw $e;
                }

                // Small delay before retry to reduce collision chance
                usleep(random_int(1000, 10000)); // 1-10ms
            }
        }

        // Should never reach here, but satisfy static analysis
        throw $lastException ?? new \RuntimeException('Failed to create invoice after '.$maxRetries.' attempts');
    }
}
