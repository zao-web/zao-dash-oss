<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QboInvoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'txn_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'line_items' => 'array',
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function harvestInvoice(): BelongsTo
    {
        return $this->belongsTo(HarvestInvoice::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'Paid' || $this->balance == 0;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'Open'
            && $this->due_date
            && $this->due_date->isPast()
            && $this->balance > 0;
    }

    public function getDaysOverdueAttribute(): ?int
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return $this->due_date->diffInDays(now());
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'Open')->where('balance', '>', 0);
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'Paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'Open')
            ->where('balance', '>', 0)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }
}
