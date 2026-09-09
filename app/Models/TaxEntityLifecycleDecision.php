<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxEntityLifecycleDecision extends Model
{
    use HasFactory;

    public const DECISION_ACTIVE = 'active';

    public const DECISION_FINAL_RETURN = 'final_return';

    public const DECISION_FINAL_RETURN_AND_DISSOLVE = 'final_return_and_dissolve';

    public const DECISION_INACTIVE = 'inactive';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_final_return' => 'boolean',
            'requires_dissolution' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    /**
     * @return array<int, string>
     */
    public static function decisions(): array
    {
        return [
            self::DECISION_ACTIVE,
            self::DECISION_FINAL_RETURN,
            self::DECISION_FINAL_RETURN_AND_DISSOLVE,
            self::DECISION_INACTIVE,
        ];
    }

    public static function labelFor(?string $decision): string
    {
        return match ($decision) {
            self::DECISION_ACTIVE => 'Active in filing year',
            self::DECISION_FINAL_RETURN => 'File final return',
            self::DECISION_FINAL_RETURN_AND_DISSOLVE => 'File final return and dissolve',
            self::DECISION_INACTIVE => 'Inactive / no current-year return',
            default => 'Decision needed',
        };
    }
}
