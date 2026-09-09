<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachSequence extends Model
{
    protected $guarded = [];

    protected $casts = [
        'send_days' => 'array',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_LINKEDIN = 'linkedin';

    public const CHANNEL_PHONE = 'phone';

    public const CHANNEL_MANUAL = 'manual';

    public const CONDITION_ALWAYS = 'always';

    public const CONDITION_NO_REPLY = 'no_reply';

    public const CONDITION_OPENED = 'opened';

    public const CONDITION_NOT_OPENED = 'not_opened';

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OutreachCampaign::class, 'campaign_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class, 'sequence_id');
    }

    /**
     * Get the next sequence step in this campaign.
     */
    public function getNextStep(): ?self
    {
        return self::where('campaign_id', $this->campaign_id)
            ->where('step_number', '>', $this->step_number)
            ->where('is_active', true)
            ->orderBy('step_number')
            ->first();
    }

    /**
     * Check if this step should be triggered based on condition.
     */
    public function shouldTriggerFor(OutreachMessage $previousMessage): bool
    {
        return match ($this->condition) {
            self::CONDITION_ALWAYS => true,
            self::CONDITION_NO_REPLY => $previousMessage->replied_at === null,
            self::CONDITION_OPENED => $previousMessage->opened_at !== null,
            self::CONDITION_NOT_OPENED => $previousMessage->opened_at === null,
            default => true,
        };
    }

    /**
     * Get next send time based on configuration.
     */
    public function getNextSendTime(?\DateTimeInterface $after = null): \DateTimeInterface
    {
        $after = $after ?? now();
        $target = \Carbon\Carbon::parse($after)->addDays($this->delay_days);

        // Set preferred send time
        [$hour, $minute] = explode(':', $this->send_time ?? '09:00');
        $target->setTime((int) $hour, (int) $minute);

        // Adjust to allowed days if specified
        if (! empty($this->send_days)) {
            $allowedDays = array_map('strtolower', $this->send_days);
            $maxAttempts = 7;

            while (! in_array(strtolower($target->format('l')), $allowedDays) && $maxAttempts > 0) {
                $target->addDay();
                $maxAttempts--;
            }
        }

        return $target;
    }

    /**
     * Personalize template with prospect data.
     */
    public function personalizeFor(Prospect $prospect, array $extraContext = []): array
    {
        $context = array_merge([
            'company_name' => $prospect->company_name,
            'contact_name' => $prospect->contact_name,
            'first_name' => explode(' ', $prospect->contact_name ?? '')[0] ?? '',
            'contact_title' => $prospect->contact_title,
            'industry' => $prospect->industry,
        ], $extraContext);

        $subject = $this->subject_template;
        $body = $this->body_template;

        foreach ($context as $key => $value) {
            $placeholder = '{{'.$key.'}}';
            $subject = str_replace($placeholder, $value ?? '', $subject ?? '');
            $body = str_replace($placeholder, $value ?? '', $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'context' => $context,
        ];
    }
}
