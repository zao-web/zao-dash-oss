<?php

namespace App\Agents\Definitions;

/**
 * Design Intelligence Agent - The Design Director
 *
 * Produces a comprehensive design blueprint that guides all downstream
 * design and page-building agents. Outputs art direction, visual hierarchy,
 * color strategy, typography, layout rhythm, and pattern recommendations.
 */
class DesignIntelligenceAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Design Intelligence';
    }

    protected function getDescription(): string
    {
        return 'Design director that produces a comprehensive design blueprint including art direction, visual hierarchy, color strategy, typography, layout rhythm, pattern recommendations, and conversion flow guidance. Blueprint stored in project.design_config.design_blueprint for consumption by downstream agents.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    protected function requiresApproval(): bool
    {
        return false;
    }

    public function configSchema(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'mode' => 'nullable|in:plan_only,build_assist',
            'industry' => 'nullable|string',
            'brand_voice' => 'nullable|string',
            'target_audience' => 'nullable|string',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Read project data including brief, source_data, existing design_config
            'website-builder-project-status',

            // Update progress and store the blueprint
            'website-builder-update-progress',

            // Broadcast status messages
            'website-builder-broadcast-message',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge($output, [
            'project_id' => $output['project_id'] ?? null,
            'design_blueprint' => $output['design_blueprint'] ?? null,
            'blueprint_stored' => $output['blueprint_stored'] ?? false,
        ]);
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt('design-intelligence/SKILL.md');
    }
}
