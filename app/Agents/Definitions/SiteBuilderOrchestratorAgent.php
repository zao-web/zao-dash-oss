<?php

namespace App\Agents\Definitions;

/**
 * Main orchestrator for the autonomous website building system.
 *
 * Coordinates research, WordPress site creation, content building,
 * and deployment for complete website automation.
 */
class SiteBuilderOrchestratorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Site Builder Orchestrator';
    }

    protected function getDescription(): string
    {
        return 'Orchestrates end-to-end website building from domain/brief to live WordPress site. Coordinates research, content creation, WordPress setup, and deployment.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'opus'; // Complex orchestration and reasoning
    }

    protected function getMaxBudget(): float
    {
        return 25.00; // Orchestration of multiple complex tasks
    }

    protected function requiresApproval(): bool
    {
        return true; // Production deployments require approval
    }

    public function configSchema(): array
    {
        return [
            'domain' => 'required|string|regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            'brief' => 'required|string|min:10|max:2000',
            'company_type' => 'required|in:active,defunct,startup,enterprise',
            'target_hosting' => 'required|in:wordpress_com,self_hosted,existing_site',
            'environment' => 'required|in:staging,production',
            'content_sources' => 'nullable|array',
            'source_url' => 'nullable|url',
            'industry' => 'nullable|string|max:100',
            'timeline' => 'required|in:rush,standard,extended',
        ];
    }

    public function allowedTools(): array
    {
        return [
            // Project management
            'site_builder_project_create',
            'site_builder_status_update',
            'site_builder_message',
            'ollie_get_project_status',
            'project_status_update',

            // Agent coordination
            'assign_agent_task',
            'agent_execution_monitor',
            'task_dependency_manager',

            // WordPress setup
            'wordpress_site_create',
            'wordpress_theme_install',
            'wordpress_plugin_setup',
            'wordpress_user_onboarding',

            // Content pipeline
            'content_sync_setup',
            'wordpress_content_push',

            // Quality assurance
            'site_quality_check',
            'performance_test',
            'seo_audit',

            // Deployment
            'deployment_coordinator',
            'client_notification',
        ];
    }

    public function processOutput(array $output): array
    {
        // Add project tracking and client communication
        return array_merge($output, [
            'project_id' => $output['project_id'] ?? null,
            'client_notification' => true,
            'next_steps' => $output['next_steps'] ?? [],
            'estimated_completion' => $output['estimated_completion'] ?? null,
        ]);
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
