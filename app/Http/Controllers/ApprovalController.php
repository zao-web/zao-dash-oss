<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteApprovedRunJob;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Services\Agents\AgentExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    public function __construct(
        protected AgentExecutor $agentExecutor
    ) {}

    /**
     * Enhanced approvals dashboard with filtering.
     */
    public function index(Request $request)
    {
        $query = ApprovalRequest::with(['agentRun.agent', 'decidedBy:id,name']);

        // Filter by status (default to 'pending' if not specified)
        $status = $request->input('status') ?? 'pending';
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        // Filter by risk level
        if ($risk = $request->input('risk_level')) {
            $query->where('risk_level', $risk);
        }

        // Filter by agent
        if ($agentId = $request->input('agent_id')) {
            $query->whereHas('agentRun', fn ($q) => $q->where('agent_id', $agentId));
        }

        // Filter by action type
        if ($type = $request->input('action_type')) {
            $query->where('action_type', $type);
        }

        $approvals = $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 WHEN 'expired' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Approvals/Index', [
            'approvals' => $approvals->through(fn ($approval) => $this->serializeApproval($approval)),
            'stats' => $this->safeStats(),
            'filters' => [
                'status' => $request->input('status') ?? 'pending',
                'risk_level' => $request->input('risk_level'),
                'agent_id' => $request->input('agent_id'),
                'action_type' => $request->input('action_type'),
            ],
            'agents' => $this->safeQuery(
                fn () => Agent::select('id', 'name', 'slug')->get()->toArray(),
                'agents',
                [],
            ),
            'action_types' => $this->safeQuery(
                fn () => ApprovalRequest::query()
                    ->select('action_type')
                    ->distinct()
                    ->whereNotNull('action_type')
                    ->pluck('action_type')
                    ->values()
                    ->all(),
                'action_types',
                [],
            ),
        ]);
    }

    /**
     * Show detailed approval request.
     */
    public function show(ApprovalRequest $approval)
    {
        $approval->load(['agentRun.agent', 'decidedBy:id,name']);

        return Inertia::render('Approvals/Show', [
            'approval' => [
                'id' => $approval->id,
                'action_type' => $approval->action_type,
                'description' => $approval->description,
                'risk_level' => $approval->risk_level ?? 'medium',
                'status' => $approval->status,
                'payload' => $approval->payload,
                'agent' => $approval->agentRun?->agent ? [
                    'id' => $approval->agentRun->agent->id,
                    'name' => $approval->agentRun->agent->name,
                    'slug' => $approval->agentRun->agent->slug,
                    'model' => $approval->agentRun->agent->model,
                ] : null,
                'run' => $approval->agentRun ? [
                    'id' => $approval->agentRun->id,
                    'task' => $approval->agentRun->task,
                    'context' => $approval->agentRun->context,
                    'status' => $approval->agentRun->status,
                ] : null,
                'decided_by' => $approval->decidedBy?->name,
                'decision_note' => $approval->decision_note,
                'expires_at' => $approval->expires_at?->format('M d, Y H:i'),
                'decided_at' => $approval->decided_at?->format('M d, Y H:i'),
                'created_at' => $approval->created_at->format('M d, Y H:i'),
            ],
            'similar_approvals' => $this->getSimilarApprovals($approval),
        ]);
    }

    /**
     * Execute a query closure with a graceful fallback. Used to keep one
     * subsystem failure from 500ing the whole Approvals page.
     *
     * @template T
     *
     * @param  \Closure(): T  $closure
     * @param  T  $fallback
     * @return T
     */
    protected function safeQuery(\Closure $closure, string $name, mixed $fallback): mixed
    {
        try {
            return $closure();
        } catch (\Throwable $e) {
            Log::warning("ApprovalController: {$name} query failed", [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            return $fallback;
        }
    }

    /**
     * Build stats with each metric wrapped so one broken count doesn't kill
     * the whole stats object. Falls back to 0 per metric on failure.
     *
     * @return array<string, int>
     */
    protected function safeStats(): array
    {
        $metrics = [
            'pending' => fn () => ApprovalRequest::where('status', 'pending')->count(),
            'high_risk_pending' => fn () => ApprovalRequest::where('status', 'pending')->where('risk_level', 'high')->count(),
            'approved_today' => fn () => ApprovalRequest::where('status', 'approved')->whereDate('decided_at', today())->count(),
            'rejected_today' => fn () => ApprovalRequest::where('status', 'rejected')->whereDate('decided_at', today())->count(),
            'expired' => fn () => ApprovalRequest::where('status', 'expired')->count(),
            'expiring_soon' => fn () => ApprovalRequest::where('status', 'pending')
                ->where('expires_at', '<', now()->addDay())
                ->count(),
        ];

        $stats = [];
        foreach ($metrics as $key => $fn) {
            $stats[$key] = $this->safeQuery($fn, "stats.{$key}", 0);
        }

        return $stats;
    }

    /**
     * Build the Inertia payload for one approval row. Wrapped in try/catch
     * so a single malformed row (corrupt JSON payload, stale relation, etc.)
     * cannot 500 the entire Approvals page — the bad row degrades to a
     * placeholder and the offending ID is logged for cleanup.
     *
     * @return array<string, mixed>
     */
    protected function serializeApproval(ApprovalRequest $approval): array
    {
        try {
            return [
                'id' => $approval->id,
                'action_type' => $approval->action_type,
                'description' => $approval->description,
                'risk_level' => $approval->risk_level ?? 'medium',
                'status' => $approval->status,
                'agent' => $approval->agentRun?->agent ? [
                    'id' => $approval->agentRun->agent->id,
                    'name' => $approval->agentRun->agent->name,
                    'slug' => $approval->agentRun->agent->slug,
                ] : null,
                'run_id' => $approval->agentRun?->id,
                'payload_preview' => $this->safePayloadPreview($approval),
                'decided_by' => $approval->decidedBy?->name,
                'decision_note' => $approval->decision_note,
                'expires_at' => $approval->expires_at?->diffForHumans(),
                'expires_at_raw' => $approval->expires_at,
                'decided_at' => $approval->decided_at?->diffForHumans(),
                'created_at' => $approval->created_at->diffForHumans(),
                'is_expiring_soon' => $approval->expires_at && $approval->expires_at->diffInHours(now()) < 24,
                '_render_error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('ApprovalController: failed to serialize approval row', [
                'approval_id' => $approval->id ?? null,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            return [
                'id' => $approval->id ?? 0,
                'action_type' => $approval->action_type ?? 'unknown',
                'description' => $approval->description ?? '(unrenderable — see logs)',
                'risk_level' => 'medium',
                'status' => $approval->status ?? 'unknown',
                'agent' => null,
                'run_id' => null,
                'payload_preview' => null,
                'decided_by' => null,
                'decision_note' => null,
                'expires_at' => null,
                'expires_at_raw' => null,
                'decided_at' => null,
                'created_at' => null,
                'is_expiring_soon' => false,
                '_render_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read the payload via the model accessor but never let an unexpected
     * shape (string, scalar, throwable cast) take down the whole index.
     */
    protected function safePayloadPreview(ApprovalRequest $approval): ?string
    {
        try {
            return $this->getPayloadPreview($approval->payload);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get payload preview for list view. Accepts `mixed` because the
     * `payload` column is array-cast in the model, but production has
     * rows whose JSON decodes to a bare string or other scalar — those
     * must degrade to a string preview rather than blowing up.
     */
    protected function getPayloadPreview(mixed $payload): ?string
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return null;
        }

        if (is_string($payload)) {
            return \Str::limit($payload, 100);
        }

        if (! is_array($payload)) {
            return \Str::limit((string) json_encode($payload), 100);
        }

        if (isset($payload['preview']) && is_string($payload['preview'])) {
            return \Str::limit($payload['preview'], 100);
        }

        if (isset($payload['prompt']) && is_string($payload['prompt'])) {
            return \Str::limit($payload['prompt'], 100);
        }

        return \Str::limit((string) json_encode($payload), 100);
    }

    /**
     * Get similar past approvals for context.
     */
    protected function getSimilarApprovals(ApprovalRequest $approval): array
    {
        return ApprovalRequest::where('action_type', $approval->action_type)
            ->where('id', '!=', $approval->id)
            ->where('status', '!=', 'pending')
            ->orderByDesc('decided_at')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'status' => $a->status,
                'description' => \Str::limit($a->description, 50),
                'decided_at' => $a->decided_at?->diffForHumans(),
            ])
            ->all();
    }

    public function approve(ApprovalRequest $approval, Request $request)
    {
        if ($approval->status !== 'pending') {
            return back()->with('error', 'This approval request has already been processed.');
        }

        $approval->update([
            'status' => 'approved',
            'decision_note' => $request->input('comment'),
            'decided_at' => now(),
            'decided_by' => auth()->id(),
        ]);

        // Handle different action types
        $this->executeApprovedAction($approval);

        return back()->with('success', 'Approval request approved successfully.');
    }

    /**
     * Execute the action after approval based on action_type.
     */
    protected function executeApprovedAction(ApprovalRequest $approval): void
    {
        match ($approval->action_type) {
            'agent_execution' => $this->handleAgentExecution($approval),
            'create_agent' => $this->handleCreateAgent($approval),
            default => null,
        };
    }

    /**
     * Handle agent execution approval - dispatches to queue for async execution.
     */
    protected function handleAgentExecution(ApprovalRequest $approval): void
    {
        if ($approval->agentRun) {
            ExecuteApprovedRunJob::dispatch(
                $approval->agentRun,
                $approval->payload ?? []
            );

            Log::info('Dispatched approved agent execution to queue', [
                'approval_id' => $approval->id,
                'run_id' => $approval->agentRun->id,
            ]);
        }
    }

    /**
     * Handle agent creation approval - actually create the agent.
     */
    protected function handleCreateAgent(ApprovalRequest $approval): void
    {
        $config = $approval->payload['agent_config'] ?? null;

        if (! $config) {
            \Log::warning('Create agent approval missing agent_config', ['approval_id' => $approval->id]);

            return;
        }

        // Check if agent already exists
        if (Agent::where('slug', $config['slug'])->exists()) {
            \Log::info('Agent already exists, skipping creation', ['slug' => $config['slug']]);

            return;
        }

        // Create the agent
        $agent = Agent::create([
            'name' => $config['name'],
            'slug' => $config['slug'],
            'description' => $config['description'],
            'status' => 'paused', // Start paused for safety
            'model' => 'sonnet', // Default model
            'requires_approval' => true,
            'max_budget_usd' => 5.00,
            'allowed_tools' => $config['suggested_tools'] ?? [],
            'is_dynamic' => true,
        ]);

        \Log::info('Agent created from approval', [
            'agent_id' => $agent->id,
            'slug' => $agent->slug,
            'approval_id' => $approval->id,
        ]);

        // If there's an initial task, queue it
        if (! empty($config['initial_task'])) {
            \App\Models\AgentTask::create([
                'agent_id' => $agent->id,
                'task_description' => $config['initial_task'],
                'status' => 'pending',
                'priority' => 'normal',
                'context' => ['created_from_approval' => $approval->id],
            ]);
        }
    }

    public function reject(ApprovalRequest $approval, Request $request)
    {
        if ($approval->status !== 'pending') {
            return back()->with('error', 'This approval request has already been processed.');
        }

        $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $approval->update([
            'status' => 'rejected',
            'decision_note' => $request->reason,
            'decided_at' => now(),
            'decided_by' => auth()->id(),
        ]);

        return back()->with('success', 'Approval request rejected.');
    }

    public function bulkApprove(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:approval_requests,id',
        ]);

        $approvals = ApprovalRequest::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->with('agentRun')
            ->get();

        $approvals->each(function ($approval) use ($request) {
            $approval->update([
                'status' => 'approved',
                'decision_note' => $request->input('comment'),
                'decided_at' => now(),
                'decided_by' => auth()->id(),
            ]);

            // Handle different action types
            $this->executeApprovedAction($approval);
        });

        return back()->with('success', "{$approvals->count()} approval request(s) approved successfully.");
    }

    public function bulkReject(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:approval_requests,id',
            'reason' => 'required|string|min:3',
        ]);

        $count = ApprovalRequest::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'decision_note' => $request->reason,
                'decided_at' => now(),
                'decided_by' => auth()->id(),
            ]);

        return back()->with('success', "{$count} approval request(s) rejected.");
    }
}
