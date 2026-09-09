<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitabilityView extends Model
{
    /** @use HasFactory<\Database\Factories\ProfitabilityViewFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'revenue' => 'decimal:2',
        'cost' => 'decimal:2',
        'profit' => 'decimal:2',
        'margin' => 'decimal:2',
        'hours_logged' => 'decimal:2',
        'effective_rate' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForPeriod(Builder $query, string $start, string $end): Builder
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }

    public function scopeByClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeByProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeProfitable(Builder $query): Builder
    {
        return $query->where('profit', '>', 0);
    }

    public function scopeUnprofitable(Builder $query): Builder
    {
        return $query->where('profit', '<=', 0);
    }
}
