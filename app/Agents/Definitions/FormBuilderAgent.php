<?php

namespace App\Agents\Definitions;

class FormBuilderAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Form Builder';
    }

    protected function getDescription(): string
    {
        return 'Creates Gravity Forms from natural language descriptions. Designs optimal field structures, question flow, and validation for intake forms, applications, surveys, and lead capture.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true;
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    public function allowedTools(): array
    {
        return [
            'gf-list-forms',
            'gf-get-form',
            'gf-create-form',
            'gf-update-form',
            'gf-create-landing-page',
            'web-search',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'form_purpose' => 'required|string',
            'target_audience' => 'nullable|string',
            'required_fields' => 'nullable|array',
            'optional_context' => 'nullable|string',
            'configure_webhook' => 'nullable|boolean',
            'create_landing_page' => 'nullable|boolean',
            'landing_page_slug' => 'nullable|string',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'form_id' => null,
            'form_title' => '',
            'fields' => [],
            'field_count' => 0,
            'edit_url' => '',
            'rationale' => '',
            'landing_page_id' => null,
            'landing_page_url' => '',
        ], $output);
    }
}
