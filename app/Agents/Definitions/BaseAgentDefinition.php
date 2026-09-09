<?php

namespace App\Agents\Definitions;

use App\Agents\Contracts\AgentDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Base class for agent definitions with sensible defaults.
 *
 * Extend this class to create new agents with minimal boilerplate.
 * Override methods as needed for custom behavior.
 */
abstract class BaseAgentDefinition implements AgentDefinition
{
    /**
     * The default model for agents.
     */
    protected string $defaultModel = 'sonnet';

    /**
     * The default maximum budget per run in USD.
     */
    protected float $defaultMaxBudget = 5.00;

    /**
     * Whether agents require approval by default.
     */
    protected bool $defaultRequiresApproval = true;

    /**
     * Get the agent's unique identifier (slug).
     *
     * Override this or it will be derived from the class name.
     * Uses kebab-case to match existing database convention.
     */
    protected function getId(): string
    {
        $className = class_basename(static::class);
        $name = Str::replaceLast('Agent', '', $className);

        // Convert PascalCase to kebab-case
        return Str::kebab($name);
    }

    /**
     * Get the agent's display name.
     */
    abstract protected function getName(): string;

    /**
     * Get the agent's description.
     */
    abstract protected function getDescription(): string;

    /**
     * Get the trigger type for this agent.
     *
     * @return 'manual'|'scheduled'|'webhook'|'chained'
     */
    protected function getTrigger(): string
    {
        return 'manual';
    }

    /**
     * Get the schedule expression (for scheduled agents).
     */
    protected function getSchedule(): ?string
    {
        return null;
    }

    /**
     * Get the agent this one chains from (for chained agents).
     */
    protected function getChainFrom(): ?string
    {
        return null;
    }

    /**
     * Get the model for this agent.
     *
     * @return 'opus'|'sonnet'|'haiku'
     */
    protected function getModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Get the maximum budget for this agent.
     */
    protected function getMaxBudget(): float
    {
        return $this->defaultMaxBudget;
    }

    /**
     * Whether this agent requires approval.
     */
    protected function requiresApproval(): bool
    {
        return $this->defaultRequiresApproval;
    }

    /**
     * {@inheritdoc}
     */
    public function metadata(): array
    {
        $meta = [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'model' => $this->getModel(),
            'trigger' => $this->getTrigger(),
            'requires_approval' => $this->requiresApproval(),
            'max_budget_usd' => $this->getMaxBudget(),
        ];

        if ($schedule = $this->getSchedule()) {
            $meta['schedule'] = $schedule;
        }

        if ($chainFrom = $this->getChainFrom()) {
            $meta['chain_from'] = $chainFrom;
        }

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function requiredSecrets(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function validateContext(array $context): bool
    {
        $schema = $this->configSchema();

        if (empty($schema)) {
            return true;
        }

        return Validator::make($context, $schema)->passes();
    }

    /**
     * {@inheritdoc}
     */
    public function processOutput(array $output): array
    {
        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function executionMode(): ?string
    {
        return null;
    }

    /**
     * Load system prompt from a SKILL.md file.
     *
     * @param  string|null  $path  Relative path from storage/app/skills/
     * @param  bool  $includeToneGuide  Whether to append the tone guide
     */
    protected function loadSkillPrompt(?string $path = null, bool $includeToneGuide = false): string
    {
        $path ??= $this->getId().'/SKILL.md';
        $fullPath = storage_path('app/skills/'.$path);

        if (! file_exists($fullPath)) {
            $prompt = $this->getFallbackPrompt();
        } else {
            $prompt = file_get_contents($fullPath);
        }

        // Append tone guide for content-generating agents
        if ($includeToneGuide) {
            $prompt .= "\n\n".$this->loadToneGuide();
        }

        return $prompt;
    }

    /**
     * Load the shared tone guide.
     */
    protected function loadToneGuide(): string
    {
        $tonePath = storage_path('app/skills/TONE.md');

        if (! file_exists($tonePath)) {
            return '';
        }

        return file_get_contents($tonePath);
    }

    /**
     * Get a fallback prompt if SKILL.md doesn't exist.
     */
    protected function getFallbackPrompt(): string
    {
        $meta = $this->metadata();

        return <<<PROMPT
You are {$meta['name']}.

{$meta['description']}

Follow all instructions carefully and complete the task to the best of your ability.
PROMPT;
    }
}
