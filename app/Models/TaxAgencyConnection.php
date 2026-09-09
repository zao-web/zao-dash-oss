<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class TaxAgencyConnection extends Model
{
    /** @use HasFactory<\Database\Factories\TaxAgencyConnectionFactory> */
    use HasFactory;

    public const AGENCY_IRS = 'irs';

    public const AGENCY_OREGON_DOR = 'oregon_dor';

    public const STATUS_NEEDS_AUTH = 'needs_auth';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = [];

    protected $casts = [
        'capabilities' => 'array',
        'sync_enabled' => 'boolean',
        'sync_progress' => 'integer',
        'latest_balance_amount' => 'decimal:2',
        'last_synced_at' => 'datetime',
        'latest_notice_at' => 'datetime',
        'latest_transcript_at' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
    ];

    protected $hidden = [
        'auth_payload',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountStates(): HasMany
    {
        return $this->hasMany(TaxAgencyAccountState::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('sync_enabled', true);
    }

    public function scopeForAgency(Builder $query, string $agencyCode): Builder
    {
        return $query->where('agency_code', $agencyCode);
    }

    public function setAuthPayloadAttribute(?array $value): void
    {
        $this->attributes['auth_payload'] = $value === null
            ? null
            : Crypt::encryptString((string) json_encode($value));
    }

    public function getAuthPayloadAttribute(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($value), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Connected',
            self::STATUS_NEEDS_AUTH => 'Needs auth',
            self::STATUS_ERROR => 'Error',
            self::STATUS_REVOKED => 'Revoked',
            default => 'Not configured',
        };
    }

    public function getSyncStatusLabelAttribute(): string
    {
        return match ($this->sync_status) {
            'syncing' => 'Syncing',
            'completed' => 'Current',
            'failed' => 'Failed',
            default => 'Waiting',
        };
    }
}
