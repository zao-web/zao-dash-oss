<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentActivityLog;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\Agents\AgentExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    public function __construct(
        protected AgentExecutor $agentExecutor
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:agents,slug',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,paused,disabled',
            'model' => 'required|in:opus,sonnet,haiku',
            'requires_approval' => 'nullable|boolean',
            'use_consortium' => 'nullable|boolean',
            'max_budget_usd' => 'nullable|numeric|min:0',
            'system_prompt' => 'nullable|string',
            'skill_file' => 'nullable|string|max:255',
            'tools' => 'nullable|array',
            'schedule' => 'nullable|string',
            // Trigger configuration
            'trigger_type' => 'nullable|in:manual,scheduled,webhook,chained',
            'chain_from' => 'nullable|string|exists:agents,slug',
            'cron_expression' => 'nullable|string|max:100',
        ]);

        // Normalize slug
        $validated['slug'] = Str::slug($validated['slug']);

        // Convert tools array to allowed_tools for database
        if (isset($validated['tools'])) {
            $validated['allowed_tools'] = $validated['tools'];
            unset($validated['tools']);
        }

        // Build trigger_config from individual fields
        $triggerConfig = [];
        if (isset($validated['trigger_type'])) {
            $triggerConfig['trigger_type'] = $validated['trigger_type'];
            unset($validated['trigger_type']);
        }
        if (isset($validated['chain_from'])) {
            $triggerConfig['chain_from'] = $validated['chain_from'];
            unset($validated['chain_from']);
        }
        if (isset($validated['cron_expression'])) {
            $triggerConfig['cron'] = $validated['cron_expression'];
            unset($validated['cron_expression']);
        }
        if (! empty($triggerConfig)) {
            $validated['trigger_config'] = $triggerConfig;
        }

        // Set defaults
        $validated['requires_approval'] = $validated['requires_approval'] ?? true;
        $validated['status'] = $validated['status'] ?? 'paused';
        $validated['max_budget_usd'] = $validated['max_budget_usd'] ?? 10;
        $validated['is_dynamic'] = true; // UI-created agents are always dynamic

        $agent = Agent::create($validated);

        return redirect()->back()->with('success', 'Agent created successfully.');
    }

    public function update(Request $request, string $agent)
    {
        $agent = Agent::where('slug', $agent)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,paused,disabled',
            'model' => 'required|in:opus,sonnet,haiku',
            'requires_approval' => 'nullable|boolean',
            'use_consortium' => 'nullable|boolean',
            'max_budget_usd' => 'nullable|numeric|min:0',
            'system_prompt' => 'nullable|string',
            'skill_file' => 'nullable|string|max:255',
            'tools' => 'nullable|array',
            'schedule' => 'nullable|string',
            // Trigger configuration
            'trigger_type' => 'nullable|in:manual,scheduled,webhook,chained',
            'chain_from' => 'nullable|string|exists:agents,slug',
            'cron_expression' => 'nullable|string|max:100',
        ]);

        // Update slug if name changes
        if ($validated['name'] !== $agent->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Convert tools array to allowed_tools for database
        if (isset($validated['tools'])) {
            $validated['allowed_tools'] = $validated['tools'];
            unset($validated['tools']);
        }

        // Build trigger_config from individual fields
        $triggerConfig = $agent->trigger_config ?? [];
        if (array_key_exists('trigger_type', $validated)) {
            $triggerConfig['trigger_type'] = $validated['trigger_type'];
            unset($validated['trigger_type']);
        }
        if (array_key_exists('chain_from', $validated)) {
            $triggerConfig['chain_from'] = $validated['chain_from'];
            unset($validated['chain_from']);
        }
        if (array_key_exists('cron_expression', $validated)) {
            $triggerConfig['cron'] = $validated['cron_expression'];
            unset($validated['cron_expression']);
        }
        $validated['trigger_config'] = $triggerConfig;

        $agent->update($validated);

        return redirect()->back()->with('success', 'Agent updated successfully.');
    }

    public function destroy(string $agent)
    {
        $agent = Agent::where('slug', $agent)->firstOrFail();
        $agent->delete();

        return redirect()->route('agents.index')->with('success', 'Agent deleted successfully.');
    }

    public function updateStatus(Request $request, string $agent)
    {
        $agent = Agent::where('slug', $agent)->firstOrFail();

        $validated = $request->validate([
            'status' => 'required|in:active,paused,disabled',
        ]);

        $agent->update(['status' => $validated['status']]);

        return redirect()->back()->with('success', 'Agent status updated successfully.');
    }

    /**
     * Trigger an agent execution.
     *
     * Can be called from command palette, API, or UI.
     */
    public function trigger(Request $request, string $agent)
    {
        $agent = Agent::where('slug', $agent)->firstOrFail();

        $validated = $request->validate([
            'prompt' => 'nullable|string',
            'context' => 'nullable|array',
            'async' => 'nullable|boolean',
        ]);

        $config = [
            'prompt' => $validated['prompt'] ?? '',
            'context' => $validated['context'] ?? [],
        ];

        // Determine invocation source
        $source = $request->header('X-Invocation-Source', AgentRun::SOURCE_MANUAL);
        if ($request->is('api/*')) {
            $source = AgentRun::SOURCE_API;
        }

        // Always execute via queue to prevent timeout
        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: $source,
            invokedBy: auth()->id() ? 'user:'.auth()->id() : null,
        );

        // Log the trigger
        AgentActivityLog::logTriggered($agent, $source);

        // Return JSON for API/command palette, redirect for UI
        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'queued',
                'message' => 'Agent execution queued',
            ]);
        }

        return redirect()->back()
            ->with('success', 'Agent execution queued. Check activity feed for progress.');
    }

    /**
     * Get agent execution status.
     */
    public function status(string $agent, int $runId)
    {
        $agent = Agent::where('slug', $agent)->firstOrFail();
        $run = AgentRun::where('agent_id', $agent->id)
            ->where('id', $runId)
            ->firstOrFail();

        return response()->json([
            'status' => $run->status,
            'output' => $run->output,
            'cost_usd' => $run->cost_usd,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at,
        ]);
    }

    /**
     * Cancel a running agent execution.
     */
    public function cancelRun(Agent $agent, AgentRun $run)
    {
        if ($run->agent_id !== $agent->id) {
            abort(404);
        }

        if (! in_array($run->status, ['pending', AgentRun::STATUS_RUNNING, AgentRun::STATUS_PENDING_APPROVAL, AgentRun::STATUS_AWAITING_INPUT], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Run cannot be cancelled - status is: '.$run->status,
            ], 422);
        }

        $previousStatus = $run->status;
        $run = $this->agentExecutor->cancel($run, 'Cancelled by user');

        // Log the cancellation
        AgentActivityLog::create([
            'agent_id' => $agent->id,
            'type' => 'run_cancelled',
            'metadata' => [
                'run_id' => $run->id,
                'previous_status' => $previousStatus,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Run cancelled successfully',
        ]);
    }

    /**
     * List available agents for command palette.
     */
    public function list()
    {
        $agents = Agent::where('status', 'active')
            ->select(['id', 'name', 'slug', 'description', 'requires_approval'])
            ->get();

        return response()->json($agents);
    }

    /**
     * API: Get single agent details.
     */
    public function apiShow(Agent $agent)
    {
        return response()->json([
            'agent' => $agent->load(['runs' => fn ($q) => $q->latest()->limit(5)]),
            'stats' => [
                'total_runs' => $agent->runs()->count(),
                'completed_runs' => $agent->runs()->where('status', 'completed')->count(),
                'total_cost' => $agent->runs()->sum('cost_usd'),
            ],
        ]);
    }

    /**
     * API: Create agent.
     */
    public function apiStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:agents,slug',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,paused,disabled',
            'model' => 'required|in:opus,sonnet,haiku',
            'requires_approval' => 'nullable|boolean',
            'use_consortium' => 'nullable|boolean',
            'max_budget_usd' => 'nullable|numeric|min:0',
            'system_prompt' => 'nullable|string',
            'skill_file' => 'nullable|string|max:255',
            'tools' => 'nullable|array',
            'schedule' => 'nullable|string',
            'trigger_config' => 'nullable|array',
            'trigger_config.trigger_type' => 'nullable|in:manual,scheduled,webhook,chained',
            'trigger_config.chain_from' => 'nullable|string|exists:agents,slug',
            'trigger_config.cron' => 'nullable|string|max:100',
        ]);

        // Generate slug if not provided
        $validated['slug'] = isset($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        // Ensure unique slug
        $baseSlug = $validated['slug'];
        $counter = 1;
        while (Agent::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug.'-'.$counter++;
        }

        // Convert tools array to allowed_tools
        if (isset($validated['tools'])) {
            $validated['allowed_tools'] = $validated['tools'];
            unset($validated['tools']);
        }

        // Set defaults
        $validated['requires_approval'] = $validated['requires_approval'] ?? true;
        $validated['status'] = $validated['status'] ?? 'paused';
        $validated['max_budget_usd'] = $validated['max_budget_usd'] ?? 10;
        $validated['is_dynamic'] = true;

        $agent = Agent::create($validated);

        return response()->json([
            'message' => 'Agent created successfully',
            'agent' => $agent,
        ], 201);
    }

    /**
     * API: Update agent.
     */
    public function apiUpdate(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,paused,disabled',
            'model' => 'sometimes|in:opus,sonnet,haiku',
            'requires_approval' => 'nullable|boolean',
            'use_consortium' => 'nullable|boolean',
            'max_budget_usd' => 'nullable|numeric|min:0',
            'system_prompt' => 'nullable|string',
            'skill_file' => 'nullable|string|max:255',
            'tools' => 'nullable|array',
            'schedule' => 'nullable|string',
            'trigger_config' => 'nullable|array',
            'trigger_config.trigger_type' => 'nullable|in:manual,scheduled,webhook,chained',
            'trigger_config.chain_from' => 'nullable|string|exists:agents,slug',
            'trigger_config.cron' => 'nullable|string|max:100',
        ]);

        // Convert tools array to allowed_tools
        if (isset($validated['tools'])) {
            $validated['allowed_tools'] = $validated['tools'];
            unset($validated['tools']);
        }

        $agent->update($validated);

        return response()->json([
            'message' => 'Agent updated successfully',
            'agent' => $agent->fresh(),
        ]);
    }

    /**
     * API: Delete agent.
     */
    public function apiDestroy(Agent $agent)
    {
        $agent->delete();

        return response()->json([
            'message' => 'Agent deleted successfully',
        ]);
    }

    /**
     * API: List agent runs.
     */
    public function runs(Request $request, Agent $agent)
    {
        $query = $agent->runs()->with('agent:id,name,slug');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $runs = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($runs);
    }

    /**
     * Dry run (sandbox mode) - preview execution without side effects.
     */
    public function dryRun(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'prompt' => 'nullable|string',
            'context' => 'nullable|array',
        ]);

        $config = [
            'prompt' => $validated['prompt'] ?? '',
            'context' => $validated['context'] ?? [],
        ];

        $result = $this->agentExecutor->dryRun($agent, $config);

        return response()->json($result);
    }

    /**
     * Launch an agent from a proactive insight.
     *
     * Maps insight action types to specific agents and triggers them
     * with the appropriate configuration.
     */
    public function launchFromInsight(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:upsell,landing,case_study,followup,review',
            'client' => 'nullable|string|exists:clients,slug',
            'tone' => 'nullable|string|in:professional,friendly,formal,casual,technical',
            'focus' => 'nullable|string|max:500',
            'additionalContext' => 'nullable|string|max:2000',
            'notifyOnComplete' => 'nullable|boolean',
        ]);

        // Map insight types to agent slugs
        $agentMap = [
            'upsell' => 'upsell-proposal',
            'landing' => 'landing-page-generator',
            'case_study' => 'case-study-writer',
            'followup' => 'lead-nurture',
            'review' => 'client-health-monitor',
        ];

        $agentSlug = $agentMap[$validated['type']] ?? null;
        if (! $agentSlug) {
            return back()->with('error', 'Unknown insight type.');
        }

        $agent = Agent::where('slug', $agentSlug)->first();
        if (! $agent) {
            return back()->with('error', "Agent '{$agentSlug}' not found. Run `php artisan agents:sync` to register agents.");
        }

        if ($agent->status !== 'active') {
            return back()->with('error', "Agent '{$agent->name}' is not active. Activate it in agent settings.");
        }

        // Build context from insight data
        $context = [
            'client_slug' => $validated['client'] ?? null,
            'tone' => $validated['tone'] ?? 'professional',
            'focus' => $validated['focus'] ?? null,
            'additional_context' => $validated['additionalContext'] ?? null,
            'notify_on_complete' => $validated['notifyOnComplete'] ?? true,
            'triggered_from' => 'proactive_insight',
            'insight_type' => $validated['type'],
        ];

        // Build prompt based on insight type
        $prompts = [
            'upsell' => "Draft an upsell proposal for client: {$validated['client']}. Focus: {$validated['focus']}.",
            'landing' => "Generate a landing page. Focus: {$validated['focus']}. Context: {$validated['additionalContext']}.",
            'case_study' => "Write a case study for client: {$validated['client']}. Focus: {$validated['focus']}.",
            'followup' => "Review and draft follow-up for leads. Focus: {$validated['focus']}.",
            'review' => "Analyze client health. Client: {$validated['client']}.",
        ];

        $config = [
            'prompt' => $prompts[$validated['type']] ?? '',
            'context' => array_filter($context),
        ];

        // Execute asynchronously
        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_MANUAL,
            invokedBy: auth()->id() ? 'user:'.auth()->id() : 'insight',
            triggerMetadata: [
                'insight_type' => $validated['type'],
                'client' => $validated['client'] ?? null,
            ],
        );

        return back()->with('success', "Agent '{$agent->name}' launched. Track progress in the Activity feed.");
    }

    /**
     * Link an agent run to a project and/or task.
     *
     * This allows retroactively associating a completed run
     * with a project/task for activity tracking.
     */
    public function linkRun(Request $request, Agent $agent, AgentRun $run)
    {
        if ($run->agent_id !== $agent->id) {
            abort(404);
        }

        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
        ]);

        $projectId = $validated['project_id'] ?? null;
        $taskId = $validated['task_id'] ?? null;

        if (! $projectId && ! $taskId) {
            return response()->json([
                'message' => 'At least one of project_id or task_id is required',
            ], 422);
        }

        $hadTaskBefore = (bool) $run->task_id;

        $run->update([
            'project_id' => $projectId ?? $run->project_id,
            'task_id' => $taskId ?? $run->task_id,
        ]);

        if ($taskId && ! $hadTaskBefore && in_array($run->status, ['completed', 'failed'])) {
            $task = Task::find($taskId);
            if ($task) {
                if ($run->status === 'completed') {
                    TaskActivity::logAgentCompleted($task, $run);

                    $output = $run->output ?? [];
                    $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;
                    $prNumber = $output['pr_number'] ?? null;

                    if (! $prUrl) {
                        $result = $output['result'] ?? '';
                        if (is_string($result) && preg_match('#https://github\.com/[^/]+/[^/]+/pull/(\d+)#', $result, $matches)) {
                            $prUrl = $matches[0];
                            $prNumber = $matches[1];
                        }
                    }

                    if ($prUrl && $prNumber) {
                        TaskActivity::logPrCreated($task, $prUrl, (string) $prNumber, $run);
                    }
                } else {
                    $error = $run->output['error'] ?? $run->error_message ?? 'Unknown error';
                    TaskActivity::logAgentFailed($task, $run, $error);
                }
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Run linked successfully',
                'run' => $run->fresh(['project', 'task']),
            ]);
        }

        return redirect()->back()->with('success', 'Run linked to project/task successfully.');
    }

    /**
     * Clone an existing agent.
     */
    public function clone(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $newName = $validated['name'] ?? $agent->name.' (Copy)';
        $newSlug = Str::slug($newName);

        // Ensure unique slug
        $counter = 1;
        $baseSlug = $newSlug;
        while (Agent::where('slug', $newSlug)->exists()) {
            $newSlug = $baseSlug.'-'.$counter++;
        }

        $clone = Agent::create([
            'name' => $newName,
            'slug' => $newSlug,
            'description' => $agent->description,
            'status' => 'paused', // Always start paused
            'model' => $agent->model,
            'requires_approval' => $agent->requires_approval,
            'max_budget_usd' => $agent->max_budget_usd,
            'allowed_tools' => $agent->allowed_tools,
            'system_prompt' => $agent->system_prompt,
            'schedule' => null, // Don't copy schedule
            'is_dynamic' => true, // Clones are always dynamic
        ]);

        // Log the clone activity (on both original and clone)
        AgentActivityLog::logCloned($agent, $clone);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Agent cloned successfully',
                'agent' => $clone,
            ]);
        }

        return redirect()->route('agents.show', $clone->slug)
            ->with('success', 'Agent cloned successfully.');
    }
}
