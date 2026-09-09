<?php

namespace App\Agents\Definitions;

/**
 * Dev Agent
 *
 * Generates code changes from task specifications:
 * - Feature implementation
 * - Bug fixes
 * - Refactoring
 * - Tests
 *
 * Chains to QA Agent for validation before merge.
 */
class DevAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Dev Agent';
    }

    protected function getDescription(): string
    {
        return 'Generate code changes from task specifications. Chains to QA for validation.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true; // Code changes require review
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Balance of capability and cost
    }

    protected function getMaxBudget(): float
    {
        return 10.00; // Higher budget for complex tasks
    }

    public function allowedTools(): array
    {
        return ['code_exec', 'github', 'file_ops'];
    }

    public function executionMode(): ?string
    {
        return 'cli'; // CLI mode has proper tool mapping for code_exec, github, file_ops
    }

    public function requiredSecrets(): array
    {
        return ['GITHUB_TOKEN']; // Required for git push and gh CLI operations
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'task_id' => 'nullable|integer|exists:tasks,id',
            'task_description' => 'required|string|min:20',
            'repository' => 'nullable|string',
            'branch' => 'nullable|string',
            'file_scope' => 'nullable|array',
            'file_scope.*' => 'string',
            'test_requirements' => 'nullable|string',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'files_changed' => [],
            'tests_added' => [],
            'commit_message' => '',
            'pr_description' => '',
            'needs_review' => true,
        ], $output);
    }
}
