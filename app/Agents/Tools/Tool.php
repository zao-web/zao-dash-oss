<?php

namespace App\Agents\Tools;

/**
 * Interface for deterministic tools.
 *
 * Tools are pure functions that perform specific actions.
 * They should NOT require LLM reasoning - they execute deterministically
 * based on their input parameters.
 *
 * @see docs/AGENTIC_WORKFLOWS.md - Principle #2: Direct Function Calls Over Tool Calls
 */
interface Tool
{
    /**
     * Unique identifier for the tool.
     */
    public function id(): string;

    /**
     * Human-readable name.
     */
    public function name(): string;

    /**
     * Description for LLM tool selection.
     */
    public function description(): string;

    /**
     * JSON Schema for input parameters.
     *
     * Used by LLMs to understand required inputs.
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with given parameters.
     *
     * This should be a DETERMINISTIC operation.
     * No LLM reasoning should happen here.
     *
     * @param  array  $params  Validated input parameters
     * @return array Result data
     *
     * @throws \Exception On execution failure
     */
    public function execute(array $params): array;

    /**
     * Validate input parameters.
     *
     * @param  array  $params  Raw input
     * @return array Validated/normalized params
     *
     * @throws \InvalidArgumentException On validation failure
     */
    public function validate(array $params): array;

    /**
     * Check if tool requires user approval before execution.
     */
    public function requiresApproval(): bool;

    /**
     * Get the risk level of this tool.
     */
    public function riskLevel(): string; // 'low', 'medium', 'high'

    /**
     * Get the category for grouping in UI.
     */
    public function category(): string;
}
