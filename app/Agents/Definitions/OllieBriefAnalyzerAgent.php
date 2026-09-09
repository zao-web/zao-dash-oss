<?php

namespace App\Agents\Definitions;

/**
 * Orchestrator agent that parses product briefs and coordinates Ollie site building.
 *
 * Input sources: PDFs, Google Docs, emails, existing site URLs
 * Output: Structured project brief, delegated tasks to specialized agents
 */
class OllieBriefAnalyzerAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ollie Brief Analyzer';
    }

    protected function getDescription(): string
    {
        return 'Parses product briefs from PDFs, Google Docs, and emails to extract brand guidelines, requirements, and content. Orchestrates site building by delegating to specialized Ollie agents for design, page building, migration, and custom blocks.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'opus'; // Complex reasoning for brief analysis
    }

    protected function getMaxBudget(): float
    {
        return 15.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Production deployments require approval
    }

    public function configSchema(): array
    {
        return [
            'brief_source' => 'required|string', // pdf_path, google_doc_id, or email content
            'source_type' => 'required|in:pdf,google_doc,email,url',
            'environment' => 'required|in:staging,production',
            'migration_source_url' => 'nullable|url',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Brief parsing
            'ollie_parse_brief',
            'ollie_extract_brand',

            // Site analysis
            'ollie_analyze_site',
            'ollie_crawl_sitemap',

            // Project management
            'ollie_create_project',
            'ollie_get_project_status',

            // Agent delegation
            'assign_agent_task',

            // Utilities
            'web_search',
            'web_fetch',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
