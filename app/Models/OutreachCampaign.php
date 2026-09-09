<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class OutreachCampaign extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_industries' => 'array',
        'target_titles' => 'array',
        'use_email' => 'boolean',
        'use_linkedin' => 'boolean',
        'use_phone' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const TYPE_COLD_OUTREACH = 'cold_outreach';

    public const TYPE_NURTURE = 'nurture';

    public const TYPE_REENGAGEMENT = 'reengagement';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name).'-'.Str::random(4);
            }
        });
    }

    public function icp(): BelongsTo
    {
        return $this->belongsTo(IdealCustomerProfile::class, 'icp_id');
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(OutreachSequence::class, 'campaign_id')->orderBy('step_number');
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(OutreachMessage::class, OutreachSequence::class, 'campaign_id', 'sequence_id');
    }

    /**
     * Get eligible prospects for this campaign.
     */
    public function getEligibleProspects(): \Illuminate\Support\Collection
    {
        $query = Prospect::where('status', '!=', Prospect::STATUS_CONVERTED)
            ->where('icp_score', '>=', $this->min_icp_score);

        if ($this->icp_id) {
            $query->where('icp_id', $this->icp_id);
        }

        if (! empty($this->target_industries)) {
            $query->whereIn('industry', $this->target_industries);
        }

        if (! empty($this->target_titles)) {
            $query->where(function ($q) {
                foreach ($this->target_titles as $title) {
                    $q->orWhere('contact_title', 'like', "%{$title}%");
                }
            });
        }

        // Exclude already enrolled prospects
        $enrolledIds = $this->messages()->pluck('prospect_id')->filter();
        if ($enrolledIds->isNotEmpty()) {
            $query->whereNotIn('id', $enrolledIds);
        }

        return $query->get();
    }

    /**
     * Enroll a prospect in this campaign.
     */
    public function enrollProspect(Prospect $prospect): ?OutreachMessage
    {
        $firstSequence = $this->sequences()->where('is_active', true)->first();

        if (! $firstSequence) {
            return null;
        }

        $message = OutreachMessage::create([
            'sequence_id' => $firstSequence->id,
            'prospect_id' => $prospect->id,
            'channel' => $firstSequence->channel,
            'subject' => $firstSequence->subject_template,
            'body' => $firstSequence->body_template,
            'status' => 'draft',
            'scheduled_for' => now()->addDays($firstSequence->delay_days),
        ]);

        $this->increment('enrolled_count');

        return $message;
    }

    /**
     * Update denormalized metrics.
     */
    public function refreshMetrics(): void
    {
        $this->update([
            'enrolled_count' => $this->messages()->distinct('prospect_id')->count('prospect_id'),
            'sent_count' => $this->messages()->whereNotNull('sent_at')->count(),
            'opened_count' => $this->messages()->whereNotNull('opened_at')->count(),
            'replied_count' => $this->messages()->whereNotNull('replied_at')->count(),
            'converted_count' => $this->messages()
                ->whereHas('prospect', fn ($q) => $q->where('status', Prospect::STATUS_CONVERTED))
                ->distinct('prospect_id')
                ->count('prospect_id'),
        ]);
    }

    /**
     * Get conversion rate.
     */
    public function getConversionRateAttribute(): float
    {
        return $this->enrolled_count > 0
            ? round(($this->converted_count / $this->enrolled_count) * 100, 1)
            : 0;
    }

    /**
     * Get reply rate.
     */
    public function getReplyRateAttribute(): float
    {
        return $this->sent_count > 0
            ? round(($this->replied_count / $this->sent_count) * 100, 1)
            : 0;
    }

    /**
     * Get open rate.
     */
    public function getOpenRateAttribute(): float
    {
        return $this->sent_count > 0
            ? round(($this->opened_count / $this->sent_count) * 100, 1)
            : 0;
    }

    /**
     * Scope: Active campaigns.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
