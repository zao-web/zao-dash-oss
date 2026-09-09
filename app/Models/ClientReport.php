<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientReport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'data_snapshot' => 'array',
        'highlights' => 'array',
        'metrics' => 'array',
        'hours_by_category' => 'array',
        'hours_by_project' => 'array',
        'total_hours' => 'decimal:2',
        'tasks_completed' => 'integer',
        'prs_merged' => 'integer',
        'issues_closed' => 'integer',
        'meetings_held' => 'integer',
        'sent_to' => 'array',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'opens_count' => 'integer',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_GENERATED = 'generated';

    const STATUS_SENT = 'sent';

    const STATUS_FAILED = 'failed';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeGenerated($query)
    {
        return $query->where('status', self::STATUS_GENERATED);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->where('period_start', $start)->where('period_end', $end);
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }

    /**
     * Get the PDF URL if available.
     */
    public function getPdfUrlAttribute(): ?string
    {
        if (! $this->pdf_path) {
            return null;
        }

        return Storage::disk($this->pdf_disk ?? 'local')->url($this->pdf_path);
    }

    /**
     * Get formatted period string.
     */
    public function getPeriodLabelAttribute(): string
    {
        if ($this->report_type === 'monthly') {
            return $this->period_start->format('F Y');
        }
        if ($this->report_type === 'quarterly') {
            $quarter = ceil($this->period_start->month / 3);

            return "Q{$quarter} {$this->period_start->year}";
        }

        return $this->period_start->format('M j').' - '.$this->period_end->format('M j, Y');
    }

    /**
     * Get the hero metric (biggest achievement).
     */
    public function getHeroMetricAttribute(): ?array
    {
        $metrics = $this->metrics ?? [];

        return $metrics[0] ?? null;
    }

    /**
     * Mark as generated with PDF path.
     */
    public function markGenerated(string $pdfPath, string $disk = 'local'): self
    {
        $this->update([
            'status' => self::STATUS_GENERATED,
            'pdf_path' => $pdfPath,
            'pdf_disk' => $disk,
        ]);

        return $this;
    }

    /**
     * Mark as sent.
     */
    public function markSent(array $recipients): self
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_to' => $recipients,
            'sent_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);

        return $this;
    }

    /**
     * Record an email open.
     */
    public function recordOpen(): self
    {
        $this->increment('opens_count');

        if (! $this->opened_at) {
            $this->update(['opened_at' => now()]);
        }

        return $this;
    }

    /**
     * Check if report can be regenerated.
     */
    public function canRegenerate(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_GENERATED, self::STATUS_FAILED]);
    }

    /**
     * Get work highlights for display.
     */
    public function getFormattedHighlightsAttribute(): array
    {
        $highlights = $this->highlights ?? [];

        return collect($highlights)->map(function ($item) {
            return [
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'metrics' => $item['metrics'] ?? [],
                'icon' => $item['icon'] ?? '✅',
            ];
        })->toArray();
    }
}
