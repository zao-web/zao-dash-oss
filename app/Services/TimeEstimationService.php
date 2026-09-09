<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * TimeEstimationService calculates human-equivalent hours for agent work.
 *
 * The core insight: An AI agent might complete a task in 20 minutes, but the
 * equivalent work would take a senior engineer 4-6 hours. We need to bill
 * for the value delivered, not the agent's execution time.
 *
 * Estimation factors:
 * - Task complexity (from priority, description analysis)
 * - Agent execution metrics (tokens used, tools invoked)
 * - Historical data (if available)
 * - Task type/category
 * - Project context
 */
class TimeEstimationService
{
    /**
     * Base multipliers by task priority.
     * Higher priority often correlates with complexity.
     */
    protected array $priorityMultipliers = [
        'low' => 1.0,
        'medium' => 1.5,
        'high' => 2.5,
        'urgent' => 3.0,
    ];

    /**
     * Base hours by task type indicators (detected from description).
     */
    protected array $taskTypeBaseHours = [
        'bug_fix' => 2.0,           // Bug fixes: typically 1-3 hours
        'feature' => 4.0,           // New features: typically 3-6 hours
        'refactor' => 3.0,          // Refactoring: typically 2-4 hours
        'integration' => 5.0,       // API/integration work: typically 4-8 hours
        'documentation' => 1.5,     // Docs: typically 1-2 hours
        'testing' => 2.0,           // Test writing: typically 1-3 hours
        'deployment' => 1.0,        // Deployment tasks: typically 0.5-1.5 hours
        'research' => 2.0,          // Research/investigation: typically 1-3 hours
        'default' => 2.5,           // Default for unclassified tasks
    ];

    /**
     * Multipliers based on agent execution complexity.
     */
    protected array $executionComplexityFactors = [
        'tokens_per_1k' => 0.1,     // Each 1k tokens suggests ~6 min human thinking
        'tools_invoked' => 0.25,    // Each tool call suggests ~15 min human action
        'files_modified' => 0.5,    // Each file modified suggests ~30 min human work
        'lines_changed' => 0.01,    // Each line changed suggests ~36 sec human time
    ];

    /**
     * Calculate human-equivalent hours for a completed agent run.
     */
    public function estimate(AgentRun $run, ?Task $task = null): float
    {
        // Start with base hours from task type
        $taskType = $this->detectTaskType($task, $run);
        $baseHours = $this->taskTypeBaseHours[$taskType] ?? $this->taskTypeBaseHours['default'];

        // Apply priority multiplier
        $priority = $task?->priority ?? 'medium';
        $priorityMultiplier = $this->priorityMultipliers[$priority] ?? 1.5;

        // Calculate execution complexity factor
        $complexityFactor = $this->calculateComplexityFactor($run);

        // Calculate estimated hours
        $estimatedHours = $baseHours * $priorityMultiplier * $complexityFactor;

        // Apply bounds (minimum 0.5h, maximum 16h for a single task)
        $estimatedHours = max(0.5, min(16.0, $estimatedHours));

        // Round to nearest 0.25 for cleaner billing
        $estimatedHours = round($estimatedHours * 4) / 4;

        Log::info('Time estimation calculated', [
            'agent_run_id' => $run->id,
            'task_id' => $task?->id,
            'task_type' => $taskType,
            'base_hours' => $baseHours,
            'priority' => $priority,
            'priority_multiplier' => $priorityMultiplier,
            'complexity_factor' => $complexityFactor,
            'estimated_hours' => $estimatedHours,
        ]);

        return $estimatedHours;
    }

    /**
     * Estimate hours from task alone (before execution).
     */
    public function estimateFromTask(Task $task): float
    {
        $taskType = $this->detectTaskTypeFromDescription($task->title.' '.($task->description ?? ''));
        $baseHours = $this->taskTypeBaseHours[$taskType] ?? $this->taskTypeBaseHours['default'];

        $priority = $task->priority ?? 'medium';
        $priorityMultiplier = $this->priorityMultipliers[$priority] ?? 1.5;

        $estimatedHours = $baseHours * $priorityMultiplier;
        $estimatedHours = max(0.5, min(16.0, $estimatedHours));

        return round($estimatedHours * 4) / 4;
    }

    /**
     * Detect task type from task and agent run context.
     */
    protected function detectTaskType(?Task $task, AgentRun $run): string
    {
        // First try task description
        if ($task) {
            $text = strtolower($task->title.' '.($task->description ?? ''));
            $detected = $this->detectTaskTypeFromDescription($text);
            if ($detected !== 'default') {
                return $detected;
            }
        }

        // Fall back to agent run task description
        $runTask = strtolower($run->task ?? '');

        return $this->detectTaskTypeFromDescription($runTask);
    }

    /**
     * Detect task type from description text.
     */
    protected function detectTaskTypeFromDescription(string $text): string
    {
        $text = strtolower($text);

        // Bug fix indicators
        if (preg_match('/\b(fix|bug|issue|error|broken|crash|exception|fail)\b/', $text)) {
            return 'bug_fix';
        }

        // Feature indicators
        if (preg_match('/\b(add|create|implement|build|new|feature|develop)\b/', $text)) {
            return 'feature';
        }

        // Refactor indicators
        if (preg_match('/\b(refactor|clean|improve|optimize|restructure|reorganize)\b/', $text)) {
            return 'refactor';
        }

        // Integration indicators
        if (preg_match('/\b(api|integration|connect|sync|webhook|endpoint|external)\b/', $text)) {
            return 'integration';
        }

        // Documentation indicators
        if (preg_match('/\b(doc|readme|comment|guide|tutorial|explain)\b/', $text)) {
            return 'documentation';
        }

        // Testing indicators
        if (preg_match('/\b(test|spec|coverage|assert|mock|stub)\b/', $text)) {
            return 'testing';
        }

        // Deployment indicators
        if (preg_match('/\b(deploy|release|publish|launch|ship|rollout)\b/', $text)) {
            return 'deployment';
        }

        // Research indicators
        if (preg_match('/\b(research|investigate|explore|analyze|evaluate|assess)\b/', $text)) {
            return 'research';
        }

        return 'default';
    }

    /**
     * Calculate complexity factor from agent run metrics.
     */
    protected function calculateComplexityFactor(AgentRun $run): float
    {
        $factor = 1.0;

        // Token-based complexity
        $totalTokens = ($run->input_tokens ?? 0) + ($run->output_tokens ?? 0);
        $factor += ($totalTokens / 1000) * $this->executionComplexityFactors['tokens_per_1k'];

        // Analyze output for additional complexity signals
        $output = $run->output ?? [];

        // Files modified (if tracked in output)
        if (isset($output['files_modified'])) {
            $filesCount = is_array($output['files_modified'])
                ? count($output['files_modified'])
                : (int) $output['files_modified'];
            $factor += $filesCount * $this->executionComplexityFactors['files_modified'];
        }

        // Tools invoked (if tracked)
        if (isset($output['tools_used'])) {
            $toolsCount = is_array($output['tools_used'])
                ? count($output['tools_used'])
                : (int) $output['tools_used'];
            $factor += $toolsCount * $this->executionComplexityFactors['tools_invoked'];
        }

        // Lines changed (if tracked)
        if (isset($output['lines_changed'])) {
            $factor += (int) $output['lines_changed'] * $this->executionComplexityFactors['lines_changed'];
        }

        // Cap complexity factor at 3x to prevent runaway estimates
        return min(3.0, $factor);
    }

    /**
     * Get estimation breakdown for transparency/debugging.
     */
    public function getEstimationBreakdown(AgentRun $run, ?Task $task = null): array
    {
        $taskType = $this->detectTaskType($task, $run);
        $baseHours = $this->taskTypeBaseHours[$taskType] ?? $this->taskTypeBaseHours['default'];

        $priority = $task?->priority ?? 'medium';
        $priorityMultiplier = $this->priorityMultipliers[$priority] ?? 1.5;

        $complexityFactor = $this->calculateComplexityFactor($run);
        $estimatedHours = $this->estimate($run, $task);

        return [
            'task_type_detected' => $taskType,
            'base_hours' => $baseHours,
            'priority' => $priority,
            'priority_multiplier' => $priorityMultiplier,
            'complexity_factor' => round($complexityFactor, 2),
            'raw_estimate' => round($baseHours * $priorityMultiplier * $complexityFactor, 2),
            'final_estimate_hours' => $estimatedHours,
            'agent_execution_seconds' => $run->duration_ms ? $run->duration_ms / 1000 : null,
            'efficiency_ratio' => $run->duration_ms
                ? round(($estimatedHours * 3600) / ($run->duration_ms / 1000), 1)
                : null,
        ];
    }
}
