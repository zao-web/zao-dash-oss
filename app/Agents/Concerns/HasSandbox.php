<?php

namespace App\Agents\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provides sandboxing functionality for agent execution.
 *
 * Implements two-layer isolation:
 * 1. Filesystem isolation - agents only access designated directories
 * 2. Network isolation - allowlisted domains only
 */
trait HasSandbox
{
    /**
     * The current sandbox workspace path.
     */
    protected ?string $sandboxPath = null;

    /**
     * Allowed filesystem paths (relative to sandbox root).
     */
    protected array $allowedPaths = [
        'workspace',
        'output',
        'temp',
    ];

    /**
     * Blocked filesystem patterns (never accessible).
     */
    protected array $blockedPatterns = [
        '*.env*',
        '*credentials*',
        '*secret*',
        '*.pem',
        '*.key',
        '*password*',
        '.git/config',
    ];

    /**
     * Allowed network domains for this agent.
     */
    protected array $allowedDomains = [
        'api.anthropic.com',
        'api.openai.com',
    ];

    /**
     * Create an isolated workspace for this agent run.
     */
    public function createSandbox(string $runId): string
    {
        $basePath = storage_path('app/agent-workspaces');
        $this->sandboxPath = $basePath.'/'.$runId;

        // Create sandbox directories
        File::ensureDirectoryExists($this->sandboxPath.'/workspace');
        File::ensureDirectoryExists($this->sandboxPath.'/output');
        File::ensureDirectoryExists($this->sandboxPath.'/temp');

        // Create sandbox manifest
        $manifest = [
            'run_id' => $runId,
            'agent_id' => $this->metadata()['id'] ?? 'unknown',
            'created_at' => now()->toIso8601String(),
            'allowed_paths' => $this->allowedPaths,
            'allowed_domains' => $this->getAllowedDomains(),
        ];

        File::put(
            $this->sandboxPath.'/.sandbox-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        Log::info('Sandbox created', [
            'run_id' => $runId,
            'path' => $this->sandboxPath,
        ]);

        return $this->sandboxPath;
    }

    /**
     * Get the sandbox path for the current run.
     */
    public function getSandboxPath(): ?string
    {
        return $this->sandboxPath;
    }

    /**
     * Check if a path is accessible within the sandbox.
     */
    public function isPathAllowed(string $path): bool
    {
        // Resolve to absolute path
        $realPath = realpath($path) ?: $path;

        // Must be within sandbox
        if ($this->sandboxPath && ! Str::startsWith($realPath, $this->sandboxPath)) {
            return false;
        }

        // Check blocked patterns
        foreach ($this->blockedPatterns as $pattern) {
            if (fnmatch($pattern, basename($path), FNM_CASEFOLD)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a file operation within the sandbox.
     */
    public function validateFileOperation(string $operation, string $path): bool
    {
        if (! $this->isPathAllowed($path)) {
            Log::warning('Sandbox violation: path not allowed', [
                'operation' => $operation,
                'path' => $path,
                'sandbox' => $this->sandboxPath,
            ]);

            return false;
        }

        // Additional checks for write operations
        if (in_array($operation, ['write', 'delete', 'move'])) {
            $outputPath = $this->sandboxPath.'/output';
            $tempPath = $this->sandboxPath.'/temp';

            // Writes only allowed to output or temp directories
            if (! Str::startsWith($path, $outputPath) && ! Str::startsWith($path, $tempPath)) {
                Log::warning('Sandbox violation: write outside allowed directories', [
                    'operation' => $operation,
                    'path' => $path,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Get allowed domains for network access.
     */
    public function getAllowedDomains(): array
    {
        // Merge default domains with agent-specific domains
        $agentDomains = config('agents.domains.'.($this->metadata()['id'] ?? ''), []);

        return array_unique(array_merge($this->allowedDomains, $agentDomains));
    }

    /**
     * Check if a URL is allowed for network access.
     */
    public function isUrlAllowed(string $url): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        foreach ($this->getAllowedDomains() as $domain) {
            // Exact match or subdomain match
            if ($host === $domain || Str::endsWith($host, '.'.$domain)) {
                return true;
            }
        }

        Log::warning('Sandbox violation: domain not allowed', [
            'url' => $url,
            'host' => $host,
            'allowed' => $this->getAllowedDomains(),
        ]);

        return false;
    }

    /**
     * Get environment variables for sandboxed execution.
     */
    public function getSandboxEnvironment(): array
    {
        return [
            'SANDBOX_PATH' => $this->sandboxPath,
            'SANDBOX_WORKSPACE' => $this->sandboxPath.'/workspace',
            'SANDBOX_OUTPUT' => $this->sandboxPath.'/output',
            'SANDBOX_TEMP' => $this->sandboxPath.'/temp',
            'SANDBOX_ALLOWED_DOMAINS' => implode(',', $this->getAllowedDomains()),
            // Never expose sensitive credentials
            'HOME' => $this->sandboxPath,
            'TMPDIR' => $this->sandboxPath.'/temp',
        ];
    }

    /**
     * Clean up the sandbox after execution.
     */
    public function cleanupSandbox(bool $preserveOutput = true): void
    {
        if (! $this->sandboxPath || ! File::isDirectory($this->sandboxPath)) {
            return;
        }

        if ($preserveOutput) {
            // Only clean workspace and temp, keep output
            File::deleteDirectory($this->sandboxPath.'/workspace');
            File::deleteDirectory($this->sandboxPath.'/temp');
        } else {
            // Clean everything
            File::deleteDirectory($this->sandboxPath);
        }

        Log::info('Sandbox cleaned', [
            'path' => $this->sandboxPath,
            'preserved_output' => $preserveOutput,
        ]);
    }

    /**
     * Copy files into the sandbox workspace.
     */
    public function copyToSandbox(string $sourcePath, ?string $destName = null): string
    {
        if (! $this->sandboxPath) {
            throw new \Exception('Sandbox not initialized');
        }

        $destName = $destName ?? basename($sourcePath);
        $destPath = $this->sandboxPath.'/workspace/'.$destName;

        if (File::isDirectory($sourcePath)) {
            File::copyDirectory($sourcePath, $destPath);
        } else {
            File::copy($sourcePath, $destPath);
        }

        return $destPath;
    }

    /**
     * Get files from the sandbox output directory.
     */
    public function getOutputFiles(): array
    {
        if (! $this->sandboxPath) {
            return [];
        }

        $outputPath = $this->sandboxPath.'/output';

        if (! File::isDirectory($outputPath)) {
            return [];
        }

        return File::allFiles($outputPath);
    }

    /**
     * Add allowed domains for this agent.
     */
    public function addAllowedDomains(array $domains): self
    {
        $this->allowedDomains = array_unique(array_merge($this->allowedDomains, $domains));

        return $this;
    }
}
