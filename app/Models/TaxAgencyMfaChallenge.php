<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxAgencyMfaChallenge extends Model
{
    /** @use HasFactory<\Database\Factories\TaxAgencyMfaChallengeFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected $hidden = [
        'response_code',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'response_code' => 'encrypted',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TaxAgencyConnection::class, 'tax_agency_connection_id');
    }

    public function slackWorkspace(): BelongsTo
    {
        return $this->belongsTo(SlackWorkspace::class, 'slack_workspace_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
