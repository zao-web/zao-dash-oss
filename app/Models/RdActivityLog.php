<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RdActivityLog extends Model
{
    /** @use HasFactory<\Database\Factories\RdActivityLogFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'activity_date' => 'date',
        'hours' => 'decimal:2',
        'qualifies_for_rd' => 'boolean',
        'wage_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeQualifying(Builder $query): Builder
    {
        return $query->where('qualifies_for_rd', true);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('activity_date', $year);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
