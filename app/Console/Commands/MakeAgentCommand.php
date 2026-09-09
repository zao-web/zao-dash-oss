<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffold a new agent following agentic workflow best practices.
 *
 * Creates:
 * - AgentDefinition class in app/Agents/Definitions/
 * - SKILL.md prompt file in storage/app/skills/{slug}/
 *
 * Follows principles from "A Practical Guide for Production-Grade Agentic AI Workflows":
 * - Single responsibility (one tool per agent)
 * - External prompts (SKILL.md files)
 * - Explicit configuration schemas
 */
class MakeAgentCommand extends Command
{
    protected $signature = 'make:agent
        {name : The agent name (e.g., MeetingParser)}
        {--tool= : Primary tool for the agent}
        {--model=sonnet : AI model (opus, sonnet, haiku)}
        {--approval : Requires approval before actions}
        {--budget=5.00 : Max budget per run in USD}
        {--schedule= : Cron expression for scheduled runs}';

    protected $description = 'Create a new agent definition following best practices';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = Str::slug(Str::snake($name));
        $className = Str::studly($name).'Agent';

        // Validate name
        if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
            $this->error('Agent name must start with a letter and contain only alphanumeric characters.');

            return Command::FAILURE;
        }

        $definitionPath = app_path("Agents/Definitions/{$className}.php");
        $skillDir = storage_path("app/skills/{$slug}");
        $skillPath = "{$skillDir}/SKILL.md";

        // Check if already exists
        if (file_exists($definitionPath)) {
            $this->error("Agent definition already exists: {$definitionPath}");

            return Command::FAILURE;
        }

        // Get options
        $tool = $this->option('tool');
        $model = $this->option('model');
        $requiresApproval = $this->option('approval');
        $budget = $this->option('budget');
        $schedule = $this->option('schedule');

        // Create definition class
        $definitionContent = $this->generateDefinitionClass(
            className: $className,
            slug: $slug,
            name: $name,
            tool: $tool,
            model: $model,
            requiresApproval: $requiresApproval,
            budget: $budget,
            schedule: $schedule,
        );

        if (! is_dir(app_path('Agents/Definitions'))) {
            mkdir(app_path('Agents/Definitions'), 0755, true);
        }

        file_put_contents($definitionPath, $definitionContent);
        $this->info("Created agent definition: {$definitionPath}");

        // Create SKILL.md
        $skillContent = $this->generateSkillPrompt($name, $slug, $tool);

        if (! is_dir($skillDir)) {
            mkdir($skillDir, 0755, true);
        }

        file_put_contents($skillPath, $skillContent);
        $this->info("Created SKILL.md: {$skillPath}");

        // Show next steps
        $this->newLine();
        $this->info('Next steps:');
        $this->line("1. Edit the SKILL.md prompt: {$skillPath}");
        $this->line("2. Customize the agent definition: {$definitionPath}");
        $this->line('3. Sync to database: php artisan agents:sync');

        if ($tool) {
            $this->line("4. Your agent uses the '{$tool}' tool - ensure it's registered in ToolRegistry");
        }

        return Command::SUCCESS;
    }

    protected function generateDefinitionClass(
        string $className,
        string $slug,
        string $name,
        ?string $tool,
        string $model,
        bool $requiresApproval,
        string $budget,
        ?string $schedule,
    ): string {
        $toolsArray = $tool ? "['{$tool}']" : '[]';
        $scheduleValue = $schedule ? "'{$schedule}'" : 'null';
        $approvalValue = $requiresApproval ? 'true' : 'false';

        return <<<PHP
<?php

namespace App\Agents\Definitions;

/**
 * {$name} Agent Definition
 *
 * Following agentic workflow principles:
 * - Single responsibility: one primary tool
 * - External prompts: SKILL.md for system prompt
 * - Explicit configuration schema
 */
class {$className} extends BaseAgentDefinition
{
    public function metadata(): array
    {
        return [
            'id' => '{$slug}',
            'name' => '{$name}',
            'description' => 'TODO: Add description',
            'model' => '{$model}',
            'requires_approval' => {$approvalValue},
            'max_budget_usd' => {$budget},
            'schedule' => {$scheduleValue},
        ];
    }

    public function allowedTools(): array
    {
        // Single-responsibility: prefer ONE tool per agent
        return {$toolsArray};
    }

    public function systemPrompt(): string
    {
        return \$this->loadSkillPrompt('{$slug}');
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

    public function validateContext(array \$context): bool
    {
        // Add validation logic for execution context
        return true;
    }

    public function processOutput(array \$output): array
    {
        // Post-process agent output before storing
        return \$output;
    }
}
PHP;
    }

    protected function generateSkillPrompt(string $name, string $slug, ?string $tool): string
    {
        $toolSection = $tool
            ? "\n## Available Tools\n\nYou have access to the `{$tool}` tool. Use it when needed.\n"
            : '';

        return <<<MD
# {$name} Agent

You are the {$name} agent. Your role is to [TODO: describe the agent's purpose].

## Responsibilities

1. [TODO: Primary responsibility]
2. [TODO: Secondary responsibility]
{$toolSection}
## Output Format

Always return structured JSON with the following format:

```json
{
  "status": "success" | "error",
  "result": { ... },
  "summary": "Brief human-readable summary"
}
```

## Guidelines

- Be concise and focused
- Validate inputs before processing
- Report errors clearly
- Follow the single-responsibility principle

## Context Variables

The following variables may be provided in your context:
- `{{prompt}}` - The user's request
- `{{context}}` - Additional context data

MD;
    }
}
