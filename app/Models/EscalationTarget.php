<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationTarget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    const LEVEL_ACCOUNT_MANAGER = 0;

    const LEVEL_MANAGER = 1;

    const LEVEL_DIRECTOR = 2;

    const LEVEL_EXECUTIVE = 3;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    public static function getTargetsForLevel(int $level): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->forLevel($level)
            ->orderBy('order')
            ->with('user')
            ->get();
    }

    public static function getLevelName(int $level): string
    {
        return match ($level) {
            self::LEVEL_ACCOUNT_MANAGER => 'Account Manager',
            self::LEVEL_MANAGER => 'Manager',
            self::LEVEL_DIRECTOR => 'Director',
            self::LEVEL_EXECUTIVE => 'Executive',
            default => 'Unknown',
        };
    }
}
