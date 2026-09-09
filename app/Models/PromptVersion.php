<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prompt Version - tracks changes to prompts for rollback and A/B testing.
 */
class PromptVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'ab_test_weight' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'prompt_version_id');
    }

    /**
     * Render this version with variables.
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
     * Get performance metrics for this version.
     */
    public function getMetrics(): array
    {
        $runs = $this->runs();

        return [
            'total_runs' => $runs->count(),
            'successful_runs' => $runs->clone()->where('status', 'completed')->count(),
            'success_rate' => $runs->count() > 0
                ? round(($runs->clone()->where('status', 'completed')->count() / $runs->count()) * 100, 1)
                : 0,
            'avg_cost' => $runs->avg('cost_usd') ?? 0,
            'avg_tokens' => $runs->avg(\DB::raw('input_tokens + output_tokens')) ?? 0,
        ];
    }

    /**
     * Activate this version (deactivates others).
     */
    public function activate(): void
    {
        $this->template->versions()->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }

    /**
     * Compare this version's performance with another.
     */
    public function compareWith(PromptVersion $other): array
    {
        $thisMetrics = $this->getMetrics();
        $otherMetrics = $other->getMetrics();

        return [
            'this' => [
                'version' => $this->version_number,
                'metrics' => $thisMetrics,
            ],
            'other' => [
                'version' => $other->version_number,
                'metrics' => $otherMetrics,
            ],
            'comparison' => [
                'success_rate_diff' => $thisMetrics['success_rate'] - $otherMetrics['success_rate'],
                'cost_diff' => $thisMetrics['avg_cost'] - $otherMetrics['avg_cost'],
                'winner' => $thisMetrics['success_rate'] > $otherMetrics['success_rate'] ? 'this' : 'other',
            ],
        ];
    }
}
