<?php

namespace App\Services\Symphony;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkflowConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = []) {}

    public function trackerKind(): ?string
    {
        $kind = $this->stringValue('tracker.kind');

        return $kind ? Str::lower(trim($kind)) : null;
    }

    public function activeStates(): array
    {
        return $this->normalizeStates(
            $this->configValue('tracker.active_states'),
            ['pending', 'in_progress']
        );
    }

    public function terminalStates(): array
    {
        return $this->normalizeStates(
            $this->configValue('tracker.terminal_states'),
            ['completed', 'cancelled', 'canceled', 'duplicate', 'done']
        );
    }

    public function pollIntervalMs(): int
    {
        return $this->positiveInt($this->configValue('polling.interval_ms'), 30000);
    }

    public function workspaceRoot(): string
    {
        $raw = $this->stringValue('workspace.root');
        $resolved = $this->resolveEnvToken($raw) ?? storage_path('app/symphony-workspaces');

        return $this->expandPath($resolved);
    }

    public function hookAfterCreate(): ?string
    {
        return $this->nullableString('hooks.after_create');
    }

    public function hookBeforeRun(): ?string
    {
        return $this->nullableString('hooks.before_run');
    }

    public function hookAfterRun(): ?string
    {
        return $this->nullableString('hooks.after_run');
    }

    public function hookBeforeRemove(): ?string
    {
        return $this->nullableString('hooks.before_remove');
    }

    public function hookTimeoutMs(): int
    {
        return $this->positiveInt($this->configValue('hooks.timeout_ms'), 60000);
    }

    public function maxConcurrentAgents(): int
    {
        return $this->positiveInt($this->configValue('agent.max_concurrent_agents'), 10);
    }

    public function maxRetryBackoffMs(): int
    {
        return $this->positiveInt($this->configValue('agent.max_retry_backoff_ms'), 300000);
    }

    public function maxTurns(): int
    {
        return $this->positiveInt($this->configValue('agent.max_turns'), 20);
    }

    /**
     * @return array<string, int>
     */
    public function maxConcurrentAgentsByState(): array
    {
        $value = $this->configValue('agent.max_concurrent_agents_by_state');
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $state => $limit) {
            if (! is_string($state)) {
                continue;
            }

            $coerced = $this->positiveInt($limit, 0);
            if ($coerced <= 0) {
                continue;
            }

            $normalized[$this->normalizeState($state)] = $coerced;
        }

        return $normalized;
    }

    public function codexCommand(): string
    {
        return $this->nullableString('codex.command') ?? 'codex app-server';
    }

    /**
     * @return array<int, string>
     */
    public function validateDispatchConfig(): array
    {
        $errors = [];

        $trackerKind = $this->trackerKind();
        if (! $trackerKind) {
            $errors[] = 'tracker.kind is required.';
        }

        if ($trackerKind && ! in_array($trackerKind, ['kanban_tasks', 'linear'], true)) {
            $errors[] = "tracker.kind '{$trackerKind}' is not supported.";
        }

        if ($trackerKind === 'linear') {
            $apiKey = $this->resolveEnvToken($this->stringValue('tracker.api_key')) ?: env('LINEAR_API_KEY');
            if (! $apiKey) {
                $errors[] = 'tracker.api_key is required for linear tracker kind.';
            }

            if (! $this->stringValue('tracker.project_slug')) {
                $errors[] = 'tracker.project_slug is required for linear tracker kind.';
            }
        }

        if (! trim($this->codexCommand())) {
            $errors[] = 'codex.command must not be empty.';
        }

        return $errors;
    }

    protected function configValue(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    protected function stringValue(string $key): ?string
    {
        $value = $this->configValue($key);

        return is_string($value) ? $value : null;
    }

    protected function nullableString(string $key): ?string
    {
        $value = $this->stringValue($key);
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (is_string($value) && is_numeric($value)) {
            $intValue = (int) $value;

            return $intValue > 0 ? $intValue : $default;
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeStates(mixed $value, array $default): array
    {
        $states = [];

        if (is_string($value)) {
            $states = explode(',', $value);
        } elseif (is_array($value)) {
            $states = $value;
        } else {
            $states = $default;
        }

        $normalized = collect($states)
            ->filter(fn ($state) => is_string($state) && trim($state) !== '')
            ->map(fn ($state) => $this->normalizeState((string) $state))
            ->unique()
            ->values()
            ->all();

        if (empty($normalized)) {
            return $default;
        }

        return $normalized;
    }

    protected function normalizeState(string $state): string
    {
        return Str::lower(trim($state));
    }

    protected function resolveEnvToken(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $trimmed = trim($value);
        if (! str_starts_with($trimmed, '$')) {
            return $trimmed;
        }

        $envKey = substr($trimmed, 1);
        $resolved = env($envKey);

        if (! is_string($resolved) || trim($resolved) === '') {
            return null;
        }

        return $resolved;
    }

    protected function expandPath(string $value): string
    {
        $expanded = trim($value);
        if (str_starts_with($expanded, '~')) {
            $home = getenv('HOME') ?: '';
            $suffix = ltrim(substr($expanded, 1), '/\\');
            $expanded = rtrim($home, '/\\').($suffix !== '' ? DIRECTORY_SEPARATOR.$suffix : '');
        }

        return str_replace('\\', DIRECTORY_SEPARATOR, $expanded);
    }
}
