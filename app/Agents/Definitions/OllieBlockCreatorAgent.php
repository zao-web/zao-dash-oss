<?php

namespace App\Agents\Definitions;

/**
 * Creates custom WordPress blocks when core blocks are insufficient.
 *
 * Follows WordPress Block API best practices, integrates with
 * theme.json tokens, and registers blocks in the child theme.
 */
class OllieBlockCreatorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ollie Block Creator';
    }

    protected function getDescription(): string
    {
        return 'Creates custom WordPress blocks when core blocks cannot meet requirements. Scaffolds blocks using @wordpress/create-block, implements block.json with proper attributes, ensures theme.json token support, and registers blocks in the Ollie child theme.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'ollie-migration'; // Or ollie-page-builder when gaps identified
    }

    protected function getModel(): string
    {
        return 'opus'; // Complex code generation
    }

    protected function getMaxBudget(): float
    {
        return 10.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Creates code
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|string',
            'block_requirements' => 'required|array',
            'block_requirements.*.name' => 'required|string',
            'block_requirements.*.purpose' => 'required|string',
            'block_requirements.*.functionality' => 'required|string',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Block scaffolding
            'ollie_scaffold_block',
            'ollie_create_block_json',

            // Code generation
            'ollie_generate_block_edit',
            'ollie_generate_block_save',
            'ollie_generate_block_styles',

            // Block variations
            'ollie_create_block_variation',

            // Pattern creation
            'ollie_create_pattern',
            'ollie_register_pattern',

            // Theme integration
            'ollie_register_block',
            'ollie_write_child_theme_file',

            // Validation
            'ollie_validate_block',
            'ollie_test_block',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
