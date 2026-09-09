<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'title',
        'department',
        'permissions',
        'ui_preferences',
        'client_id',
        'slack_user_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'ui_preferences' => 'array',
        ];
    }

    /**
     * Get a UI preference value.
     */
    public function getUiPreference(string $key, mixed $default = null): mixed
    {
        return data_get($this->ui_preferences, $key, $default);
    }

    /**
     * Set a UI preference value.
     */
    public function setUiPreference(string $key, mixed $value): void
    {
        $preferences = $this->ui_preferences ?? [];
        data_set($preferences, $key, $value);
        $this->ui_preferences = $preferences;
        $this->save();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function googleCredential(): HasOne
    {
        return $this->hasOne(GoogleCredential::class);
    }

    public function harvestCredential(): HasOne
    {
        return $this->hasOne(HarvestCredential::class);
    }

    public function githubCredential(): HasOne
    {
        return $this->hasOne(GitHubCredential::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function linkedInCredential(): HasOne
    {
        return $this->hasOne(LinkedInCredential::class);
    }

    public function xCredentials(): HasMany
    {
        return $this->hasMany(XCredential::class);
    }

    /**
     * Get personal X account (@JS_Zao style).
     */
    public function personalXCredential(): ?XCredential
    {
        return $this->xCredentials()->where('account_type', XCredential::TYPE_PERSONAL)->first();
    }

    /**
     * Get company X account (@zaowebdev style).
     */
    public function companyXCredential(): ?XCredential
    {
        return $this->xCredentials()->where('account_type', XCredential::TYPE_COMPANY)->first();
    }

    /**
     * Backwards compatibility - get first X credential.
     *
     * @deprecated Use xCredentials(), personalXCredential(), or companyXCredential()
     */
    public function xCredential(): HasOne
    {
        return $this->hasOne(XCredential::class);
    }

    public function pmConnections(): HasMany
    {
        return $this->hasMany(PmConnection::class);
    }

    public function personalAccounts(): HasMany
    {
        return $this->hasMany(PersonalAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Check if user is a client portal user (has client role).
     */
    public function isClientUser(): bool
    {
        return $this->role === 'client';
    }

    /**
     * Check if user is an internal team member.
     */
    public function isInternalUser(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'staff']);
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->isInternalUser();
    }
}
