<?php

namespace App\Services\Symphony;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class WorkspaceManager
{
    /**
     * @return array{path: string, workspace_key: string, created_now: bool}
     */
    public function createForIssue(string $issueIdentifier, WorkflowConfig $config): array
    {
        $root = $this->ensureWorkspaceRoot($config->workspaceRoot());
        $workspaceKey = $this->sanitizeWorkspaceKey($issueIdentifier);
        $workspacePath = $root.DIRECTORY_SEPARATOR.$workspaceKey;

        if (File::exists($workspacePath) && ! File::isDirectory($workspacePath)) {
            throw new \RuntimeException("Workspace path exists but is not a directory: {$workspacePath}");
        }

        $createdNow = false;
        if (! File::isDirectory($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
            $createdNow = true;
        }

        $this->assertInsideWorkspaceRoot($root, $workspacePath);

        if ($createdNow && $config->hookAfterCreate()) {
            $this->runHook(
                hookName: 'after_create',
                script: $config->hookAfterCreate(),
                workspacePath: $workspacePath,
                timeoutMs: $config->hookTimeoutMs(),
                failOnError: true
            );
        }

        return [
            'path' => $workspacePath,
            'workspace_key' => $workspaceKey,
            'created_now' => $createdNow,
        ];
    }

    public function runBeforeRun(string $workspacePath, WorkflowConfig $config): void
    {
        $this->assertInsideWorkspaceRoot($config->workspaceRoot(), $workspacePath);

        if (! $config->hookBeforeRun()) {
            return;
        }

        $this->runHook(
            hookName: 'before_run',
            script: $config->hookBeforeRun(),
            workspacePath: $workspacePath,
            timeoutMs: $config->hookTimeoutMs(),
            failOnError: true
        );
    }

    public function runAfterRun(string $workspacePath, WorkflowConfig $config): void
    {
        if (! File::isDirectory($workspacePath) || ! $config->hookAfterRun()) {
            return;
        }

        $this->runHook(
            hookName: 'after_run',
            script: $config->hookAfterRun(),
            workspacePath: $workspacePath,
            timeoutMs: $config->hookTimeoutMs(),
            failOnError: false
        );
    }

    public function cleanupForIssue(string $issueIdentifier, WorkflowConfig $config): void
    {
        $root = $this->ensureWorkspaceRoot($config->workspaceRoot());
        $workspacePath = $root.DIRECTORY_SEPARATOR.$this->sanitizeWorkspaceKey($issueIdentifier);

        if (! File::isDirectory($workspacePath)) {
            return;
        }

        $this->assertInsideWorkspaceRoot($root, $workspacePath);

        if ($config->hookBeforeRemove()) {
            $this->runHook(
                hookName: 'before_remove',
                script: $config->hookBeforeRemove(),
                workspacePath: $workspacePath,
                timeoutMs: $config->hookTimeoutMs(),
                failOnError: false
            );
        }

        File::deleteDirectory($workspacePath);
    }

    public function sanitizeWorkspaceKey(string $issueIdentifier): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $issueIdentifier) ?: 'issue';
    }

    protected function ensureWorkspaceRoot(string $root): string
    {
        if (! File::exists($root)) {
            File::makeDirectory($root, 0755, true);
        }

        if (! File::isDirectory($root)) {
            throw new \RuntimeException("Workspace root is not a directory: {$root}");
        }

        return realpath($root) ?: $root;
    }

    protected function assertInsideWorkspaceRoot(string $workspaceRoot, string $workspacePath): void
    {
        $absoluteRoot = realpath($workspaceRoot) ?: $workspaceRoot;
        $absoluteWorkspace = realpath($workspacePath) ?: $workspacePath;

        $absoluteRoot = rtrim($absoluteRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($absoluteWorkspace, $absoluteRoot)) {
            throw new \RuntimeException("Workspace path is outside workspace root: {$workspacePath}");
        }
    }

    protected function runHook(
        string $hookName,
        ?string $script,
        string $workspacePath,
        int $timeoutMs,
        bool $failOnError
    ): void {
        if (! $script) {
            return;
        }

        try {
            $result = Process::timeout($this->timeoutSeconds($timeoutMs))
                ->path($workspacePath)
                ->run(['bash', '-lc', $script]);
        } catch (ProcessTimedOutException $exception) {
            Log::warning('Symphony hook timed out', [
                'hook' => $hookName,
                'workspace_path' => $workspacePath,
                'timeout_ms' => $timeoutMs,
            ]);

            if ($failOnError) {
                throw new \RuntimeException("Hook '{$hookName}' timed out.", 0, $exception);
            }

            return;
        } catch (\Throwable $exception) {
            Log::warning('Symphony hook failed', [
                'hook' => $hookName,
                'workspace_path' => $workspacePath,
                'error' => $exception->getMessage(),
            ]);

            if ($failOnError) {
                throw new \RuntimeException("Hook '{$hookName}' failed.", 0, $exception);
            }

            return;
        }

        if ($result->successful()) {
            return;
        }

        Log::warning('Symphony hook exited with non-zero status', [
            'hook' => $hookName,
            'workspace_path' => $workspacePath,
            'exit_code' => $result->exitCode(),
            'stderr' => $result->errorOutput(),
        ]);

        if ($failOnError) {
            throw new \RuntimeException("Hook '{$hookName}' failed with exit code {$result->exitCode()}.");
        }
    }

    protected function timeoutSeconds(int $timeoutMs): int
    {
        return (int) max(1, ceil($timeoutMs / 1000));
    }
}
