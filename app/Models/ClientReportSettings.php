<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReportSettings extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'send_day' => 'integer',
        'recipients' => 'array',
        'include_time_breakdown' => 'boolean',
        'include_github_activity' => 'boolean',
        'include_tasks_completed' => 'boolean',
        'include_financials' => 'boolean',
        'include_upcoming' => 'boolean',
        'custom_branding' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the logo URL, falling back to client's logo or null.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->custom_branding['logo_url']
            ?? $this->client?->logo_url
            ?? null;
    }

    /**
     * Get the primary brand color.
     */
    public function getPrimaryColorAttribute(): string
    {
        return $this->custom_branding['primary_color'] ?? '#1a1a2e';
    }

    /**
     * Get the accent color.
     */
    public function getAccentColorAttribute(): string
    {
        return $this->custom_branding['accent_color'] ?? '#4f46e5';
    }

    /**
     * Get recipient emails as array.
     */
    public function getRecipientEmailsAttribute(): array
    {
        if (! $this->recipients) {
            // Fall back to client contacts marked for reports
            return $this->client?->contacts()
                ->where('receives_reports', true)
                ->pluck('email')
                ->toArray() ?? [];
        }

        return $this->recipients;
    }

    /**
     * Check if it's time to send based on frequency and day.
     */
    public function shouldSendToday(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        $today = now();

        return match ($this->frequency) {
            'monthly' => $today->day === $this->send_day,
            'quarterly' => $today->day === $this->send_day && in_array($today->month, [1, 4, 7, 10]),
            'weekly' => $today->dayOfWeek === $this->send_day, // 0=Sunday, 1=Monday, etc.
            default => false,
        };
    }
}
