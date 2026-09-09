<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agent Chain - Defines a workflow of multiple agents.
 *
 * Example: Case Study Chain
 * 1. case-study-writer → writes draft
 * 2. landing-page-generator → creates landing page (if output contains "success story")
 * 3. content-scheduler → schedules publication
 */
class AgentChain extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'steps' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Chain step structure:
     * [
     *   ['agent_slug' => 'case-study-writer', 'condition' => null, 'transform' => null],
     *   ['agent_slug' => 'landing-page-generator', 'condition' => 'output_contains:success', 'transform' => 'extract_key_points'],
     *   ['agent_slug' => 'content-scheduler', 'condition' => 'previous_success', 'transform' => null],
     * ]
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentChainRun::class);
    }

    public function getStepAgent(int $stepIndex): ?Agent
    {
        $steps = $this->steps ?? [];
        if (! isset($steps[$stepIndex])) {
            return null;
        }

        return Agent::where('slug', $steps[$stepIndex]['agent_slug'])->first();
    }

    public function getStepCount(): int
    {
        return count($this->steps ?? []);
    }

    public function shouldExecuteStep(int $stepIndex, ?string $previousOutput, bool $previousSuccess): bool
    {
        $steps = $this->steps ?? [];
        if (! isset($steps[$stepIndex])) {
            return false;
        }

        $condition = $steps[$stepIndex]['condition'] ?? null;
        if (! $condition) {
            return true; // No condition = always execute
        }

        return $this->evaluateCondition($condition, $previousOutput, $previousSuccess);
    }

    protected function evaluateCondition(string $condition, ?string $output, bool $success): bool
    {
        // Parse condition
        if ($condition === 'previous_success') {
            return $success;
        }

        if ($condition === 'previous_failure') {
            return ! $success;
        }

        if (str_starts_with($condition, 'output_contains:')) {
            $needle = substr($condition, 16);

            return $output && str_contains(strtolower($output), strtolower($needle));
        }

        if (str_starts_with($condition, 'output_not_contains:')) {
            $needle = substr($condition, 20);

            return $output && ! str_contains(strtolower($output), strtolower($needle));
        }

        if (str_starts_with($condition, 'output_length_gt:')) {
            $minLength = (int) substr($condition, 17);

            return strlen($output ?? '') > $minLength;
        }

        return true; // Unknown condition = execute
    }

    public function transformOutput(int $stepIndex, string $output): string
    {
        $steps = $this->steps ?? [];
        if (! isset($steps[$stepIndex])) {
            return $output;
        }

        $transform = $steps[$stepIndex]['transform'] ?? null;
        if (! $transform) {
            return $output;
        }

        return match ($transform) {
            'extract_summary' => $this->extractSummary($output),
            'extract_key_points' => $this->extractKeyPoints($output),
            'first_paragraph' => $this->extractFirstParagraph($output),
            'last_paragraph' => $this->extractLastParagraph($output),
            default => $output,
        };
    }

    protected function extractSummary(string $output): string
    {
        // Take first 500 chars
        return substr($output, 0, 500);
    }

    protected function extractKeyPoints(string $output): string
    {
        // Extract bullet points or numbered lists
        preg_match_all('/^[\-\*\d+\.]\s*(.+)$/m', $output, $matches);
        if (! empty($matches[1])) {
            return implode("\n", $matches[1]);
        }

        return $this->extractSummary($output);
    }

    protected function extractFirstParagraph(string $output): string
    {
        $paragraphs = preg_split('/\n\n+/', trim($output));

        return $paragraphs[0] ?? $output;
    }

    protected function extractLastParagraph(string $output): string
    {
        $paragraphs = preg_split('/\n\n+/', trim($output));

        return end($paragraphs) ?: $output;
    }

    /**
     * Predefined chain templates.
     */
    public static function templates(): array
    {
        return [
            'content_pipeline' => [
                'name' => 'Content Pipeline',
                'description' => 'Case study → Landing page → Schedule',
                'steps' => [
                    ['agent_slug' => 'case-study-writer', 'condition' => null, 'transform' => null],
                    ['agent_slug' => 'landing-page-generator', 'condition' => 'output_length_gt:500', 'transform' => 'extract_key_points'],
                    ['agent_slug' => 'content-scheduler', 'condition' => 'previous_success', 'transform' => null],
                ],
            ],
            'lead_nurture_pipeline' => [
                'name' => 'Lead Nurture Pipeline',
                'description' => 'Lead analysis → Upsell proposal → Follow-up',
                'steps' => [
                    ['agent_slug' => 'lead-nurture', 'condition' => null, 'transform' => null],
                    ['agent_slug' => 'upsell-proposal', 'condition' => 'output_contains:opportunity', 'transform' => null],
                ],
            ],
            'client_health_response' => [
                'name' => 'Client Health Response',
                'description' => 'Health check → Case study (if healthy) OR Outreach (if at-risk)',
                'steps' => [
                    ['agent_slug' => 'client-health-monitor', 'condition' => null, 'transform' => null],
                    ['agent_slug' => 'case-study-writer', 'condition' => 'output_contains:healthy', 'transform' => null],
                    ['agent_slug' => 'lead-nurture', 'condition' => 'output_contains:at-risk', 'transform' => null],
                ],
            ],
        ];
    }

    /**
     * Create a chain from a template.
     */
    public static function createFromTemplate(string $templateKey): ?self
    {
        $templates = self::templates();
        if (! isset($templates[$templateKey])) {
            return null;
        }

        $template = $templates[$templateKey];

        return self::create([
            'name' => $template['name'],
            'slug' => $templateKey,
            'description' => $template['description'],
            'steps' => $template['steps'],
            'is_active' => true,
        ]);
    }
}
