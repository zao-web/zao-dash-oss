<?php

namespace App\Agents\Definitions;

/**
 * QA Agent
 *
 * Validates code changes from Dev Agent:
 * - Runs tests
 * - Checks code quality
 * - Reviews for security issues
 * - Validates against requirements
 *
 * Chains from Dev Agent, triggers approval for merge.
 */
class QAAgent extends BaseAgentDefinition
{
    protected function getId(): string
    {
        return 'qa-agent'; // Override: kebab('QA') produces 'q-a'
    }

    protected function getName(): string
    {
        return 'QA Agent';
    }

    protected function getDescription(): string
    {
        return 'Validate code changes, run tests, and check for quality and security issues.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'dev-agent';
    }

    protected function requiresApproval(): bool
    {
        return true; // Merge approval required
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    public function allowedTools(): array
    {
        return ['code_exec', 'file_ops'];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'dev_agent_run_id' => 'required|integer|exists:agent_runs,id',
            'files_changed' => 'required|array',
            'files_changed.*.path' => 'required|string',
            'test_suite' => 'nullable|in:unit,feature,all',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'tests_passed' => false,
            'test_results' => [],
            'quality_issues' => [],
            'security_issues' => [],
            'ready_to_merge' => false,
            'review_notes' => '',
        ], $output);
    }
}
