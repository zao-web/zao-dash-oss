<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'attendees' => 'array',
        'key_decisions' => 'array',
        'is_all_day' => 'boolean',
        'is_client_meeting' => 'boolean',
        'pre_brief_sent' => 'boolean',
        'post_followup_sent' => 'boolean',
        'parsed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isUpcoming(): bool
    {
        return $this->start_at->isFuture();
    }

    public function isInProgress(): bool
    {
        return $this->start_at->isPast() && $this->end_at->isFuture();
    }

    public function isPast(): bool
    {
        return $this->end_at->isPast();
    }

    public function needsPreBrief(): bool
    {
        if ($this->pre_brief_sent || ! $this->is_client_meeting) {
            return false;
        }

        // 30 minutes before meeting
        return $this->start_at->subMinutes(30)->isPast() && $this->start_at->isFuture();
    }

    public function needsPostFollowup(): bool
    {
        if ($this->post_followup_sent || ! $this->is_client_meeting) {
            return false;
        }

        return $this->isPast();
    }

    public function getDurationHoursAttribute(): float
    {
        return round($this->start_at->diffInMinutes($this->end_at) / 60, 2);
    }

    public function getExternalAttendeesAttribute(): array
    {
        $attendees = $this->attendees ?? [];
        $internalDomains = ['example.com', 'internal.example.com'];

        return array_filter($attendees, function ($attendee) use ($internalDomains) {
            $email = $attendee['email'] ?? '';
            foreach ($internalDomains as $domain) {
                if (str_ends_with($email, "@{$domain}")) {
                    return false;
                }
            }

            return true;
        });
    }
}
