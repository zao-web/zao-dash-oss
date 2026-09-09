<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentConfig extends Model
{
    protected $guarded = [];

    protected $casts = [
        'secrets' => 'array',
        'onboarding_completed' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GitHubRepo::class, 'repo_id');
    }

    public function onboardingAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'onboarding_agent_run_id');
    }

    public function isSftp(): bool
    {
        return $this->deployment_type === 'sftp';
    }

    public function isVercel(): bool
    {
        return $this->deployment_type === 'vercel';
    }

    public function isNetlify(): bool
    {
        return $this->deployment_type === 'netlify';
    }

    public function needsOnboarding(): bool
    {
        return ! $this->onboarding_completed;
    }

    public function getWorkflowTemplatePath(): string
    {
        return match ($this->deployment_type) {
            'sftp' => 'sftp-deploy.yml',
            'vercel' => 'vercel-deploy.yml',
            'netlify' => 'netlify-deploy.yml',
            'wordpress' => 'wordpress-deploy.yml',
            default => 'generic-deploy.yml',
        };
    }
}
