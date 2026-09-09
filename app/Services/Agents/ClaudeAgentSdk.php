<?php

namespace App\Services\Agents;

use App\Agents\Tools\Tool;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Claude Agent SDK - Direct API integration for full programmatic control.
 *
 * Implements the agentic loop pattern:
 * 1. Send message with tools
 * 2. If tool_use in response, execute tool and continue
 * 3. Repeat until end_turn or max iterations
 *
 * Benefits over CLI:
 * - Native streaming support
 * - Full tool use control
 * - Better cost/token tracking
 * - Session/conversation management
 * - Programmatic tool execution
 */
class ClaudeAgentSdk
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected string $apiVersion = '2023-06-01';

    protected int $maxTurns = 25;

    protected int $maxTokens = 8192;

    protected int $maxRetries = 5;

    protected int $baseRetryDelayMs = 1000;

    protected array $toolRegistry = [];

    /**
     * Rate limit tracking for intelligent throttling.
     *
     * @var array{requests_remaining: int|null, tokens_remaining: int|null, reset_at: int|null}
     */
    protected array $rateLimitState = [
        'requests_remaining' => null,
        'tokens_remaining' => null,
        'reset_at' => null,
    ];

    protected bool $toolsDiscovered = false;

    // Token costs per million (as of 2024)
    protected array $modelCosts = [
        'claude-opus-4-20250514' => ['input' => 15.0, 'output' => 75.0],
        'claude-sonnet-4-20250514' => ['input' => 3.0, 'output' => 15.0],
        'claude-3-5-haiku-20241022' => ['input' => 0.80, 'output' => 4.0],
    ];

    public function __construct()
    {
        // Coalesce to '' so the service can be constructed when the key isn't set
        // (e.g. package:discover during composer install, before .env exists) —
        // assigning null to a typed string property is a fatal TypeError.
        // API calls still require a real key and will fail loudly without one.
        $this->apiKey = config('services.anthropic.api_key') ?? '';
    }

    /**
     * Execute an agent with the agentic loop.
     */
    public function execute(Agent $agent, AgentRun $run, array $config = []): ExecutionResult
    {
        $startTime = microtime(true);
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $turns = 0;

        $model = $this->resolveModel($agent->model ?? 'sonnet');
        $systemPrompt = $this->buildSystemPrompt($agent, $config);
        $tools = $this->buildTools($agent);

        // Initialize conversation - ensure prompt is never empty
        $userPrompt = $config['prompt'] ?? '';
        if (empty(trim($userPrompt))) {
            $userPrompt = 'Execute your primary function as defined in your system prompt.';
        }
        $messages = [
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Add context as a separate user message if present
        if (! empty($config['context'])) {
            $contextStr = is_array($config['context'])
                ? json_encode($config['context'], JSON_PRETTY_PRINT)
                : $config['context'];
            array_unshift($messages, [
                'role' => 'user',
                'content' => "Context:\n{$contextStr}",
            ]);
            // Add assistant acknowledgment to maintain alternating pattern
            array_splice($messages, 1, 0, [
                ['role' => 'assistant', 'content' => 'I understand the context. Please proceed with your request.'],
            ]);
        }

        $finalOutput = null;
        $toolResults = [];

        try {
            // Agentic loop
            while ($turns < $this->maxTurns) {
                $turns++;

                $response = $this->callApi($model, $systemPrompt, $messages, $tools, $config);

                if (! $response['success']) {
                    return new ExecutionResult(
                        success: false,
                        output: ['error' => $response['error']],
                        rawOutput: json_encode($response),
                        errorOutput: $response['error'],
                        exitCode: 1,
                        durationSeconds: microtime(true) - $startTime,
                        tokensUsed: $totalInputTokens + $totalOutputTokens,
                        costUsd: $this->calculateCost($model, $totalInputTokens, $totalOutputTokens),
                        workspace: null,
                        inputTokens: $totalInputTokens,
                        outputTokens: $totalOutputTokens,
                    );
                }

                $data = $response['data'];
                $totalInputTokens += $data['usage']['input_tokens'] ?? 0;
                $totalOutputTokens += $data['usage']['output_tokens'] ?? 0;

                // Process response content
                $content = $data['content'] ?? [];
                $hasToolUse = false;
                $assistantContent = [];

                foreach ($content as $block) {
                    $assistantContent[] = $block;

                    if ($block['type'] === 'tool_use') {
                        $hasToolUse = true;
                        $toolResult = $this->executeToolCall($agent, $block, $run);
                        $toolResults[] = [
                            'tool' => $block['name'],
                            'input' => $block['input'],
                            'result' => $toolResult,
                        ];
                    } elseif ($block['type'] === 'text') {
                        $finalOutput = $block['text'];
                    }
                }

                // Add assistant response to conversation
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

                // Check stop reason
                $stopReason = $data['stop_reason'] ?? 'end_turn';

                if ($stopReason === 'end_turn' || ! $hasToolUse) {
                    // Agent finished
                    break;
                }

                // Continue with tool results
                if ($hasToolUse) {
                    $toolResultMessages = [];
                    foreach ($content as $block) {
                        if ($block['type'] === 'tool_use') {
                            $result = $this->getToolResultForBlock($block, $toolResults);
                            $toolResultMessages[] = [
                                'type' => 'tool_result',
                                'tool_use_id' => $block['id'],
                                'content' => is_string($result) ? $result : json_encode($result),
                            ];
                        }
                    }
                    $messages[] = ['role' => 'user', 'content' => $toolResultMessages];
                }

                // Check for max tokens stop (might need continuation)
                if ($stopReason === 'max_tokens') {
                    Log::warning('Agent hit max tokens', ['run_id' => $run->id, 'turn' => $turns]);
                }
            }

            $duration = microtime(true) - $startTime;

            Log::info('Agent SDK execution completed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'turns' => $turns,
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
                'cost' => $this->calculateCost($model, $totalInputTokens, $totalOutputTokens),
                'duration' => $duration,
            ]);

            return new ExecutionResult(
                success: true,
                output: [
                    'response' => $finalOutput,
                    'tool_results' => $toolResults,
                    'turns' => $turns,
                ],
                rawOutput: $finalOutput ?? '',
                errorOutput: '',
                exitCode: 0,
                durationSeconds: $duration,
                tokensUsed: $totalInputTokens + $totalOutputTokens,
                costUsd: $this->calculateCost($model, $totalInputTokens, $totalOutputTokens),
                workspace: null,
                inputTokens: $totalInputTokens,
                outputTokens: $totalOutputTokens,
            );

        } catch (\Exception $e) {
            Log::error('Agent SDK execution failed', [
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return new ExecutionResult(
                success: false,
                output: ['error' => $e->getMessage()],
                rawOutput: '',
                errorOutput: $e->getMessage(),
                exitCode: 1,
                durationSeconds: microtime(true) - $startTime,
                tokensUsed: $totalInputTokens + $totalOutputTokens,
                costUsd: $this->calculateCost($model, $totalInputTokens, $totalOutputTokens),
                workspace: null,
                inputTokens: $totalInputTokens,
                outputTokens: $totalOutputTokens,
            );
        }
    }

    /**
     * Call the Anthropic API with automatic retry on rate limits.
     */
    protected function callApi(
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        array $config
    ): array {
        $payload = [
            'model' => $model,
            'max_tokens' => $config['max_tokens'] ?? $this->maxTokens,
            'messages' => $messages,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $this->callApiWithRetry($payload);
    }

    /**
     * Execute API call with exponential backoff retry for rate limits.
     *
     * @param  array  $payload  The API request payload
     */
    protected function callApiWithRetry(array $payload): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type' => 'application/json',
                ])
                    ->timeout(120)
                    ->post("{$this->baseUrl}/messages", $payload);

                // Update rate limit state from response headers
                $this->updateRateLimitState($response);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'error' => null,
                        'data' => $response->json(),
                    ];
                }

                // Handle rate limiting (429)
                if ($response->status() === 429) {
                    $retryAfter = $this->getRetryAfterSeconds($response);
                    $lastError = "Rate limited (attempt {$attempt}/{$this->maxRetries})";

                    Log::warning('Anthropic API rate limited', [
                        'attempt' => $attempt,
                        'max_retries' => $this->maxRetries,
                        'retry_after_seconds' => $retryAfter,
                        'rate_limit_state' => $this->rateLimitState,
                    ]);

                    if ($attempt < $this->maxRetries) {
                        $this->sleepWithJitter($retryAfter);

                        continue;
                    }

                    return [
                        'success' => false,
                        'error' => "Rate limit exceeded after {$this->maxRetries} attempts. Last retry-after: {$retryAfter}s. Response: {$response->body()}",
                        'data' => null,
                    ];
                }

                // Handle overloaded (529)
                if ($response->status() === 529) {
                    $retryAfter = $this->calculateBackoff($attempt);
                    $lastError = "API overloaded (attempt {$attempt}/{$this->maxRetries})";

                    Log::warning('Anthropic API overloaded', [
                        'attempt' => $attempt,
                        'backoff_seconds' => $retryAfter,
                    ]);

                    if ($attempt < $this->maxRetries) {
                        $this->sleepWithJitter($retryAfter);

                        continue;
                    }

                    return [
                        'success' => false,
                        'error' => "API overloaded after {$this->maxRetries} attempts. Response: {$response->body()}",
                        'data' => null,
                    ];
                }

                // Non-retryable error
                return [
                    'success' => false,
                    'error' => "API error: {$response->status()} - {$response->body()}",
                    'data' => null,
                ];

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastError = "Connection error: {$e->getMessage()}";
                Log::warning('Anthropic API connection error', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->maxRetries) {
                    $this->sleepWithJitter($this->calculateBackoff($attempt));

                    continue;
                }
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'data' => null,
                ];
            }
        }

        return [
            'success' => false,
            'error' => $lastError ?? 'Unknown error after retries',
            'data' => null,
        ];
    }

    /**
     * Update rate limit tracking state from response headers.
     */
    protected function updateRateLimitState(\Illuminate\Http\Client\Response $response): void
    {
        // Anthropic rate limit headers
        $requestsRemaining = $response->header('anthropic-ratelimit-requests-remaining');
        $tokensRemaining = $response->header('anthropic-ratelimit-tokens-remaining');
        $requestsReset = $response->header('anthropic-ratelimit-requests-reset');
        $tokensReset = $response->header('anthropic-ratelimit-tokens-reset');

        if ($requestsRemaining !== null) {
            $this->rateLimitState['requests_remaining'] = (int) $requestsRemaining;
        }
        if ($tokensRemaining !== null) {
            $this->rateLimitState['tokens_remaining'] = (int) $tokensRemaining;
        }

        // Parse reset time (ISO 8601 format)
        $resetTime = $tokensReset ?? $requestsReset;
        if ($resetTime) {
            try {
                $this->rateLimitState['reset_at'] = \Carbon\Carbon::parse($resetTime)->timestamp;
            } catch (\Exception $e) {
                // Ignore parse errors
            }
        }

        // Log low rate limit warnings
        if ($this->rateLimitState['tokens_remaining'] !== null && $this->rateLimitState['tokens_remaining'] < 5000) {
            Log::warning('Low token rate limit remaining', [
                'tokens_remaining' => $this->rateLimitState['tokens_remaining'],
                'reset_at' => $this->rateLimitState['reset_at'],
            ]);
        }
    }

    /**
     * Get retry delay from response headers or calculate backoff.
     */
    protected function getRetryAfterSeconds(\Illuminate\Http\Client\Response $response): int
    {
        // Check retry-after header (seconds)
        $retryAfter = $response->header('retry-after');
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        // Check Anthropic-specific reset header
        $tokensReset = $response->header('anthropic-ratelimit-tokens-reset');
        if ($tokensReset) {
            try {
                $resetTime = \Carbon\Carbon::parse($tokensReset);
                $secondsUntilReset = max(1, $resetTime->diffInSeconds(now()));

                return min($secondsUntilReset, 120); // Cap at 2 minutes
            } catch (\Exception $e) {
                // Fall through to default
            }
        }

        // Default: wait 60 seconds for rate limits (token limits reset per minute)
        return 60;
    }

    /**
     * Calculate exponential backoff delay.
     */
    protected function calculateBackoff(int $attempt): int
    {
        // Exponential backoff: 1s, 2s, 4s, 8s, 16s (capped)
        $delay = (int) ($this->baseRetryDelayMs * pow(2, $attempt - 1) / 1000);

        return min($delay, 60); // Cap at 60 seconds
    }

    /**
     * Sleep with jitter to prevent thundering herd.
     */
    protected function sleepWithJitter(int $baseSeconds): void
    {
        // Add 10-30% jitter
        $jitter = $baseSeconds * (mt_rand(10, 30) / 100);
        $totalSeconds = $baseSeconds + $jitter;

        Log::info("Rate limit backoff: sleeping for {$totalSeconds}s");
        sleep((int) ceil($totalSeconds));
    }

    /**
     * Build system prompt from agent definition.
     */
    protected function buildSystemPrompt(Agent $agent, array $config): string
    {
        $definition = $agent->getDefinition();

        // Pass runtime config to definition if it supports it
        if ($definition && method_exists($definition, 'setConfig')) {
            $contextToPass = $config['context'] ?? [];
            Log::info('ClaudeAgentSdk: passing config to definition', [
                'agent' => $agent->slug,
                'context' => $contextToPass,
                'full_config' => $config,
            ]);
            $definition->setConfig($contextToPass);
        }

        $prompt = $definition?->systemPrompt() ?? $agent->system_prompt ?? '';

        // Add agent identity
        $identity = "You are {$agent->name}, an AI agent in the Zao Dash system.";

        return "{$identity}\n\n{$prompt}";
    }

    /**
     * Discover and load all Tool classes from app/Agents/Tools (including subdirectories).
     */
    protected function discoverTools(): void
    {
        if ($this->toolsDiscovered) {
            return;
        }

        $toolsPath = app_path('Agents/Tools');

        // Get files from root and all subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($toolsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Build class name from path relative to Tools directory
            $relativePath = str_replace($toolsPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativeClass = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relativePath);
            $className = 'App\\Agents\\Tools\\'.$relativeClass;

            if (! class_exists($className)) {
                continue;
            }

            if ($className === 'App\\Agents\\Tools\\BaseTool' || $className === 'App\\Agents\\Tools\\Tool') {
                continue;
            }

            try {
                $instance = app($className);
                if ($instance instanceof Tool) {
                    $this->toolRegistry[$instance->id()] = $instance;
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to instantiate tool: {$className}", ['error' => $e->getMessage()]);
            }
        }

        $this->toolsDiscovered = true;
        Log::debug('Discovered '.count($this->toolRegistry).' tools');
    }

    /**
     * Get a tool instance by ID.
     */
    protected function getToolInstance(string $toolId): ?Tool
    {
        $this->discoverTools();

        // Normalize: convert underscores to kebab-case
        $normalizedId = str_replace('_', '-', $toolId);

        return $this->toolRegistry[$normalizedId] ?? $this->toolRegistry[$toolId] ?? null;
    }

    /**
     * Build tools array from agent configuration.
     */
    protected function buildTools(Agent $agent): array
    {
        $this->discoverTools();

        $definition = $agent->getDefinition();
        $allowedTools = $definition?->allowedTools() ?? $agent->tools ?? [];

        $tools = [];

        // First try Tool class instances
        foreach ($allowedTools as $toolName) {
            $tool = $this->getToolInstance($toolName);
            if ($tool) {
                $tools[] = $tool->toAnthropicTool();

                continue;
            }

            // Fall back to legacy hardcoded definitions
            $legacyDefs = $this->getLegacyToolDefinitions();
            if (isset($legacyDefs[$toolName])) {
                $tools[] = $legacyDefs[$toolName];
            }
        }

        return $tools;
    }

    /**
     * Legacy hardcoded tool definitions (fallback for tools not yet migrated to classes).
     */
    protected function getLegacyToolDefinitions(): array
    {
        return [
            'web_search' => [
                'name' => 'web_search',
                'description' => 'Search the web for current information. Use for research, fact-checking, or finding recent data.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            'read_file' => [
                'name' => 'read_file',
                'description' => 'Read contents of a file from the workspace.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'File path relative to workspace',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            'write_file' => [
                'name' => 'write_file',
                'description' => 'Write content to a file in the workspace.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'File path relative to workspace',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Content to write',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
            'api_call' => [
                'name' => 'api_call',
                'description' => 'Make an HTTP API call to fetch or send data.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'The API endpoint URL',
                        ],
                        'method' => [
                            'type' => 'string',
                            'enum' => ['GET', 'POST', 'PUT', 'DELETE'],
                            'description' => 'HTTP method',
                        ],
                        'headers' => [
                            'type' => 'object',
                            'description' => 'Request headers',
                        ],
                        'body' => [
                            'type' => 'object',
                            'description' => 'Request body for POST/PUT',
                        ],
                    ],
                    'required' => ['url', 'method'],
                ],
            ],
            'create_task' => [
                'name' => 'create_task',
                'description' => 'Create a task in Zao Dash.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                        'due_date' => ['type' => 'string', 'description' => 'Due date (YYYY-MM-DD)'],
                        'assignee' => ['type' => 'string', 'description' => 'Assignee name or email'],
                    ],
                    'required' => ['title'],
                ],
            ],
            'send_notification' => [
                'name' => 'send_notification',
                'description' => 'Send a notification to users.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'severity' => ['type' => 'string', 'enum' => ['info', 'success', 'warning', 'error']],
                        'action_url' => ['type' => 'string', 'description' => 'Optional link'],
                    ],
                    'required' => ['title', 'message'],
                ],
            ],
            'query_database' => [
                'name' => 'query_database',
                'description' => 'Query Zao Dash data (clients, projects, tasks, etc).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity' => [
                            'type' => 'string',
                            'enum' => ['clients', 'projects', 'tasks', 'leads', 'time_entries'],
                        ],
                        'filters' => [
                            'type' => 'object',
                            'description' => 'Filter conditions',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 10)',
                        ],
                    ],
                    'required' => ['entity'],
                ],
            ],

            // Business Strategist tools
            'analyze_goal_progress' => [
                'name' => 'analyze_goal_progress',
                'description' => 'Analyze progress toward the strategic revenue goal. Returns current progress, pace tracking, and levers to pull.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'goal_id' => [
                            'type' => 'integer',
                            'description' => 'Strategic goal ID (optional, uses active goal if not specified)',
                        ],
                        'include_levers' => [
                            'type' => 'boolean',
                            'description' => 'Include recommended levers to pull if behind pace',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            'get_funnel_metrics' => [
                'name' => 'get_funnel_metrics',
                'description' => 'Get sales funnel metrics including conversion rates, cycle times, and pipeline health.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['week', 'month', 'quarter', 'year'],
                            'description' => 'Time period for metrics (default: month)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            'forecast_revenue' => [
                'name' => 'forecast_revenue',
                'description' => 'Forecast revenue based on current pipeline and historical close rates.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => [
                            'type' => 'integer',
                            'description' => 'Days to forecast (30, 60, or 90)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            'create_weekly_plan' => [
                'name' => 'create_weekly_plan',
                'description' => 'Create a weekly action plan with focus areas, targets, and action items.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'focus_areas' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Top 1-3 focus areas for the week (e.g., Lead Generation, Pipeline Acceleration)',
                        ],
                        'targets' => [
                            'type' => 'object',
                            'description' => 'Specific numeric targets (e.g., {"new_leads": 10, "proposals_sent": 3, "revenue_target": 50000})',
                        ],
                        'strategy_notes' => [
                            'type' => 'string',
                            'description' => 'Strategic context and reasoning for this week\'s plan. Explain the "why" behind focus areas and how they connect to goals.',
                        ],
                        'human_items' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Action items for humans (ONLY for tasks requiring human judgment: approvals, relationship decisions, budget allocation)',
                        ],
                        'agent_items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'agent_slug' => ['type' => 'string'],
                                    'action' => ['type' => 'string'],
                                ],
                            ],
                            'description' => 'Tasks to assign to other agents (PREFERRED - maximize agent delegation)',
                        ],
                    ],
                    'required' => ['focus_areas'],
                ],
            ],
            'assign_agent_task' => [
                'name' => 'assign_agent_task',
                'description' => 'Assign a task to a specialized agent for execution.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'agent_slug' => [
                            'type' => 'string',
                            'description' => 'Agent slug (e.g., lead-generation, outreach-campaign)',
                        ],
                        'task_description' => [
                            'type' => 'string',
                            'description' => 'Description of what the agent should do',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high', 'urgent'],
                            'description' => 'Task priority (default: normal)',
                        ],
                        'context' => [
                            'type' => 'object',
                            'description' => 'Additional context for the agent',
                        ],
                    ],
                    'required' => ['agent_slug', 'task_description'],
                ],
            ],
            'search_prospects' => [
                'name' => 'search_prospects',
                'description' => 'Search for prospects matching criteria.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['new', 'researched', 'qualified', 'contacted', 'converted'],
                        ],
                        'industry' => ['type' => 'string'],
                        'min_score' => ['type' => 'integer', 'description' => 'Minimum ICP score (0-100)'],
                        'limit' => ['type' => 'integer'],
                    ],
                    'required' => [],
                ],
            ],
            'search_leads' => [
                'name' => 'search_leads',
                'description' => 'Search for leads in the pipeline.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'],
                            'description' => 'Pipeline stage',
                        ],
                        'stale_days' => [
                            'type' => 'integer',
                            'description' => 'Find leads with no activity in X days',
                        ],
                        'limit' => ['type' => 'integer'],
                    ],
                    'required' => [],
                ],
            ],
            'list_available_agents' => [
                'name' => 'list_available_agents',
                'description' => 'List all available agents and their capabilities. Use this to find existing agents that can handle tasks before creating human tasks or proposing new agents.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['active', 'paused', 'all'],
                            'description' => 'Filter by agent status (default: active)',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Filter by category (e.g., sales, content, operations)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            'propose_agent_creation' => [
                'name' => 'propose_agent_creation',
                'description' => 'Propose creating a new agent type when no existing agent can handle a required capability. Creates an approval request for human review.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Name for the proposed agent (e.g., "Sales Collateral Creator")',
                        ],
                        'slug' => [
                            'type' => 'string',
                            'description' => 'URL-friendly slug (e.g., "sales-collateral-creator")',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'What this agent would do and why it is needed',
                        ],
                        'capabilities' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of capabilities this agent would have',
                        ],
                        'suggested_tools' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Tools this agent would need',
                        ],
                        'initial_task' => [
                            'type' => 'string',
                            'description' => 'The task to assign once agent is approved and created',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high'],
                            'description' => 'Priority for this agent creation (default: normal)',
                        ],
                    ],
                    'required' => ['name', 'slug', 'description', 'capabilities'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call and return the result.
     */
    protected function executeToolCall(Agent $agent, array $toolUse, AgentRun $run): mixed
    {
        $toolName = $toolUse['name'];
        $input = $toolUse['input'];

        Log::info('Executing tool', [
            'agent_id' => $agent->id,
            'run_id' => $run->id,
            'tool' => $toolName,
            'input' => $input,
        ]);

        try {
            // First, try to execute via Tool class instance
            $tool = $this->getToolInstance($toolName);
            if ($tool) {
                $validated = $tool->validate($input);

                return $tool->execute($validated);
            }

            // Fall back to legacy hardcoded implementations
            return match ($toolName) {
                'web_search' => $this->toolWebSearch($input),
                'read_file' => $this->toolReadFile($input, $run),
                'write_file' => $this->toolWriteFile($input, $run),
                'api_call' => $this->toolApiCall($input),
                'create_task' => $this->toolCreateTask($input, $run),
                'send_notification' => $this->toolSendNotification($input),
                'query_database' => $this->toolQueryDatabase($input),
                // Business Strategist tools
                'analyze_goal_progress' => $this->toolAnalyzeGoalProgress($input),
                'get_funnel_metrics' => $this->toolGetFunnelMetrics($input),
                'forecast_revenue' => $this->toolForecastRevenue($input),
                'create_weekly_plan' => $this->toolCreateWeeklyPlan($input, $run),
                'assign_agent_task' => $this->toolAssignAgentTask($input, $run),
                'search_prospects' => $this->toolSearchProspects($input),
                'search_leads' => $this->toolSearchLeads($input),
                'list_available_agents' => $this->toolListAvailableAgents($input),
                'propose_agent_creation' => $this->toolProposeAgentCreation($input, $run),
                default => ['error' => "Unknown tool: {$toolName}"],
            };
        } catch (\Exception $e) {
            Log::error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get tool result for a specific block.
     */
    protected function getToolResultForBlock(array $block, array $toolResults): mixed
    {
        foreach ($toolResults as $result) {
            if ($result['tool'] === $block['name'] && $result['input'] === $block['input']) {
                return $result['result'];
            }
        }

        return ['error' => 'Tool result not found'];
    }

    // Tool implementations

    protected function toolWebSearch(array $input): array
    {
        // Use a search service or return placeholder
        // In production, integrate with a search API
        return [
            'status' => 'success',
            'message' => 'Web search not yet implemented - use WebSearch tool in production',
            'query' => $input['query'],
        ];
    }

    protected function toolReadFile(array $input, AgentRun $run): array
    {
        $workspace = storage_path("app/agent-workspaces/{$run->id}");
        $path = $workspace.'/'.ltrim($input['path'], '/');

        if (! file_exists($path)) {
            return ['error' => 'File not found'];
        }

        return [
            'content' => file_get_contents($path),
            'path' => $input['path'],
        ];
    }

    protected function toolWriteFile(array $input, AgentRun $run): array
    {
        $workspace = storage_path("app/agent-workspaces/{$run->id}");
        $path = $workspace.'/'.ltrim($input['path'], '/');

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $input['content']);

        return [
            'status' => 'success',
            'path' => $input['path'],
            'bytes' => strlen($input['content']),
        ];
    }

    protected function toolApiCall(array $input): array
    {
        $method = strtoupper($input['method']);
        $url = $input['url'];
        $headers = $input['headers'] ?? [];
        $body = $input['body'] ?? null;

        $request = Http::withHeaders($headers)->timeout(30);

        $response = match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $body),
            'PUT' => $request->put($url, $body),
            'DELETE' => $request->delete($url),
            default => throw new \Exception("Invalid method: {$method}"),
        };

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
            'success' => $response->successful(),
        ];
    }

    protected function toolCreateTask(array $input, AgentRun $run): array
    {
        $task = \App\Models\Task::create([
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'priority' => $input['priority'] ?? 'medium',
            'status' => 'pending',
            'due_date' => isset($input['due_date']) ? \Carbon\Carbon::parse($input['due_date']) : null,
            'source' => 'agent',
            'source_id' => $run->id,
        ]);

        return [
            'status' => 'success',
            'task_id' => $task->id,
            'title' => $task->title,
        ];
    }

    protected function toolSendNotification(array $input): array
    {
        $notification = \App\Models\Notification::create([
            'type' => 'agent_notification',
            'title' => $input['title'],
            'message' => $input['message'],
            'severity' => $input['severity'] ?? 'info',
            'action_url' => $input['action_url'] ?? null,
        ]);

        event(new \App\Events\NotificationCreated($notification));

        return [
            'status' => 'success',
            'notification_id' => $notification->id,
        ];
    }

    protected function toolQueryDatabase(array $input): array
    {
        $entity = $input['entity'];
        $filters = $input['filters'] ?? [];
        $limit = min($input['limit'] ?? 10, 100);

        $modelMap = [
            'clients' => \App\Models\Client::class,
            'projects' => \App\Models\Project::class,
            'tasks' => \App\Models\Task::class,
            'leads' => \App\Models\Lead::class,
            'time_entries' => \App\Models\TimeEntry::class,
        ];

        if (! isset($modelMap[$entity])) {
            return ['error' => "Unknown entity: {$entity}"];
        }

        $query = $modelMap[$entity]::query();

        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        $results = $query->limit($limit)->get();

        return [
            'entity' => $entity,
            'count' => $results->count(),
            'data' => $results->toArray(),
        ];
    }

    // Business Strategist Tool Implementations

    protected function toolAnalyzeGoalProgress(array $input): array
    {
        $goalId = $input['goal_id'] ?? null;
        $includeLevers = $input['include_levers'] ?? false;

        // Get active goal or specified goal
        $goal = $goalId
            ? \App\Models\StrategicGoal::find($goalId)
            : \App\Models\StrategicGoal::where('status', 'active')->first();

        if (! $goal) {
            return ['error' => 'No active strategic goal found'];
        }

        $yearlyPeriod = $goal->yearlyPeriod;
        $currentQuarter = $goal->currentQuarter();
        $currentMonth = $goal->currentMonth();
        $currentWeek = $goal->currentWeek();

        $progress = [
            'goal_id' => $goal->id,
            'fiscal_year' => $goal->fiscal_year,
            'revenue_target' => $goal->revenue_target,
            'revenue_actual' => $yearlyPeriod?->revenue_actual ?? 0,
            'progress_percent' => $goal->progress_percent,
            'time_elapsed_percent' => $goal->time_elapsed_percent,
            'is_on_track' => $goal->is_on_track,
            'pace' => $goal->progress_percent >= $goal->time_elapsed_percent ? 'ahead' : 'behind',
            'gap' => $goal->revenue_target - ($yearlyPeriod?->revenue_actual ?? 0),
        ];

        // Add period breakdowns
        if ($currentQuarter) {
            $progress['current_quarter'] = [
                'label' => $currentQuarter->period_label,
                'target' => $currentQuarter->revenue_target,
                'actual' => $currentQuarter->revenue_actual,
                'leads_target' => $currentQuarter->leads_target,
                'leads_actual' => $currentQuarter->leads_actual,
            ];
        }

        if ($currentWeek) {
            $progress['current_week'] = [
                'label' => $currentWeek->period_label,
                'target' => $currentWeek->revenue_target,
                'leads_target' => $currentWeek->leads_target,
                'leads_actual' => $currentWeek->leads_actual,
            ];
        }

        // Add levers if requested and behind pace
        if ($includeLevers && ! $goal->is_on_track) {
            $assumptions = $goal->assumptions ?? [];
            $avgDealSize = $assumptions['avg_deal_size'] ?? 25000;
            $winRate = ($assumptions['win_rate'] ?? 25) / 100;

            $gap = $progress['gap'];
            $dealsNeeded = ceil($gap / $avgDealSize);
            $leadsNeeded = ceil($dealsNeeded / $winRate);

            $progress['levers'] = [
                'additional_deals_needed' => $dealsNeeded,
                'additional_leads_needed' => $leadsNeeded,
                'recommendations' => [
                    'Increase outreach volume to generate more leads',
                    'Focus on faster deal velocity in current pipeline',
                    'Pursue larger deal sizes or upsells',
                    'Improve qualification to increase win rate',
                ],
            ];
        }

        return $progress;
    }

    protected function toolGetFunnelMetrics(array $input): array
    {
        $period = $input['period'] ?? 'month';

        // Calculate date range
        $endDate = now();
        $startDate = match ($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        // Get pipeline stats
        $leads = \App\Models\Lead::whereBetween('created_at', [$startDate, $endDate]);
        $totalLeads = $leads->count();

        $prospects = \App\Models\Prospect::whereBetween('created_at', [$startDate, $endDate]);
        $totalProspects = $prospects->count();
        $qualifiedProspects = $prospects->clone()->where('status', 'qualified')->count();

        // Calculate conversion rates
        $prospectToLeadRate = $totalProspects > 0
            ? round(($totalLeads / $totalProspects) * 100, 1)
            : 0;

        return [
            'period' => $period,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'funnel' => [
                'prospects' => $totalProspects,
                'qualified_prospects' => $qualifiedProspects,
                'leads' => $totalLeads,
            ],
            'conversion_rates' => [
                'prospect_to_lead' => $prospectToLeadRate,
            ],
            'pipeline_health' => [
                'status' => $totalLeads >= 5 ? 'healthy' : 'needs_attention',
                'message' => $totalLeads >= 5
                    ? 'Pipeline has adequate lead flow'
                    : 'Lead generation needs to increase',
            ],
        ];
    }

    protected function toolForecastRevenue(array $input): array
    {
        $days = $input['days'] ?? 90;
        $days = min(max($days, 30), 90); // Clamp to 30-90

        // Get active goal assumptions
        $goal = \App\Models\StrategicGoal::where('status', 'active')->first();
        $assumptions = $goal?->assumptions ?? [];
        $avgDealSize = $assumptions['avg_deal_size'] ?? 25000;
        $winRate = ($assumptions['win_rate'] ?? 25) / 100;
        $salesCycleDays = $assumptions['sales_cycle_days'] ?? 45;

        // Get current pipeline (active stages: new, contacted, qualified, proposal, negotiation)
        $activePipeline = \App\Models\Lead::whereNotIn('stage', ['won', 'lost'])
            ->sum('deal_value');

        // Simple forecast based on pipeline and win rate
        $expectedFromPipeline = $activePipeline * $winRate;

        // Time-adjusted (deals that can close in timeframe)
        $timeAdjustment = min(1, $days / $salesCycleDays);
        $adjustedForecast = $expectedFromPipeline * $timeAdjustment;

        return [
            'forecast_days' => $days,
            'current_pipeline_value' => $activePipeline,
            'forecast' => [
                'optimistic' => round($expectedFromPipeline * 1.2),
                'expected' => round($adjustedForecast),
                'conservative' => round($adjustedForecast * 0.7),
            ],
            'assumptions' => [
                'win_rate' => $winRate * 100 .'%',
                'avg_deal_size' => $avgDealSize,
                'sales_cycle_days' => $salesCycleDays,
            ],
        ];
    }

    protected function toolCreateWeeklyPlan(array $input, AgentRun $run): array
    {
        $focusAreas = $input['focus_areas'] ?? [];
        $targets = $input['targets'] ?? [];
        $strategyNotes = $input['strategy_notes'] ?? null;
        $humanItems = $input['human_items'] ?? [];
        $agentItems = $input['agent_items'] ?? [];

        // Get active goal
        $goal = \App\Models\StrategicGoal::where('status', 'active')->first();

        // Create or get this week's plan
        $plan = \App\Models\WeeklyPlan::forWeek(now(), $goal?->id);

        // Check if this is a revision (plan exists with revision_requested status or has items already)
        $isRevision = $plan->status === 'revision_requested' || $plan->items()->exists();

        if ($isRevision) {
            // Clear all pending/incomplete items before adding new ones
            // Keep completed items as they represent actual work done
            $deletedCount = $plan->items()
                ->whereIn('status', ['pending', 'in_progress'])
                ->delete();

            Log::info('Cleared pending items for plan revision', [
                'plan_id' => $plan->id,
                'deleted_count' => $deletedCount,
            ]);
        }

        $plan->update([
            'focus_areas' => $focusAreas,
            'targets' => $targets,
            'strategy_notes' => $strategyNotes,
            'status' => 'draft',
        ]);

        // Add human items
        foreach ($humanItems as $item) {
            $plan->addHumanItem($item);
        }

        // Add agent items
        foreach ($agentItems as $item) {
            $plan->addAgentItem(
                $item['agent_slug'] ?? 'unknown',
                $item['action'] ?? ''
            );
        }

        Log::info('Weekly plan created', [
            'plan_id' => $plan->id,
            'run_id' => $run->id,
            'focus_areas' => $focusAreas,
            'items_count' => count($humanItems) + count($agentItems),
            'is_revision' => $isRevision,
        ]);

        return [
            'status' => 'success',
            'plan_id' => $plan->id,
            'week_label' => $plan->week_label,
            'focus_areas' => $focusAreas,
            'targets' => $targets,
            'items_created' => [
                'human' => count($humanItems),
                'agent' => count($agentItems),
            ],
            'is_revision' => $isRevision,
        ];
    }

    protected function toolAssignAgentTask(array $input, AgentRun $run): array
    {
        $agentSlug = $input['agent_slug'];
        $taskDescription = $input['task_description'];
        $priority = $input['priority'] ?? 'normal';
        $context = $input['context'] ?? [];

        // Find the target agent
        $agent = \App\Models\Agent::where('slug', $agentSlug)->first();

        if (! $agent) {
            return ['error' => "Agent not found: {$agentSlug}"];
        }

        if ($agent->status !== 'active') {
            return ['error' => "Agent is not active: {$agentSlug}"];
        }

        // Create the task
        $task = \App\Models\AgentTask::createFromStrategist(
            agent: $agent,
            description: $taskDescription,
            context: array_merge($context, ['assigned_by_run' => $run->id]),
            priority: $priority
        );

        Log::info('Agent task assigned', [
            'task_id' => $task->id,
            'agent' => $agentSlug,
            'run_id' => $run->id,
        ]);

        return [
            'status' => 'success',
            'task_id' => $task->id,
            'agent' => $agentSlug,
            'priority' => $priority,
            'description' => $taskDescription,
        ];
    }

    protected function toolSearchProspects(array $input): array
    {
        $query = \App\Models\Prospect::query();

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (isset($input['industry'])) {
            $query->where('industry', 'like', '%'.$input['industry'].'%');
        }

        if (isset($input['min_score'])) {
            $query->where('icp_score', '>=', $input['min_score']);
        }

        $limit = min($input['limit'] ?? 20, 100);
        $prospects = $query->orderByDesc('icp_score')
            ->limit($limit)
            ->get(['id', 'company_name', 'industry', 'status', 'icp_score', 'created_at']);

        return [
            'count' => $prospects->count(),
            'prospects' => $prospects->toArray(),
        ];
    }

    protected function toolSearchLeads(array $input): array
    {
        $query = \App\Models\Lead::query();

        if (isset($input['stage'])) {
            $query->where('stage', $input['stage']);
        }

        if (isset($input['stale_days'])) {
            $staleDate = now()->subDays($input['stale_days']);
            $query->where('updated_at', '<', $staleDate);
        }

        $limit = min($input['limit'] ?? 20, 100);
        $leads = $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'company_name', 'stage', 'deal_value', 'created_at', 'updated_at']);

        return [
            'count' => $leads->count(),
            'leads' => $leads->toArray(),
        ];
    }

    protected function toolListAvailableAgents(array $input): array
    {
        $status = $input['status'] ?? 'active';
        $category = $input['category'] ?? null;

        $query = \App\Models\Agent::query();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Get agents with their capabilities
        $agents = $query->get(['id', 'name', 'slug', 'description', 'status', 'model', 'allowed_tools']);

        // Enhance with capability descriptions
        $agentList = $agents->map(function ($agent) {
            return [
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'status' => $agent->status,
                'capabilities' => $this->inferCapabilities($agent),
                'tools' => $agent->allowed_tools ?? [],
            ];
        });

        // Filter by category if specified (based on capabilities/description)
        if ($category) {
            $agentList = $agentList->filter(function ($agent) use ($category) {
                $searchText = strtolower($agent['description'].' '.implode(' ', $agent['capabilities']));

                return str_contains($searchText, strtolower($category));
            })->values();
        }

        return [
            'count' => $agentList->count(),
            'agents' => $agentList->toArray(),
            'tip' => 'Use assign_agent_task to assign work to any active agent. Use propose_agent_creation if no agent fits your needs.',
        ];
    }

    /**
     * Infer agent capabilities from name, description, and tools.
     */
    protected function inferCapabilities(\App\Models\Agent $agent): array
    {
        $capabilities = [];

        // Map tools to capabilities
        $toolCapabilities = [
            'web_search' => 'Research and information gathering',
            'write_file' => 'Content creation and documentation',
            'api_call' => 'External service integration',
            'query_database' => 'Data analysis and reporting',
            'send_notification' => 'Communication and alerts',
            'create_task' => 'Task management',
        ];

        foreach ($agent->allowed_tools ?? [] as $tool) {
            if (isset($toolCapabilities[$tool])) {
                $capabilities[] = $toolCapabilities[$tool];
            }
        }

        // Infer from slug/name
        $slugCapabilities = [
            'lead-generation' => ['Finding and qualifying prospects', 'Lead research', 'ICP matching'],
            'outreach-campaign' => ['Email sequences', 'Personalized outreach', 'Follow-up automation'],
            'email-writer' => ['Email composition', 'Personalized messaging', 'Sales copywriting'],
            'content-creator' => ['Blog posts', 'Marketing content', 'Social media'],
            'case-study-writer' => ['Case studies', 'Success stories', 'Customer testimonials'],
            'client-health-monitor' => ['Churn prediction', 'Account health scoring', 'Relationship alerts'],
            'upsell-proposal' => ['Expansion opportunities', 'Upsell identification', 'Proposal drafting'],
            'lead-nurture' => ['Drip campaigns', 'Warm lead engagement', 'Relationship building'],
            'opportunity-scout' => ['Market research', 'Opportunity identification', 'Competitive analysis'],
        ];

        if (isset($slugCapabilities[$agent->slug])) {
            $capabilities = array_merge($capabilities, $slugCapabilities[$agent->slug]);
        }

        return array_unique($capabilities);
    }

    protected function toolProposeAgentCreation(array $input, AgentRun $run): array
    {
        $name = $input['name'];
        $slug = $input['slug'];
        $description = $input['description'];
        $capabilities = $input['capabilities'] ?? [];
        $suggestedTools = $input['suggested_tools'] ?? [];
        $initialTask = $input['initial_task'] ?? null;
        $priority = $input['priority'] ?? 'normal';

        // Check if agent with this slug already exists
        $existing = \App\Models\Agent::where('slug', $slug)->first();
        if ($existing) {
            return [
                'error' => "Agent with slug '{$slug}' already exists",
                'existing_agent' => [
                    'name' => $existing->name,
                    'status' => $existing->status,
                    'description' => $existing->description,
                ],
                'suggestion' => $existing->status === 'active'
                    ? 'Use assign_agent_task to assign work to this agent'
                    : 'This agent exists but is paused. Consider activating it.',
            ];
        }

        // Create approval request for agent creation
        $approval = \App\Models\ApprovalRequest::create([
            'agent_run_id' => $run->id,
            'action_type' => 'create_agent',
            'status' => 'pending',
            'risk_level' => $priority === 'high' ? 'medium' : 'low',
            'description' => "Create new agent: {$name}",
            'payload' => [
                'agent_config' => [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'capabilities' => $capabilities,
                    'suggested_tools' => $suggestedTools,
                    'initial_task' => $initialTask,
                ],
                'reason' => "Business Strategist identified a capability gap: {$description}",
                'proposed_by' => 'business-strategist',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        // Create notification
        \App\Models\Notification::approvalNeeded($approval);

        Log::info('Agent creation proposed', [
            'approval_id' => $approval->id,
            'proposed_agent' => $slug,
            'run_id' => $run->id,
        ]);

        return [
            'status' => 'pending_approval',
            'approval_id' => $approval->id,
            'proposed_agent' => [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'capabilities' => $capabilities,
            ],
            'message' => "Agent creation proposal submitted for approval. Once approved, the agent '{$name}' will be created and can be assigned tasks.",
            'initial_task_queued' => $initialTask !== null,
        ];
    }

    /**
     * Resolve model name to full model ID.
     */
    protected function resolveModel(string $model): string
    {
        return match (strtolower($model)) {
            'opus' => 'claude-opus-4-20250514',
            'sonnet' => 'claude-sonnet-4-20250514',
            'haiku' => 'claude-3-5-haiku-20241022',
            default => $model,
        };
    }

    /**
     * Calculate cost based on token usage.
     */
    protected function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $costs = $this->modelCosts[$model] ?? ['input' => 3.0, 'output' => 15.0];

        $inputCost = ($inputTokens / 1_000_000) * $costs['input'];
        $outputCost = ($outputTokens / 1_000_000) * $costs['output'];

        return round($inputCost + $outputCost, 6);
    }
}
