<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manages sandboxed execution environments for agents.
 *
 * Provides:
 * - Filesystem isolation (agents only access designated directories)
 * - Network isolation (allowlisted domains only)
 * - Credential separation (minimal secrets per task)
 * - Cleanup and audit trail
 */
class AgentSandbox
{
    /**
     * Base path for all sandbox workspaces.
     */
    protected string $basePath;

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
        'storage/app/vault/*',
        'config/*.php',
    ];

    /**
     * Default allowed domains for all agents.
     */
    protected array $defaultAllowedDomains = [
        'api.anthropic.com',
    ];

    public function __construct()
    {
        $this->basePath = config('agents.sandbox.base_path', storage_path('app/agent-workspaces'));
    }

    /**
     * Create a new sandbox workspace for an agent run.
     */
    public function create(AgentRun $run, ?Agent $agent = null): string
    {
        $sandboxPath = $this->basePath.'/'.$run->id;

        // Create directory structure
        File::ensureDirectoryExists($sandboxPath.'/workspace');
        File::ensureDirectoryExists($sandboxPath.'/output');
        File::ensureDirectoryExists($sandboxPath.'/temp');
        File::ensureDirectoryExists($sandboxPath.'/logs');

        // Create manifest
        $manifest = [
            'run_id' => $run->id,
            'agent_id' => $run->agent_id,
            'created_at' => now()->toIso8601String(),
            'allowed_domains' => $this->getAllowedDomains($agent),
            'blocked_patterns' => $this->blockedPatterns,
            'max_file_size_mb' => config('agents.sandbox.max_file_size_mb', 50),
        ];

        File::put(
            $sandboxPath.'/.sandbox-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        // Create .gitignore to prevent accidental commits
        File::put($sandboxPath.'/.gitignore', "*\n");

        Log::info('Sandbox created', [
            'run_id' => $run->id,
            'path' => $sandboxPath,
        ]);

        return $sandboxPath;
    }

    /**
     * Get the sandbox path for a run.
     */
    public function getPath(AgentRun $run): string
    {
        return $this->basePath.'/'.$run->id;
    }

    /**
     * Check if a sandbox exists for a run.
     */
    public function exists(AgentRun $run): bool
    {
        return File::isDirectory($this->getPath($run));
    }

    /**
     * Validate that a path is allowed within the sandbox.
     */
    public function isPathAllowed(string $sandboxPath, string $targetPath): bool
    {
        $realSandbox = realpath($sandboxPath);
        $realTarget = realpath($targetPath) ?: $targetPath;

        // Must be within sandbox
        if (! $realSandbox || ! Str::startsWith($realTarget, $realSandbox)) {
            return false;
        }

        // Check blocked patterns
        $relativePath = Str::after($realTarget, $realSandbox);
        foreach ($this->blockedPatterns as $pattern) {
            if (fnmatch($pattern, $relativePath, FNM_CASEFOLD) ||
                fnmatch($pattern, basename($relativePath), FNM_CASEFOLD)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a file write operation.
     */
    public function validateWrite(string $sandboxPath, string $targetPath, int $sizeBytes): bool
    {
        if (! $this->isPathAllowed($sandboxPath, $targetPath)) {
            Log::warning('Sandbox write blocked: path not allowed', [
                'sandbox' => $sandboxPath,
                'target' => $targetPath,
            ]);

            return false;
        }

        // Must write to output or temp only
        $outputPath = $sandboxPath.'/output';
        $tempPath = $sandboxPath.'/temp';

        if (! Str::startsWith($targetPath, $outputPath) && ! Str::startsWith($targetPath, $tempPath)) {
            Log::warning('Sandbox write blocked: must write to output or temp', [
                'target' => $targetPath,
            ]);

            return false;
        }

        // Check file size limit
        $maxBytes = config('agents.sandbox.max_file_size_mb', 50) * 1024 * 1024;
        if ($sizeBytes > $maxBytes) {
            Log::warning('Sandbox write blocked: file too large', [
                'size_bytes' => $sizeBytes,
                'max_bytes' => $maxBytes,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Get allowed domains for an agent.
     */
    public function getAllowedDomains(?Agent $agent = null): array
    {
        $domains = $this->defaultAllowedDomains;

        // Add default domains from config
        $configDefaults = config('agents.domains._default', []);
        $domains = array_merge($domains, $configDefaults);

        // Add agent-specific domains
        if ($agent) {
            $agentDomains = config("agents.domains.{$agent->slug}", []);
            $domains = array_merge($domains, $agentDomains);
        }

        return array_unique($domains);
    }

    /**
     * Validate a URL is allowed for network access.
     */
    public function isUrlAllowed(string $url, ?Agent $agent = null): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return false;
        }

        $allowedDomains = $this->getAllowedDomains($agent);

        foreach ($allowedDomains as $domain) {
            if ($host === $domain || Str::endsWith($host, '.'.$domain)) {
                return true;
            }
        }

        Log::warning('Network access blocked: domain not allowed', [
            'url' => $url,
            'host' => $host,
            'allowed' => $allowedDomains,
        ]);

        return false;
    }

    /**
     * Make an HTTP request through the sandbox (validates domain).
     */
    public function httpGet(string $url, ?Agent $agent = null): ?string
    {
        if (! $this->isUrlAllowed($url, $agent)) {
            throw new \Exception('Domain not allowed: '.parse_url($url, PHP_URL_HOST));
        }

        $response = Http::timeout(30)->get($url);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    /**
     * Get environment variables for sandboxed execution.
     */
    public function getEnvironment(AgentRun $run, array $secrets = []): array
    {
        $sandboxPath = $this->getPath($run);

        $env = [
            // Sandbox paths
            'SANDBOX_PATH' => $sandboxPath,
            'SANDBOX_WORKSPACE' => $sandboxPath.'/workspace',
            'SANDBOX_OUTPUT' => $sandboxPath.'/output',
            'SANDBOX_TEMP' => $sandboxPath.'/temp',

            // Override dangerous paths
            'HOME' => $sandboxPath,
            'TMPDIR' => $sandboxPath.'/temp',
            'TMP' => $sandboxPath.'/temp',
            'TEMP' => $sandboxPath.'/temp',

            // Prevent credential leakage
            'AWS_ACCESS_KEY_ID' => '',
            'AWS_SECRET_ACCESS_KEY' => '',
            'GITHUB_TOKEN' => '',
        ];

        // Add scoped secrets
        foreach ($secrets as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * Copy files into the sandbox workspace.
     */
    public function copyIn(AgentRun $run, string $sourcePath, ?string $destName = null): string
    {
        $sandboxPath = $this->getPath($run);
        $destName = $destName ?? basename($sourcePath);
        $destPath = $sandboxPath.'/workspace/'.$destName;

        // Validate source isn't blocked
        foreach ($this->blockedPatterns as $pattern) {
            if (fnmatch($pattern, $sourcePath, FNM_CASEFOLD) ||
                fnmatch($pattern, basename($sourcePath), FNM_CASEFOLD)) {
                throw new \Exception("Cannot copy blocked file pattern: {$sourcePath}");
            }
        }

        if (File::isDirectory($sourcePath)) {
            File::copyDirectory($sourcePath, $destPath);
        } else {
            File::copy($sourcePath, $destPath);
        }

        Log::info('Copied file into sandbox', [
            'run_id' => $run->id,
            'source' => $sourcePath,
            'dest' => $destPath,
        ]);

        return $destPath;
    }

    /**
     * Get output files from the sandbox.
     */
    public function getOutputFiles(AgentRun $run): array
    {
        $outputPath = $this->getPath($run).'/output';

        if (! File::isDirectory($outputPath)) {
            return [];
        }

        return File::allFiles($outputPath);
    }

    /**
     * Get logs from the sandbox.
     */
    public function getLogs(AgentRun $run): ?string
    {
        $logPath = $this->getPath($run).'/logs/agent.log';

        if (File::exists($logPath)) {
            return File::get($logPath);
        }

        return null;
    }

    /**
     * Clean up a sandbox (optionally preserve output).
     */
    public function cleanup(AgentRun $run, bool $preserveOutput = true): void
    {
        $sandboxPath = $this->getPath($run);

        if (! File::isDirectory($sandboxPath)) {
            return;
        }

        if ($preserveOutput) {
            // Archive output before cleanup
            $this->archiveOutput($run);

            // Only remove workspace and temp
            File::deleteDirectory($sandboxPath.'/workspace');
            File::deleteDirectory($sandboxPath.'/temp');
        } else {
            File::deleteDirectory($sandboxPath);
        }

        Log::info('Sandbox cleaned', [
            'run_id' => $run->id,
            'preserved_output' => $preserveOutput,
        ]);
    }

    /**
     * Archive sandbox output to permanent storage.
     */
    protected function archiveOutput(AgentRun $run): void
    {
        $outputPath = $this->getPath($run).'/output';
        $archivePath = storage_path("app/agent-archives/{$run->id}");

        if (File::isDirectory($outputPath) && count(File::files($outputPath)) > 0) {
            File::ensureDirectoryExists($archivePath);
            File::copyDirectory($outputPath, $archivePath);
        }
    }

    /**
     * Clean up old sandboxes.
     */
    public function cleanupOld(): int
    {
        $maxAgeHours = config('agents.sandbox.cleanup_after_hours', 24);
        $cutoff = now()->subHours($maxAgeHours);
        $cleaned = 0;

        if (! File::isDirectory($this->basePath)) {
            return 0;
        }

        $directories = File::directories($this->basePath);

        foreach ($directories as $dir) {
            $manifestPath = $dir.'/.sandbox-manifest.json';

            if (! File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true);
            $createdAt = $manifest['created_at'] ?? null;

            if ($createdAt && now()->parse($createdAt)->lt($cutoff)) {
                File::deleteDirectory($dir);
                $cleaned++;
            }
        }

        Log::info('Cleaned old sandboxes', ['count' => $cleaned]);

        return $cleaned;
    }

    /**
     * Get sandbox statistics.
     */
    public function getStats(): array
    {
        if (! File::isDirectory($this->basePath)) {
            return [
                'total_sandboxes' => 0,
                'total_size_mb' => 0,
            ];
        }

        $directories = File::directories($this->basePath);
        $totalSize = 0;

        foreach ($directories as $dir) {
            $totalSize += $this->getDirectorySize($dir);
        }

        return [
            'total_sandboxes' => count($directories),
            'total_size_mb' => round($totalSize / (1024 * 1024), 2),
            'base_path' => $this->basePath,
        ];
    }

    /**
     * Get directory size in bytes.
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;

        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
