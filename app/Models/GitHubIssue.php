<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubIssue extends Model
{
    use HasFactory;

    protected $table = 'github_issues';

    protected $guarded = [];

    protected $casts = [
        'labels' => 'array',
        'assignees' => 'array',
        'closed_at' => 'datetime',
    ];

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'repo_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function hasLabel(string $label): bool
    {
        $labels = $this->labels ?? [];

        return in_array(strtolower($label), array_map('strtolower', $labels));
    }

    public function isAgentTask(): bool
    {
        return $this->hasLabel('agent') || $this->hasLabel('agent-task');
    }

    public function getUrlAttribute(): string
    {
        return "https://github.com/{$this->repo->full_name}/issues/{$this->issue_number}";
    }
}
