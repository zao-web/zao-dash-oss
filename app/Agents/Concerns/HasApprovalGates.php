<?php

namespace App\Agents\Concerns;

use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\Log;

/**
 * Provides approval gate functionality for agents.
 *
 * Agents using this trait can define approval gates that pause
 * execution until human approval is received.
 */
trait HasApprovalGates
{
    /**
     * Get categories of actions that require approval.
     * Override in agent definition to customize.
     */
    protected function getApprovalCategories(): array
    {
        return $this->approvalCategories ?? [
            'deploy.production',
            'deploy.staging',
            'financial.invoice',
            'financial.payment',
            'database.migration',
            'communication.client_email',
            'communication.cold_outreach',
            'content.publish',
        ];
    }

    /**
     * Check if an action requires approval based on category.
     */
    public function requiresApprovalFor(string $category): bool
    {
        // Check config-based policies first
        $policies = config('approval_policies.categories', []);

        if (isset($policies[$category])) {
            return $policies[$category]['requires_approval'] ?? true;
        }

        // Fall back to agent-level setting
        return in_array($category, $this->getApprovalCategories());
    }

    /**
     * Check if an action can be auto-approved based on conditions.
     */
    public function canAutoApprove(string $category, array $context = []): bool
    {
        $policies = config('approval_policies.categories', []);

        if (! isset($policies[$category])) {
            return false;
        }

        $policy = $policies[$category];

        // Never auto-approve critical actions
        if (($policy['risk_level'] ?? 'medium') === 'critical') {
            return false;
        }

        // Check auto-approve conditions
        $conditions = $policy['auto_approve_conditions'] ?? [];

        foreach ($conditions as $condition => $value) {
            if (! $this->evaluateCondition($condition, $value, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create an approval request for a pending action.
     */
    public function createApprovalRequest(
        AgentRun $run,
        string $category,
        string $title,
        array $payload,
        ?string $description = null
    ): ApprovalRequest {
        $policy = config("approval_policies.categories.{$category}", []);

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

        Log::info('Approval request created', [
            'approval_id' => $approval->id,
            'agent_run_id' => $run->id,
            'category' => $category,
        ]);

        return $approval;
    }

    /**
     * Wait for approval before proceeding.
     * Returns true if approved, false if rejected/expired.
     */
    public function waitForApproval(ApprovalRequest $approval, int $timeoutSeconds = 3600): bool
    {
        $startTime = time();

        while (time() - $startTime < $timeoutSeconds) {
            $approval->refresh();

            if ($approval->status === 'approved') {
                return true;
            }

            if (in_array($approval->status, ['rejected', 'expired'])) {
                return false;
            }

            // Check expiration
            if ($approval->expires_at && $approval->expires_at->isPast()) {
                $approval->update(['status' => 'expired']);

                return false;
            }

            sleep(5); // Poll every 5 seconds
        }

        return false;
    }

    /**
     * Execute an action that may require approval.
     */
    public function executeWithApproval(
        AgentRun $run,
        string $category,
        string $title,
        array $payload,
        callable $action
    ): mixed {
        // Check if approval is needed
        if (! $this->requiresApprovalFor($category)) {
            return $action($payload);
        }

        // Check auto-approval
        if ($this->canAutoApprove($category, $payload)) {
            Log::info('Auto-approving action', [
                'category' => $category,
                'agent_run_id' => $run->id,
            ]);

            return $action($payload);
        }

        // Create approval request and wait
        $approval = $this->createApprovalRequest($run, $category, $title, $payload);

        if ($this->waitForApproval($approval)) {
            return $action($payload);
        }

        throw new \Exception("Approval denied or expired for: {$title}");
    }

    /**
     * Evaluate a single auto-approve condition.
     */
    protected function evaluateCondition(string $condition, mixed $value, array $context): bool
    {
        return match ($condition) {
            'amount_under' => ($context['amount'] ?? PHP_INT_MAX) < $value,
            'existing_client' => ! empty($context['client_id']),
            'tests_pass' => ($context['tests_passed'] ?? false) === true,
            'no_breaking_changes' => ($context['breaking_changes'] ?? true) === false,
            'pre_approved_template' => in_array($context['template_id'] ?? null, $value),
            default => false,
        };
    }

    /**
     * Get pending approvals for this agent.
     */
    public function getPendingApprovals(): \Illuminate\Database\Eloquent\Collection
    {
        return ApprovalRequest::where('status', 'pending')
            ->whereHas('agentRun', function ($query) {
                $query->where('agent_id', $this->metadata()['id']);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
