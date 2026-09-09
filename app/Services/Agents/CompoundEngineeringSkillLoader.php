<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Loads and parses Compound Engineering skill definitions.
 *
 * Skills are loaded from storage/app/compound-engineering/skills/ and parsed
 * to extract system prompts and tool configurations for agent execution.
 *
 * Skill invocations follow the format:
 * - /workflows:plan <args> - Plan a feature implementation
 * - /workflows:work <args> - Execute implementation from a plan
 * - /workflows:review <args> - Code review
 * - /lfg <args> - Full autonomous engineering loop
 */
class CompoundEngineeringSkillLoader
{
    /**
     * Available skills and their configurations.
     *
     * @var array<string, array{path: string, model: string, timeout_minutes: int}>
     */
    protected array $skillConfigs = [
        'workflows:plan' => [
            'path' => 'workflows/plan.md',
            'model' => 'sonnet',
            'timeout_minutes' => 30,
        ],
        'workflows:work' => [
            'path' => 'workflows/work.md',
            'model' => 'opus',
            'timeout_minutes' => 60,
        ],
        'workflows:review' => [
            'path' => 'workflows/review.md',
            'model' => 'opus',
            'timeout_minutes' => 45,
        ],
        'workflows:compound' => [
            'path' => 'workflows/compound.md',
            'model' => 'sonnet',
            'timeout_minutes' => 20,
        ],
        'lfg' => [
            'path' => 'lfg.md',
            'model' => 'opus',
            'timeout_minutes' => 120,
        ],
    ];

    /**
     * Get the base path for skill files.
     */
    protected function getBasePath(): string
    {
        return storage_path('app/compound-engineering/skills');
    }

    /**
     * Parse a skill invocation from a prompt string.
     *
     * @param  string  $prompt  The user prompt (e.g., "/workflows:plan Add authentication")
     * @return array{skill: string|null, args: string|null, matched: bool}
     */
    public function parseInvocation(string $prompt): array
    {
        $prompt = trim($prompt);

        // Check for slash command pattern
        if (! Str::startsWith($prompt, '/')) {
            return ['skill' => null, 'args' => null, 'matched' => false];
        }

        // Extract the command and arguments
        $parts = preg_split('/\s+/', $prompt, 2);
        $command = ltrim($parts[0] ?? '', '/');
        $args = $parts[1] ?? null;

        // Check if it's a known skill
        if (array_key_exists($command, $this->skillConfigs)) {
            return ['skill' => $command, 'args' => $args, 'matched' => true];
        }

        return ['skill' => null, 'args' => null, 'matched' => false];
    }

    /**
     * Check if a prompt contains a skill invocation.
     */
    public function isSkillInvocation(string $prompt): bool
    {
        return $this->parseInvocation($prompt)['matched'];
    }

    /**
     * Get all available skill names.
     *
     * @return array<string>
     */
    public function availableSkills(): array
    {
        return array_keys($this->skillConfigs);
    }

    /**
     * Check if a skill exists and has a loadable file.
     */
    public function skillExists(string $skillName): bool
    {
        if (! array_key_exists($skillName, $this->skillConfigs)) {
            return false;
        }

        $path = $this->getSkillPath($skillName);

        return $path && File::exists($path);
    }

    /**
     * Get the full path to a skill file.
     */
    public function getSkillPath(string $skillName): ?string
    {
        $config = $this->skillConfigs[$skillName] ?? null;

        if (! $config) {
            return null;
        }

        return $this->getBasePath().'/'.$config['path'];
    }

    /**
     * Load a skill definition.
     *
     * @return array{
     *   name: string,
     *   system_prompt: string,
     *   model: string,
     *   timeout_minutes: int,
     *   allowed_tools: array<string>,
     *   config: array<string, mixed>
     * }|null
     */
    public function loadSkill(string $skillName): ?array
    {
        $config = $this->skillConfigs[$skillName] ?? null;

        if (! $config) {
            Log::warning('CompoundEngineeringSkillLoader: Unknown skill', ['skill' => $skillName]);

            return null;
        }

        $path = $this->getSkillPath($skillName);

        if (! $path || ! File::exists($path)) {
            Log::warning('CompoundEngineeringSkillLoader: Skill file not found', [
                'skill' => $skillName,
                'path' => $path,
            ]);

            return null;
        }

        $content = File::get($path);

        // Parse the skill content for any embedded configuration
        $parsedConfig = $this->parseSkillContent($content);

        return [
            'name' => $skillName,
            'system_prompt' => $content,
            'model' => $config['model'],
            'timeout_minutes' => $config['timeout_minutes'],
            'allowed_tools' => $this->getAllowedToolsForSkill($skillName),
            'config' => array_merge($config, $parsedConfig),
        ];
    }

    /**
     * Build a complete system prompt for a skill invocation.
     *
     * Combines the skill definition with project context and arguments.
     */
    public function buildSystemPrompt(string $skillName, ?string $args = null, array $context = []): ?string
    {
        $skill = $this->loadSkill($skillName);

        if (! $skill) {
            return null;
        }

        $systemPrompt = $skill['system_prompt'];

        // Add project context if available
        if (! empty($context['project'])) {
            $projectContext = $this->formatProjectContext($context['project']);
            $systemPrompt = "## Project Context\n\n{$projectContext}\n\n---\n\n".$systemPrompt;
        }

        // Add any additional context
        if (! empty($context['additional'])) {
            $systemPrompt .= "\n\n## Additional Context\n\n".$context['additional'];
        }

        return $systemPrompt;
    }

    /**
     * Build the initial user prompt for a skill invocation.
     */
    public function buildUserPrompt(string $skillName, ?string $args = null): string
    {
        if (empty($args)) {
            return "Execute the /{$skillName} workflow.";
        }

        return $args;
    }

    /**
     * Get the recommended model for a skill.
     *
     * @return 'opus'|'sonnet'|'haiku'
     */
    public function getRecommendedModel(string $skillName): string
    {
        $config = $this->skillConfigs[$skillName] ?? null;

        return $config['model'] ?? 'sonnet';
    }

    /**
     * Get the timeout in minutes for a skill.
     */
    public function getTimeoutMinutes(string $skillName): int
    {
        $config = $this->skillConfigs[$skillName] ?? null;

        return $config['timeout_minutes'] ?? 60;
    }

    /**
     * Get allowed tools for a specific skill.
     *
     * Different skills may require different tool permissions.
     *
     * @return array<string>
     */
    protected function getAllowedToolsForSkill(string $skillName): array
    {
        // Base tools available to all skills
        $baseTools = [
            'Read',
            'Glob',
            'Grep',
            'Bash',
            'Write',
            'Edit',
            'MultiEdit',
            'NotebookEdit',
            'TodoWrite',
            'AskUserQuestion',
            'WebSearch',
            'WebFetch',
        ];

        // Add skill-specific tools
        return match ($skillName) {
            'workflows:plan' => [
                ...$baseTools,
                'Task', // Can spawn sub-agents for research
            ],
            'workflows:work' => [
                ...$baseTools,
                'Task', // Can spawn review agents
            ],
            'workflows:review' => [
                ...$baseTools,
                'Task', // Can spawn specialized reviewers
            ],
            'lfg' => [
                ...$baseTools,
                'Task', // Full access
            ],
            default => $baseTools,
        };
    }

    /**
     * Parse skill content for embedded configuration.
     *
     * Skills may have YAML frontmatter with configuration.
     *
     * @return array<string, mixed>
     */
    protected function parseSkillContent(string $content): array
    {
        // Check for YAML frontmatter
        if (! Str::startsWith($content, '---')) {
            return [];
        }

        // Find end of frontmatter
        $endPos = strpos($content, '---', 3);
        if ($endPos === false) {
            return [];
        }

        $frontmatter = substr($content, 3, $endPos - 3);

        try {
            return \Symfony\Component\Yaml\Yaml::parse($frontmatter) ?? [];
        } catch (\Exception $e) {
            Log::warning('CompoundEngineeringSkillLoader: Failed to parse frontmatter', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Format project context for inclusion in system prompt.
     *
     * @param  array<string, mixed>  $project
     */
    protected function formatProjectContext(array $project): string
    {
        $lines = [];

        if (isset($project['name'])) {
            $lines[] = "**Project Name:** {$project['name']}";
        }

        if (isset($project['github_repo'])) {
            $lines[] = "**GitHub Repository:** {$project['github_repo']}";
        }

        if (isset($project['description'])) {
            $lines[] = "**Description:** {$project['description']}";
        }

        if (isset($project['tech_stack']) && is_array($project['tech_stack'])) {
            $lines[] = '**Tech Stack:** '.implode(', ', $project['tech_stack']);
        }

        return implode("\n", $lines);
    }

    /**
     * Sync skill files from the Compound Engineering Plugin repository.
     *
     * This should be called during deployment or via artisan command.
     *
     * @param  string  $sourceDir  The directory containing skill files
     * @return array{synced: int, errors: array<string>}
     */
    public function syncFromSource(string $sourceDir): array
    {
        $result = ['synced' => 0, 'errors' => []];
        $basePath = $this->getBasePath();

        // Ensure base directory exists
        if (! File::isDirectory($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }

        foreach ($this->skillConfigs as $skillName => $config) {
            $sourcePath = $sourceDir.'/'.$config['path'];
            $destPath = $basePath.'/'.$config['path'];

            if (! File::exists($sourcePath)) {
                $result['errors'][] = "Source not found: {$sourcePath}";

                continue;
            }

            try {
                // Ensure destination directory exists
                $destDir = dirname($destPath);
                if (! File::isDirectory($destDir)) {
                    File::makeDirectory($destDir, 0755, true);
                }

                File::copy($sourcePath, $destPath);
                $result['synced']++;

                Log::info('CompoundEngineeringSkillLoader: Synced skill', [
                    'skill' => $skillName,
                    'path' => $destPath,
                ]);
            } catch (\Exception $e) {
                $result['errors'][] = "Failed to sync {$skillName}: ".$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get a summary of skill availability.
     *
     * @return array<string, array{available: bool, path: string|null, model: string}>
     */
    public function getSkillStatus(): array
    {
        $status = [];

        foreach ($this->skillConfigs as $skillName => $config) {
            $path = $this->getSkillPath($skillName);

            $status[$skillName] = [
                'available' => $path && File::exists($path),
                'path' => $config['path'],
                'model' => $config['model'],
                'timeout_minutes' => $config['timeout_minutes'],
            ];
        }

        return $status;
    }
}
