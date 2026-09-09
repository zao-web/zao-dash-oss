<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'contract_value' => 'decimal:2',
        'google_modified_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isExpiringSoon(): bool
    {
        if (! $this->expiration_date) {
            return false;
        }

        // Within 30 days
        return $this->expiration_date->isFuture() &&
            $this->expiration_date->diffInDays(now()) <= 30;
    }

    public function isExpired(): bool
    {
        return $this->expiration_date?->isPast() ?? false;
    }

    public function isLinked(): bool
    {
        return $this->client_id !== null;
    }

    public function getDocumentTypeLabel(): string
    {
        return match ($this->document_type) {
            'msa' => 'Master Service Agreement',
            'sow' => 'Statement of Work',
            'proposal' => 'Proposal',
            'contract' => 'Contract',
            'nda' => 'NDA',
            default => ucfirst($this->document_type ?? 'Document'),
        };
    }
}
