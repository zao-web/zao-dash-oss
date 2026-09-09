<?php

namespace App\Http\Controllers;

use App\Events\SiteBuilderMessageReceived;
use App\Models\Agent;
use App\Models\SiteBuilderProject;
use App\Services\Agents\AgentExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SiteBuilderController extends Controller
{
    /**
     * Show the site builder interface.
     */
    public function index()
    {
        return inertia('SiteBuilder/Index');
    }

    /**
     * Show a specific site builder project.
     */
    public function show(SiteBuilderProject $project)
    {
        // Ensure user owns this project
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        return inertia('SiteBuilder/Show', [
            'project' => $project->load('user'),
            'progressPercentage' => $project->getProgressPercentage(),
            'liveUrl' => $project->getLiveUrl(),
        ]);
    }

    public function __construct(
        private AgentExecutor $agentExecutor
    ) {}

    /**
     * Create a new site builder project.
     */
    public function createProject(Request $request): JsonResponse
    {
        // Clean domain - strip protocol and trailing slashes
        $domain = $request->input('domain');
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        $request->merge(['domain' => $domain]);

        $validator = Validator::make($request->all(), [
            'domain' => 'required|string|regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
            'brief' => 'required|string|min:10|max:2000',
            'company_type' => 'required|in:active,defunct,startup,enterprise',
            'target_hosting' => 'required|in:wordpress_com,self_hosted,existing_site',
            'environment' => 'required|in:staging,production',
            'content_sources' => 'nullable|array',
            'source_url' => 'nullable|url',
            'industry' => 'nullable|string|max:100',
            'timeline' => 'required|in:rush,standard,extended',
            'budget_limit' => 'nullable|numeric|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $project = SiteBuilderProject::create([
            'domain' => $request->domain,
            'project_name' => $request->project_name ?? ucfirst(explode('.', $request->domain)[0]).' Website',
            'brief' => $request->brief,
            'company_type' => $request->company_type,
            'status' => SiteBuilderProject::STATUS_CREATED,
            'environment' => $request->environment,
            'target_hosting' => $request->target_hosting,
            'user_id' => Auth::id(),
            'budget_allocated' => $request->budget_limit,
            'estimated_completion' => now()->addMinutes($this->calculateEstimatedMinutes($request->all())),
        ]);

        // Trigger the site builder orchestrator agent
        $agentConfig = $request->only([
            'domain', 'brief', 'company_type', 'target_hosting', 'environment',
            'content_sources', 'source_url', 'industry', 'timeline',
        ]);

        try {
            // Find the site builder orchestrator agent
            $agent = Agent::where('slug', 'site-builder-orchestrator')->first();

            if (! $agent) {
                throw new \Exception('Site builder orchestrator agent not found. Please ensure the agent is configured.');
            }

            // Build proper prompt/context structure for agent
            $prompt = $this->buildInitialPrompt($project, $agentConfig);
            $config = [
                'prompt' => $prompt,
                'context' => array_merge($agentConfig, [
                    'project_id' => $project->id,
                    'project' => $project->toArray(),
                ]),
            ];

            $run = $this->agentExecutor->execute(
                agent: $agent,
                config: $config,
                invocationSource: 'site_builder_api',
                projectId: $project->id
            );

            $project->update([
                'agent_runs' => [$run->id],
                'status' => SiteBuilderProject::STATUS_RESEARCH,
            ]);

            return response()->json([
                'success' => true,
                'project' => $project->load('user'),
                'agent_run_id' => $run->id,
                'message' => 'Site builder project created and agent execution started',
            ]);

        } catch (\Exception $e) {
            $project->update([
                'status' => SiteBuilderProject::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to start site building process: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get project details.
     */
    public function getProject(SiteBuilderProject $project): JsonResponse
    {
        // Ensure user owns this project
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'project' => $project->load(['user', 'wordpressSite', 'agentRuns']),
            'progress_percentage' => $project->getProgressPercentage(),
            'live_url' => $project->getLiveUrl(),
        ]);
    }

    /**
     * Get project status updates.
     */
    public function getProjectStatus(SiteBuilderProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'status' => $project->status,
            'progress_percentage' => $project->getProgressPercentage(),
            'progress_data' => $project->progress_data,
            'estimated_completion' => $project->estimated_completion,
            'last_error' => $project->last_error,
            'live_url' => $project->getLiveUrl(),
            'client_credentials' => $project->getClientCredentials(),
        ]);
    }

    /**
     * Deploy project to production.
     */
    public function deployProject(Request $request, SiteBuilderProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($project->status !== SiteBuilderProject::STATUS_COMPLETE) {
            return response()->json([
                'success' => false,
                'error' => 'Project must be completed before deployment',
            ], 400);
        }

        if ($project->environment === SiteBuilderProject::ENV_PRODUCTION) {
            return response()->json([
                'success' => false,
                'error' => 'Project is already in production',
            ], 400);
        }

        // Trigger deployment agent (would need a deployment agent)
        // For now, just update the environment
        $project->update([
            'environment' => SiteBuilderProject::ENV_PRODUCTION,
            'production_url' => $project->staging_url, // In real implementation, this would be different
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project deployed to production',
            'production_url' => $project->production_url,
        ]);
    }

    /**
     * Get preview URL for project.
     */
    public function getPreviewUrl(SiteBuilderProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $url = $project->getLiveUrl();

        if (! $url) {
            return response()->json([
                'success' => false,
                'error' => 'No preview URL available yet',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'preview_url' => $url,
            'is_production' => $project->environment === SiteBuilderProject::ENV_PRODUCTION,
        ]);
    }

    /**
     * List user's site builder projects.
     */
    public function listProjects(Request $request): JsonResponse
    {
        $projects = SiteBuilderProject::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'projects' => $projects,
        ]);
    }

    public function sendChatMessage(Request $request, SiteBuilderProject $project): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:2000',
            'context' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $userMessage = $request->input('message');
        $context = $request->input('context', []);

        try {
            $agent = Agent::where('slug', 'site-builder-orchestrator')->first();

            if (! $agent) {
                broadcast(new SiteBuilderMessageReceived(
                    project: $project,
                    content: 'The site builder agent is not configured. Please contact support.',
                    role: 'assistant'
                ));

                return response()->json([
                    'success' => false,
                    'error' => 'Agent not configured',
                ], 500);
            }

            $this->agentExecutor->execute(
                agent: $agent,
                config: [
                    'prompt' => $userMessage,
                    'context' => [
                        'project_id' => $project->id,
                        'project' => $project->toArray(),
                        'mode' => 'chat',
                        'conversation_history' => $context,
                    ],
                ],
                invocationSource: 'site_builder_chat',
                projectId: $project->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Message sent',
            ]);

        } catch (\Exception $e) {
            broadcast(new SiteBuilderMessageReceived(
                project: $project,
                content: 'Sorry, I encountered an error processing your message. Please try again.',
                role: 'assistant',
                metadata: ['error' => $e->getMessage()]
            ));

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function calculateEstimatedMinutes(array $config): int
    {
        $baseMinutes = 40;

        $companyMultiplier = match ($config['company_type']) {
            SiteBuilderProject::COMPANY_DEFUNCT => 1.5,
            SiteBuilderProject::COMPANY_STARTUP => 0.8,
            SiteBuilderProject::COMPANY_ENTERPRISE => 1.8,
            default => 1.0,
        };

        $timelineMultiplier = match ($config['timeline']) {
            'rush' => 0.7,
            'extended' => 1.3,
            default => 1.0,
        };

        return (int) ($baseMinutes * $companyMultiplier * $timelineMultiplier);
    }

    /**
     * Build an initial prompt for the site builder orchestrator.
     */
    private function buildInitialPrompt(SiteBuilderProject $project, array $config): string
    {
        $domain = $project->domain;
        $brief = $config['brief'] ?? $project->brief ?? '';
        $companyType = $config['company_type'] ?? 'active';
        $targetHosting = $config['target_hosting'] ?? 'wordpress_com';

        $prompt = "Build a website for {$domain}.";

        if ($companyType === 'defunct') {
            $prompt .= ' This is a defunct company - research archive.org for historical content.';
        } elseif ($companyType === 'startup') {
            $prompt .= ' This is a new startup - focus on modern, minimal design.';
        } elseif ($companyType === 'enterprise') {
            $prompt .= ' This is an enterprise client - ensure professional, scalable architecture.';
        }

        if ($brief) {
            $prompt .= "\n\nProject Brief:\n{$brief}";
        }

        return $prompt;
    }
}
