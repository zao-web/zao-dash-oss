<?php

namespace App\Http\Controllers;

use App\Agents\ToolRegistry;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\CommandHistory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AnthropicService;
use App\Services\CapabilitySynthesisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles AI chat for the command palette.
 */
class CommandPaletteController extends Controller
{
    public function __construct(
        protected AnthropicService $anthropic,
        protected ToolRegistry $toolRegistry,
        protected CapabilitySynthesisService $synthesisService
    ) {}

    /**
     * Stream AI response for command palette chat.
     */
    public function chat(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'context' => 'nullable|array',
            'use_tools' => 'nullable|boolean',
            'page_context' => 'nullable|array',
        ]);

        $message = $validated['message'];
        $context = $validated['context'] ?? [];
        $useTools = $validated['use_tools'] ?? true;
        $pageContext = $validated['page_context'] ?? [];

        // Build system context with current data
        $systemContext = $this->buildSystemContext($pageContext);

        return new StreamedResponse(function () use ($message, $context, $systemContext, $useTools) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            if (! $this->anthropic->isConfigured()) {
                $this->sendEvent('error', [
                    'message' => 'AI service not configured. Please set ANTHROPIC_API_KEY.',
                ]);

                return;
            }

            try {
                if ($useTools) {
                    // Use tool-enabled chat with real-time streaming
                    $tools = $this->toolRegistry->toAnthropicTools();
                    $finalContent = '';

                    $result = $this->anthropic->messageWithTools(
                        prompt: $message,
                        systemPrompt: $this->getSystemPrompt($systemContext),
                        context: $context,
                        tools: $tools,
                        toolExecutor: fn ($name, $input) => $this->executeToolSafe($name, $input),
                        onEvent: function (string $type, array $data) use (&$finalContent) {
                            switch ($type) {
                                case 'thinking':
                                    $this->sendEvent('thinking', $data);
                                    break;

                                case 'tool_call':
                                    $this->sendEvent('tool_call', [
                                        'id' => $data['id'],
                                        'name' => $data['name'],
                                        'input' => $data['input'],
                                    ]);
                                    break;

                                case 'tool_result':
                                    $this->sendEvent('tool_result', [
                                        'id' => $data['id'],
                                        'name' => $data['name'],
                                        'result' => $data['result'],
                                    ]);
                                    break;

                                case 'text':
                                    $finalContent = $data['content'];
                                    // Stream text in chunks for smooth display
                                    $chunks = str_split($data['content'], 20);
                                    foreach ($chunks as $chunk) {
                                        $this->sendEvent('content', ['text' => $chunk]);
                                        usleep(5000);
                                        if (connection_aborted()) {
                                            break;
                                        }
                                    }
                                    break;
                            }
                        },
                    );

                    $finalResponse = $result['final_response'] ?? null;
                } else {
                    // Simple chat without tools
                    $response = $this->anthropic->message(
                        prompt: $message,
                        systemPrompt: $this->getSystemPrompt($systemContext),
                        context: $context,
                    );
                    $content = $response['content'][0]['text'] ?? '';

                    // Send the response in chunks for streaming feel
                    $chunks = str_split($content, 20);
                    foreach ($chunks as $chunk) {
                        $this->sendEvent('content', ['text' => $chunk]);
                        usleep(10000);
                        if (connection_aborted()) {
                            break;
                        }
                    }
                }

                $this->sendEvent('done', [
                    'usage' => $finalResponse['usage'] ?? $response['usage'] ?? [],
                ]);

            } catch (\Exception $e) {
                Log::error('Command palette AI error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Provide user-friendly error messages for common issues
                $userMessage = $this->getHumanReadableError($e);

                $this->sendEvent('error', [
                    'message' => $userMessage,
                    'retry_after' => $this->getRetryAfterSeconds($e),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Execute a tool safely, catching errors.
     */
    protected function executeToolSafe(string $toolId, array $params): array
    {
        try {
            $result = $this->toolRegistry->execute($toolId, $params);

            return $result['result'] ?? $result;
        } catch (\Exception $e) {
            Log::error('Tool execution error', [
                'tool' => $toolId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Quick search across entities.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:100',
            'types' => 'nullable|array',
        ]);

        $query = $validated['query'];
        $types = $validated['types'] ?? ['projects', 'tasks', 'clients'];

        $results = [];

        if (in_array('projects', $types)) {
            $results['projects'] = Project::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'slug', 'status']);
        }

        if (in_array('tasks', $types)) {
            $results['tasks'] = Task::where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'title', 'status', 'priority']);
        }

        if (in_array('clients', $types)) {
            $results['clients'] = Client::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'slug', 'status']);
        }

        return response()->json($results);
    }

    /**
     * Enhanced object search for command palette with comprehensive entity coverage.
     * Returns structured results with URLs for navigation.
     */
    public function objectSearch(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = $validated['query'];
        $limit = $validated['limit'] ?? 5;
        $results = [];

        // Search Projects
        $projects = Project::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%");
        })
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'status', 'description'])
            ->map(fn ($p) => [
                'id' => "project-{$p->id}",
                'type' => 'project',
                'name' => $p->name,
                'description' => $p->description ? substr($p->description, 0, 100) : "Project · {$p->status}",
                'url' => "/projects/{$p->slug}",
                'icon' => 'folder',
                'metadata' => [
                    'status' => $p->status,
                    'slug' => $p->slug,
                ],
            ]);

        if ($projects->isNotEmpty()) {
            $results['projects'] = $projects->toArray();
        }

        // Search Clients
        $clients = Client::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%");
        })
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'status', 'description'])
            ->map(fn ($c) => [
                'id' => "client-{$c->id}",
                'type' => 'client',
                'name' => $c->name,
                'description' => $c->description ? substr($c->description, 0, 100) : "Client · {$c->status}",
                'url' => "/clients/{$c->slug}",
                'icon' => 'users',
                'metadata' => [
                    'status' => $c->status,
                    'slug' => $c->slug,
                ],
            ]);

        if ($clients->isNotEmpty()) {
            $results['clients'] = $clients->toArray();
        }

        // Search Tasks
        $tasks = Task::where(function ($q) use ($query) {
            $q->where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%");
        })
            ->with('project:id,name,slug')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get(['id', 'title', 'description', 'status', 'priority', 'project_id'])
            ->map(fn ($t) => [
                'id' => "task-{$t->id}",
                'type' => 'task',
                'name' => $t->title,
                'description' => $t->project
                    ? "{$t->project->name} · {$t->status} · {$t->priority}"
                    : "{$t->status} · {$t->priority}",
                'url' => $t->project ? "/projects/{$t->project->slug}?task={$t->id}" : "/tasks?task={$t->id}",
                'icon' => 'check-square',
                'metadata' => [
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'project_name' => $t->project?->name,
                ],
            ]);

        if ($tasks->isNotEmpty()) {
            $results['tasks'] = $tasks->toArray();
        }

        // Search Agents
        $agents = Agent::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%");
        })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'description', 'status'])
            ->map(fn ($a) => [
                'id' => "agent-{$a->id}",
                'type' => 'agent',
                'name' => $a->name,
                'description' => $a->description ? substr($a->description, 0, 100) : "AI Agent · {$a->status}",
                'url' => "/agents/{$a->slug}",
                'icon' => 'cpu',
                'metadata' => [
                    'status' => $a->status,
                    'slug' => $a->slug,
                ],
            ]);

        if ($agents->isNotEmpty()) {
            $results['agents'] = $agents->toArray();
        }

        // Search Team Members
        $team = User::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%");
        })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn ($u) => [
                'id' => "user-{$u->id}",
                'type' => 'user',
                'name' => $u->name,
                'description' => "{$u->email} · {$u->role}",
                'url' => "/team?user={$u->id}",
                'icon' => 'user',
                'metadata' => [
                    'email' => $u->email,
                    'role' => $u->role,
                ],
            ]);

        if ($team->isNotEmpty()) {
            $results['team'] = $team->toArray();
        }

        // Search Invoices
        $invoices = Invoice::where(function ($q) use ($query) {
            $q->where('number', 'like', "%{$query}%")
                ->orWhere('subject', 'like', "%{$query}%");
        })
            ->with('client:id,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'number', 'subject', 'status', 'total', 'client_id'])
            ->map(fn ($i) => [
                'id' => "invoice-{$i->id}",
                'type' => 'invoice',
                'name' => "Invoice #{$i->number}",
                'description' => $i->client
                    ? "{$i->client->name} · {$i->status} · \${$i->total}"
                    : "{$i->status} · \${$i->total}",
                'url' => "/invoices/{$i->id}",
                'icon' => 'document',
                'metadata' => [
                    'status' => $i->status,
                    'client_name' => $i->client?->name,
                    'total' => $i->total,
                ],
            ]);

        if ($invoices->isNotEmpty()) {
            $results['invoices'] = $invoices->toArray();
        }

        // Search Approval Requests
        $approvals = ApprovalRequest::where('status', 'pending')
            ->where(function ($q) use ($query) {
                $q->where('action_type', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->with('agentRun.agent:id,name,slug')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'action_type', 'description', 'status', 'agent_run_id'])
            ->map(fn ($ar) => [
                'id' => "approval-{$ar->id}",
                'type' => 'approval',
                'name' => str_replace('.', ' ', ucfirst($ar->action_type)),
                'description' => $ar->agentRun?->agent
                    ? "Approval · {$ar->agentRun->agent->name}"
                    : 'Approval · pending',
                'url' => "/approvals?id={$ar->id}",
                'icon' => 'shield',
                'metadata' => [
                    'status' => $ar->status,
                    'agent_name' => $ar->agentRun?->agent?->name,
                ],
            ]);

        if ($approvals->isNotEmpty()) {
            $results['approvals'] = $approvals->toArray();
        }

        // Count total results
        $totalResults = collect($results)->flatten(1)->count();

        return response()->json([
            'results' => $results,
            'total' => $totalResults,
            'query' => $query,
        ]);
    }

    /**
     * Get quick stats for AI context.
     */
    public function stats()
    {
        return response()->json([
            'projects' => [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
            ],
            'tasks' => [
                'total' => Task::count(),
                'pending' => Task::where('status', 'pending')->count(),
                'in_progress' => Task::where('status', 'in_progress')->count(),
            ],
            'clients' => [
                'total' => Client::count(),
                'active' => Client::where('status', 'active')->count(),
            ],
            'approvals' => [
                'pending' => ApprovalRequest::where('status', 'pending')->count(),
            ],
            'agents' => [
                'total' => Agent::count(),
                'active' => Agent::where('status', 'active')->count(),
            ],
        ]);
    }

    /**
     * Build system context with current data snapshot.
     */
    protected function buildSystemContext(array $pageContext = []): array
    {
        // Get focus summary from capability synthesis
        $briefing = $this->synthesisService->getMorningBriefing();

        $context = [
            'stats' => [
                'active_projects' => Project::where('status', 'active')->count(),
                'pending_tasks' => Task::where('status', 'pending')->count(),
                'in_progress_tasks' => Task::where('status', 'in_progress')->count(),
                'pending_approvals' => ApprovalRequest::where('status', 'pending')->count(),
                'active_agents' => Agent::where('status', 'active')->count(),
            ],
            'focus' => [
                'total_items_needing_attention' => $briefing['summary']['total_items'],
                'critical' => $briefing['summary']['critical'],
                'high' => $briefing['summary']['high'],
                'recommendations' => $briefing['recommendations'],
            ],
            'recent_projects' => Project::orderBy('updated_at', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'slug', 'status'])
                ->toArray(),
            'overdue_tasks' => Task::where('status', '!=', 'completed')
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->limit(5)
                ->get(['id', 'title', 'due_date'])
                ->toArray(),
            'page' => $pageContext,
        ];

        // Enrich context based on current page
        if (! empty($pageContext['route'])) {
            $context['page_specific'] = $this->getPageSpecificContext($pageContext);
        }

        return $context;
    }

    /**
     * Get page-specific context for better AI responses.
     */
    protected function getPageSpecificContext(array $pageContext): array
    {
        $route = $pageContext['route'] ?? '';
        $params = $pageContext['params'] ?? [];

        switch (true) {
            case str_starts_with($route, '/projects/') && ! empty($params['slug']):
                $project = Project::where('slug', $params['slug'])->first();
                if ($project) {
                    return [
                        'type' => 'project',
                        'project' => [
                            'id' => $project->id,
                            'name' => $project->name,
                            'status' => $project->status,
                            'tasks_count' => $project->tasks()->count(),
                            'pending_tasks' => $project->tasks()->where('status', 'pending')->count(),
                        ],
                    ];
                }
                break;

            case str_starts_with($route, '/clients/') && ! empty($params['slug']):
                $client = Client::where('slug', $params['slug'])->first();
                if ($client) {
                    return [
                        'type' => 'client',
                        'client' => [
                            'id' => $client->id,
                            'name' => $client->name,
                            'status' => $client->status,
                            'projects_count' => $client->projects()->count(),
                        ],
                    ];
                }
                break;

            case str_starts_with($route, '/agents/') && ! empty($params['slug']):
                $agent = Agent::where('slug', $params['slug'])->first();
                if ($agent) {
                    return [
                        'type' => 'agent',
                        'agent' => [
                            'id' => $agent->id,
                            'name' => $agent->name,
                            'status' => $agent->status,
                            'runs_count' => $agent->runs()->count(),
                        ],
                    ];
                }
                break;

            case $route === '/tasks':
                return [
                    'type' => 'tasks_list',
                    'hint' => 'User is viewing the tasks list',
                ];

            case $route === '/projects':
                return [
                    'type' => 'projects_list',
                    'hint' => 'User is viewing the projects list',
                ];

            case $route === '/approvals':
                return [
                    'type' => 'approvals',
                    'pending' => ApprovalRequest::where('status', 'pending')->count(),
                    'hint' => 'User is viewing pending approvals',
                ];
        }

        return [];
    }

    /**
     * Get system prompt with context.
     */
    protected function getSystemPrompt(array $context): string
    {
        $stats = json_encode($context['stats'], JSON_PRETTY_PRINT);
        $focus = json_encode($context['focus'], JSON_PRETTY_PRINT);
        $recentProjects = json_encode($context['recent_projects'], JSON_PRETTY_PRINT);
        $overdueTasks = json_encode($context['overdue_tasks'], JSON_PRETTY_PRINT);

        // Build page context section
        $pageContextSection = '';
        if (! empty($context['page_specific'])) {
            $pageSpecific = json_encode($context['page_specific'], JSON_PRETTY_PRINT);
            $pageContextSection = <<<PAGE

## Current Page Context
The user is currently viewing:
{$pageSpecific}

When the user asks to create tasks or perform actions, consider the current page context.
For example, if they're on a project page, default to that project for new tasks.
PAGE;
        }

        return <<<PROMPT
You are an AI assistant integrated into Zao Dash, an agency command center application.

## Current System State
Stats:
{$stats}

Focus (Human-Required Items):
{$focus}

Recent Projects:
{$recentProjects}

Overdue Tasks:
{$overdueTasks}
{$pageContextSection}

## Available Tools
You have access to tools that can:
- Search for tasks, projects, and clients
- Create new tasks, projects, and clients
- Update task status and priority
- Get pending approvals
- Trigger AI agents
- Provide navigation URLs
- **Get focus recommendations** - use when asked "What should I focus on?" or "What needs my attention?"

When a user asks to create or find something, USE THE APPROPRIATE TOOL rather than just describing what they could do.

## Multi-Step Workflows
You can chain multiple tool calls to complete complex requests. Follow these rules:

1. **Plan the sequence first.** For requests like "create a client, project, and invoice", identify the dependency order: client first (to get client_id), then project (needs client_id, produces project_id), then invoice (needs client_id and optionally project_id).
2. **Use returned IDs from prior steps.** When a create tool succeeds, it returns the created record with its `id`. Use that exact `id` in subsequent tool calls — do not search for it separately.
3. **Handle "already exists" gracefully.** If a create tool returns `"existing": true`, the record already exists. Use the returned `id` and continue with the next step — do not retry the creation.
4. **Never retry a tool with the same parameters.** If a tool fails, try a different approach or inform the user of the issue.
5. **Report results at the end.** After all steps complete, give the user a concise summary of everything that was created, with names and any relevant links.

### Example chain: "Create client Acme, project Website Redesign, and invoice for $5,000"
- Step 1: Call create-client → get client_id from result
- Step 2: Call create-project with client_id → get project_id from result
- Step 3: Call create-invoice with client_id, project_id, and fixed_fees
- Step 4: Summarize all three created items to the user

## Response Guidelines
- Keep responses concise and actionable — 1-3 short sentences is ideal
- Use plain text only. Do NOT use markdown headers (#), bold (**), bullet lists, or code blocks — the command palette renders in a compact UI
- When referencing items, include their name/title clearly
- For navigation suggestions, mention the Cmd+K shortcuts
- USE TOOLS to actually perform actions when requested
- If a tool requires approval, let the user know
- When asked about priorities or focus, use the get-focus tool to get detailed recommendations

Current context: The user is accessing you via the command palette (Cmd+K).
PROMPT;
    }

    /**
     * Get recent commands for the current user.
     */
    public function recentCommands()
    {
        return response()->json([
            'recent' => CommandHistory::recent(10),
            'frequent' => CommandHistory::frequent(5),
        ]);
    }

    /**
     * Log a command execution.
     */
    public function logCommand(Request $request)
    {
        $validated = $request->validate([
            'command_id' => 'required|string|max:100',
            'command_type' => 'required|in:navigation,action,agent,ai',
            'query' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        $history = CommandHistory::log(
            $validated['command_id'],
            $validated['command_type'],
            $validated['query'] ?? null,
            $validated['metadata'] ?? null
        );

        return response()->json([
            'logged' => $history !== null,
        ]);
    }

    /**
     * Get available tools for documentation.
     */
    public function tools()
    {
        return response()->json([
            'tools' => $this->toolRegistry->toArray(),
        ]);
    }

    /**
     * Send SSE event.
     */
    protected function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Get a human-readable error message for common API errors.
     */
    protected function getHumanReadableError(\Exception $e): string
    {
        $message = $e->getMessage();

        // Rate limit errors
        if (str_contains($message, 'rate_limit_error') || str_contains($message, 'rate limit')) {
            return 'AI service is temporarily busy. Please wait a moment and try again.';
        }

        // Overloaded errors
        if (str_contains($message, 'overloaded') || str_contains($message, 'capacity')) {
            return 'AI service is experiencing high demand. Please try again in a few seconds.';
        }

        // Authentication errors
        if (str_contains($message, 'authentication') || str_contains($message, 'unauthorized') || str_contains($message, '401')) {
            return 'AI service authentication failed. Please contact support.';
        }

        // Token/context length errors
        if (str_contains($message, 'context_length') || str_contains($message, 'token')) {
            return 'Request was too large. Please try a shorter message.';
        }

        // Network/timeout errors
        if (str_contains($message, 'timeout') || str_contains($message, 'Connection')) {
            return 'Could not connect to AI service. Please check your connection and try again.';
        }

        // Default fallback
        return 'Failed to get AI response. Please try again.';
    }

    /**
     * Extract retry-after seconds from error if available.
     */
    protected function getRetryAfterSeconds(\Exception $e): ?int
    {
        $message = $e->getMessage();

        // Try to extract retry timing from rate limit messages
        if (preg_match('/try again (?:in |after )?(\d+)\s*(?:second|sec)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        // Default retry timing for rate limits
        if (str_contains($message, 'rate_limit_error') || str_contains($message, 'rate limit')) {
            return 30; // Suggest 30 second wait for rate limits
        }

        if (str_contains($message, 'overloaded')) {
            return 10; // Suggest 10 second wait for overload
        }

        return null;
    }
}
