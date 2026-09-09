<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\InteractionRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Executes Claude CLI interactively with streaming output parsing.
 *
 * This runner uses Symfony's InputStream to pipe input to a running process
 * and streams output to detect tool_use blocks (particularly AskUserQuestion).
 * When interaction is needed, it creates a checkpoint and returns control
 * so the job can exit and free up the worker.
 */
class InteractiveClaudeRunner
{
    protected string $workspacesPath;

    protected int $defaultTimeoutSeconds = 3600; // 60 minutes for interactive sessions

    protected int $pollIntervalMicroseconds = 10000; // 10ms

    private ?Process $process = null;

    private ?InputStream $inputStream = null;

    private string $outputBuffer = '';

    private array $jsonEvents = [];

    private float $startTime = 0;

    public function __construct()
    {
        $this->workspacesPath = storage_path('app/agent-workspaces');
    }

    /**
     * Execute an agent and run until completion or interaction is needed.
     */
    public function execute(Agent $agent, AgentRun $run, array $config = []): InteractiveRunResult
    {
        $workspace = $this->createWorkspace($run);
        $this->startTime = microtime(true);

        try {
            $prompt = $this->buildPrompt($agent, $config);

            return $this->startAndRun($agent, $run, $prompt, $workspace, $config);
        } catch (\Throwable $e) {
            Log::error('Interactive agent execution failed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return InteractiveRunResult::failed(
                $e->getMessage(),
                $this->outputBuffer,
                $this->getElapsedTime()
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Resume an agent run from a checkpoint with a user response.
     */
    public function resume(AgentRun $run, string $response): InteractiveRunResult
    {
        $this->startTime = microtime(true);
        $checkpoint = $run->checkpoint;

        if (! $checkpoint) {
            return InteractiveRunResult::failed(
                'No checkpoint available to resume from',
                '',
                0
            );
        }

        $agent = $run->agent;
        $workspace = $checkpoint['workspace'] ?? $this->createWorkspace($run);

        try {
            // Rebuild context with the response included
            $config = $checkpoint['config'] ?? [];
            $config['resume_response'] = $response;
            $config['conversation_history'] = $checkpoint['conversation_history'] ?? [];

            $prompt = $this->buildResumePrompt($checkpoint, $response);

            return $this->startAndRun($agent, $run, $prompt, $workspace, $config);
        } catch (\Throwable $e) {
            Log::error('Interactive agent resume failed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return InteractiveRunResult::failed(
                $e->getMessage(),
                $this->outputBuffer,
                $this->getElapsedTime()
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Start the process and run until completion or interaction is needed.
     */
    protected function startAndRun(
        Agent $agent,
        AgentRun $run,
        string $prompt,
        string $workspace,
        array $config
    ): InteractiveRunResult {
        $this->inputStream = new InputStream;
        $command = $this->buildCommandArray($agent);
        $env = $this->buildEnv($agent, $config);

        Log::info('Starting interactive agent', [
            'agent_id' => $agent->id,
            'run_id' => $run->id,
            'workspace' => $workspace,
        ]);

        $this->process = new Process($command);
        $this->process->setWorkingDirectory($workspace);
        $this->process->setEnv($env);
        $this->process->setInput($this->inputStream);
        $this->process->setTimeout(null); // We manage timeout ourselves
        $this->process->start();

        // Write the prompt to stdin
        $this->inputStream->write($prompt);
        $this->inputStream->close();

        // Monitor output for tool_use blocks
        return $this->monitorUntilInteractionOrComplete($run, $workspace, $config);
    }

    /**
     * Monitor process output, looking for AskUserQuestion tool calls.
     */
    protected function monitorUntilInteractionOrComplete(
        AgentRun $run,
        string $workspace,
        array $config
    ): InteractiveRunResult {
        $maxWaitSeconds = $config['timeout_seconds'] ?? $this->defaultTimeoutSeconds;
        $deadline = microtime(true) + $maxWaitSeconds;

        while ($this->process->isRunning() && microtime(true) < $deadline) {
            $this->readProcessOutput();

            // Check for AskUserQuestion tool call
            $interaction = $this->detectAskUserQuestion($run);
            if ($interaction) {
                // Kill the process - we'll restart on resume
                $this->process->stop(5);

                return InteractiveRunResult::needsInteraction(
                    $interaction,
                    $this->createCheckpoint($run, $workspace, $config),
                    $this->outputBuffer,
                    $this->getElapsedTime()
                );
            }

            usleep($this->pollIntervalMicroseconds);
        }

        // Process finished or timed out
        $this->readProcessOutput(); // Get final output

        if (microtime(true) >= $deadline && $this->process->isRunning()) {
            $this->process->stop(5);

            return InteractiveRunResult::failed(
                'Interactive session timed out',
                $this->outputBuffer,
                $this->getElapsedTime()
            );
        }

        // Process completed
        $exitCode = $this->process->getExitCode();
        $output = $this->parseOutput($this->outputBuffer);
        $usage = $this->extractUsage($this->outputBuffer);

        if ($exitCode !== 0) {
            return InteractiveRunResult::failed(
                $this->process->getErrorOutput() ?: "Process exited with code {$exitCode}",
                $this->outputBuffer,
                $this->getElapsedTime()
            );
        }

        return InteractiveRunResult::completed(
            $output,
            $this->outputBuffer,
            $this->getElapsedTime(),
            $usage['tokens'] ?? 0,
            $usage['cost'] ?? 0.0
        );
    }

    /**
     * Read available output from the process.
     */
    protected function readProcessOutput(): void
    {
        $output = $this->process->getIncrementalOutput();
        if ($output) {
            $this->outputBuffer .= $output;
            $this->parseJsonLines($output);
        }
    }

    /**
     * Parse JSONL output lines for events.
     */
    protected function parseJsonLines(string $output): void
    {
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->jsonEvents[] = $decoded;
            }
        }
    }

    /**
     * Detect if AskUserQuestion was called in the output.
     */
    protected function detectAskUserQuestion(AgentRun $run): ?InteractionRequest
    {
        foreach ($this->jsonEvents as $index => $event) {
            // Look for tool_use blocks with name = AskUserQuestion
            if ($this->isAskUserQuestionEvent($event)) {
                // Remove processed events
                $this->jsonEvents = array_slice($this->jsonEvents, $index + 1);

                return $this->createInteractionFromEvent($event, $run);
            }

            // Also check for nested content blocks
            $contentBlocks = $event['content'] ?? [];
            foreach ($contentBlocks as $block) {
                if ($this->isAskUserQuestionEvent($block)) {
                    $this->jsonEvents = array_slice($this->jsonEvents, $index + 1);

                    return $this->createInteractionFromEvent($block, $run);
                }
            }
        }

        return null;
    }

    /**
     * Check if an event is an AskUserQuestion tool call.
     */
    protected function isAskUserQuestionEvent(array $event): bool
    {
        // Check for direct tool_use
        if (($event['type'] ?? '') === 'tool_use' && ($event['name'] ?? '') === 'AskUserQuestion') {
            return true;
        }

        // Check for content_block_start with tool_use
        if (($event['type'] ?? '') === 'content_block_start') {
            $contentBlock = $event['content_block'] ?? [];
            if (($contentBlock['type'] ?? '') === 'tool_use' && ($contentBlock['name'] ?? '') === 'AskUserQuestion') {
                return true;
            }
        }

        return false;
    }

    /**
     * Create an InteractionRequest from an AskUserQuestion event.
     */
    protected function createInteractionFromEvent(array $event, AgentRun $run): InteractionRequest
    {
        $input = $event['input'] ?? $event['content_block']['input'] ?? [];

        // Handle both direct and nested input formats
        $questions = $input['questions'] ?? [];
        $firstQuestion = $questions[0] ?? [];

        $questionType = $this->mapQuestionType($firstQuestion);
        $questionContent = $firstQuestion['question'] ?? 'Please provide input';
        $options = $firstQuestion['options'] ?? null;

        return InteractionRequest::create([
            'agent_run_id' => $run->id,
            'question_type' => $questionType,
            'question_content' => $questionContent,
            'options' => $options,
            'context' => [
                'header' => $firstQuestion['header'] ?? null,
                'multi_select' => $firstQuestion['multiSelect'] ?? false,
                'all_questions' => $questions,
            ],
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * Map AskUserQuestion question format to our type.
     */
    protected function mapQuestionType(array $question): string
    {
        // Check options to determine type
        $options = $question['options'] ?? [];
        if (empty($options)) {
            return InteractionRequest::TYPE_TEXT;
        }

        // Check if it's a confirm-style question (Yes/No)
        if (count($options) === 2) {
            $labels = array_map(fn ($o) => strtolower($o['label'] ?? ''), $options);
            if (in_array('yes', $labels) && in_array('no', $labels)) {
                return InteractionRequest::TYPE_CONFIRM;
            }
        }

        return InteractionRequest::TYPE_SELECT;
    }

    /**
     * Create a checkpoint for resuming later.
     */
    protected function createCheckpoint(AgentRun $run, string $workspace, array $config): array
    {
        return [
            'run_id' => $run->id,
            'workspace' => $workspace,
            'config' => $config,
            'output_so_far' => $this->outputBuffer,
            'conversation_history' => $this->extractConversationHistory(),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Extract conversation history from output for resume context.
     */
    protected function extractConversationHistory(): array
    {
        // Extract meaningful parts from the output
        $history = [];

        foreach ($this->jsonEvents as $event) {
            $type = $event['type'] ?? '';
            if (in_array($type, ['text', 'assistant', 'user'])) {
                $history[] = $event;
            }
        }

        return $history;
    }

    /**
     * Build prompt for resuming from checkpoint.
     */
    protected function buildResumePrompt(array $checkpoint, string $response): string
    {
        $previousOutput = $checkpoint['output_so_far'] ?? '';
        $history = $checkpoint['conversation_history'] ?? [];

        // Build a continuation prompt with the response
        $historyContext = '';
        foreach ($history as $entry) {
            $text = $entry['text'] ?? json_encode($entry);
            $historyContext .= $text."\n";
        }

        return <<<PROMPT
[CONTINUATION FROM PREVIOUS SESSION]

Previous context and work:
{$historyContext}

---

User's response to the question: {$response}

Continue from where you left off, incorporating the user's response.
PROMPT;
    }

    /**
     * Build the full prompt for execution.
     */
    protected function buildPrompt(Agent $agent, array $config): string
    {
        $definition = $agent->getDefinition();
        $systemPrompt = $definition?->systemPrompt() ?? $agent->system_prompt ?? '';

        $context = $config['context'] ?? '';
        if (is_array($context)) {
            $context = json_encode($context, JSON_PRETTY_PRINT);
        }

        $userPrompt = $config['prompt'] ?? '';

        return <<<PROMPT
{$systemPrompt}

---

## Context
{$context}

## Task
{$userPrompt}
PROMPT;
    }

    /**
     * Build command array for Symfony Process.
     */
    protected function buildCommandArray(Agent $agent): array
    {
        $model = $agent->model ?? 'sonnet';

        return [
            'claude',
            '--print',
            '--output-format', 'stream-json',
            '--dangerously-skip-permissions',
            '--model', $model,
        ];
    }

    /**
     * Build environment variables for execution.
     */
    protected function buildEnv(Agent $agent, array $config): array
    {
        $env = [
            'AGENT_ID' => (string) $agent->id,
            'AGENT_SLUG' => $agent->slug,
        ];

        // OAuth token takes priority (Max subscription)
        $oauthToken = env('CLAUDE_CODE_OAUTH_TOKEN') ?: getenv('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = env('ANTHROPIC_API_KEY') ?: getenv('ANTHROPIC_API_KEY');

        if ($oauthToken) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $oauthToken;
            $env['ANTHROPIC_API_KEY'] = '';
        } elseif ($apiKey) {
            $env['ANTHROPIC_API_KEY'] = $apiKey;
        }

        // Add secrets from config
        $secrets = $config['secrets'] ?? [];
        foreach ($secrets as $key => $value) {
            $env[strtoupper($key)] = $value;
        }

        // GitHub tokens
        if (isset($env['GITHUB_TOKEN']) && ! isset($env['GH_TOKEN'])) {
            $env['GH_TOKEN'] = $env['GITHUB_TOKEN'];
        } elseif (isset($env['GH_TOKEN']) && ! isset($env['GITHUB_TOKEN'])) {
            $env['GITHUB_TOKEN'] = $env['GH_TOKEN'];
        }

        // App URL for callbacks
        $env['APP_URL'] = config('app.url');

        return $env;
    }

    /**
     * Create isolated workspace for this run.
     */
    protected function createWorkspace(AgentRun $run): string
    {
        $path = $this->workspacesPath.'/'.$run->id.'-'.Str::random(8);
        File::ensureDirectoryExists($path);
        File::ensureDirectoryExists($path.'/input');
        File::ensureDirectoryExists($path.'/output');

        return $path;
    }

    /**
     * Parse output to extract structured data.
     */
    protected function parseOutput(string $output): array
    {
        $lines = explode("\n", trim($output));

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        return [
            'response' => $output,
            'parsed' => false,
        ];
    }

    /**
     * Extract usage statistics from output.
     */
    protected function extractUsage(string $output): array
    {
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return [
                'tokens' => $decoded['usage']['total_tokens'] ?? 0,
                'cost' => $decoded['usage']['cost_usd'] ?? 0.0,
            ];
        }

        return ['tokens' => 0, 'cost' => 0.0];
    }

    /**
     * Get elapsed time since start.
     */
    protected function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Clean up resources.
     */
    protected function cleanup(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop(5);
        }

        $this->process = null;
        $this->inputStream = null;
    }

    /**
     * Clean up workspace after execution.
     */
    public function cleanupWorkspace(string $workspace): void
    {
        if (File::isDirectory($workspace) && str_starts_with($workspace, $this->workspacesPath)) {
            File::deleteDirectory($workspace);
        }
    }
}
