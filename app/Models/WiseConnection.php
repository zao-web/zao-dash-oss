<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class WiseConnection extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'api_token',
        'webhook_secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(WiseTransfer::class);
    }

    // Encrypted api_token accessors
    public function setApiTokenAttribute($value): void
    {
        $this->attributes['api_token'] = Crypt::encryptString($value);
    }

    public function getApiTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    // Encrypted webhook_secret accessors
    public function setWebhookSecretAttribute($value): void
    {
        $this->attributes['webhook_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getWebhookSecretAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function markSynced(): void
    {
        $this->update(['last_synced_at' => now()]);
    }
}
