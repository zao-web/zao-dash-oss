<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VaultSecretGitHubTarget extends Model
{
    use HasFactory;

    protected $table = 'vault_secret_github_targets';

    const DRIFT_STATUS_UNKNOWN = 'unknown';

    const DRIFT_STATUS_IN_SYNC = 'in_sync';

    const DRIFT_STATUS_MISSING_ON_GITHUB = 'missing_on_github';

    const DRIFT_STATUS_MODIFIED_ON_GITHUB = 'modified_on_github';

    protected $fillable = [
        'github_repo_id',
        'vault_secret_id',
        'environment',
        'github_secret_name',
        'github_environment_name',
        'is_managed',
        'last_pushed_at',
        'last_pushed_fingerprint',
        'last_seen_github_updated_at',
        'drift_status',
        'drift_detected_at',
        'last_pushed_by_user_id',
        'last_pushed_by_agent_run_id',
    ];

    protected $casts = [
        'is_managed' => 'boolean',
        'last_pushed_at' => 'datetime',
        'last_seen_github_updated_at' => 'datetime',
        'drift_detected_at' => 'datetime',
    ];

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'github_repo_id');
    }

    public function secret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'vault_secret_id');
    }

    public function vaultSecret(): BelongsTo
    {
        return $this->secret();
    }

    public function secretValue(): BelongsTo
    {
        return $this->belongsTo(VaultSecretValue::class, 'vault_secret_id', 'vault_secret_id')
            ->where('environment', $this->environment);
    }

    public function pushedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_pushed_by_user_id');
    }

    public function pushedByAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'last_pushed_by_agent_run_id');
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(VaultSecretGitHubSyncEvent::class);
    }

    public function scopeForRepo($query, int $repoId)
    {
        return $query->where('github_repo_id', $repoId);
    }

    public function scopeManaged($query)
    {
        return $query->where('is_managed', true);
    }

    public function scopeWithDrift($query)
    {
        return $query->whereIn('drift_status', [
            self::DRIFT_STATUS_MISSING_ON_GITHUB,
            self::DRIFT_STATUS_MODIFIED_ON_GITHUB,
        ]);
    }

    public function scopeInSync($query)
    {
        return $query->where('drift_status', self::DRIFT_STATUS_IN_SYNC);
    }

    public function markAsSynced(string $fingerprint, ?int $userId = null, ?int $agentRunId = null): void
    {
        $this->update([
            'drift_status' => self::DRIFT_STATUS_IN_SYNC,
            'last_pushed_at' => now(),
            'last_pushed_fingerprint' => $fingerprint,
            'last_seen_github_updated_at' => now(),
            'drift_detected_at' => null,
            'last_pushed_by_user_id' => $userId,
            'last_pushed_by_agent_run_id' => $agentRunId,
        ]);
    }

    public function markDrift(string $driftStatus): void
    {
        $this->update([
            'drift_status' => $driftStatus,
            'drift_detected_at' => now(),
        ]);
    }

    public function hasDrift(): bool
    {
        return in_array($this->drift_status, [
            self::DRIFT_STATUS_MISSING_ON_GITHUB,
            self::DRIFT_STATUS_MODIFIED_ON_GITHUB,
        ]);
    }

    public function needsSync(string $currentFingerprint): bool
    {
        return $this->last_pushed_fingerprint !== $currentFingerprint;
    }

    public function isEnvironmentSecret(): bool
    {
        return $this->github_environment_name !== null;
    }
}
