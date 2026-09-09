<?php

namespace App\Services\Slack;

use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;

class SlackEngineeringApprovalService
{
    public function __construct(
        private SlackGitHubEngineeringActionService $gitHubEngineeringActionService,
        private SlackStagingWorkflowService $stagingWorkflowService,
    ) {}

    public function requiresIssueRunApproval(?GitHubRepo $repo, string $deliveryTarget, ?string $branchPreference): bool
    {
        return $deliveryTarget === 'staging'
            || $this->isProtectedBranch($repo, $branchPreference);
    }

    public function queueIssueRunApproval(
        AgentRun $run,
        SlackWorkspace $workspace,
        SlackChannel $channel,
        Project $project,
        ?GitHubRepo $repo,
        int $issueNumber,
        string $deliveryTarget,
        ?string $branchPreference,
    ): ApprovalRequest {
        $targetLabel = $deliveryTarget === 'staging'
            ? 'staging delivery'
            : 'protected branch execution';
        $branchLabel = $branchPreference ? " on `{$branchPreference}`" : '';

        return ApprovalRequest::create([
            'agent_run_id' => $run->id,
            'action_type' => 'agent_execution',
            'description' => "Slack engineering run for issue #{$issueNumber} in {$project->github_repo} requires approval for {$targetLabel}{$branchLabel}.",
            'risk_level' => 'high',
            'status' => 'pending',
            'payload' => [
                'slack' => array_filter([
                    'workspace_id' => $workspace->workspace_id,
                    'channel_id' => $channel->channel_id,
                    'thread_ts' => data_get($run->context, 'slack.thread_ts'),
                ]),
                'project' => [
                    'id' => $project->id,
                    'github_repo' => $project->github_repo,
                ],
                'engineering' => array_filter([
                    'issue_number' => $issueNumber,
                    'delivery_target' => $deliveryTarget,
                    'branch_preference' => $branchPreference,
                    'default_branch' => $repo?->default_branch,
                ]),
            ],
            'expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * @return array{success: bool, approval_required?: bool, approval?: array<string, mixed>, run_id?: int, message?: string, error?: string}
     */
    public function requestReviewDeploy(AgentRun $run): array
    {
        $approval = $this->findPendingRunApproval($run, 'slack_review_deploy');
        $prNumber = (int) ($run->output['pr_number'] ?? 0);
        $branch = $run->output['branch'] ?? $run->output['branch_name'] ?? null;
        $repo = data_get($run->context, 'engineering.repo', data_get($run->context, 'project.github_repo', 'this repository'));

        if (! $approval) {
            $approval = ApprovalRequest::create([
                'agent_run_id' => $run->id,
                'action_type' => 'slack_review_deploy',
                'description' => "Slack requested a review deploy for PR #{$prNumber} in {$repo}".($branch ? " on `{$branch}`" : '').'.',
                'risk_level' => 'high',
                'status' => 'pending',
                'payload' => [
                    'slack' => $run->context['slack'] ?? [],
                    'engineering' => [
                        'repo' => $repo,
                        'pr_number' => $prNumber,
                        'branch' => $branch,
                    ],
                ],
                'expires_at' => now()->addHours(24),
            ]);
        }

        return [
            'success' => true,
            'approval_required' => true,
            'approval' => $this->formatApproval($approval),
            'run_id' => $run->id,
            'message' => "Queued approval #{$approval->id} to deploy PR #{$prNumber} for review.",
        ];
    }

    /**
     * @return array{success: bool, approval_required?: bool, approval?: array<string, mixed>, run_id?: int, message?: string, error?: string}
     */
    public function retryReviewDeploy(AgentRun $run): array
    {
        $approval = $this->findPendingRunApproval($run, 'slack_retry_review_deploy');
        $prNumber = (int) ($run->output['pr_number'] ?? 0);
        $repo = data_get($run->context, 'engineering.repo', data_get($run->context, 'project.github_repo', 'this repository'));
        $workflowRunId = (int) ($run->output['workflow_run_id'] ?? 0);

        if (! $approval) {
            $approval = ApprovalRequest::create([
                'agent_run_id' => $run->id,
                'action_type' => 'slack_retry_review_deploy',
                'description' => "Slack requested a retry of the review deploy for PR #{$prNumber} in {$repo}.",
                'risk_level' => 'high',
                'status' => 'pending',
                'payload' => [
                    'slack' => $run->context['slack'] ?? [],
                    'engineering' => [
                        'repo' => $repo,
                        'pr_number' => $prNumber,
                        'workflow_run_id' => $workflowRunId,
                    ],
                ],
                'expires_at' => now()->addHours(24),
            ]);
        }

        return [
            'success' => true,
            'approval_required' => true,
            'approval' => $this->formatApproval($approval),
            'run_id' => $run->id,
            'message' => "Queued approval #{$approval->id} to retry the review deploy for PR #{$prNumber}.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publishToStaging(string $teamId, string $channelId, ?SlackThreadContext $threadContext = null): array
    {
        $status = $this->stagingWorkflowService->describeChannelStaging($teamId, $channelId);

        if (isset($status['error'])) {
            return $status;
        }

        if (! ($status['deployment']['workflow_file_path'] ?? null)) {
            return ['error' => 'This repository does not have a staging workflow configured yet.'];
        }

        if (($status['summary']['missing'] ?? 0) > 0) {
            return ['error' => 'Required staging secrets are still missing from the vault.'];
        }

        if (($status['summary']['pending_sync'] ?? 0) > 0) {
            return ['error' => 'Required staging secrets are saved, but not yet synced to GitHub.'];
        }

        $approval = $this->findPendingStagingApproval($teamId, $channelId, $status, $threadContext);

        if (! $approval) {
            $approval = ApprovalRequest::create([
                'agent_run_id' => null,
                'action_type' => 'slack_staging_publish',
                'description' => "Slack requested a staging publish for {$status['repo']['full_name']} via `{$status['deployment']['workflow_identifier']}` on `{$status['deployment']['publish_branch']}`.",
                'risk_level' => 'high',
                'status' => 'pending',
                'payload' => [
                    'slack' => array_filter([
                        'team_id' => $teamId,
                        'channel_id' => $channelId,
                        'thread_context_id' => $threadContext?->id,
                        'thread_ts' => $threadContext?->thread_ts,
                    ]),
                    'publish' => [
                        'repo_full_name' => $status['repo']['full_name'],
                        'workflow_identifier' => $status['deployment']['workflow_identifier'],
                        'branch' => $status['deployment']['publish_branch'],
                    ],
                ],
                'expires_at' => now()->addHours(24),
            ]);
        }

        return array_merge($status, [
            'success' => true,
            'approval_required' => true,
            'approval' => $this->formatApproval($approval),
            'message' => "Queued approval #{$approval->id} to publish {$status['repo']['full_name']} to staging.",
        ]);
    }

    public function executeApprovedReviewDeploy(ApprovalRequest $approval): array
    {
        if (! $approval->agentRun) {
            return ['success' => false, 'error' => 'Approval is not linked to an engineering run.'];
        }

        return $this->gitHubEngineeringActionService->requestReviewDeploy($approval->agentRun);
    }

    public function executeApprovedRetryReviewDeploy(ApprovalRequest $approval): array
    {
        if (! $approval->agentRun) {
            return ['success' => false, 'error' => 'Approval is not linked to an engineering run.'];
        }

        return $this->gitHubEngineeringActionService->retryReviewDeploy($approval->agentRun);
    }

    public function executeApprovedStagingPublish(ApprovalRequest $approval): array
    {
        $teamId = (string) data_get($approval->payload, 'slack.team_id', '');
        $channelId = (string) data_get($approval->payload, 'slack.channel_id', '');

        if ($teamId === '' || $channelId === '') {
            return ['success' => false, 'error' => 'Approval is missing Slack workspace or channel context.'];
        }

        return $this->stagingWorkflowService->publishToStaging($teamId, $channelId);
    }

    public function isProtectedBranch(?GitHubRepo $repo, ?string $branchPreference): bool
    {
        if (! $branchPreference) {
            return false;
        }

        $normalizedBranch = strtolower(trim($branchPreference));
        $protectedBranches = array_filter([
            'main',
            'master',
            'production',
            'prod',
            $repo?->default_branch ? strtolower($repo->default_branch) : null,
        ]);

        return in_array($normalizedBranch, array_values(array_unique($protectedBranches)), true);
    }

    private function findPendingRunApproval(AgentRun $run, string $actionType): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('status', 'pending')
            ->where('action_type', $actionType)
            ->where('agent_run_id', $run->id)
            ->first();
    }

    private function findPendingStagingApproval(
        string $teamId,
        string $channelId,
        array $status,
        ?SlackThreadContext $threadContext,
    ): ?ApprovalRequest {
        return ApprovalRequest::query()
            ->where('status', 'pending')
            ->where('action_type', 'slack_staging_publish')
            ->latest('id')
            ->get()
            ->first(function (ApprovalRequest $approval) use ($teamId, $channelId, $status, $threadContext): bool {
                return data_get($approval->payload, 'slack.team_id') === $teamId
                    && data_get($approval->payload, 'slack.channel_id') === $channelId
                    && data_get($approval->payload, 'publish.repo_full_name') === ($status['repo']['full_name'] ?? null)
                    && data_get($approval->payload, 'publish.branch') === ($status['deployment']['publish_branch'] ?? null)
                    && data_get($approval->payload, 'publish.workflow_identifier') === ($status['deployment']['workflow_identifier'] ?? null)
                    && data_get($approval->payload, 'slack.thread_context_id') === $threadContext?->id;
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatApproval(ApprovalRequest $approval): array
    {
        return [
            'id' => $approval->id,
            'description' => $approval->description,
            'risk_level' => $approval->risk_level,
            'action_type' => $approval->action_type,
            'expires_at' => $approval->expires_at?->toIso8601String(),
        ];
    }
}
