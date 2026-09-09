<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubPullRequest extends Model
{
    use HasFactory;

    protected $table = 'github_pull_requests';

    protected $guarded = [];

    protected $casts = [
        'reviewers' => 'array',
        'checks_passed' => 'boolean',
        'merged_at' => 'datetime',
    ];

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'repo_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function qaAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'qa_agent_run_id');
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function isMerged(): bool
    {
        return $this->state === 'merged' || $this->merged_at !== null;
    }

    public function targetsMain(): bool
    {
        return in_array($this->base_branch, ['main', 'master']);
    }

    public function targetsDevelop(): bool
    {
        return in_array($this->base_branch, ['develop', 'dev', 'development']);
    }

    public function needsApproval(): bool
    {
        return $this->targetsMain() && $this->approval_status === 'pending';
    }

    public function getUrlAttribute(): string
    {
        return "https://github.com/{$this->repo->full_name}/pull/{$this->pr_number}";
    }
}
