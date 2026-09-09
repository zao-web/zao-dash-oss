<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SiteBuilderProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'project_name',
        'brief',
        'company_type',
        'status',
        'environment',
        'target_hosting',
        'user_id',
        'wordpress_site_id',
        'agent_runs',
        'progress_data',
        'started_at',
        'completed_at',
        'estimated_completion',
        'staging_url',
        'production_url',
        'client_credentials',
        'research_data',
        'last_error',
        'retry_count',
        'budget_allocated',
        'cost_incurred',
    ];

    protected $casts = [
        'agent_runs' => 'array',
        'progress_data' => 'array',
        'client_credentials' => 'array',
        'research_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_completion' => 'datetime',
        'budget_allocated' => 'decimal:2',
        'cost_incurred' => 'decimal:2',
    ];

    // Status constants
    const STATUS_CREATED = 'created';

    const STATUS_RESEARCH = 'research';

    const STATUS_WORDPRESS_SETUP = 'wordpress_setup';

    const STATUS_CONTENT_GENERATION = 'content_generation';

    const STATUS_CONTENT_SYNC = 'content_sync';

    const STATUS_QA = 'qa';

    const STATUS_COMPLETE = 'complete';

    const STATUS_FAILED = 'failed';

    // Company type constants
    const COMPANY_ACTIVE = 'active';

    const COMPANY_DEFUNCT = 'defunct';

    const COMPANY_STARTUP = 'startup';

    const COMPANY_ENTERPRISE = 'enterprise';

    // Environment constants
    const ENV_STAGING = 'staging';

    const ENV_PRODUCTION = 'production';

    // Hosting constants
    const HOSTING_WORDPRESS_COM = 'wordpress_com';

    const HOSTING_SELF_HOSTED = 'self_hosted';

    const HOSTING_EXISTING_SITE = 'existing_site';

    /**
     * Get the user that owns this project.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the associated WordPress site.
     */
    public function wordpressSite(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class);
    }

    /**
     * Get agent runs associated with this project.
     */
    public function agentRuns(): BelongsToMany
    {
        return $this->belongsToMany(AgentRun::class, 'site_builder_project_agent_runs')
            ->withPivot('phase', 'order')
            ->orderBy('pivot_order');
    }

    /**
     * Check if project is in a specific status.
     */
    public function isStatus(string $status): bool
    {
        return $this->status === $status;
    }

    /**
     * Check if project is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * Check if project has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if project is in progress.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_RESEARCH,
            self::STATUS_WORDPRESS_SETUP,
            self::STATUS_CONTENT_GENERATION,
            self::STATUS_CONTENT_SYNC,
            self::STATUS_QA,
        ]);
    }

    /**
     * Update project status.
     */
    public function updateStatus(string $status, array $progressData = []): bool
    {
        $updateData = ['status' => $status];

        if ($status === self::STATUS_RESEARCH && ! $this->started_at) {
            $updateData['started_at'] = now();
        }

        if (in_array($status, [self::STATUS_COMPLETE, self::STATUS_FAILED])) {
            $updateData['completed_at'] = now();
        }

        if (! empty($progressData)) {
            $updateData['progress_data'] = array_merge($this->progress_data ?? [], $progressData);
        }

        return $this->update($updateData);
    }

    /**
     * Get progress percentage (0-100).
     */
    public function getProgressPercentage(): int
    {
        $statusProgress = [
            self::STATUS_CREATED => 0,
            self::STATUS_RESEARCH => 20,
            self::STATUS_WORDPRESS_SETUP => 40,
            self::STATUS_CONTENT_GENERATION => 60,
            self::STATUS_CONTENT_SYNC => 80,
            self::STATUS_QA => 90,
            self::STATUS_COMPLETE => 100,
            self::STATUS_FAILED => 0,
        ];

        return $statusProgress[$this->status] ?? 0;
    }

    /**
     * Get client credentials safely.
     */
    public function getClientCredentials(): ?array
    {
        return $this->client_credentials;
    }

    /**
     * Set client credentials securely.
     */
    public function setClientCredentials(array $credentials): void
    {
        // In production, you might want to encrypt these
        $this->update(['client_credentials' => $credentials]);
    }

    /**
     * Calculate estimated completion time based on project type.
     */
    public function calculateEstimatedCompletion(): \Carbon\Carbon
    {
        $baseMinutes = match ($this->company_type) {
            self::COMPANY_DEFUNCT => 45, // More research needed
            self::COMPANY_STARTUP => 30, // Less content to migrate
            self::COMPANY_ENTERPRISE => 60, // More complex requirements
            default => 40,
        };

        return now()->addMinutes($baseMinutes);
    }

    /**
     * Get the live URL for the project.
     */
    public function getLiveUrl(): ?string
    {
        return $this->environment === self::ENV_PRODUCTION
            ? $this->production_url
            : $this->staging_url;
    }
}
