<?php

namespace App\Agents;

use App\Agents\Tools\Tool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ReflectionClass;

/**
 * Registry for all available tools.
 *
 * Auto-discovers tools from the Tools directory.
 * Provides execution with validation and logging.
 */
class ToolRegistry
{
    protected array $tools = [];

    protected bool $discovered = false;

    /**
     * Get all registered tools.
     *
     * @return Tool[]
     */
    public function all(): array
    {
        $this->discover();

        return $this->tools;
    }

    /**
     * Get a tool by ID.
     */
    public function get(string $id): ?Tool
    {
        $this->discover();

        return $this->tools[$id] ?? null;
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Execute a tool with parameters.
     *
     * Handles validation, logging, error handling, and automatic retry with exponential backoff.
     */
    public function execute(string $toolId, array $params): array
    {
        $tool = $this->get($toolId);

        if (! $tool) {
            throw new \InvalidArgumentException("Tool not found: {$toolId}");
        }

        // Validate parameters
        $validatedParams = $tool->validate($params);

        // Get retry configuration from tool or use defaults
        $maxRetries = $this->getMaxRetries($tool);
        $retryableExceptions = $this->getRetryableExceptions($tool);

        Log::info('Executing tool', [
            'tool' => $toolId,
            'params' => $validatedParams,
            'max_retries' => $maxRetries,
        ]);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $result = $tool->execute($validatedParams);

                Log::info('Tool executed successfully', [
                    'tool' => $toolId,
                    'result_keys' => array_keys($result),
                    'attempt' => $attempt + 1,
                ]);

                return [
                    'success' => true,
                    'tool' => $toolId,
                    'result' => $result,
                    'attempts' => $attempt + 1,
                ];

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                // Check if this exception is retryable
                if (! $this->isRetryable($e, $retryableExceptions)) {
                    Log::error('Tool execution failed (non-retryable)', [
                        'tool' => $toolId,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                    ]);
                    break;
                }

                if ($attempt <= $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s, 8s...
                    $delay = min(pow(2, $attempt - 1), 30); // Cap at 30 seconds
                    Log::warning('Tool execution failed, retrying', [
                        'tool' => $toolId,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_seconds' => $delay,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($delay);
                }
            }
        }

        Log::error('Tool execution failed after all retries', [
            'tool' => $toolId,
            'attempts' => $attempt,
            'error' => $lastException?->getMessage(),
        ]);

        return [
            'success' => false,
            'tool' => $toolId,
            'error' => $lastException?->getMessage() ?? 'Unknown error',
            'attempts' => $attempt,
        ];
    }

    /**
     * Get maximum retry attempts for a tool.
     */
    protected function getMaxRetries(Tool $tool): int
    {
        // Check if tool has Retryable trait or maxRetries method
        if (method_exists($tool, 'maxRetries')) {
            return $tool->maxRetries();
        }

        // Default: no retries for most tools
        return 0;
    }

    /**
     * Get list of retryable exception classes for a tool.
     */
    protected function getRetryableExceptions(Tool $tool): array
    {
        if (method_exists($tool, 'retryableExceptions')) {
            return $tool->retryableExceptions();
        }

        // Default retryable exceptions (network/timeout related)
        return [
            \Illuminate\Http\Client\ConnectionException::class,
            \Illuminate\Http\Client\RequestException::class,
            \GuzzleHttp\Exception\ConnectException::class,
            \GuzzleHttp\Exception\ServerException::class,
        ];
    }

    /**
     * Check if an exception is retryable.
     */
    protected function isRetryable(\Exception $e, array $retryableExceptions): bool
    {
        foreach ($retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        // Also retry on common transient error messages
        $message = strtolower($e->getMessage());
        $transientPatterns = [
            'timeout',
            'timed out',
            'connection refused',
            'connection reset',
            'temporarily unavailable',
            'service unavailable',
            'too many requests',
            'rate limit',
            '503',
            '502',
            '504',
            'etimedout',
            'econnrefused',
            'econnreset',
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get tools formatted for Anthropic API.
     *
     * @return array[]
     */
    public function toAnthropicTools(): array
    {
        // array_values ensures numeric indices (JSON array, not object)
        return array_values(array_map(
            fn (Tool $tool) => $tool->toAnthropicTool(),
            $this->all()
        ));
    }

    /**
     * Get tools as array for API.
     */
    public function toArray(): array
    {
        return array_map(
            fn (Tool $tool) => $tool->toArray(),
            $this->all()
        );
    }

    /**
     * Register a tool manually.
     */
    public function register(Tool $tool): void
    {
        $this->tools[$tool->id()] = $tool;
    }

    /**
     * Discover tools from the Tools directory and subdirectories.
     */
    protected function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $toolsPath = app_path('Agents/Tools');

        if (! File::isDirectory($toolsPath)) {
            $this->discovered = true;

            return;
        }

        // Discover tools in root directory
        $this->discoverInDirectory($toolsPath, 'App\\Agents\\Tools');

        // Discover tools in subdirectories (e.g., Tools/Ollie/)
        $directories = File::directories($toolsPath);
        foreach ($directories as $directory) {
            $subNamespace = 'App\\Agents\\Tools\\'.basename($directory);
            $this->discoverInDirectory($directory, $subNamespace);
        }

        $this->discovered = true;
    }

    /**
     * Discover tools in a specific directory.
     */
    protected function discoverInDirectory(string $path, string $namespace): void
    {
        $files = File::files($path);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $file->getFilenameWithoutExtension();

            // Skip interface and base class
            if (in_array($className, ['Tool', 'BaseTool'])) {
                continue;
            }

            $fullClassName = "{$namespace}\\{$className}";

            if (! class_exists($fullClassName)) {
                continue;
            }

            $reflection = new ReflectionClass($fullClassName);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->implementsInterface(Tool::class)) {
                continue;
            }

            $tool = app($fullClassName);
            $this->tools[$tool->id()] = $tool;
        }
    }
}
