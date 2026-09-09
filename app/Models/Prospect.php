<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prospect extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'tech_stack' => 'array',
        'signals' => 'array',
        'research_notes' => 'array',
        'score_breakdown' => 'array',
        'estimated_revenue' => 'decimal:2',
        'converted_at' => 'datetime',
    ];

    public const STATUS_NEW = 'new';

    public const STATUS_RESEARCHING = 'researching';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_UNQUALIFIED = 'unqualified';

    public const STATUS_CONVERTED = 'converted';

    public function icp(): BelongsTo
    {
        return $this->belongsTo(IdealCustomerProfile::class, 'icp_id');
    }

    public function convertedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_to_lead_id');
    }

    public function discoveredByRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'discovered_by_agent_run_id');
    }

    public function outreachMessages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class);
    }

    /**
     * Calculate ICP score based on assigned ICP.
     */
    public function calculateScore(): array
    {
        if (! $this->icp) {
            return ['total_score' => 0, 'breakdown' => [], 'is_qualified' => false];
        }

        $result = $this->icp->scoreProspect([
            'industry' => $this->industry,
            'company_size' => $this->company_size,
            'tech_stack' => $this->tech_stack ?? [],
            'signals' => $this->signals ?? [],
        ]);

        $this->icp_score = $result['total_score'];
        $this->score_breakdown = $result['breakdown'];
        $this->save();

        return $result;
    }

    /**
     * Convert prospect to a lead.
     */
    public function convertToLead(): Lead
    {
        if ($this->converted_to_lead_id) {
            return $this->convertedLead;
        }

        $lead = Lead::create([
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'website' => $this->company_website,
            'source' => 'prospect_'.($this->source ?? 'unknown'),
            'stage' => 'new',
            'deal_value' => $this->icp?->avg_deal_value,
            'probability' => 10,
            'description' => "Converted from prospect. ICP Score: {$this->icp_score}",
        ]);

        $this->update([
            'converted_to_lead_id' => $lead->id,
            'converted_at' => now(),
            'status' => self::STATUS_CONVERTED,
        ]);

        return $lead;
    }

    /**
     * Scope: New prospects.
     */
    public function scopeNew($query)
    {
        return $query->where('status', self::STATUS_NEW);
    }

    /**
     * Scope: Qualified prospects (score >= threshold).
     */
    public function scopeQualified($query, int $minScore = 60)
    {
        return $query->where('icp_score', '>=', $minScore)
            ->where('status', '!=', self::STATUS_CONVERTED);
    }

    /**
     * Scope: Not yet converted.
     */
    public function scopeNotConverted($query)
    {
        return $query->whereNull('converted_to_lead_id');
    }

    /**
     * Scope: By ICP.
     */
    public function scopeForIcp($query, int $icpId)
    {
        return $query->where('icp_id', $icpId);
    }

    /**
     * Get qualification status as string.
     */
    public function getQualificationStatusAttribute(): string
    {
        if ($this->icp_score >= 80) {
            return 'highly_qualified';
        }
        if ($this->icp_score >= 60) {
            return 'qualified';
        }
        if ($this->icp_score >= 40) {
            return 'potential';
        }

        return 'low_fit';
    }

    /**
     * Check if prospect has been contacted.
     */
    public function getHasBeenContactedAttribute(): bool
    {
        return $this->outreachMessages()->whereNotNull('sent_at')->exists();
    }
}
