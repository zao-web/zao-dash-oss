<?php

namespace App\Agents\Definitions;

/**
 * Case Study Writer Agent
 *
 * Creates compelling case studies from completed projects:
 * - Challenge/Solution/Results framework
 * - Client quotes and testimonials
 * - Metrics and outcomes
 * - Visual storytelling suggestions
 */
class CaseStudyWriterAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Case Study Writer';
    }

    protected function getDescription(): string
    {
        return 'Create compelling case studies from completed project data.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true; // Requires client approval before publishing
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    public function allowedTools(): array
    {
        return [
            'search_clients',
            'search_projects',
            'search_tasks',
            'web_search',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt(includeToneGuide: true);
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'nullable|integer|exists:projects,id',
            'client' => 'required_without:project_id|string',
            'focus' => 'nullable|string', // Specific aspect to highlight
            'format' => 'nullable|in:full,summary,social',
            'include_metrics' => 'nullable|boolean',
            'tone' => 'nullable|in:professional,storytelling,technical',
            'additional_context' => 'nullable|string',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'title' => '',
            'summary' => '',
            'challenge' => '',
            'solution' => '',
            'results' => '',
            'metrics' => [],
            'quotes' => [],
            'full_content' => '',
            'social_snippet' => '',
            'suggested_visuals' => [],
            'tags' => [],
        ], $output);
    }
}
