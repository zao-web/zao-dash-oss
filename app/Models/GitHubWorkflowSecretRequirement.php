<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubWorkflowSecretRequirement extends Model
{
    use HasFactory;

    protected $table = 'github_workflow_secret_requirements';

    const SOURCE_PARSED = 'parsed';

    const SOURCE_MANUAL = 'manual';

    const SOURCE_AGENT_INFERRED = 'agent_inferred';

    const MATCH_STATUS_MATCHED = 'matched';

    const MATCH_STATUS_UNMATCHED = 'unmatched';

    const MATCH_STATUS_MISSING_VALUE = 'missing_value';

    protected $fillable = [
        'github_repo_id',
        'workflow_path',
        'workflow_name',
        'secret_name',
        'is_required',
        'job_name',
        'job_environment_name',
        'source',
        'detected_at',
        'last_verified_at',
        'vault_secret_id',
        'match_status',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'detected_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'github_repo_id');
    }

    public function vaultSecret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'vault_secret_id');
    }

    public function scopeForRepo($query, int $repoId)
    {
        return $query->where('github_repo_id', $repoId);
    }

    public function scopeUnmatched($query)
    {
        return $query->where('match_status', self::MATCH_STATUS_UNMATCHED);
    }

    public function scopeMatched($query)
    {
        return $query->where('match_status', self::MATCH_STATUS_MATCHED);
    }

    public function scopeMissingValue($query)
    {
        return $query->where('match_status', self::MATCH_STATUS_MISSING_VALUE);
    }

    public function scopeForWorkflow($query, string $workflowPath)
    {
        return $query->where('workflow_path', $workflowPath);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where('job_environment_name', $environment);
    }

    public function isMatched(): bool
    {
        return $this->match_status === self::MATCH_STATUS_MATCHED;
    }

    public function linkToVaultSecret(VaultSecret $secret): void
    {
        $this->update([
            'vault_secret_id' => $secret->id,
            'match_status' => self::MATCH_STATUS_MATCHED,
            'last_verified_at' => now(),
        ]);
    }

    public function markMissingValue(): void
    {
        $this->update([
            'match_status' => self::MATCH_STATUS_MISSING_VALUE,
            'last_verified_at' => now(),
        ]);
    }

    public function markUnmatched(): void
    {
        $this->update([
            'vault_secret_id' => null,
            'match_status' => self::MATCH_STATUS_UNMATCHED,
            'last_verified_at' => now(),
        ]);
    }
}
