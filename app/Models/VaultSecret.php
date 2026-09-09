<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class VaultSecret extends Model
{
    use HasFactory;

    const CATEGORY_API_KEY = 'api_key';

    const CATEGORY_OAUTH = 'oauth';

    const CATEGORY_CREDENTIAL = 'credential';

    const CATEGORY_CERTIFICATE = 'certificate';

    const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'name',
        'key',
        'encrypted_value',
        'category',
        'description',
        'project_id',
        'client_id',
        'allowed_agents',
        'allowed_users',
        'is_sensitive',
        'created_by',
        'updated_by',
        'last_accessed_at',
        'access_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'allowed_agents' => 'array',
        'allowed_users' => 'array',
        'is_sensitive' => 'boolean',
        'is_active' => 'boolean',
        'last_accessed_at' => 'datetime',
        'expires_at' => 'datetime',
        'access_count' => 'integer',
    ];

    protected $hidden = [
        'encrypted_value',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(VaultAccessLog::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(VaultSecretValue::class);
    }

    public function githubTargets(): HasMany
    {
        return $this->hasMany(VaultSecretGitHubTarget::class);
    }

    public function workflowRequirements(): HasMany
    {
        return $this->hasMany(GitHubWorkflowSecretRequirement::class);
    }

    public function getValueForEnvironment(?string $environment = null): ?VaultSecretValue
    {
        return $this->values()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->first()
            ?? $this->values()
                ->whereNull('environment')
                ->where('is_active', true)
                ->first();
    }

    // Scopes

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

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('project_id')->whereNull('client_id');
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // Accessors

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getScopeLabelAttribute(): string
    {
        if ($this->project_id) {
            return 'Project: '.($this->project?->name ?? 'Unknown');
        }
        if ($this->client_id) {
            return 'Client: '.($this->client?->name ?? 'Unknown');
        }

        return 'Global';
    }

    // Access Control

    public function canBeAccessedBy(?User $user = null, ?string $agentSlug = null): bool
    {
        if (! $this->is_active || $this->is_expired) {
            return false;
        }

        if ($agentSlug) {
            $allowedAgents = $this->allowed_agents ?? [];
            if (empty($allowedAgents)) {
                return true;
            }

            return in_array($agentSlug, $allowedAgents);
        }

        if ($user) {
            if ($user->is_admin) {
                return true;
            }

            $allowedUsers = $this->allowed_users ?? [];
            if (empty($allowedUsers)) {
                return true;
            }

            return in_array($user->id, $allowedUsers);
        }

        return false;
    }

    public function setValueAttribute(string $value): void
    {
        $this->attributes['encrypted_value'] = Crypt::encryptString($value);
    }

    public function getDecryptedValue(): string
    {
        return Crypt::decryptString($this->encrypted_value);
    }
}
