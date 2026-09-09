<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ContractorInvoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'line_items' => 'array',
        'attachments' => 'array',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }
        });
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(WiseTransfer::class, 'contractor_invoice_id');
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_APPROVED]);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_APPROVED]);
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && ! $this->isPaid();
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeSubmitted(): bool
    {
        return $this->isDraft() && $this->amount > 0;
    }

    public function canBeApproved(): bool
    {
        return $this->isSubmitted();
    }

    public function canBePaid(): bool
    {
        return $this->isApproved() && $this->contractor->canReceivePayments();
    }

    // Actions
    public function submit(): self
    {
        $this->update([
            'status' => self::STATUS_SUBMITTED,
        ]);

        return $this;
    }

    public function approve(int $approverId): self
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);

        return $this;
    }

    public function reject(string $reason): self
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        return $this;
    }

    public function markPaid(int $transferId): self
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'wise_transfer_id' => $transferId,
            'paid_at' => now(),
        ]);

        return $this;
    }

    // Generate invoice number
    public static function generateInvoiceNumber(Contractor $contractor): string
    {
        $prefix = strtoupper(substr($contractor->name, 0, 3));
        $year = now()->format('Y');
        $sequence = static::where('contractor_id', $contractor->id)
            ->whereYear('created_at', now()->year)
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }
}
