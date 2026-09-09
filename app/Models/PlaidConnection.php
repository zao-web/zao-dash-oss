<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class PlaidConnection extends Model
{
    /** @use HasFactory<\Database\Factories\PlaidConnectionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'consent_expiration' => 'datetime',
        'last_synced_at' => 'datetime',
        'products' => 'array',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->whereNotNull('error_code');
    }
}
