<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'to_addresses' => 'array',
        'attachments' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'ai_analyzed_at' => 'datetime',
        'sentiment_score' => 'decimal:2',
        'is_transcript' => 'boolean',
        'is_processed' => 'boolean',
        'has_invoice' => 'boolean',
        'action_required' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function detectedInvoice(): BelongsTo
    {
        return $this->belongsTo(ContractorInvoice::class, 'detected_invoice_id');
    }

    public function isFromContractor(): bool
    {
        return $this->contractor_id !== null;
    }

    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }

    public function getPdfAttachments(): array
    {
        if (! $this->attachments) {
            return [];
        }

        return array_filter($this->attachments, function ($attachment) {
            return str_ends_with(strtolower($attachment['filename'] ?? ''), '.pdf')
                || ($attachment['mime_type'] ?? '') === 'application/pdf';
        });
    }

    public function isFromClient(): bool
    {
        return $this->client_id !== null;
    }

    public function isGeminiTranscript(): bool
    {
        return $this->is_transcript ||
            str_contains($this->from_address, 'meet-recordings-noreply@google.com') ||
            str_contains($this->subject ?? '', 'Meeting transcript');
    }

    public function getSentimentBadgeAttribute(): ?string
    {
        return match ($this->sentiment_label) {
            'positive' => 'success',
            'negative' => 'danger',
            'urgent' => 'warning',
            default => 'secondary',
        };
    }

    public function scopeNeedsAnalysis($query)
    {
        return $query->whereNull('ai_analyzed_at')
            ->whereNotNull('client_id')
            ->where('is_transcript', false);
    }

    public function scopeActionRequired($query)
    {
        return $query->where('action_required', true);
    }

    public function scopeFromClients($query)
    {
        return $query->whereNotNull('client_id');
    }

    public function needsResponse(): bool
    {
        return $this->action_required && $this->urgency !== 'low';
    }

    public function getUrgencyBadgeAttribute(): ?string
    {
        return match ($this->urgency) {
            'high' => 'danger',
            'medium' => 'warning',
            'low' => 'secondary',
            default => null,
        };
    }
}
