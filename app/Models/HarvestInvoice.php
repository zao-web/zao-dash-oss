<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestInvoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'date',
        'paid_at' => 'date',
        'line_items' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isPaid(): bool
    {
        return $this->state === 'paid';
    }

    public function isOverdue(): bool
    {
        return $this->state === 'open' &&
            $this->due_date &&
            $this->due_date->isPast();
    }

    public function getDaysOverdueAttribute(): ?int
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return $this->due_date->diffInDays(now());
    }
}
