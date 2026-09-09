<?php

namespace App\Agents\Tools;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Base implementation for tools.
 *
 * Provides common functionality for validation and metadata.
 */
abstract class BaseTool implements Tool
{
    /**
     * Get the tool ID (defaults to kebab-case class name).
     */
    public function id(): string
    {
        $className = class_basename(static::class);
        $name = Str::replaceLast('Tool', '', $className);

        return Str::kebab($name);
    }

    /**
     * Default: tool does not require approval.
     */
    public function requiresApproval(): bool
    {
        return false;
    }

    /**
     * Default risk level.
     */
    public function riskLevel(): string
    {
        return 'low';
    }

    /**
     * Tool category for grouping in UI.
     *
     * Categories: data, seo, social, quickbooks, actions, analysis, content, agent, navigation
     */
    public function category(): string
    {
        return 'general';
    }

    /**
     * Validate parameters using Laravel validator.
     */
    public function validate(array $params): array
    {
        $rules = $this->validationRules();

        if (empty($rules)) {
            return $params;
        }

        $validator = Validator::make($params, $rules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Tool validation failed: '.$validator->errors()->first()
            );
        }

        return $validator->validated();
    }

    /**
     * Laravel validation rules for parameters.
     *
     * Override this in subclasses.
     */
    protected function validationRules(): array
    {
        return [];
    }

    /**
     * Convert to array for API/LLM consumption.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'description' => $this->description(),
            'category' => $this->category(),
            'input_schema' => $this->inputSchema(),
            'requires_approval' => $this->requiresApproval(),
            'risk_level' => $this->riskLevel(),
        ];
    }

    /**
     * Convert to Anthropic tool format.
     */
    public function toAnthropicTool(): array
    {
        return [
            'name' => $this->id(),
            'description' => $this->description(),
            'input_schema' => $this->inputSchema(),
        ];
    }
}
