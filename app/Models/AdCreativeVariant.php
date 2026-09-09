<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreativeVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_control' => 'boolean',
        'test_started_at' => 'datetime',
        'test_ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function adCreative(): BelongsTo
    {
        return $this->belongsTo(AdCreative::class);
    }

    public function isTesting(): bool
    {
        return $this->status === 'testing';
    }

    public function isWinner(): bool
    {
        return $this->status === 'winner';
    }

    public function isLoser(): bool
    {
        return $this->status === 'loser';
    }

    public function isControl(): bool
    {
        return $this->is_control;
    }

    public function markAsWinner(): void
    {
        $this->update([
            'status' => 'winner',
            'test_ended_at' => now(),
        ]);
    }

    public function markAsLoser(): void
    {
        $this->update([
            'status' => 'loser',
            'test_ended_at' => now(),
        ]);
    }
}
