<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Services\Agents\AgentExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches the Dev Agent to implement a self-development feature request.
 *
 * This job bridges the "Request Feature" tool with agent execution,
 * providing rich context from the task to help the Dev Agent understand
 * what needs to be built.
 */
class ImplementFeatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Agent runs shouldn't retry automatically

    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public Task $task,
        public Agent $agent,
        public array $fileScope = [],
        public ?string $githubRepo = null,
    ) {}

    public function handle(AgentExecutor $executor): void
    {
        Log::info('ImplementFeatureJob starting', [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'agent' => $this->agent->name,
        ]);

        // Update task to in_progress
        $this->task->update(['status' => 'in_progress']);

        try {
            // Build the prompt for the Dev Agent
            $prompt = $this->buildPrompt();

            // Build config with proper prompt/context structure
            $config = [
                'prompt' => $prompt,
                'context' => [
                    'task_id' => $this->task->id,
                    'task' => $this->task->toArray(),
                    'repository' => $this->githubRepo,
                    'file_scope' => $this->fileScope,
                    'test_requirements' => 'Create Pest tests for new functionality',
                ],
            ];

            // Execute the Dev Agent
            $run = $executor->execute(
                agent: $this->agent,
                config: $config,
                invocationSource: AgentRun::SOURCE_SCHEDULE, // Automated invocation
                invokedBy: 'job:implement-feature',
                triggerMetadata: [
                    'task_id' => $this->task->id,
                    'job_type' => 'self_development',
                ],
            );

            // Update task with agent run reference
            $this->task->update([
                'metadata' => array_merge($this->task->metadata ?? [], [
                    'agent_run_id' => $run->id,
                    'agent_status' => $run->status,
                ]),
            ]);

            Log::info('ImplementFeatureJob dispatched agent', [
                'task_id' => $this->task->id,
                'run_id' => $run->id,
                'run_status' => $run->status,
            ]);

        } catch (\Exception $e) {
            Log::error('ImplementFeatureJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            // Update task with failure info
            $this->task->update([
                'status' => 'blocked',
                'metadata' => array_merge($this->task->metadata ?? [], [
                    'implementation_error' => $e->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            throw $e;
        }
    }

    /**
     * Build the implementation prompt for the Dev Agent.
     */
    protected function buildPrompt(): string
    {
        $title = $this->task->title;
        $description = $this->task->description ?? 'No detailed description provided.';
        $metadata = $this->task->metadata ?? [];
        $type = $metadata['type'] ?? 'feature';

        $prompt = <<<PROMPT
# Self-Development Task: {$title}

## Type
{$type}

## Description
{$description}

PROMPT;

        if (! empty($this->fileScope)) {
            $files = implode("\n- ", $this->fileScope);
            $prompt .= <<<SCOPE

## Affected Files/Directories
The following files or directories are likely affected:
- {$files}

SCOPE;
        }

        $prompt .= <<<GUIDELINES

## Implementation Guidelines

1. **Follow existing patterns**: Study similar implementations in the codebase before building
2. **Write tests**: Create Pest tests for new functionality
3. **Update documentation**: Add to /docs if necessary
4. **Keep it simple**: Don't over-engineer - implement exactly what's requested
5. **Commit message**: Use conventional commits format

## Context

This is a self-development request for Zao Dashboard. The codebase uses:
- Laravel 12 (PHP 8.4)
- Vue 3 + Inertia.js
- Pest for testing
- TailwindCSS for styling

Task ID: {$this->task->id}
GUIDELINES;

        return $prompt;
    }

    /**
     * Get the tags for this job.
     */
    public function tags(): array
    {
        return [
            'self-development',
            'task:'.$this->task->id,
            'agent:'.$this->agent->slug,
        ];
    }
}
