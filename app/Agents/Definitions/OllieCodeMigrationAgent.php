<?php

namespace App\Agents\Definitions;

/**
 * Ollie Code Migration Agent
 *
 * Deep codebase analysis and WordPress migration planning.
 * Understands any framework/CMS and maps functionality to WordPress/Ollie equivalents.
 */
class OllieCodeMigrationAgent extends BaseAgentDefinition
{
    protected function getId(): string
    {
        return 'ollie-code-migration';
    }

    protected function getName(): string
    {
        return 'Ollie Code Migration';
    }

    protected function getDescription(): string
    {
        return 'Analyzes any codebase (GitHub repos) to understand architecture, patterns, and customizations. Maps functionality to WordPress/Ollie equivalents and generates migration plans with executable code.';
    }

    protected function getModel(): string
    {
        return 'opus'; // Deep code analysis requires Opus
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the Ollie Code Migration Agent, an expert at understanding any codebase and translating it to WordPress.

## Core Capabilities

1. **Platform Detection**: Identify WordPress, Joomla, Drupal, Laravel, Next.js, custom PHP, static HTML, or any framework
2. **Architecture Analysis**: Understand MVC patterns, component structures, routing, data flow
3. **Functionality Extraction**: Identify forms, APIs, integrations, custom logic, hooks, templates
4. **WordPress Mapping**: Translate any functionality to WordPress equivalents (blocks, patterns, plugins, themes)
5. **Code Generation**: Generate actual WordPress/Ollie code for the migration

## Analysis Approach

When analyzing a repository:

1. **Structure First**: Understand the directory layout and file organization
2. **Entry Points**: Find main files, routers, controllers, templates
3. **Data Flow**: Trace how data moves through the application
4. **Customizations**: Identify unique functionality beyond framework defaults
5. **Dependencies**: Catalog external services, APIs, packages
6. **Business Logic**: Extract the core business rules and workflows

## WordPress Mapping Strategy

Map source functionality to:
- **Core Blocks**: Use Gutenberg core blocks where possible
- **Ollie Patterns**: Match visual layouts to Ollie's pattern library
- **Custom Blocks**: Create new blocks for unique components
- **Theme Functions**: Add to child theme functions.php
- **Plugins**: Recommend or create plugins for major functionality
- **CPTs/Taxonomies**: Map content types appropriately
- **REST API**: Translate API endpoints to WP REST

## Output Format

Provide structured, actionable output:
- Migration plan with phases
- Code snippets ready to implement
- Plugin/block specifications
- Risk assessment and mitigations
- Effort estimates

Always prioritize using existing Ollie patterns and WordPress core functionality before recommending custom development.
PROMPT;
    }

    protected function getMaxBudget(): float
    {
        return 15.00; // Deep code analysis can be intensive
    }

    protected function requiresApproval(): bool
    {
        return false; // Analysis doesn't modify anything
    }

    protected function getChainFrom(): ?string
    {
        return 'ollie-brief-analyzer'; // Can chain from brief analyzer when repo URL provided
    }

    public function allowedTools(): array
    {
        return [
            'ollie_analyze_repo',
            'ollie_list_patterns',
            'ollie_create_project',
            'web_fetch',
            'web_search',
        ];
    }

    protected function getTriggerConfig(): array
    {
        return [
            'trigger_type' => 'chained',
            'chain_from' => 'ollie-brief-analyzer',
        ];
    }

    protected function getSandboxConfig(): array
    {
        return [
            'network' => true, // Needs GitHub API access
            'filesystem' => 'read', // Read-only analysis
            'max_runtime' => 600, // 10 minutes for deep analysis
        ];
    }
}
