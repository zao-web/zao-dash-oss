<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionsAccount extends Model
{
    /** @use HasFactory<\Database\Factories\CollectionsAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'date_sent_to_collections' => 'date',
        'statute_of_limitations' => 'date',
        'last_contact_date' => 'date',
        'settlement_offered' => 'decimal:2',
        'settlement_accepted' => 'decimal:2',
        'dispute_filed' => 'boolean',
        'dispute_date' => 'date',
        'correspondence_log' => 'array',
        'validation_notice_date' => 'date',
        'dispute_deadline' => 'date',
        'verification_received' => 'boolean',
        'cease_communication' => 'boolean',
        'lawsuit_filed' => 'boolean',
        'judgment_entered' => 'boolean',
        'garnishment_active' => 'boolean',
    ];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('dispute_filed', true);
    }

    public function scopeWithSettlement(Builder $query): Builder
    {
        return $query->whereNotNull('settlement_accepted');
    }

    public function scopeStatuteExpiring(Builder $query): Builder
    {
        return $query->whereNotNull('statute_of_limitations')
            ->where('statute_of_limitations', '<=', now()->addMonths(6));
    }

    public function scopeWithActiveGarnishment(Builder $query): Builder
    {
        return $query->where('garnishment_active', true);
    }

    public function scopeWithPendingDispute(Builder $query): Builder
    {
        return $query->where('dispute_filed', true)
            ->where('verification_received', false);
    }

    public function scopeValidationOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('dispute_deadline')
            ->where('dispute_deadline', '<', now())
            ->where('verification_received', false);
    }
}
