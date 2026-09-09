<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TaxReturnWorkpaper extends Model
{
    protected $guarded = [];

    protected $casts = [
        'packet' => 'array',
        'readiness_percent' => 'integer',
        'mapped_field_count' => 'integer',
        'total_field_count' => 'integer',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signoffDocument(): BelongsTo
    {
        return $this->belongsTo(FinancialDocument::class, 'signoff_document_id');
    }

    public function getIsReadyForSignoffAttribute(): bool
    {
        return $this->status === 'draft_ready_for_owner_review';
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->approved_at !== null;
    }

    public function getIsReadyForOwnerApprovalAttribute(): bool
    {
        return $this->is_ready_for_signoff
            && $this->total_field_count > 0
            && $this->mapped_field_count === $this->total_field_count
            && $this->readiness_percent === 100;
    }

    public function approve(): void
    {
        if (! $this->is_ready_for_owner_approval) {
            throw new RuntimeException('Only fully mapped annual return workpapers can be approved.');
        }

        $this->forceFill([
            'approved_at' => now(),
        ])->save();
    }
}
