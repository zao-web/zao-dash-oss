<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookkeepingPeriodClose extends Model
{
    /** @use HasFactory<\Database\Factories\BookkeepingPeriodCloseFactory> */
    use HasFactory;

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'closed_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
