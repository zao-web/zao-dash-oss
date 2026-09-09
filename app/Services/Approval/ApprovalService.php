<?php

namespace App\Services\Approval;

use App\Events\NotificationCreated;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Manages the human-in-the-loop approval queue.
 *
 * Responsibilities:
 * - Create approval requests from agent runs
 * - Process approvals/rejections
 * - Handle escalation for stale requests
 * - Send notifications to appropriate roles
 * - Check auto-approval conditions
 */
class ApprovalService
{
    /**
     * Create an approval request for an agent action.
     */
    public function createRequest(
        AgentRun $run,
        string $category,
        string $title,
        array $payload,
        ?string $description = null
    ): ApprovalRequest {
        $policy = $this->getPolicy($category);

        // Check if this can be auto-approved
        if ($this->canAutoApprove($category, $payload)) {
            Log::info('Auto-approving request', [
                'category' => $category,
                'agent_run_id' => $run->id,
            ]);

            return $this->createAutoApprovedRequest($run, $category, $title, $payload, $description);
        }

        $approval = ApprovalRequest::create([
            'agent_run_id' => $run->id,
            'agent_id' => $run->agent_id,
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'risk_level' => $policy['risk_level'] ?? 'medium',
            'status' => 'pending',
            'expires_at' => now()->addHours($policy['expires_hours'] ?? 24),
        ]);

        $this->notifyReviewers($approval, $policy);

        Log::info('Approval request created', [
            'approval_id' => $approval->id,
            'category' => $category,
            'risk_level' => $approval->risk_level,
        ]);

        return $approval;
    }

    /**
     * Approve a pending request.
     */
    public function approve(
        ApprovalRequest $approval,
        User $approver,
        ?string $notes = null
    ): ApprovalRequest {
        if ($approval->status !== 'pending') {
            throw new \Exception("Cannot approve request with status: {$approval->status}");
        }

        // Check if 2FA is required for critical actions
        $policy = $this->getPolicy($approval->category);
        if (($policy['requires_2fa'] ?? false) && ! $approver->hasVerified2FA()) {
            throw new \Exception('2FA verification required for this approval');
        }

        $approval->update([
            'status' => 'approved',
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        // Execute the approved action if there's a callback
        $this->executeApprovedAction($approval);

        Log::info('Approval granted', [
            'approval_id' => $approval->id,
            'approver_id' => $approver->id,
        ]);

        event(new NotificationCreated([
            'type' => 'approval.approved',
            'title' => 'Request Approved',
            'message' => "{$approval->title} was approved by {$approver->name}",
            'data' => ['approval_id' => $approval->id],
        ]));

        return $approval->fresh();
    }

    /**
     * Reject a pending request.
     */
    public function reject(
        ApprovalRequest $approval,
        User $rejector,
        ?string $reason = null
    ): ApprovalRequest {
        if ($approval->status !== 'pending') {
            throw new \Exception("Cannot reject request with status: {$approval->status}");
        }

        $approval->update([
            'status' => 'rejected',
            'reviewed_by' => $rejector->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);

        Log::info('Approval rejected', [
            'approval_id' => $approval->id,
            'rejector_id' => $rejector->id,
            'reason' => $reason,
        ]);

        event(new NotificationCreated([
            'type' => 'approval.rejected',
            'title' => 'Request Rejected',
            'message' => "{$approval->title} was rejected".($reason ? ": {$reason}" : ''),
            'data' => ['approval_id' => $approval->id],
        ]));

        return $approval->fresh();
    }

    /**
     * Cancel a pending request.
     */
    public function cancel(ApprovalRequest $approval, ?string $reason = null): ApprovalRequest
    {
        if ($approval->status !== 'pending') {
            throw new \Exception("Cannot cancel request with status: {$approval->status}");
        }

        $approval->update([
            'status' => 'cancelled',
            'review_notes' => $reason ?? 'Cancelled by system',
        ]);

        return $approval->fresh();
    }

    /**
     * Get pending approvals, optionally filtered.
     */
    public function getPending(
        ?string $category = null,
        ?string $riskLevel = null,
        ?int $limit = null
    ): Collection {
        $query = ApprovalRequest::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByRaw("FIELD(risk_level, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'asc');

        if ($category) {
            $query->where('category', $category);
        }

        if ($riskLevel) {
            $query->where('risk_level', $riskLevel);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get approvals requiring escalation.
     */
    public function getStaleApprovals(): Collection
    {
        $escalateAfterHours = config('approval_policies.escalation.escalate_after_hours', 12);

        return ApprovalRequest::where('status', 'pending')
            ->where('created_at', '<', now()->subHours($escalateAfterHours))
            ->whereNull('escalated_at')
            ->get();
    }

    /**
     * Escalate stale approvals.
     */
    public function escalateStale(): int
    {
        $stale = $this->getStaleApprovals();
        $escalatedCount = 0;

        foreach ($stale as $approval) {
            $this->escalate($approval);
            $escalatedCount++;
        }

        return $escalatedCount;
    }

    /**
     * Escalate a single approval.
     */
    public function escalate(ApprovalRequest $approval): void
    {
        $approval->update(['escalated_at' => now()]);

        $escalateTo = config('approval_policies.escalation.escalate_to', ['owner']);
        $users = User::whereIn('role', $escalateTo)->get();

        foreach ($users as $user) {
            event(new NotificationCreated([
                'type' => 'approval.escalated',
                'title' => 'Escalated: '.$approval->title,
                'message' => "Approval pending for {$approval->created_at->diffForHumans()}",
                'data' => ['approval_id' => $approval->id],
                'user_id' => $user->id,
                'urgent' => true,
            ]));
        }

        Log::warning('Approval escalated', [
            'approval_id' => $approval->id,
            'age_hours' => $approval->created_at->diffInHours(now()),
        ]);
    }

    /**
     * Expire old pending approvals.
     */
    public function expireOld(): int
    {
        $expired = ApprovalRequest::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $approval) {
            $approval->update(['status' => 'expired']);
        }

        return $expired->count();
    }

    /**
     * Cancel all pending approvals (emergency).
     */
    public function cancelAllPending(?string $reason = null): int
    {
        $pending = ApprovalRequest::where('status', 'pending')->get();

        foreach ($pending as $approval) {
            $this->cancel($approval, $reason ?? 'Emergency cancellation');
        }

        Log::warning('All pending approvals cancelled', [
            'count' => $pending->count(),
            'reason' => $reason,
        ]);

        return $pending->count();
    }

    /**
     * Check if an action can be auto-approved.
     */
    public function canAutoApprove(string $category, array $context = []): bool
    {
        $policy = $this->getPolicy($category);

        if (! ($policy['requires_approval'] ?? true)) {
            return true;
        }

        // Critical actions never auto-approve
        if (($policy['risk_level'] ?? 'medium') === 'critical') {
            return false;
        }

        $conditions = $policy['auto_approve_conditions'] ?? [];

        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition => $value) {
            if (! $this->evaluateCondition($condition, $value, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get policy for a category.
     */
    protected function getPolicy(string $category): array
    {
        return config("approval_policies.categories.{$category}", [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin'],
        ]);
    }

    /**
     * Evaluate an auto-approve condition.
     */
    protected function evaluateCondition(string $condition, mixed $value, array $context): bool
    {
        return match ($condition) {
            'amount_under' => ($context['amount'] ?? PHP_INT_MAX) < $value,
            'existing_client' => ! empty($context['client_id']),
            'tests_pass' => ($context['tests_passed'] ?? false) === true,
            'no_breaking_changes' => ($context['breaking_changes'] ?? true) === false,
            'pre_approved_template' => in_array($context['template_id'] ?? null, (array) $value),
            default => false,
        };
    }

    /**
     * Create an auto-approved request (for audit trail).
     */
    protected function createAutoApprovedRequest(
        AgentRun $run,
        string $category,
        string $title,
        array $payload,
        ?string $description
    ): ApprovalRequest {
        $policy = $this->getPolicy($category);

        return ApprovalRequest::create([
            'agent_run_id' => $run->id,
            'agent_id' => $run->agent_id,
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'risk_level' => $policy['risk_level'] ?? 'low',
            'status' => 'approved',
            'reviewed_at' => now(),
            'review_notes' => 'Auto-approved by policy',
        ]);
    }

    /**
     * Notify appropriate reviewers about new approval.
     */
    protected function notifyReviewers(ApprovalRequest $approval, array $policy): void
    {
        $roles = $policy['notify_roles'] ?? ['owner', 'admin'];
        $users = User::whereIn('role', $roles)->get();

        $channels = in_array($approval->risk_level, ['critical', 'high'])
            ? config('approval_policies.notifications.urgent_channels', ['database', 'mail', 'slack'])
            : config('approval_policies.notifications.channels', ['database', 'mail']);

        foreach ($users as $user) {
            event(new NotificationCreated([
                'type' => 'approval.pending',
                'title' => "Approval Required: {$approval->title}",
                'message' => "Risk level: {$approval->risk_level}",
                'data' => [
                    'approval_id' => $approval->id,
                    'category' => $approval->category,
                    'risk_level' => $approval->risk_level,
                ],
                'user_id' => $user->id,
                'channels' => $channels,
            ]));
        }
    }

    /**
     * Execute the approved action callback.
     */
    protected function executeApprovedAction(ApprovalRequest $approval): void
    {
        // If there's a stored callback, execute it
        $callback = $approval->payload['callback'] ?? null;

        if (! $callback) {
            return;
        }

        try {
            $class = $callback['class'] ?? null;
            $method = $callback['method'] ?? 'execute';
            $params = $callback['params'] ?? [];

            if ($class && class_exists($class)) {
                $instance = app($class);
                $instance->$method($approval, $params);
            }
        } catch (\Exception $e) {
            Log::error('Failed to execute approved action', [
                'approval_id' => $approval->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get approval statistics.
     */
    public function getStats(): array
    {
        return [
            'pending' => ApprovalRequest::where('status', 'pending')->count(),
            'approved_today' => ApprovalRequest::where('status', 'approved')
                ->whereDate('reviewed_at', today())
                ->count(),
            'rejected_today' => ApprovalRequest::where('status', 'rejected')
                ->whereDate('reviewed_at', today())
                ->count(),
            'by_risk_level' => ApprovalRequest::where('status', 'pending')
                ->selectRaw('risk_level, COUNT(*) as count')
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray(),
            'avg_approval_time_hours' => ApprovalRequest::where('status', 'approved')
                ->whereNotNull('reviewed_at')
                ->whereMonth('created_at', now()->month)
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, reviewed_at)) as avg')
                ->value('avg') ?? 0,
        ];
    }
}
