<?php

namespace App\Agents\Definitions;

/**
 * MyComplexAgent Agent Definition
 *
 * Following agentic workflow principles:
 * - Single responsibility: one primary tool
 * - External prompts: SKILL.md for system prompt
 * - Explicit configuration schema
 */
class MyComplexAgentAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'MyComplexAgent';
    }

    protected function getDescription(): string
    {
        return 'Placeholder agent definition generated during local scaffolding.';
    }

    public function metadata(): array
    {
        return [
            'id' => 'my-complex-agent',
            'name' => 'MyComplexAgent',
            'description' => 'TODO: Add description',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
            'schedule' => null,
        ];
    }

    public function allowedTools(): array
    {
        // Single-responsibility: prefer ONE tool per agent
        return [];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt('my-complex-agent');
    }

    public function requiredSecrets(): array
    {
        return [];
    }

    public function configSchema(): array
    {
        return [
            // Define required configuration fields
            // 'field_name' => [
            //     'type' => 'string',
            //     'required' => true,
            //     'description' => 'Field description',
            // ],
        ];
    }

    public function validateContext(array $context): bool
    {
        // Add validation logic for execution context
        return true;
    }

    public function processOutput(array $output): array
    {
        // Post-process agent output before storing
        return $output;
    }
}
