<?php

namespace App\Agents;

use App\Agents\Contracts\AgentDefinition;
use App\Models\Agent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Registry for discovering and managing agent definitions.
 *
 * The registry scans the Definitions directory for PHP classes
 * implementing AgentDefinition and provides methods to sync
 * them to the database.
 */
class AgentRegistry
{
    /**
     * Cached definitions.
     *
     * @var Collection<string, AgentDefinition>|null
     */
    protected ?Collection $definitions = null;

    /**
     * The namespace for agent definitions.
     */
    protected string $namespace = 'App\\Agents\\Definitions';

    /**
     * The path to agent definitions.
     */
    protected string $path;

    public function __construct()
    {
        $this->path = app_path('Agents/Definitions');
    }

    /**
     * Get all registered agent definitions.
     *
     * @return Collection<string, AgentDefinition>
     */
    public function allDefinitions(): Collection
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = collect();

        if (! File::isDirectory($this->path)) {
            return $this->definitions;
        }

        $files = File::files($this->path);

        foreach ($files as $file) {
            $className = $this->namespace.'\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            // Skip abstract classes and non-implementations
            if ($reflection->isAbstract()) {
                continue;
            }

            if (! $reflection->implementsInterface(AgentDefinition::class)) {
                continue;
            }

            $instance = app($className);
            $id = $instance->metadata()['id'];

            $this->definitions->put($id, $instance);
        }

        return $this->definitions;
    }

    /**
     * Get a specific agent definition by ID.
     */
    public function getDefinition(string $id): ?AgentDefinition
    {
        return $this->allDefinitions()->get($id);
    }

    /**
     * Check if a definition exists.
     */
    public function hasDefinition(string $id): bool
    {
        return $this->allDefinitions()->has($id);
    }

    /**
     * Sync all definitions to the database.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function syncToDatabase(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($this->allDefinitions() as $definition) {
            $result = $this->syncDefinition($definition);
            $stats[$result]++;
        }

        return $stats;
    }

    /**
     * Sync a single definition to the database.
     *
     * @return 'created'|'updated'|'unchanged'
     */
    public function syncDefinition(AgentDefinition $definition): string
    {
        $meta = $definition->metadata();
        $slug = Str::slug($meta['id']);

        $existing = Agent::where('slug', $slug)->first();

        $attributes = [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'model' => $meta['model'],
            'requires_approval' => $meta['requires_approval'],
            'max_budget_usd' => $meta['max_budget_usd'],
            'allowed_tools' => $definition->allowedTools(),
            'system_prompt' => $definition->systemPrompt(),
            'schedule' => $meta['schedule'] ?? null,
            'is_dynamic' => false,
            'definition_class' => get_class($definition),
            'definition_synced_at' => now(),
        ];

        if ($existing) {
            // Check if anything changed
            $changed = false;
            foreach ($attributes as $key => $value) {
                if ($key === 'definition_synced_at') {
                    continue;
                }

                $currentValue = $existing->$key;

                // Handle array comparison
                if (is_array($value) && is_array($currentValue)) {
                    if ($value != $currentValue) {
                        $changed = true;
                        break;
                    }
                } elseif ($currentValue != $value) {
                    $changed = true;
                    break;
                }
            }

            if ($changed) {
                $existing->update($attributes);

                return 'updated';
            }

            // Update sync timestamp even if unchanged
            $existing->update(['definition_synced_at' => now()]);

            return 'unchanged';
        }

        // Create new agent
        Agent::create(array_merge($attributes, [
            'slug' => $slug,
            'status' => 'paused', // New agents start paused
        ]));

        return 'created';
    }

    /**
     * Check if a database agent is out of sync with its definition.
     */
    public function isOutOfSync(Agent $agent): bool
    {
        if ($agent->is_dynamic) {
            return false; // Dynamic agents have no definition
        }

        $definition = $this->getDefinition($agent->slug);

        if (! $definition) {
            return true; // Definition no longer exists
        }

        // Compare key fields
        $meta = $definition->metadata();

        return $agent->system_prompt !== $definition->systemPrompt()
            || $agent->allowed_tools != $definition->allowedTools()
            || $agent->model !== $meta['model']
            || $agent->requires_approval !== $meta['requires_approval']
            || $agent->max_budget_usd != $meta['max_budget_usd'];
    }

    /**
     * Get agents that are out of sync with their definitions.
     *
     * @return Collection<int, Agent>
     */
    public function getOutOfSyncAgents(): Collection
    {
        return Agent::where('is_dynamic', false)
            ->get()
            ->filter(fn (Agent $agent) => $this->isOutOfSync($agent));
    }

    /**
     * Clear the cached definitions.
     */
    public function clearCache(): void
    {
        $this->definitions = null;
    }
}
