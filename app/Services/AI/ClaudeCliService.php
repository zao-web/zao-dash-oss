<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Claude CLI Service - Uses Claude Code CLI for AI calls.
 *
 * This service uses the Claude CLI (`claude` command) instead of direct API calls.
 * When CLAUDE_CODE_OAUTH_TOKEN is set, it uses your Max subscription instead of API billing.
 *
 * Benefits:
 * - Uses Max subscription (no per-token API costs)
 * - Consistent interface across all AI features
 * - Supports all Claude models
 */
class ClaudeCliService
{
    protected string $defaultModel = 'sonnet';

    protected int $defaultTimeout = 120;

    protected int $maxTimeout = 600;

    /**
     * Send a message and get a response.
     */
    public function message(
        string $prompt,
        ?string $systemPrompt = null,
        ?string $model = null,
        int $maxTokens = 4096,
        int $timeout = 120
    ): array {
        // Check if trying to use OAuth but CLI isn't available
        $oauthToken = config('services.anthropic.oauth_token') ?: env('CLAUDE_CODE_OAUTH_TOKEN');
        if ($oauthToken) {
            $cliCheck = Process::run('which claude');
            if (! $cliCheck->successful() || empty(trim($cliCheck->output()))) {
                throw new \RuntimeException(
                    'Claude CLI not available on this server. Please install Claude Code CLI or use ANTHROPIC_API_KEY instead of CLAUDE_CODE_OAUTH_TOKEN.'
                );
            }
        }

        $command = $this->buildCommand($model, $systemPrompt);
        $env = $this->buildEnv();

        Log::debug('ClaudeCliService: executing', [
            'model' => $model ?? $this->defaultModel,
            'prompt_length' => strlen($prompt),
            'has_system_prompt' => ! empty($systemPrompt),
            'has_oauth' => isset($env['CLAUDE_CODE_OAUTH_TOKEN']),
        ]);

        $startedAt = microtime(true);

        $result = Process::timeout(min($timeout, $this->maxTimeout))
            ->env($env)
            ->input($prompt)
            ->run($command);

        $runtimeMs = (int) round((microtime(true) - $startedAt) * 1000);
        $stdout = $result->output();
        $stderr = $result->errorOutput();
        $cliDiagnostics = $this->extractCliDiagnostics($stdout);

        // Check for org/auth errors in stdout too (CLI sometimes returns 0 with error in output)
        $combinedOutput = $stdout.$stderr;
        $hasOrgError = str_contains($combinedOutput, 'Unable to resolve organization')
            || str_contains($combinedOutput, 'organization UUID');

        if ($result->successful() && $hasOrgError) {
            // CLI returned 0 but with an org error — treat as failure
            Log::warning('ClaudeCliService: CLI returned success but with org error in output');
            $result = new class($result)
            {
                private $inner;

                public function __construct($inner)
                {
                    $this->inner = $inner;
                }

                public function successful(): bool
                {
                    return false;
                }

                public function exitCode(): int
                {
                    return 1;
                }

                public function output(): string
                {
                    return $this->inner->output();
                }

                public function errorOutput(): string
                {
                    return $this->inner->errorOutput();
                }
            };
        }

        if (! $result->successful()) {
            $wasUsingOAuth = ! empty($env['CLAUDE_CODE_OAUTH_TOKEN']);
            $hasApiKeyFallback = ! empty(config('services.anthropic.api_key'));

            // Auto-fallback: if OAuth was in use and API key is available, always retry
            // Don't try to guess the error type — any OAuth failure should fallback
            if ($wasUsingOAuth && $hasApiKeyFallback) {
                Log::warning('ClaudeCliService: OAuth token failed, falling back to ANTHROPIC_API_KEY', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => substr($stderr, 0, 500),
                    'runtime_ms' => $runtimeMs,
                ]);

                $fallbackEnv = [
                    'ANTHROPIC_API_KEY' => config('services.anthropic.api_key'),
                    'CLAUDE_CODE_OAUTH_TOKEN' => false, // false removes from env entirely
                    'CLAUDECODE' => false,
                ];

                $fallbackResult = Process::timeout(min($timeout, $this->maxTimeout))
                    ->env($fallbackEnv)
                    ->input($prompt)
                    ->run($command);

                if ($fallbackResult->successful()) {
                    Log::info('ClaudeCliService: API key fallback succeeded');

                    return $this->parseResponse($fallbackResult->output());
                }

                $stderr = $fallbackResult->errorOutput();
            }

            Log::error('ClaudeCliService: CLI failed', [
                'exit_code' => $result->exitCode(),
                'command' => $this->buildCommand($model),
                'runtime_ms' => $runtimeMs,
                'stdout_length' => strlen($stdout),
                'stderr_length' => strlen($stderr),
                'cli_diagnostics' => $cliDiagnostics,
                'stdout' => substr($stdout, 0, 1000),
                'stderr' => substr($stderr, 0, 1000),
            ]);

            throw new \RuntimeException(
                'Claude CLI failed: '.$stderr
            );
        }

        Log::debug('ClaudeCliService: CLI succeeded', [
            'runtime_ms' => $runtimeMs,
            'output_length' => strlen($stdout),
            'stderr_length' => strlen($stderr),
            'cli_diagnostics' => $cliDiagnostics,
            'output_preview' => substr($stdout, 0, 200),
            'output_tail_preview' => substr($stdout, -200),
        ]);

        return $this->parseResponse($stdout);
    }

    /**
     * Send a message and get raw text response.
     */
    public function messageText(
        string $prompt,
        ?string $systemPrompt = null,
        ?string $model = null,
        int $timeout = 120
    ): string {
        $response = $this->message($prompt, $systemPrompt, $model, 4096, $timeout);

        return $this->extractText($response);
    }

    /**
     * Send a message and get JSON response.
     */
    public function messageJson(
        string $prompt,
        ?string $systemPrompt = null,
        ?string $model = null,
        int $timeout = 120
    ): ?array {
        // Append JSON instruction to system prompt
        $jsonSystemPrompt = ($systemPrompt ?? '')."\n\nIMPORTANT: Return ONLY valid JSON, no markdown code blocks or explanation.";

        $response = $this->message($prompt, $jsonSystemPrompt, $model, 4096, $timeout);
        $text = $this->extractText($response);

        return $this->parseJsonFromText($text);
    }

    /**
     * Check if the CLI is available and configured.
     */
    public function isConfigured(): bool
    {
        // Check if OAuth token or API key is available
        $hasOAuth = ! empty(config('services.anthropic.oauth_token'))
            || ! empty(env('CLAUDE_CODE_OAUTH_TOKEN'));
        $hasApiKey = ! empty(config('services.anthropic.api_key'));

        if (! $hasOAuth && ! $hasApiKey) {
            return false;
        }

        // Check if claude CLI exists
        $result = Process::run('which claude');

        return $result->successful() && ! empty(trim($result->output()));
    }

    /**
     * Build the CLI command.
     */
    protected function buildCommand(?string $model, ?string $systemPrompt = null): string
    {
        $modelArg = $this->resolveModelName($model ?? $this->defaultModel);

        $parts = [
            'claude',
            '--print',
            '--output-format json',
            "--model {$modelArg}",
            '--tools ""',
            '--no-session-persistence',
        ];

        if ($systemPrompt) {
            $parts[] = '--system-prompt '.escapeshellarg($systemPrompt);
        }

        return implode(' ', $parts);
    }

    /**
     * Resolve model names to CLI-compatible identifiers.
     * Handles shorthand names (haiku, sonnet, opus) and maps deprecated model IDs.
     */
    protected function resolveModelName(string $model): string
    {
        // Map deprecated/old model IDs to current shorthand
        // The Claude CLI resolves shorthand names to the latest version automatically
        return match (true) {
            str_contains($model, 'haiku') => 'haiku',
            str_contains($model, 'sonnet') => 'sonnet',
            str_contains($model, 'opus') => 'opus',
            default => $model,
        };
    }

    /**
     * Build environment variables for CLI execution.
     */
    protected function buildEnv(): array
    {
        $env = [];

        // Prefer OAuth token (Max subscription) over API key
        $oauthToken = config('services.anthropic.oauth_token') ?: env('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = config('services.anthropic.api_key');

        if ($oauthToken) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $oauthToken;
            // Explicitly unset API key to prevent inheritance from server environment
            $env['ANTHROPIC_API_KEY'] = '';
        } elseif ($apiKey) {
            $env['ANTHROPIC_API_KEY'] = $apiKey;
        }

        // Unset CLAUDECODE env var so spawned CLI doesn't refuse to run
        // when invoked from within a Claude Code terminal session.
        // Must use false (not '') — Symfony Process only removes inherited
        // env vars when set to false; empty string still counts as "exists".
        $env['CLAUDECODE'] = false;

        return $env;
    }

    /**
     * Parse the CLI JSON output.
     */
    protected function parseResponse(string $output): array
    {
        $lines = explode("\n", trim($output));
        $cliDiagnostics = $this->extractCliDiagnostics($output);

        // Try to find JSON in output
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        Log::warning('ClaudeCliService: Failed to parse CLI response envelope', [
            'line_count' => count($lines),
            'output_length' => strlen($output),
            'cli_diagnostics' => $cliDiagnostics,
            'output_preview' => substr($output, 0, 500),
            'output_tail_preview' => substr($output, -500),
        ]);

        return [
            'result' => $output,
            'parsed' => false,
        ];
    }

    /**
     * Extract text content from CLI response.
     */
    protected function extractText(array $response): string
    {
        if (isset($response['result'])) {
            return $response['result'];
        }

        if (isset($response['content'])) {
            if (is_string($response['content'])) {
                return $response['content'];
            }
            if (is_array($response['content'])) {
                $text = '';
                foreach ($response['content'] as $block) {
                    if (is_string($block)) {
                        $text .= $block;
                    } elseif (isset($block['text'])) {
                        $text .= $block['text'];
                    }
                }

                return $text;
            }
        }

        return json_encode($response);
    }

    /**
     * Parse JSON from text, handling markdown code blocks.
     */
    protected function parseJsonFromText(string $text): ?array
    {
        $text = trim($text);

        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $matches)) {
            $text = trim($matches[1]);
        }

        // Try to find JSON object or array
        if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/', $text, $matches)) {
            $text = $matches[1];
        }

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('ClaudeCliService: Failed to parse JSON', [
                'text' => substr($text, 0, 500),
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * Extract CLI metadata (turn count, API duration, etc.) from raw JSON output.
     */
    protected function extractCliDiagnostics(string $output): array
    {
        $decoded = $this->decodeCliJsonEnvelope($output);

        if (! is_array($decoded)) {
            return [
                'json_envelope_detected' => false,
            ];
        }

        $diagnostics = [
            'json_envelope_detected' => true,
        ];

        foreach (['num_turns', 'duration_api_ms', 'duration_ms', 'total_cost_usd', 'model'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $diagnostics[$key] = $decoded[$key];
            }
        }

        if (array_key_exists('result', $decoded)) {
            $diagnostics['has_result'] = true;
            $diagnostics['result_type'] = gettype($decoded['result']);
        }

        if (array_key_exists('content', $decoded)) {
            $diagnostics['has_content'] = true;
            $diagnostics['content_type'] = gettype($decoded['content']);
        }

        return $diagnostics;
    }

    /**
     * Decode the Claude CLI JSON envelope from stdout.
     */
    protected function decodeCliJsonEnvelope(string $output): ?array
    {
        $trimmed = trim($output);

        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        foreach (array_reverse(explode("\n", $trimmed)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (! str_starts_with($line, '{') && ! str_starts_with($line, '[')) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
