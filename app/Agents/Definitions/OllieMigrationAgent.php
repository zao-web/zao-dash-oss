<?php

namespace App\Agents\Definitions;

/**
 * Migrates content from existing WordPress or Joomla sites.
 *
 * Analyzes source sites, extracts content, maps to Ollie patterns,
 * and identifies custom functionality requiring block creation.
 */
class OllieMigrationAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ollie Migration Agent';
    }

    protected function getDescription(): string
    {
        return 'Analyzes and migrates content from existing WordPress or Joomla sites. Crawls source sites, extracts content inventory, maps content to Ollie patterns, identifies custom code requiring porting, and executes staged migrations with validation.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'ollie-brief-analyzer';
    }

    protected function getModel(): string
    {
        return 'opus'; // Complex analysis required
    }

    protected function getMaxBudget(): float
    {
        return 12.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Modifies content
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|string',
            'source_url' => 'required|url',
            'source_platform' => 'required|in:wordpress,joomla,static,unknown',
            'migration_scope' => 'nullable|in:full,content_only,structure_only',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Site analysis
            'ollie_analyze_wordpress',
            'ollie_analyze_joomla',
            'ollie_crawl_site',
            'ollie_detect_platform',

            // Content extraction
            'ollie_extract_content',
            'ollie_extract_media',
            'ollie_extract_structure',

            // Code analysis
            'ollie_analyze_theme',
            'ollie_analyze_plugins',
            'ollie_identify_custom_code',

            // Pattern mapping
            'ollie_map_content_to_patterns',
            'ollie_suggest_patterns',

            // Migration execution
            'ollie_create_migration_manifest',
            'ollie_execute_migration',
            'ollie_validate_migration',

            // Agent delegation
            'assign_agent_task', // Delegate to BlockCreator when needed
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
