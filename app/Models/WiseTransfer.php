<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WiseTransfer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'target_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'fee' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FUNDS_CONVERTED = 'funds_converted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    // Payment type constants
    public const TYPE_RECURRING = 'recurring';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_REIMBURSEMENT = 'reimbursement';

    public function wiseConnection(): BelongsTo
    {
        return $this->belongsTo(WiseConnection::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ContractorInvoice::class, 'contractor_invoice_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->whereIn('status', [self::STATUS_PROCESSING, self::STATUS_FUNDS_CONVERTED]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeNeedsApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNull('approved_by');
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_FUNDS_CONVERTED]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function needsApproval(): bool
    {
        return $this->isPending() && ! $this->approved_by;
    }

    // Actions
    public function approve(int $approverId): self
    {
        $this->update([
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);

        return $this;
    }

    public function updateStatus(string $status): self
    {
        $this->update(['status' => $status]);

        // If completed, mark associated invoice as paid
        if ($status === self::STATUS_COMPLETED && $this->contractor_invoice_id) {
            $this->invoice?->markPaid($this->id);
        }

        return $this;
    }

    // Computed attributes
    public function getTotalCostAttribute(): float
    {
        return $this->source_amount + $this->fee;
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->source_amount, 2).' '.$this->source_currency;
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING, self::STATUS_FUNDS_CONVERTED => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'secondary',
            default => 'secondary',
        };
    }
}
