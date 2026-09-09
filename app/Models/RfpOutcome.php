<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfpOutcome extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'feedback_structured' => 'array',
        'win_factors' => 'array',
        'loss_factors' => 'array',
        'competitor_info' => 'array',
        'price_comparison' => 'array',
        'lessons_learned' => 'array',
        'organization_would_bid_again' => 'boolean',
        'score_received' => 'decimal:2',
        'awarded_amount' => 'decimal:2',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(RfpOpportunity::class, 'rfp_opportunity_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(RfpProposal::class, 'rfp_proposal_id');
    }
}
