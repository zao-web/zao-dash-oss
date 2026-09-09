<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GitHubRepo extends Model
{
    use HasFactory;

    protected $table = 'github_repos';

    protected $guarded = [];

    protected $casts = [
        'is_private' => 'boolean',
        'is_archived' => 'boolean',
        'monitoring_enabled' => 'boolean',
        'deployment_config' => 'array',
        'pushed_at' => 'datetime',
        'issues_synced_at' => 'datetime',
        'prs_synced_at' => 'datetime',
    ];

    /**
     * Check if the repo has been active recently (pushed to in the last N months).
     */
    public function isRecentlyActive(int $months = 6): bool
    {
        if (! $this->pushed_at) {
            return false;
        }

        return $this->pushed_at->gte(now()->subMonths($months));
    }

    /**
     * Scope to only recently active repos.
     */
    public function scopeRecentlyActive($query, int $months = 6)
    {
        return $query->where('pushed_at', '>=', now()->subMonths($months));
    }

    /**
     * Scope to only monitored and active repos.
     */
    public function scopeActivelyMonitored($query, int $months = 6)
    {
        return $query->where('monitoring_enabled', true)
            ->where('is_archived', false)
            ->where('pushed_at', '>=', now()->subMonths($months));
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'installation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(GitHubIssue::class, 'repo_id');
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(GitHubPullRequest::class, 'repo_id');
    }

    public function deploymentConfig(): HasOne
    {
        return $this->hasOne(DeploymentConfig::class, 'repo_id');
    }

    public function openIssues(): HasMany
    {
        return $this->issues()->where('state', 'open');
    }

    public function openPullRequests(): HasMany
    {
        return $this->pullRequests()->where('state', 'open');
    }

    public function getUrlAttribute(): string
    {
        return "https://github.com/{$this->full_name}";
    }

    public function secretTargets(): HasMany
    {
        return $this->hasMany(VaultSecretGitHubTarget::class, 'github_repo_id');
    }

    public function workflowSecretRequirements(): HasMany
    {
        return $this->hasMany(GitHubWorkflowSecretRequirement::class, 'github_repo_id');
    }
}
