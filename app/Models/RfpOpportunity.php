<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RfpOpportunity extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
        'fit_score_breakdown' => 'array',
        'budget_line_items' => 'array',
        'tech_requirements' => 'array',
        'requirements_summary' => 'array',
        'parsed_sections' => 'array',
        'timeline_requirements' => 'array',
        'evaluation_criteria' => 'array',
        'tags' => 'array',
        'submission_deadline' => 'datetime',
        'generation_started_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(RfpSource::class, 'rfp_source_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(RfpProposal::class);
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(RfpOutcome::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isExpired(): bool
    {
        return $this->submission_deadline && $this->submission_deadline->isPast();
    }

    public function budgetRange(): string
    {
        if ($this->budget_min && $this->budget_max) {
            return '$'.number_format($this->budget_min, 0).' - $'.number_format($this->budget_max, 0);
        }

        if ($this->budget_min) {
            return 'From $'.number_format($this->budget_min, 0);
        }

        if ($this->budget_max) {
            return 'Up to $'.number_format($this->budget_max, 0);
        }

        return 'Not specified';
    }
}
