<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'personalization_context' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'replied_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    public const STATUS_OPENED = 'opened';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_BOUNCED = 'bounced';

    public const SENTIMENT_POSITIVE = 'positive';

    public const SENTIMENT_NEUTRAL = 'neutral';

    public const SENTIMENT_NEGATIVE = 'negative';

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OutreachSequence::class, 'sequence_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdByRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'created_by_agent_run_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Approve this message for sending.
     */
    public function approve(User $user): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Mark as sent.
     */
    public function markSent(): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->sequence?->campaign?->increment('sent_count');
    }

    /**
     * Mark as opened.
     */
    public function markOpened(): void
    {
        if ($this->opened_at) {
            return;
        }

        $this->update([
            'status' => self::STATUS_OPENED,
            'opened_at' => now(),
        ]);

        $this->sequence?->campaign?->increment('opened_count');
    }

    /**
     * Record a reply.
     */
    public function recordReply(string $replyText, ?string $sentiment = null): void
    {
        $this->update([
            'status' => self::STATUS_REPLIED,
            'replied_at' => now(),
            'reply_text' => $replyText,
            'reply_sentiment' => $sentiment,
        ]);

        $this->sequence?->campaign?->increment('replied_count');
    }

    /**
     * Create next step message if applicable.
     */
    public function createNextStepMessage(): ?self
    {
        $nextSequence = $this->sequence?->getNextStep();

        if (! $nextSequence || ! $nextSequence->shouldTriggerFor($this)) {
            return null;
        }

        $personalized = $nextSequence->personalizeFor($this->prospect, $this->personalization_context ?? []);

        return self::create([
            'sequence_id' => $nextSequence->id,
            'prospect_id' => $this->prospect_id,
            'lead_id' => $this->lead_id,
            'channel' => $nextSequence->channel,
            'subject' => $personalized['subject'],
            'body' => $personalized['body'],
            'personalization_context' => $personalized['context'],
            'status' => $nextSequence->requires_approval ? self::STATUS_DRAFT : self::STATUS_SCHEDULED,
            'scheduled_for' => $nextSequence->getNextSendTime($this->sent_at),
        ]);
    }

    /**
     * Scope: Ready to send.
     */
    public function scopeReadyToSend($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_SCHEDULED])
            ->where('scheduled_for', '<=', now());
    }

    /**
     * Scope: Pending approval.
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope: By channel.
     */
    public function scopeOfChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Get recipient email or identifier.
     */
    public function getRecipientAttribute(): ?string
    {
        if ($this->prospect) {
            return match ($this->channel) {
                'email' => $this->prospect->contact_email,
                'linkedin' => $this->prospect->contact_linkedin,
                'phone' => $this->prospect->contact_phone,
                default => $this->prospect->contact_email,
            };
        }

        if ($this->lead) {
            return $this->lead->contact_email;
        }

        return null;
    }
}
