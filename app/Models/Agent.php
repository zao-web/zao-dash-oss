<?php

namespace App\Models;

use App\Agents\AgentRegistry;
use App\Agents\Contracts\AgentDefinition;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\File;

class Agent extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Transient storage for audit logging (not persisted).
     */
    protected static array $auditOriginals = [];

    protected $casts = [
        'requires_approval' => 'boolean',
        'use_consortium' => 'boolean',
        'consortium_config' => 'array',
        'is_dynamic' => 'boolean',
        'max_budget_usd' => 'decimal:2',
        'allowed_tools' => 'array',
        'trigger_config' => 'array',
        'circuit_broken_at' => 'datetime',
        'definition_synced_at' => 'datetime',
        'webhook_enabled' => 'boolean',
        'webhook_allowed_ips' => 'array',
    ];

    /**
     * Boot the model and register event listeners for audit logging.
     */
    protected static function booted(): void
    {
        static::created(function (Agent $agent) {
            AgentActivityLog::logCreated($agent);
        });

        static::updating(function (Agent $agent) {
            // Store original values before update in static array (keyed by id)
            static::$auditOriginals[$agent->id] = $agent->getOriginal();
        });

        static::updated(function (Agent $agent) {
            $original = static::$auditOriginals[$agent->id] ?? [];
            unset(static::$auditOriginals[$agent->id]); // Clean up
            $changed = $agent->getChanges();

            // Skip if only timestamps changed
            $nonTimestampChanges = array_diff_key($changed, ['updated_at' => 1, 'created_at' => 1]);
            if (empty($nonTimestampChanges)) {
                return;
            }

            // Check for status change specifically
            if (isset($changed['status']) && isset($original['status'])) {
                AgentActivityLog::logStatusChanged($agent, $original['status'], $changed['status']);
            } else {
                AgentActivityLog::logUpdated($agent, $original, $changed);
            }
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AgentActivityLog::class)->orderByDesc('created_at');
    }

    /**
     * Get the PHP definition for this agent (if registered).
     */
    public function getDefinition(): ?AgentDefinition
    {
        if ($this->is_dynamic) {
            return null;
        }

        return app(AgentRegistry::class)->getDefinition($this->slug);
    }

    /**
     * Check if this is a registered (PHP-defined) agent.
     */
    public function isRegistered(): bool
    {
        return ! $this->is_dynamic;
    }

    /**
     * Check if this agent is out of sync with its definition.
     */
    public function isOutOfSync(): bool
    {
        if ($this->is_dynamic) {
            return false;
        }

        return app(AgentRegistry::class)->isOutOfSync($this);
    }

    /**
     * Get the system prompt for this agent.
     *
     * Priority:
     * 1. skill_file (if set and file exists)
     * 2. system_prompt column (DB value)
     * 3. PHP definition systemPrompt() (for registered agents)
     */
    public function getSystemPrompt(): string
    {
        // Check skill_file first (file-based prompt)
        if ($this->skill_file) {
            $path = storage_path('app/skills/'.ltrim($this->skill_file, '/'));
            if (File::exists($path)) {
                return File::get($path);
            }
        }

        // DB system_prompt column
        if ($this->system_prompt) {
            return $this->system_prompt;
        }

        // Fall back to PHP definition for registered agents
        if (! $this->is_dynamic) {
            $definition = $this->getDefinition();
            if ($definition) {
                return $definition->systemPrompt();
            }
        }

        return '';
    }

    /**
     * Check if the prompt is sourced from a skill file.
     */
    public function usesSkillFile(): bool
    {
        if (! $this->skill_file) {
            return false;
        }

        $path = storage_path('app/skills/'.ltrim($this->skill_file, '/'));

        return File::exists($path);
    }

    /**
     * Get the execution config for this agent.
     *
     * Uses the PHP definition if registered, otherwise uses DB values.
     */
    public function getExecutionConfig(): array
    {
        if ($this->is_dynamic) {
            return [
                'model' => $this->model,
                'allowed_tools' => $this->allowed_tools ?? [],
                'system_prompt' => $this->getSystemPrompt(),
                'max_budget_usd' => (float) $this->max_budget_usd,
                'requires_approval' => $this->requires_approval,
            ];
        }

        $definition = $this->getDefinition();

        if (! $definition) {
            // Fallback to DB values if definition missing
            return [
                'model' => $this->model ?? 'sonnet',
                'allowed_tools' => $this->allowed_tools ?? [],
                'system_prompt' => $this->getSystemPrompt(),
                'max_budget_usd' => (float) ($this->max_budget_usd ?? 5.00),
                'requires_approval' => $this->requires_approval ?? true,
            ];
        }

        $meta = $definition->metadata();

        return [
            'model' => $meta['model'],
            'allowed_tools' => $definition->allowedTools(),
            'system_prompt' => $definition->systemPrompt(),
            'max_budget_usd' => $meta['max_budget_usd'],
            'requires_approval' => $meta['requires_approval'],
        ];
    }
}
