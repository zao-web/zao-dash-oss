<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultSecretGitHubSyncEvent extends Model
{
    use HasFactory;

    protected $table = 'vault_secret_github_sync_events';

    const ACTION_CHECK = 'check';

    const ACTION_PUSH = 'push';

    const ACTION_DELETE = 'delete';

    const ACTION_ROTATE_PUSH = 'rotate_push';

    const ACTION_DRIFT_DETECTED = 'drift_detected';

    const STATUS_SUCCESS = 'success';

    const STATUS_FAILURE = 'failure';

    const STATUS_SKIPPED = 'skipped';

    const TRIGGERED_BY_USER = 'user';

    const TRIGGERED_BY_AGENT = 'agent';

    const TRIGGERED_BY_SYSTEM = 'system';

    const TRIGGERED_BY_SCHEDULE = 'schedule';

    public $timestamps = false;

    protected $fillable = [
        'github_repo_id',
        'vault_secret_id',
        'vault_secret_github_target_id',
        'action',
        'status',
        'error_message',
        'github_secret_name',
        'environment',
        'github_environment_name',
        'triggered_by_type',
        'triggered_by_id',
        'triggered_by_name',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'github_repo_id');
    }

    public function secret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'vault_secret_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(VaultSecretGitHubTarget::class, 'vault_secret_github_target_id');
    }

    public function scopeForRepo($query, int $repoId)
    {
        return $query->where('github_repo_id', $repoId);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILURE);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public static function logEvent(
        int $repoId,
        string $action,
        string $status,
        ?int $secretId = null,
        ?int $targetId = null,
        ?string $secretName = null,
        ?string $environment = null,
        ?string $githubEnvironment = null,
        ?string $errorMessage = null,
        ?string $triggeredByType = null,
        ?int $triggeredById = null,
        ?string $triggeredByName = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'github_repo_id' => $repoId,
            'vault_secret_id' => $secretId,
            'vault_secret_github_target_id' => $targetId,
            'action' => $action,
            'status' => $status,
            'error_message' => $errorMessage,
            'github_secret_name' => $secretName,
            'environment' => $environment,
            'github_environment_name' => $githubEnvironment,
            'triggered_by_type' => $triggeredByType,
            'triggered_by_id' => $triggeredById,
            'triggered_by_name' => $triggeredByName,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
