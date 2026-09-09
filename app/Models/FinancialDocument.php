<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialDocument extends Model
{
    /** @use HasFactory<\Database\Factories\FinancialDocumentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'file_size' => 'integer',
        'extracted_data' => 'array',
        'extraction_confidence' => 'decimal:2',
        'needs_review' => 'boolean',
        'reviewed_at' => 'datetime',
        'effective_date' => 'date',
        'response_deadline' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function taxObligation(): BelongsTo
    {
        return $this->belongsTo(TaxObligation::class);
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('needs_review', true);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopeWithUpcomingDeadline(Builder $query): Builder
    {
        return $query->whereNotNull('response_deadline')
            ->where('response_deadline', '>=', now())
            ->where('response_deadline', '<=', now()->addDays(30));
    }

    public function scopeIrsNotices(Builder $query): Builder
    {
        return $query->where('document_type', 'irs_notice');
    }
}
