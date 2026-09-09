<?php

namespace App\Agents\Contracts;

/**
 * Contract for agent definitions.
 *
 * Agents can be defined in two ways:
 * 1. PHP classes implementing this interface (registered agents)
 * 2. Dynamic agents created via UI (stored in database only)
 *
 * PHP-defined agents are the source of truth - their configuration
 * is synced to the database but can be overridden at runtime.
 */
interface AgentDefinition
{
    /**
     * Get agent metadata.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   description: string,
     *   model: 'opus'|'sonnet'|'haiku',
     *   trigger: 'manual'|'scheduled'|'webhook'|'chained',
     *   requires_approval: bool,
     *   max_budget_usd: float,
     *   schedule?: string,
     *   chain_from?: string,
     * }
     */
    public function metadata(): array;

    /**
     * Get the list of allowed tools for this agent.
     *
     * @return array<string> Tool identifiers (e.g., ['web_search', 'code_exec'])
     */
    public function allowedTools(): array;

    /**
     * Get the system prompt for this agent.
     *
     * This can be loaded from a SKILL.md file or defined inline.
     */
    public function systemPrompt(): string;

    /**
     * Get required vault secrets for this agent.
     *
     * Secrets are loaded from the vault and injected into the
     * agent's execution environment.
     *
     * @return array<string> Secret keys (e.g., ['GITHUB_TOKEN'])
     */
    public function requiredSecrets(): array;

    /**
     * Get the configuration schema for validation.
     *
     * Returns Laravel validation rules for the context array.
     *
     * @return array<string, string|array>
     */
    public function configSchema(): array;

    /**
     * Validate the execution context before running.
     *
     * @param  array<string, mixed>  $context
     */
    public function validateContext(array $context): bool;

    /**
     * Process the agent's output after execution.
     *
     * Use this to transform, validate, or enrich the raw output.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function processOutput(array $output): array;

    /**
     * Get the preferred execution mode for this agent.
     *
     * @return 'sdk'|'cli'|null null means use default logic (SDK for agents with tools)
     */
    public function executionMode(): ?string;
}
