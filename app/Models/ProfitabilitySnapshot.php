<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitabilitySnapshot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'hours_logged' => 'decimal:2',
        'hours_billable' => 'decimal:2',
        'revenue' => 'decimal:2',
        'cost' => 'decimal:2',
        'profit' => 'decimal:2',
        'margin_percent' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getBillableRatioAttribute(): float
    {
        if ($this->hours_logged == 0) {
            return 0;
        }

        return round(($this->hours_billable / $this->hours_logged) * 100, 1);
    }

    public function getEffectiveRateAttribute(): ?float
    {
        if ($this->hours_billable == 0) {
            return null;
        }

        return round($this->revenue / $this->hours_billable, 2);
    }

    public static function createForPeriod(string $type, $start, $end, array $data): self
    {
        return static::create([
            'period_type' => $type,
            'period_start' => $start,
            'period_end' => $end,
            ...$data,
        ]);
    }
}
