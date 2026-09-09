<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NorthStarProgress extends Model
{
    /** @use HasFactory<\Database\Factories\NorthStarProgressFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'overall_progress_percent' => 'decimal:2',
            'milestone_progress' => 'array',
            'days_ahead_behind' => 'decimal:1',
            'snapshot_date' => 'date',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(NorthStarGoal::class, 'north_star_goal_id');
    }
}
