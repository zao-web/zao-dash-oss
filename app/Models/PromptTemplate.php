<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prompt Template - reusable prompts with versioning and A/B testing.
 */
class PromptTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'tags' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
    ];

    // Categories
    const CATEGORY_SYSTEM = 'system';

    const CATEGORY_TASK = 'task';

    const CATEGORY_ANALYSIS = 'analysis';

    const CATEGORY_CONTENT = 'content';

    const CATEGORY_COMMUNICATION = 'communication';

    const CATEGORY_CODE = 'code';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class)->orderByDesc('version_number');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'prompt_template_id');
    }

    /**
     * Get the current active version.
     */
    public function currentVersion(): ?PromptVersion
    {
        return $this->versions()->where('is_active', true)->first()
            ?? $this->versions()->first();
    }

    /**
     * Render prompt with variables substituted.
     */
    public function render(array $variables = []): string
    {
        $content = $this->content;

        foreach ($variables as $key => $value) {
            $content = str_replace("{{{$key}}}", $value, $content);
            $content = str_replace("{{ {$key} }}", $value, $content);
        }

        return $content;
    }

    /**
     * Extract variable placeholders from content.
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{\{?\s*(\w+)\s*\}?\}/', $this->content, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * Create a new version of this prompt.
     */
    public function createVersion(string $content, ?string $description = null): PromptVersion
    {
        $latestVersion = $this->versions()->max('version_number') ?? 0;

        // Deactivate current active version
        $this->versions()->update(['is_active' => false]);

        return $this->versions()->create([
            'version_number' => $latestVersion + 1,
            'content' => $content,
            'description' => $description,
            'variables' => $this->extractVariablesFrom($content),
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Extract variables from content string.
     */
    protected function extractVariablesFrom(string $content): array
    {
        preg_match_all('/\{\{?\s*(\w+)\s*\}?\}/', $content, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * Get performance metrics for this prompt.
     */
    public function getMetrics(): array
    {
        $runs = $this->runs();

        return [
            'total_runs' => $runs->count(),
            'successful_runs' => $runs->clone()->where('status', 'completed')->count(),
            'failed_runs' => $runs->clone()->where('status', 'failed')->count(),
            'success_rate' => $runs->count() > 0
                ? round(($runs->clone()->where('status', 'completed')->count() / $runs->count()) * 100, 1)
                : 0,
            'total_cost' => $runs->sum('cost_usd'),
            'avg_cost' => $runs->avg('cost_usd') ?? 0,
        ];
    }

    /**
     * Scope to filter by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by agent.
     */
    public function scopeForAgent($query, ?int $agentId)
    {
        if ($agentId) {
            return $query->where('agent_id', $agentId)->orWhereNull('agent_id');
        }

        return $query->whereNull('agent_id');
    }

    /**
     * Scope to get public/shared prompts.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
