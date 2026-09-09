<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class VaultSecretValue extends Model
{
    use HasFactory;

    const ENVIRONMENT_PRODUCTION = 'production';

    const ENVIRONMENT_STAGING = 'staging';

    const ENVIRONMENT_DEVELOPMENT = 'development';

    protected $fillable = [
        'vault_secret_id',
        'environment',
        'encrypted_value',
        'value_fingerprint',
        'expires_at',
        'is_active',
        'last_accessed_at',
        'access_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'access_count' => 'integer',
    ];

    protected $hidden = [
        'encrypted_value',
    ];

    protected static function booted(): void
    {
        static::saving(function (VaultSecretValue $value) {
            if ($value->isDirty('encrypted_value') && $value->encrypted_value) {
                $value->value_fingerprint = $value->generateFingerprint();
            }
        });
    }

    public function secret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'vault_secret_id');
    }

    public function getDecryptedValue(): string
    {
        return Crypt::decryptString($this->encrypted_value);
    }

    public function setValueAttribute(string $value): void
    {
        $this->attributes['encrypted_value'] = Crypt::encryptString($value);
    }

    public function generateFingerprint(): string
    {
        $decrypted = $this->getDecryptedValue();

        return hash_hmac('sha256', $decrypted, config('app.key'));
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopeForEnvironment($query, ?string $environment)
    {
        return $query->where('environment', $environment);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
}
