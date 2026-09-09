<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WebsiteProject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'user_id',
        'project_type',
        'source_type',
        'source_data',
        'domain',
        'hosting_type',
        'environment',
        'wordpress_site_id',
        'status',
        'phase_progress',
        'overall_progress',
        'design_config',
        'brand_assets',
        'pages',
        'patterns_selected',
        'custom_blocks',
        'template_parts',
        'site_analysis',
        'repo_analysis',
        'extracted_content',
        'agent_runs',
        'tool_executions',
        'staging_url',
        'production_url',
        'download_url',
        'client_credentials',
        'last_error',
        'retry_count',
        'budget_allocated',
        'cost_incurred',
        'started_at',
        'completed_at',
        'estimated_completion',
    ];

    protected $casts = [
        'source_data' => 'array',
        'phase_progress' => 'array',
        'overall_progress' => 'integer',
        'design_config' => 'array',
        'brand_assets' => 'array',
        'pages' => 'array',
        'patterns_selected' => 'array',
        'custom_blocks' => 'array',
        'template_parts' => 'array',
        'site_analysis' => 'array',
        'repo_analysis' => 'array',
        'extracted_content' => 'array',
        'agent_runs' => 'array',
        'tool_executions' => 'array',
        'client_credentials' => 'array',
        'retry_count' => 'integer',
        'budget_allocated' => 'decimal:2',
        'cost_incurred' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_completion' => 'datetime',
    ];

    const TYPE_AUTONOMOUS = 'autonomous';

    const TYPE_GUIDED = 'guided';

    const TYPE_MIGRATION = 'migration';

    const TYPE_REDESIGN = 'redesign';

    const STATUS_CREATED = 'created';

    const STATUS_ANALYZING = 'analyzing';

    const STATUS_DESIGNING = 'designing';

    const STATUS_BUILDING = 'building';

    const STATUS_REVIEWING = 'reviewing';

    const STATUS_DEPLOYING = 'deploying';

    const STATUS_COMPLETE = 'complete';

    const STATUS_FAILED = 'failed';

    const SOURCE_DOMAIN = 'domain';

    const SOURCE_BRIEF = 'brief';

    const SOURCE_URL = 'url';

    const SOURCE_GITHUB = 'github';

    const SOURCE_MANUAL = 'manual';

    const HOSTING_WORDPRESS_COM = 'wordpress_com';

    const HOSTING_SELF_HOSTED = 'self_hosted';

    const HOSTING_EXISTING_SITE = 'existing_site';

    const ENV_STAGING = 'staging';

    const ENV_PRODUCTION = 'production';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            if (empty($project->slug)) {
                $baseSlug = Str::slug($project->name);
                $slug = $baseSlug;
                $counter = 1;

                while (static::where('user_id', $project->user_id)
                    ->where('slug', $slug)
                    ->exists()) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }

                $project->slug = $slug;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wordpressSite(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class);
    }

    public function agentRuns(): BelongsToMany
    {
        return $this->belongsToMany(AgentRun::class, 'website_project_agent_runs')
            ->withPivot('phase', 'order')
            ->orderBy('pivot_order');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WebsiteProjectAsset::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WebsiteProjectMessage::class)->orderBy('created_at', 'asc');
    }

    public function isType(string $type): bool
    {
        return $this->project_type === $type;
    }

    public function isAutonomous(): bool
    {
        return $this->project_type === self::TYPE_AUTONOMOUS;
    }

    public function isGuided(): bool
    {
        return $this->project_type === self::TYPE_GUIDED;
    }

    public function isMigration(): bool
    {
        return $this->project_type === self::TYPE_MIGRATION;
    }

    public function isRedesign(): bool
    {
        return $this->project_type === self::TYPE_REDESIGN;
    }

    public function isStatus(string $status): bool
    {
        return $this->status === $status;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_ANALYZING,
            self::STATUS_DESIGNING,
            self::STATUS_BUILDING,
            self::STATUS_REVIEWING,
            self::STATUS_DEPLOYING,
        ]);
    }

    public function canDeploy(): bool
    {
        return $this->status === self::STATUS_REVIEWING &&
               $this->overall_progress >= 95 &&
               ! empty($this->staging_url);
    }

    public function updateStatus(string $status, array $phaseProgress = []): bool
    {
        $updateData = ['status' => $status];

        if ($status === self::STATUS_ANALYZING && ! $this->started_at) {
            $updateData['started_at'] = now();
        }

        if (in_array($status, [self::STATUS_COMPLETE, self::STATUS_FAILED])) {
            $updateData['completed_at'] = now();
        }

        if (! empty($phaseProgress)) {
            $updateData['phase_progress'] = array_merge($this->phase_progress ?? [], $phaseProgress);
        }

        return $this->update($updateData);
    }

    public function getProgressPercentage(): int
    {
        if ($this->overall_progress > 0) {
            return $this->overall_progress;
        }

        $statusProgress = [
            self::STATUS_CREATED => 0,
            self::STATUS_ANALYZING => 15,
            self::STATUS_DESIGNING => 35,
            self::STATUS_BUILDING => 60,
            self::STATUS_REVIEWING => 85,
            self::STATUS_DEPLOYING => 95,
            self::STATUS_COMPLETE => 100,
            self::STATUS_FAILED => 0,
        ];

        return $statusProgress[$this->status] ?? 0;
    }

    public function getLiveUrl(): ?string
    {
        return $this->environment === self::ENV_PRODUCTION
            ? $this->production_url
            : $this->staging_url;
    }

    public function incrementCost(float $amount): void
    {
        $this->increment('cost_incurred', $amount);
    }

    public function isOverBudget(): bool
    {
        if (! $this->budget_allocated) {
            return false;
        }

        return $this->cost_incurred > $this->budget_allocated;
    }

    public function getRemainingBudget(): ?float
    {
        if (! $this->budget_allocated) {
            return null;
        }

        return max(0, $this->budget_allocated - $this->cost_incurred);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('project_type', $type);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_ANALYZING,
            self::STATUS_DESIGNING,
            self::STATUS_BUILDING,
            self::STATUS_REVIEWING,
            self::STATUS_DEPLOYING,
        ]);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeComplete($query)
    {
        return $query->where('status', self::STATUS_COMPLETE);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
