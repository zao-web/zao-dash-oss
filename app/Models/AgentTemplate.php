<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Agent template for creating new agents.
 *
 * Templates encode best practices from our agentic workflow principles:
 * - Single responsibility (one primary tool)
 * - External prompts (system_prompt_template)
 * - Explicit configuration schema
 */
class AgentTemplate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'default_tools' => 'array',
        'config_schema' => 'array',
        'default_requires_approval' => 'boolean',
        'is_public' => 'boolean',
        'default_budget_usd' => 'decimal:2',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Create an agent from this template.
     */
    public function createAgent(array $config = []): Agent
    {
        $name = $config['name'] ?? $this->name;
        $slug = $config['slug'] ?? Str::slug($name);

        // Replace placeholders in system prompt
        $systemPrompt = $this->system_prompt_template;
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $systemPrompt = str_replace("{{{$key}}}", $value, $systemPrompt);
            }
        }

        $agent = Agent::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $config['description'] ?? $this->description,
            'status' => $config['status'] ?? 'paused',
            'model' => $config['model'] ?? $this->default_model,
            'requires_approval' => $config['requires_approval'] ?? $this->default_requires_approval,
            'max_budget_usd' => $config['max_budget_usd'] ?? $this->default_budget_usd,
            'allowed_tools' => $config['tools'] ?? $this->default_tools,
            'system_prompt' => $systemPrompt,
            'is_dynamic' => true, // UI-created agents are dynamic
        ]);

        // Increment usage count
        $this->increment('usage_count');

        return $agent;
    }

    /**
     * Validate configuration against schema.
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        $schema = $this->config_schema ?? [];

        foreach ($schema as $field => $rules) {
            $required = $rules['required'] ?? false;
            $type = $rules['type'] ?? 'string';

            if ($required && ! isset($config[$field])) {
                $errors[$field] = "The {$field} field is required.";

                continue;
            }

            if (isset($config[$field])) {
                $value = $config[$field];
                switch ($type) {
                    case 'string':
                        if (! is_string($value)) {
                            $errors[$field] = "The {$field} field must be a string.";
                        }
                        break;
                    case 'integer':
                        if (! is_int($value)) {
                            $errors[$field] = "The {$field} field must be an integer.";
                        }
                        break;
                    case 'array':
                        if (! is_array($value)) {
                            $errors[$field] = "The {$field} field must be an array.";
                        }
                        break;
                }
            }
        }

        return $errors;
    }

    /**
     * Scope to public templates.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
