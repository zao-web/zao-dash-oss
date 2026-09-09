<?php

namespace App\Http\Controllers;

use App\Agents\ToolRegistry;
use App\Http\Requests\SendProjectMessageRequest;
use App\Jobs\AnalyzeOlliePagesJob;
use App\Models\Agent;
use App\Models\WebsiteProject;
use App\Models\WebsiteProjectAsset;
use App\Services\Agents\AgentExecutor;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebsiteBuilderController extends Controller
{
    public function __construct(
        private AgentExecutor $agentExecutor,
        private ToolRegistry $toolRegistry,
        private WebsiteProjectAssetService $assetService
    ) {}

    public function index()
    {
        $projects = WebsiteProject::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        $wordpressSites = \App\Models\WordPressSite::select('id', 'name', 'url', 'is_primary')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return inertia('WebsiteBuilder/Index', [
            'projects' => $projects,
            'wordpressSites' => $wordpressSites,
        ]);
    }

    public function deleteProject(WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $project->delete();

        return response()->json(['success' => true]);
    }

    public function restartProject(WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        if (! in_array($project->status, [WebsiteProject::STATUS_FAILED, WebsiteProject::STATUS_CREATED])) {
            return response()->json([
                'success' => false,
                'error' => 'Only failed or created projects can be restarted',
            ], 422);
        }

        $project->update([
            'status' => WebsiteProject::STATUS_CREATED,
            'last_error' => null,
            'retry_count' => $project->retry_count + 1,
        ]);

        if ($project->isAutonomous()) {
            $sourceData = is_string($project->source_data)
                ? json_decode($project->source_data, true)
                : $project->source_data;

            $this->triggerAutonomousAgent($project, array_merge(
                ['project_type' => $project->project_type],
                $sourceData ?? []
            ));
        }

        return response()->json([
            'success' => true,
            'project' => $project->fresh(),
        ]);
    }

    public function show(WebsiteProject $project)
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        return inertia('WebsiteBuilder/Show', [
            'project' => $project->load('user'),
            'progressPercentage' => $project->getProgressPercentage(),
            'liveUrl' => $project->getLiveUrl(),
        ]);
    }

    public function listProjects(Request $request): JsonResponse
    {
        $query = WebsiteProject::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        if ($request->has('status')) {
            $query->withStatus($request->status);
        }

        $projects = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'projects' => $projects,
        ]);
    }

    public function createProject(Request $request): JsonResponse
    {
        $domain = $request->input('domain');
        if ($domain) {
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');
            $request->merge(['domain' => $domain]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'project_type' => 'required|in:autonomous,guided,migration,redesign',
            'source_type' => 'nullable|in:domain,brief,url,github,manual',
            'source_data' => 'nullable|array',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
            'brief' => 'nullable|string|min:10|max:5000',
            'wordpress_site_id' => 'nullable|integer|exists:wordpress_sites,id',
            'hosting_type' => 'nullable|in:wordpress_com,self_hosted,existing_site',
            'environment' => 'nullable|in:staging,production',
            'budget_limit' => 'nullable|numeric|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $sourceData = $request->source_data ?? [];
        if ($request->brief) {
            $sourceData['brief'] = $request->brief;
        }
        if ($request->url) {
            $sourceData['url'] = $request->url;
        }
        if ($request->github_url) {
            $sourceData['github_url'] = $request->github_url;
        }

        $project = WebsiteProject::create([
            'name' => $request->name,
            'user_id' => Auth::id(),
            'project_type' => $request->project_type,
            'source_type' => $request->source_type ?? WebsiteProject::SOURCE_DOMAIN,
            'source_data' => ! empty($sourceData) ? json_encode($sourceData) : null,
            'domain' => $request->domain,
            'wordpress_site_id' => $request->wordpress_site_id,
            'hosting_type' => $request->wordpress_site_id ? WebsiteProject::HOSTING_EXISTING_SITE : ($request->hosting_type ?? WebsiteProject::HOSTING_WORDPRESS_COM),
            'environment' => $request->environment ?? WebsiteProject::ENV_STAGING,
            'status' => WebsiteProject::STATUS_CREATED,
            'budget_allocated' => $request->budget_limit,
            'estimated_completion' => now()->addMinutes($this->calculateEstimatedMinutes($request->all())),
        ]);

        if ($request->project_type === WebsiteProject::TYPE_AUTONOMOUS) {
            $this->triggerAutonomousAgent($project, $request->all());
        }

        return response()->json([
            'success' => true,
            'project' => $project,
        ]);
    }

    public function updateProject(WebsiteProject $project, Request $request): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:created,analyzing,designing,building,reviewing,deploying,complete,failed',
            'design_config' => 'sometimes|array',
            'pages' => 'sometimes|array',
            'patterns_selected' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $project->update($request->only([
            'name', 'status', 'design_config', 'pages', 'patterns_selected',
        ]));

        return response()->json([
            'success' => true,
            'project' => $project->fresh(),
        ]);
    }

    public function sendMessage(WebsiteProject $project, Request $request): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $agent = Agent::where('slug', 'website-builder-orchestrator')
                ->orWhere('slug', 'site-builder-orchestrator')
                ->first();

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Agent not configured',
                ], 500);
            }

            $config = [
                'prompt' => $request->message,
                'context' => [
                    'project_id' => $project->id,
                    'project' => $project->toArray(),
                    'project_type' => $project->project_type,
                    'current_status' => $project->status,
                    'source_data' => $project->source_data,
                ],
            ];

            $agentRun = $this->agentExecutor->execute($agent, $config);

            return response()->json([
                'success' => true,
                'message' => 'Message sent',
                'agent_run_id' => $agentRun->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStatus(WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        return response()->json([
            'success' => true,
            'status' => $project->status,
            'progress_percentage' => $project->getProgressPercentage(),
            'progress_data' => $project->phase_progress,
            'estimated_completion' => $project->estimated_completion?->toIso8601String(),
            'staging_url' => $project->staging_url,
            'production_url' => $project->production_url,
            'last_error' => $project->last_error,
        ]);
    }

    public function getMessages(WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $messages = $project->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($m) => $m->toFrontendArray());

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    public function chat(SendProjectMessageRequest $request, WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        try {
            $agent = Agent::where('slug', 'website-builder-orchestrator')->first();

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Website Builder Orchestrator agent not found. Please run: php artisan agents:sync',
                ], 500);
            }

            $messageContent = $request->validated('message');
            $userId = Auth::id();

            $userMessage = $project->messages()->create([
                'user_id' => $userId,
                'role' => 'user',
                'content' => $messageContent,
                'status' => 'sent',
            ]);

            $recentMessages = $project->messages()
                ->latest()
                ->take(20)
                ->get()
                ->reverse()
                ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
                ->values()
                ->toArray();

            $config = [
                'prompt' => $messageContent,
                'context' => [
                    'project_id' => $project->id,
                    'project' => $project->toArray(),
                    'project_type' => $project->project_type,
                    'current_status' => $project->status,
                    'source_data' => $project->source_data,
                    'conversation_history' => $recentMessages,
                ],
            ];

            broadcast(new \App\Events\WebsiteBuilderMessageReceived(
                project: $project,
                content: 'Processing your request...',
                role: 'system',
                metadata: [
                    'action' => 'thinking',
                    'isStreaming' => true,
                ]
            ));

            $projectId = $project->id;
            $agentId = $agent->id;

            dispatch(function () use ($agentId, $config, $projectId) {
                $agent = Agent::find($agentId);
                $project = WebsiteProject::find($projectId);

                if (! $agent || ! $project) {
                    return;
                }

                try {
                    $run = app(AgentExecutor::class)->execute($agent, $config);

                    if ($run->status === 'completed' && $run->output) {
                        $response = is_array($run->output)
                            ? ($run->output['response'] ?? $run->output['result'] ?? json_encode($run->output))
                            : (string) $run->output;

                        $project->messages()->create([
                            'agent_run_id' => $run->id,
                            'role' => 'assistant',
                            'content' => $response,
                            'status' => 'sent',
                            'metadata' => [
                                'run_id' => $run->id,
                                'agent' => $agent->name,
                                'duration_ms' => $run->duration_ms,
                            ],
                        ]);

                        broadcast(new \App\Events\WebsiteBuilderMessageReceived(
                            project: $project,
                            content: $response,
                            role: 'assistant',
                            metadata: [
                                'run_id' => $run->id,
                                'agent' => $agent->name,
                                'duration_ms' => $run->duration_ms,
                            ]
                        ));

                        if (is_array($run->output)) {
                            $updates = [];
                            if (isset($run->output['status'])) {
                                $updates['status'] = $run->output['status'];
                            }
                            if (isset($run->output['progress'])) {
                                $updates['overall_progress'] = $run->output['progress'];
                            }
                            if (! empty($updates)) {
                                $project->update($updates);
                                $freshProject = $project->fresh();
                                broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
                                    project: $freshProject,
                                    status: $freshProject->status,
                                    progress: $freshProject->overall_progress ?? 0
                                ));
                            }
                        }
                    } elseif ($run->status === 'failed') {
                        $errorMessage = is_array($run->output) && isset($run->output['error'])
                            ? $run->output['error']
                            : 'Agent execution failed';

                        $project->messages()->create([
                            'agent_run_id' => $run->id,
                            'role' => 'system',
                            'content' => "Error: {$errorMessage}",
                            'status' => 'error',
                            'metadata' => ['action' => 'error'],
                        ]);

                        broadcast(new \App\Events\WebsiteBuilderError(
                            project: $project,
                            message: $errorMessage
                        ));

                        $project->update(['last_error' => $errorMessage]);
                    }
                } catch (\Exception $e) {
                    $project->messages()->create([
                        'role' => 'system',
                        'content' => "Error: {$e->getMessage()}",
                        'status' => 'error',
                        'metadata' => ['action' => 'error'],
                    ]);

                    broadcast(new \App\Events\WebsiteBuilderError(
                        project: $project,
                        message: $e->getMessage()
                    ));

                    $project->update([
                        'last_error' => $e->getMessage(),
                        'retry_count' => ($project->retry_count ?? 0) + 1,
                    ]);
                }
            })->onQueue('agents');

            return response()->json([
                'success' => true,
                'message' => 'Agent processing started',
                'user_message_id' => $userMessage->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function parseBrief(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required_without:file|string',
            'file' => 'required_without:content|file|mimes:pdf,txt,doc,docx|max:10240',
            'url' => 'nullable|url',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = file_get_contents($file->getRealPath());
            $toolParams = [
                'source' => $content,
                'source_type' => 'pdf',
            ];
        } else {
            $toolParams = [
                'source' => $validated['content'],
                'source_type' => 'text',
            ];
        }

        $result = $this->toolRegistry->execute('ollie-parse-brief', $toolParams);

        return response()->json($result);
    }

    public function analyzeSite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'depth' => 'nullable|in:shallow,deep',
        ]);

        $result = $this->toolRegistry->execute('ollie-analyze-site', $validated);

        return response()->json($result);
    }

    public function analyzeRepo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'repo_url' => 'required|url',
            'depth' => 'nullable|in:quick,deep',
        ]);

        $result = $this->toolRegistry->execute('ollie-analyze-repo', $validated);

        return response()->json($result);
    }

    public function analyzePages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => 'required|url',
            'pages' => 'required|array',
            'pages.*.name' => 'required|string',
            'pages.*.path' => 'required|string',
            'pages.*.url' => 'nullable|string',
        ]);

        $batchId = Str::uuid()->toString();

        Cache::put("ollie-batch:{$batchId}:status", 'pending', 3600);
        Cache::put("ollie-batch:{$batchId}:progress", [
            'completed' => 0,
            'total' => count($validated['pages']),
            'message' => 'Starting analysis...',
        ], 3600);

        AnalyzeOlliePagesJob::dispatch($batchId, $validated['pages'], $validated['base_url']);

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total_pages' => count($validated['pages']),
        ]);
    }

    public function getAnalysisResults(string $batchId): JsonResponse
    {
        $status = Cache::get("ollie-batch:{$batchId}:status");
        $progress = Cache::get("ollie-batch:{$batchId}:progress");
        $results = Cache::get("ollie-batch:{$batchId}:results");

        return response()->json([
            'success' => true,
            'status' => $status ?? 'not_found',
            'progress' => $progress,
            'results' => $results,
        ]);
    }

    public function generateTheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'colors' => 'required|array',
            'colors.primary' => 'required|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'base_style' => 'nullable|string',
            'typography' => 'nullable|array',
        ]);

        // Map 'colors' to 'brand_colors' for the tool
        $toolParams = [
            'project_id' => $validated['project_id'],
            'brand_colors' => $validated['colors'],
            'base_style' => $validated['base_style'] ?? 'default',
            'typography' => $validated['typography'] ?? [],
        ];

        $result = $this->toolRegistry->execute('ollie-generate-theme-json', $toolParams);

        return response()->json($result);
    }

    public function composePage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'page_title' => 'required|string',
            'page_slug' => 'required|string',
            'patterns' => 'required|array',
        ]);

        $result = $this->toolRegistry->execute('ollie-compose-page', $validated);

        return response()->json($result);
    }

    public function listPatterns(): JsonResponse
    {
        $result = $this->toolRegistry->execute('ollie-list-patterns', []);

        return response()->json($result);
    }

    protected function triggerAutonomousAgent(WebsiteProject $project, array $sourceConfig): void
    {
        $agent = Agent::where('slug', 'website-builder-orchestrator')
            ->orWhere('slug', 'site-builder-orchestrator')
            ->first();

        if (! $agent) {
            return;
        }

        // Broadcast immediate "starting" message so user sees activity right away
        broadcast(new \App\Events\WebsiteBuilderMessageReceived(
            project: $project,
            content: "Starting website build for {$project->name}... I'll analyze your requirements and begin creating your site.",
            role: 'assistant',
            metadata: [
                'phase' => 'initializing',
                'action' => 'start',
            ]
        ));

        // Also broadcast initial status update
        broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
            project: $project,
            status: 'analyzing',
            progress: 5,
            phase: 'research'
        ));

        $prompt = $this->buildInitialPrompt($project, $sourceConfig);

        $config = [
            'prompt' => $prompt,
            'context' => [
                'project_id' => $project->id,
                'project' => $project->toArray(),
                'project_type' => $project->project_type,
                'source_data' => $project->source_data,
                'source_config' => $sourceConfig,
            ],
        ];

        dispatch(function () use ($agent, $config, $project) {
            try {
                $run = app(AgentExecutor::class)->execute($agent, $config);

                if ($run->status === 'completed' && $run->output) {
                    $response = is_array($run->output)
                        ? ($run->output['response'] ?? $run->output['result'] ?? json_encode($run->output))
                        : (string) $run->output;

                    broadcast(new \App\Events\WebsiteBuilderMessageReceived(
                        project: $project,
                        content: $response,
                        role: 'assistant',
                        metadata: [
                            'run_id' => $run->id,
                            'agent' => $agent->name,
                        ]
                    ));

                    broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
                        project: $project->fresh(),
                        status: $project->fresh()->status,
                        progress: $project->fresh()->overall_progress ?? $project->fresh()->getProgressPercentage(),
                    ));
                } elseif ($run->status === 'failed') {
                    $errorMessage = is_array($run->output) && isset($run->output['error'])
                        ? $run->output['error']
                        : 'Agent execution failed';

                    $project->update([
                        'status' => WebsiteProject::STATUS_FAILED,
                        'last_error' => $errorMessage,
                    ]);

                    broadcast(new \App\Events\WebsiteBuilderError(
                        project: $project,
                        message: $errorMessage,
                    ));
                }
            } catch (\Exception $e) {
                $project->update([
                    'status' => WebsiteProject::STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                ]);

                broadcast(new \App\Events\WebsiteBuilderError(
                    project: $project,
                    message: $e->getMessage(),
                ));
            }
        })->afterResponse();
    }

    protected function calculateEstimatedMinutes(array $config): int
    {
        $baseMinutes = match ($config['project_type'] ?? 'autonomous') {
            'autonomous' => 40,
            'guided' => 25,
            'migration' => 50,
            'redesign' => 35,
            default => 40,
        };

        if (isset($config['timeline'])) {
            $baseMinutes = match ($config['timeline']) {
                'rush' => (int) ($baseMinutes * 0.7),
                'extended' => (int) ($baseMinutes * 1.5),
                default => $baseMinutes,
            };
        }

        return $baseMinutes;
    }

    /**
     * Internal API endpoint for agent to update project progress.
     * Authenticated via X-Agent-Token header instead of user session.
     */
    public function agentUpdateProgress(Request $request): JsonResponse
    {
        $agentToken = $request->header('X-Agent-Token');
        $expectedToken = config('services.agent.internal_token') ?: env('AGENT_INTERNAL_TOKEN');

        if (! $expectedToken || $agentToken !== $expectedToken) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|integer|exists:website_projects,id',
            'status' => 'nullable|string|in:analyzing,designing,building,reviewing,deploying,complete,failed',
            'phase' => 'nullable|string|max:100',
            'phase_progress' => 'nullable|integer|min:0|max:100',
            'overall_progress' => 'nullable|integer|min:0|max:100',
            'message' => 'nullable|string|max:5000',
            'staging_url' => 'nullable|url|max:500',
            'production_url' => 'nullable|url|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $project = WebsiteProject::findOrFail($request->project_id);

        $updates = [];

        if ($request->has('status')) {
            $updates['status'] = $request->status;
        }

        if ($request->has('overall_progress')) {
            $updates['overall_progress'] = $request->overall_progress;
        }

        if ($request->has('phase') && $request->has('phase_progress')) {
            $phaseProgress = $project->phase_progress ?? [];
            $phaseProgress[$request->phase] = $request->phase_progress;
            $updates['phase_progress'] = $phaseProgress;
        }

        if ($request->has('staging_url')) {
            $updates['staging_url'] = $request->staging_url;
        }

        if ($request->has('production_url')) {
            $updates['production_url'] = $request->production_url;
        }

        if (! empty($updates)) {
            $project->update($updates);
            $project->refresh();
        }

        // Broadcast update to frontend
        broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
            project: $project,
            status: $project->status,
            phase: $request->phase,
            message: $request->message,
            phaseProgress: $request->phase_progress ?? 0,
            progress: $project->overall_progress ?? $project->getProgressPercentage(),
            stagingUrl: $project->staging_url,
            productionUrl: $project->production_url,
        ));

        // If message provided, also broadcast as chat message
        if ($request->message) {
            broadcast(new \App\Events\WebsiteBuilderMessageReceived(
                project: $project,
                content: $request->message,
                role: 'system',
                metadata: ['phase' => $request->phase, 'progress' => $request->overall_progress],
            ));
        }

        return response()->json([
            'success' => true,
            'project' => $project->only(['id', 'status', 'overall_progress', 'phase_progress']),
        ]);
    }

    public function agentDeploy(Request $request): JsonResponse
    {
        $agentToken = $request->header('X-Agent-Token');
        $expectedToken = config('services.agent.internal_token') ?: env('AGENT_INTERNAL_TOKEN');

        if (! $expectedToken || $agentToken !== $expectedToken) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|integer|exists:website_projects,id',
            'wordpress_site_id' => 'nullable|integer|exists:wordpress_sites,id',
            'pages' => 'required|array|min:1',
            'pages.*.title' => 'required|string|max:255',
            'pages.*.slug' => 'required|string|max:200',
            'pages.*.content' => 'required|string',
            'pages.*.template' => 'nullable|string|max:100',
            'pages.*.is_front_page' => 'nullable|boolean',
            'pages.*.parent_slug' => 'nullable|string|max:200',
            'pages.*.menu_order' => 'nullable|integer|min:0',
            'publish' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $deployTool = app(\App\Agents\Tools\WebsiteBuilderDeployTool::class);
        $result = $deployTool->execute($request->all());

        return response()->json($result);
    }

    protected function buildInitialPrompt(WebsiteProject $project, array $sourceConfig): string
    {
        $projectType = $project->project_type;
        $domain = $project->domain ?? 'the website';
        $brief = $sourceConfig['brief'] ?? $project->source_data['brief'] ?? null;

        $prompt = match ($projectType) {
            'autonomous' => "Build a new website autonomously for {$domain}.",
            'guided' => "Start a guided website build for {$domain}. Walk me through each step.",
            'migration' => "Migrate the existing site at {$domain} to OllieWP.",
            'redesign' => "Redesign the existing site at {$domain} while preserving content.",
            default => "Build a website for {$domain}.",
        };

        if ($brief) {
            $prompt .= "\n\nProject Brief:\n{$brief}";
        }

        return $prompt;
    }

    public function uploadAssets(WebsiteProject $project, Request $request): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'required|file|max:51200', // 50MB max per file
            'category' => 'nullable|string|in:logo,hero,background,brief,content,reference,icon,photo',
            'descriptions' => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $uploaded = [];
        $errors = [];

        foreach ($request->file('files') as $index => $file) {
            try {
                $description = $request->input("descriptions.{$index}");
                $asset = $this->assetService->uploadFile(
                    $project,
                    $file,
                    $request->category,
                    $description,
                    Auth::user()
                );
                $uploaded[] = $asset->toArrayForAgent();
            } catch (\Exception $e) {
                $errors[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => count($uploaded) > 0,
            'uploaded' => $uploaded,
            'errors' => $errors,
            'total_uploaded' => count($uploaded),
            'total_errors' => count($errors),
        ]);
    }

    public function listAssets(WebsiteProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $assets = $this->assetService->getAssetsForAgent($project);

        return response()->json([
            'success' => true,
            'assets' => $assets,
        ]);
    }

    public function deleteAsset(WebsiteProject $project, WebsiteProjectAsset $asset): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        if ($asset->website_project_id !== $project->id) {
            return response()->json(['success' => false, 'error' => 'Asset does not belong to this project'], 404);
        }

        $this->assetService->delete($asset);

        return response()->json(['success' => true]);
    }

    public function updateAsset(WebsiteProject $project, WebsiteProjectAsset $asset, Request $request): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        if ($asset->website_project_id !== $project->id) {
            return response()->json(['success' => false, 'error' => 'Asset does not belong to this project'], 404);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'nullable|string|in:logo,hero,background,brief,content,reference,icon,photo',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $asset->update($request->only(['category', 'description']));

        return response()->json([
            'success' => true,
            'asset' => $asset->fresh()->toArrayForAgent(),
        ]);
    }
}
