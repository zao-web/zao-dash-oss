<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude API service.
 *
 * Tries direct HTTP first (Cloudflare Workers AI free tier → Anthropic API),
 * falling back to the Claude CLI for local-dev environments where neither
 * HTTP credential is configured but the CLI is logged into a subscription.
 *
 * The CLI fallback exists because Laravel Cloud containers don't have an
 * authenticated CLI install — they need API-key-based access.
 */
class AnthropicService
{
    protected ClaudeCliService $cli;

    protected string $defaultModel = 'sonnet';

    public function __construct()
    {
        $this->cli = new ClaudeCliService;
    }

    /**
     * Send a message and get a complete response.
     */
    public function message(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
        ?string $model = null,
        array $tools = [],
        int $maxTokens = 1024
    ): array {
        $fullPrompt = $this->buildPromptWithContext($prompt, $context);
        $resolvedSystem = $systemPrompt ?? $this->getDefaultSystemPrompt();

        // 1. Cloudflare Workers AI (free)
        if ($text = $this->httpCallCloudflare($fullPrompt, $resolvedSystem, $maxTokens)) {
            return $this->normalizeResponse(['content' => [['type' => 'text', 'text' => $text]]]);
        }

        // 2. Anthropic API (paid)
        if ($text = $this->httpCallAnthropic($fullPrompt, $resolvedSystem, $maxTokens, $model)) {
            return $this->normalizeResponse(['content' => [['type' => 'text', 'text' => $text]]]);
        }

        // 3. Claude CLI (local dev only — fails on containerised prod)
        try {
            $response = $this->cli->message(
                prompt: $fullPrompt,
                systemPrompt: $resolvedSystem,
                model: $model ?? $this->defaultModel,
                maxTokens: $maxTokens,
                timeout: 120
            );

            return $this->normalizeResponse($response);
        } catch (\Exception $e) {
            Log::error('AnthropicService: all LLM paths failed', [
                'cli_error' => $e->getMessage(),
                'hint' => 'Configure CLOUDFLARE_ACCOUNT_ID+CLOUDFLARE_API_TOKEN or ANTHROPIC_API_KEY.',
            ]);
            throw $e;
        }
    }

    protected function httpCallCloudflare(string $prompt, string $system, int $maxTokens): ?string
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $model = config('services.cloudflare.narrative_model', '@cf/moonshotai/kimi-k2-instruct');

        if (! $accountId || ! $apiToken) {
            return null;
        }

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(120)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}", [
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            if (! $response->successful() || ! $response->json('success', false)) {
                return null;
            }

            return $response->json('result.response') ?? $response->json('result.output.0.content') ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function httpCallAnthropic(string $prompt, string $system, int $maxTokens, ?string $model): ?string
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->mapModelForApi($model ?? $this->defaultModel),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json('content.0.text');
        } catch (\Throwable) {
            return null;
        }
    }

    /** CLI accepts shorthand like "sonnet"; API needs the full model id. */
    protected function mapModelForApi(string $model): string
    {
        return match ($model) {
            'sonnet' => 'claude-sonnet-4-20250514',
            'opus' => 'claude-opus-4-20250514',
            'haiku' => 'claude-haiku-4-5-20251001',
            default => $model,
        };
    }

    /**
     * Send a message with tools and handle tool calls.
     *
     * @param  callable|null  $onEvent  Callback for streaming events: fn(string $type, array $data)
     *                                  Event types: 'thinking', 'tool_call', 'tool_result', 'text', 'done'
     */
    public function messageWithTools(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
        array $tools = [],
        ?callable $toolExecutor = null,
        ?string $model = null,
        int $maxIterations = 10,
        ?callable $onEvent = null,
        int $timeout = 120,
    ): array {
        $fullPrompt = $this->buildPromptWithContext($prompt, $context);
        $allResponses = [];
        $toolsExecuted = [];
        $iteration = 0;
        $conversationHistory = [];
        $startTime = microtime(true);
        $wallClockLimit = $maxIterations * $timeout; // Total time budget

        // Emit thinking event
        $this->emitEvent($onEvent, 'thinking', ['message' => 'Processing your request...']);

        while ($iteration < $maxIterations) {
            $iteration++;

            // Wall-clock safety: abort if total elapsed time exceeds budget
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $wallClockLimit) {
                Log::warning('[Anthropic] Wall-clock limit exceeded', [
                    'elapsed' => round($elapsed, 1),
                    'limit' => $wallClockLimit,
                    'iteration' => $iteration,
                ]);
                break;
            }

            $currentPrompt = $fullPrompt;
            if (! empty($conversationHistory)) {
                $currentPrompt = $this->buildConversationPrompt($fullPrompt, $conversationHistory);
            }

            $toolsDescription = $this->formatToolsForPrompt($tools);
            $enhancedSystemPrompt = ($systemPrompt ?? $this->getDefaultSystemPrompt())."\n\n".$toolsDescription;

            $response = $this->cli->message(
                prompt: $currentPrompt,
                systemPrompt: $enhancedSystemPrompt,
                model: $model ?? $this->defaultModel,
                maxTokens: 2048,
                timeout: $timeout
            );

            $normalized = $this->normalizeResponse($response);
            $allResponses[] = $normalized;

            $toolCalls = $this->extractToolCalls($normalized);

            if (empty($toolCalls)) {
                // No more tool calls - emit final text
                $finalText = $this->extractTextFromContent($normalized['content'] ?? []);
                if ($finalText) {
                    $this->emitEvent($onEvent, 'text', ['content' => $finalText]);
                }
                break;
            }

            $conversationHistory[] = ['role' => 'assistant', 'content' => $normalized['content'] ?? []];

            $toolResults = [];
            foreach ($toolCalls as $toolCall) {
                // Emit tool_call event before executing
                $this->emitEvent($onEvent, 'tool_call', [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'input' => $toolCall['input'],
                ]);

                $result = $toolExecutor
                    ? $toolExecutor($toolCall['name'], $toolCall['input'])
                    : ['error' => 'No tool executor provided'];

                $toolsExecuted[] = [
                    'tool' => $toolCall['name'],
                    'success' => ! isset($result['error']),
                ];

                $toolResults[] = [
                    'tool_use_id' => $toolCall['id'],
                    'result' => $result,
                ];

                // Emit tool_result event after executing
                $this->emitEvent($onEvent, 'tool_result', [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'result' => $result,
                ]);
            }

            $conversationHistory[] = ['role' => 'tool_results', 'results' => $toolResults];

            // Emit thinking event for next iteration
            if ($iteration < $maxIterations) {
                $this->emitEvent($onEvent, 'thinking', ['message' => 'Processing tool results...']);
            }
        }

        return [
            'final_response' => $allResponses[count($allResponses) - 1] ?? null,
            'all_responses' => $allResponses,
            'iterations' => $iteration,
            'tools_executed' => $toolsExecuted,
        ];
    }

    /**
     * Emit an event via the callback if provided.
     */
    protected function emitEvent(?callable $onEvent, string $type, array $data): void
    {
        if ($onEvent) {
            $onEvent($type, $data);
        }
    }

    public function isConfigured(): bool
    {
        return $this->cli->isConfigured();
    }

    protected function buildPromptWithContext(string $prompt, array $context): string
    {
        if (empty($context)) {
            return $prompt;
        }

        $contextText = '';
        foreach ($context as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $contextText .= "[{$role}]: {$content}\n\n";
        }

        return $contextText."[user]: {$prompt}";
    }

    protected function buildConversationPrompt(string $originalPrompt, array $history): string
    {
        $prompt = "Original request: {$originalPrompt}\n\nConversation so far:\n";

        foreach ($history as $entry) {
            if ($entry['role'] === 'assistant') {
                $content = $this->extractTextFromContent($entry['content'] ?? []);
                $prompt .= "[Assistant]: {$content}\n\n";
            } elseif ($entry['role'] === 'tool_results') {
                foreach ($entry['results'] ?? [] as $result) {
                    $resultJson = json_encode($result['result']);
                    $prompt .= "[Tool Result ({$result['tool_use_id']})]: {$resultJson}\n\n";
                }
            }
        }

        $prompt .= 'Please continue based on the tool results above.';

        return $prompt;
    }

    protected function formatToolsForPrompt(array $tools): string
    {
        if (empty($tools)) {
            return '';
        }

        $toolsText = "You have access to the following tools. To use a tool, respond with a JSON object containing 'tool_use' with 'name' and 'input' fields.\n\nAvailable tools:\n";

        foreach ($tools as $tool) {
            $name = $tool['name'] ?? 'unknown';
            $description = $tool['description'] ?? '';
            $schema = json_encode($tool['input_schema'] ?? []);
            $toolsText .= "- {$name}: {$description}\n  Input schema: {$schema}\n\n";
        }

        $toolsText .= "\nTo use a tool, format your response as:\n{\"tool_use\": {\"id\": \"unique_id\", \"name\": \"tool_name\", \"input\": {...}}}\n\nIf you don't need to use a tool, respond normally.";

        return $toolsText;
    }

    /**
     * Extract tool calls from response content, including JSON embedded in text.
     *
     * Modifies the response in-place to strip extracted tool call JSON from text blocks.
     *
     * @param  array  $response  Response array (modified by reference to clean text)
     */
    protected function extractToolCalls(array &$response): array
    {
        $content = $response['content'] ?? [];
        $toolCalls = [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? uniqid('tool_'),
                    'name' => $block['name'] ?? '',
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        // Extract tool calls embedded as JSON in text (the CLI returns these as text)
        $text = $this->extractTextFromContent($content);
        $extracted = $this->extractToolCallFromText($text);

        if ($extracted) {
            $toolCalls[] = [
                'id' => $extracted['tool_call']['id'] ?? uniqid('tool_'),
                'name' => $extracted['tool_call']['name'] ?? '',
                'input' => $extracted['tool_call']['input'] ?? [],
            ];

            // Strip the raw JSON from text content so it doesn't display to the user
            foreach ($response['content'] as &$block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $block['text'] = str_replace($extracted['json_string'], '', $block['text']);
                    $block['text'] = trim($block['text']);
                }
            }
            unset($block);
        }

        return $toolCalls;
    }

    /**
     * Extract a tool call JSON object from text using brace-counting.
     *
     * Handles arbitrarily nested input objects that regex cannot match.
     *
     * @return array{tool_call: array, json_string: string}|null
     */
    protected function extractToolCallFromText(string $text): ?array
    {
        $marker = '{"tool_use"';
        $pos = strpos($text, $marker);

        if ($pos === false) {
            return null;
        }

        $depth = 0;
        $start = $pos;
        $len = strlen($text);
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\' && $inString) {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $jsonStr = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($jsonStr, true);

                    if ($parsed && isset($parsed['tool_use']['name'])) {
                        return [
                            'tool_call' => $parsed['tool_use'],
                            'json_string' => $jsonStr,
                        ];
                    }

                    break;
                }
            }
        }

        return null;
    }

    protected function extractTextFromContent(array $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if (is_string($block)) {
                $text .= $block;
            } elseif (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return $text;
    }

    protected function normalizeResponse(array $response): array
    {
        if (isset($response['content'])) {
            return $response;
        }

        if (isset($response['result'])) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => $response['result']],
                ],
                'usage' => $response['usage'] ?? [],
            ];
        }

        $text = is_string($response) ? $response : json_encode($response);

        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => [],
        ];
    }

    protected function getDefaultSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an AI assistant integrated into Zao Dash, an agency command center application.

You can help users with:
- Finding information about projects, clients, tasks, and team members
- Understanding the status of various work items
- Providing guidance on using the application
- Answering questions about their data

Keep responses concise and actionable. When referencing specific items, use clear identifiers.

Current context: The user is accessing you via the command palette (Cmd+K).
PROMPT;
    }
}
