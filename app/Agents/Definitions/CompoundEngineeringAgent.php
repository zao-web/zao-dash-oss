<?php

namespace App\Agents\Definitions;

use App\Services\Agents\CompoundEngineeringSkillLoader;
use Illuminate\Support\Facades\Log;

/**
 * Agent definition for Compound Engineering workflows.
 *
 * This agent enables server-side execution of Compound Engineering skills
 * like /workflows:plan, /workflows:work, /workflows:review, and /lfg.
 *
 * It supports interactive execution mode with checkpoint-resume pattern
 * for handling AskUserQuestion tool calls via WebSocket and Slack.
 */
class CompoundEngineeringAgent extends BaseAgentDefinition
{
    /**
     * Use Opus for complex work by default.
     */
    protected string $defaultModel = 'opus';

    /**
     * Higher budget for long-running workflows.
     */
    protected float $defaultMaxBudget = 25.00;

    /**
     * Interactive workflows don't require pre-approval.
     */
    protected bool $defaultRequiresApproval = false;

    /**
     * The currently loaded skill (set at runtime).
     */
    protected ?array $loadedSkill = null;

    public function __construct(
        protected CompoundEngineeringSkillLoader $skillLoader,
    ) {}

    /**
     * Get the agent's unique identifier.
     */
    protected function getId(): string
    {
        return 'compound-engineering';
    }

    /**
     * Get the agent's display name.
     */
    protected function getName(): string
    {
        return 'Compound Engineering';
    }

    /**
     * Get the agent's description.
     */
    protected function getDescription(): string
    {
        return 'Execute Compound Engineering workflows like /workflows:plan, /workflows:work, /workflows:review, and /lfg. Supports interactive question/answer via dashboard and Slack.';
    }

    /**
     * Get the model for this agent.
     *
     * Model is determined by the skill being executed.
     *
     * @return 'opus'|'sonnet'|'haiku'
     */
    protected function getModel(): string
    {
        if ($this->loadedSkill) {
            return $this->loadedSkill['model'] ?? $this->defaultModel;
        }

        return $this->defaultModel;
    }

    /**
     * Get allowed tools for this agent.
     *
     * Compound Engineering agents need extensive tool access.
     *
     * @return array<string>
     */
    public function allowedTools(): array
    {
        if ($this->loadedSkill && isset($this->loadedSkill['allowed_tools'])) {
            return $this->loadedSkill['allowed_tools'];
        }

        // Full tool access for Compound Engineering
        return [
            'Read',
            'Write',
            'Edit',
            'MultiEdit',
            'Glob',
            'Grep',
            'Bash',
            'Task',
            'TodoWrite',
            'AskUserQuestion',
            'WebSearch',
            'WebFetch',
            'NotebookEdit',
            'EnterPlanMode',
            'ExitPlanMode',
        ];
    }

    /**
     * Get the system prompt for this agent.
     *
     * The actual prompt comes from the loaded skill.
     */
    public function systemPrompt(): string
    {
        if ($this->loadedSkill && isset($this->loadedSkill['system_prompt'])) {
            return $this->loadedSkill['system_prompt'];
        }

        return $this->getFallbackPrompt();
    }

    /**
     * Get the preferred execution mode.
     *
     * Always use CLI for Compound Engineering to leverage Claude Max
     * subscription and access Compound Engineering plugin features.
     *
     * @return 'cli'|'sdk'|null
     */
    public function executionMode(): ?string
    {
        return 'cli';
    }

    /**
     * Get required secrets for this agent.
     *
     * @return array<string>
     */
    public function requiredSecrets(): array
    {
        return [
            'GITHUB_TOKEN', // For repository access
        ];
    }

    /**
     * Get the configuration schema.
     *
     * @return array<string, string|array>
     */
    public function configSchema(): array
    {
        return [
            'skill' => 'required|string',
            'args' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
        ];
    }

    /**
     * Validate the execution context.
     *
     * @param  array<string, mixed>  $context
     */
    public function validateContext(array $context): bool
    {
        // Check for skill specification
        if (empty($context['skill']) && empty($context['prompt'])) {
            return false;
        }

        // If skill is specified, validate it exists
        if (! empty($context['skill'])) {
            return in_array($context['skill'], $this->skillLoader->availableSkills(), true);
        }

        // If prompt is specified, parse for skill invocation
        if (! empty($context['prompt'])) {
            return $this->skillLoader->isSkillInvocation($context['prompt']);
        }

        return parent::validateContext($context);
    }

    /**
     * Process output after execution.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function processOutput(array $output): array
    {
        // Extract key metrics from workflow output
        $processed = parent::processOutput($output);

        // Parse for completed tasks, files modified, etc.
        if (isset($output['raw_output'])) {
            $processed['metrics'] = $this->extractMetrics($output['raw_output']);
        }

        return $processed;
    }

    /**
     * Load a specific skill for execution.
     *
     * This should be called before execution to configure the agent
     * with the appropriate skill's system prompt and settings.
     *
     * @param  array<string, mixed>  $context  Project and additional context
     */
    public function loadSkillForExecution(string $skillName, ?string $args = null, array $context = []): bool
    {
        $this->loadedSkill = $this->skillLoader->loadSkill($skillName);

        if (! $this->loadedSkill) {
            Log::warning('CompoundEngineeringAgent: Failed to load skill', [
                'skill' => $skillName,
            ]);

            return false;
        }

        // Build complete system prompt with context
        $this->loadedSkill['system_prompt'] = $this->skillLoader->buildSystemPrompt(
            $skillName,
            $args,
            $context
        );

        return true;
    }

    /**
     * Parse and load skill from a prompt string.
     *
     * @param  array<string, mixed>  $context
     */
    public function loadSkillFromPrompt(string $prompt, array $context = []): bool
    {
        $parsed = $this->skillLoader->parseInvocation($prompt);

        if (! $parsed['matched']) {
            Log::warning('CompoundEngineeringAgent: No skill in prompt', [
                'prompt' => substr($prompt, 0, 100),
            ]);

            return false;
        }

        return $this->loadSkillForExecution($parsed['skill'], $parsed['args'], $context);
    }

    /**
     * Get the currently loaded skill name.
     */
    public function getLoadedSkillName(): ?string
    {
        return $this->loadedSkill['name'] ?? null;
    }

    /**
     * Get the timeout in minutes for the loaded skill.
     */
    public function getTimeoutMinutes(): int
    {
        if ($this->loadedSkill && isset($this->loadedSkill['timeout_minutes'])) {
            return $this->loadedSkill['timeout_minutes'];
        }

        return 60; // Default 1 hour
    }

    /**
     * Build the user prompt for execution.
     */
    public function buildUserPrompt(): string
    {
        if (! $this->loadedSkill) {
            return 'Execute the Compound Engineering workflow.';
        }

        $skillName = $this->loadedSkill['name'] ?? 'unknown';
        $args = $this->loadedSkill['config']['args'] ?? null;

        return $this->skillLoader->buildUserPrompt($skillName, $args);
    }

    /**
     * Get a fallback prompt when skill is not loaded.
     */
    protected function getFallbackPrompt(): string
    {
        return <<<'PROMPT'
You are the Compound Engineering agent.

Your role is to execute software engineering workflows including:
- /workflows:plan - Plan feature implementations
- /workflows:work - Execute implementation from plans
- /workflows:review - Perform code reviews
- /lfg - Full autonomous engineering loop

When executing a workflow:
1. Read the plan or requirements carefully
2. Use the TodoWrite tool to track progress
3. Follow existing code patterns in the project
4. Write tests for new functionality
5. Use AskUserQuestion when you need clarification

Execute the requested workflow with precision and thoroughness.
PROMPT;
    }

    /**
     * Extract metrics from workflow output.
     *
     * @return array<string, mixed>
     */
    protected function extractMetrics(string $output): array
    {
        $metrics = [
            'files_modified' => 0,
            'tests_written' => 0,
            'tasks_completed' => 0,
        ];

        // Count file operations
        preg_match_all('/(?:Wrote|Modified|Created|Edited).*?(?:\.php|\.vue|\.ts|\.js|\.md)/i', $output, $matches);
        $metrics['files_modified'] = count(array_unique($matches[0] ?? []));

        // Count test mentions
        preg_match_all('/(?:test|it\s+[\'"]|expect\s*\()/i', $output, $testMatches);
        $metrics['tests_written'] = count($testMatches[0] ?? []);

        // Count completed todos
        preg_match_all('/\[completed\]|\[x\]|✓|✅/i', $output, $todoMatches);
        $metrics['tasks_completed'] = count($todoMatches[0] ?? []);

        return $metrics;
    }
}
