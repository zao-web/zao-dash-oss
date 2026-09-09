<?php

namespace App\Services\Approval;

use App\Jobs\ExecuteApprovedRunJob;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ApprovalRequest;
use App\Models\SlackThreadContext;
use App\Models\User;
use App\Services\Slack\SlackEngineeringApprovalService;
use App\Services\Slack\SlackStagingThreadService;
use Illuminate\Support\Facades\Log;

class ApprovalDecisionService
{
    public function __construct(
        private SlackEngineeringApprovalService $slackEngineeringApprovalService,
        private SlackStagingThreadService $slackStagingThreadService,
    ) {}

    public function approve(ApprovalRequest $approval, ?User $decider = null, ?string $note = null): ApprovalRequest
    {
        if ($approval->status !== 'pending') {
            throw new \RuntimeException("Approval request {$approval->id} has already been processed.");
        }

        $approval->update([
            'status' => 'approved',
            'decision_note' => $note,
            'decided_at' => now(),
            'decided_by' => $decider?->id,
        ]);

        $this->executeApprovedAction($approval);

        return $approval->fresh(['agentRun.agent', 'decidedBy']);
    }

    public function reject(ApprovalRequest $approval, ?User $decider = null, ?string $note = null): ApprovalRequest
    {
        if ($approval->status !== 'pending') {
            throw new \RuntimeException("Approval request {$approval->id} has already been processed.");
        }

        $approval->update([
            'status' => 'rejected',
            'decision_note' => $note,
            'decided_at' => now(),
            'decided_by' => $decider?->id,
        ]);

        return $approval->fresh(['agentRun.agent', 'decidedBy']);
    }

    protected function executeApprovedAction(ApprovalRequest $approval): void
    {
        match ($approval->action_type) {
            'agent_execution' => $this->handleAgentExecution($approval),
            'create_agent' => $this->handleCreateAgent($approval),
            'slack_review_deploy' => $this->handleSlackReviewDeploy($approval),
            'slack_retry_review_deploy' => $this->handleSlackRetryReviewDeploy($approval),
            'slack_staging_publish' => $this->handleSlackStagingPublish($approval),
            default => null,
        };
    }

    protected function handleAgentExecution(ApprovalRequest $approval): void
    {
        if (! $approval->agentRun) {
            return;
        }

        ExecuteApprovedRunJob::dispatch(
            $approval->agentRun,
            $approval->payload ?? []
        );

        Log::info('Dispatched approved agent execution to queue', [
            'approval_id' => $approval->id,
            'run_id' => $approval->agentRun->id,
        ]);
    }

    protected function handleCreateAgent(ApprovalRequest $approval): void
    {
        $config = $approval->payload['agent_config'] ?? null;

        if (! $config) {
            Log::warning('Create agent approval missing agent_config', ['approval_id' => $approval->id]);

            return;
        }

        if (Agent::where('slug', $config['slug'])->exists()) {
            Log::info('Agent already exists, skipping creation', ['slug' => $config['slug']]);

            return;
        }

        $agent = Agent::create([
            'name' => $config['name'],
            'slug' => $config['slug'],
            'description' => $config['description'],
            'status' => 'paused',
            'model' => 'sonnet',
            'requires_approval' => true,
            'max_budget_usd' => 5.00,
            'allowed_tools' => $config['suggested_tools'] ?? [],
            'is_dynamic' => true,
        ]);

        Log::info('Agent created from approval', [
            'agent_id' => $agent->id,
            'slug' => $agent->slug,
            'approval_id' => $approval->id,
        ]);

        if (! empty($config['initial_task'])) {
            AgentTask::create([
                'agent_id' => $agent->id,
                'task_description' => $config['initial_task'],
                'status' => 'pending',
                'priority' => 'normal',
                'context' => ['created_from_approval' => $approval->id],
            ]);
        }
    }

    protected function handleSlackReviewDeploy(ApprovalRequest $approval): void
    {
        $result = $this->slackEngineeringApprovalService->executeApprovedReviewDeploy($approval);

        if (! ($result['success'] ?? false)) {
            Log::warning('Approved Slack review deploy could not be executed', [
                'approval_id' => $approval->id,
                'error' => $result['error'] ?? 'unknown_error',
            ]);
        }
    }

    protected function handleSlackRetryReviewDeploy(ApprovalRequest $approval): void
    {
        $result = $this->slackEngineeringApprovalService->executeApprovedRetryReviewDeploy($approval);

        if (! ($result['success'] ?? false)) {
            Log::warning('Approved Slack review deploy retry could not be executed', [
                'approval_id' => $approval->id,
                'error' => $result['error'] ?? 'unknown_error',
            ]);
        }
    }

    protected function handleSlackStagingPublish(ApprovalRequest $approval): void
    {
        $result = $this->slackEngineeringApprovalService->executeApprovedStagingPublish($approval);

        if (! ($result['success'] ?? false)) {
            Log::warning('Approved Slack staging publish could not be executed', [
                'approval_id' => $approval->id,
                'error' => $result['error'] ?? 'unknown_error',
            ]);

            return;
        }

        $threadContextId = data_get($approval->payload, 'slack.thread_context_id');
        $threadContext = $threadContextId ? SlackThreadContext::query()->find($threadContextId) : null;

        if ($threadContext) {
            $this->slackStagingThreadService->rememberPublishedThread($threadContext, $result);
        }
    }
}
