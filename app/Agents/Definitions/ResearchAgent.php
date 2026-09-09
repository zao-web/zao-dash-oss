<?php

namespace App\Agents\Definitions;

/**
 * Research Agent for gathering company information and brand assets.
 *
 * Handles defunct and active companies, web scraping, archive.org integration,
 * logo extraction, and brand analysis for website building.
 */
class ResearchAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Company Research Agent';
    }

    protected function getDescription(): string
    {
        return 'Researches companies from domain names, extracts brand information, logos, content, and business details for autonomous website building.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Complex research and analysis reasoning
    }

    protected function getMaxBudget(): float
    {
        return 8.00; // Research can be resource intensive
    }

    protected function requiresApproval(): bool
    {
        return false; // Research is low-risk
    }

    public function configSchema(): array
    {
        return [
            'domain' => 'required|string|regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            'company_type' => 'required|in:active,defunct,startup,enterprise',
            'brief' => 'required|string|min:10|max:1000',
            'source_url' => 'nullable|url', // For active companies
            'industry' => 'nullable|string|max:100',
            'depth' => 'required|in:shallow,deep',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Web research
            'web_search',
            'web_fetch',

            // Archive research
            'archive_org_search',
            'wayback_machine_crawl',

            // Content extraction
            'logo_extractor',
            'brand_analyzer',
            'content_scraper',
            'social_media_scraper',

            // Analysis
            'business_info_analyzer',
            'brand_guidelines_extractor',
            'industry_research',
        ];
    }

    public function requiredSecrets(): array
    {
        return [
            // For enhanced web scraping capabilities
            'serp_api_key', // Optional: for better search results
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
