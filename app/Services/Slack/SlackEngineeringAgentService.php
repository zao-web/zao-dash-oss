<?php

namespace App\Services\Slack;

use App\Jobs\RunAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\GitHubIssue;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use Illuminate\Support\Str;

class SlackEngineeringAgentService
{
    public function __construct(
        private SlackLinkedProjectService $linkedProjectService,
        private SlackEngineeringApprovalService $engineeringApprovalService,
    ) {}

    /**
     * @return array{success: bool, error?: string, agent?: Agent, run?: AgentRun, issue?: GitHubIssue|null, project?: Project, repo?: string, message?: string, requires_approval?: bool, approval?: array<string, mixed>}
     */
    public function startIssueRun(
        SlackWorkspace $workspace,
        SlackChannel $channel,
        string $slackUserId,
        ?string $threadTs,
        int $issueNumber,
        string $deliveryTarget = 'pr',
        ?string $branchPreference = null,
        ?string $requestText = null,
        ?int $sourcePrNumber = null,
        ?string $sourcePrUrl = null,
    ): array {
        $agent = Agent::query()
            ->where('slug', 'dev-agent')
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return ['success' => false, 'error' => 'Dev Agent is not configured or not active.'];
        }

        if ($agent->circuit_broken_at) {
            return ['success' => false, 'error' => "Agent '{$agent->name}' is currently unavailable."];
        }

        $projectResolution = $this->linkedProjectService->resolve($channel);
        $project = $projectResolution['project'] ?? null;

        if (! $project) {
            return ['success' => false, 'error' => $projectResolution['error'] ?? 'No active project is linked to this Slack channel.'];
        }

        if (! $project->github_repo) {
            return ['success' => false, 'error' => 'This project does not have a GitHub repository linked yet.'];
        }

        $repo = $this->resolveRepo($project);
        $issue = $repo?->issues()->where('issue_number', $issueNumber)->first();
        $normalizedBranchPreference = $this->normalizeBranchPreference($branchPreference, $deliveryTarget);
        $issueUrl = $issue?->url ?? "https://github.com/{$project->github_repo}/issues/{$issueNumber}";
        $requiresApproval = $this->engineeringApprovalService->requiresIssueRunApproval(
            $repo,
            $deliveryTarget,
            $normalizedBranchPreference,
        );

        if ($requiresApproval) {
            $existingRun = $this->findPendingIssueApprovalRun(
                $agent,
                $project,
                $channel,
                $issueNumber,
                $deliveryTarget,
                $normalizedBranchPreference,
            );

            if ($existingRun?->approvalRequest && $existingRun->approvalRequest->status === 'pending') {
                return [
                    'success' => true,
                    'agent' => $agent,
                    'run' => $existingRun,
                    'issue' => $issue,
                    'project' => $project,
                    'repo' => $project->github_repo,
                    'requires_approval' => true,
                    'approval' => [
                        'id' => $existingRun->approvalRequest->id,
                        'description' => $existingRun->approvalRequest->description,
                        'risk_level' => $existingRun->approvalRequest->risk_level,
                        'action_type' => $existingRun->approvalRequest->action_type,
                        'expires_at' => $existingRun->approvalRequest->expires_at?->toIso8601String(),
                    ],
                    'message' => "Approval #{$existingRun->approvalRequest->id} is already pending for issue #{$issueNumber} in {$project->github_repo}.",
                ];
            }
        }

        $context = [
            'slack' => array_filter([
                'channel_id' => $channel->channel_id,
                'thread_ts' => $threadTs,
                'user_id' => $slackUserId,
                'workspace_id' => $workspace->workspace_id,
            ]),
            'client' => [
                'id' => $channel->client?->id,
                'name' => $channel->client?->name,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'github_repo' => $project->github_repo,
            ],
            'engineering' => array_filter([
                'workflow' => 'slack_issue',
                'github_issue_id' => $issue?->id,
                'issue_number' => $issueNumber,
                'issue_title' => $issue?->title,
                'issue_url' => $issueUrl,
                'delivery_target' => $deliveryTarget,
                'branch_preference' => $normalizedBranchPreference,
                'repo' => $project->github_repo,
                'request_text' => $requestText,
                'source_pr_number' => $sourcePrNumber,
                'source_pr_url' => $sourcePrUrl,
            ]),
        ];

        $task = $this->buildTaskDescription($project->github_repo, $issueNumber, $issue, $deliveryTarget);

        $run = $agent->runs()->create([
            'session_id' => (string) Str::uuid(),
            'status' => $requiresApproval ? AgentRun::STATUS_PENDING_APPROVAL : AgentRun::STATUS_RUNNING,
            'task' => $task,
            'context' => $context,
            'project_id' => $project->id,
            'task_id' => $issue?->task_id,
            'invocation_source' => AgentRun::SOURCE_SLACK,
            'invoked_by' => $slackUserId,
            'started_at' => now(),
        ]);

        $run->update([
            'task' => $this->buildPrompt($run, $issue, $deliveryTarget, $normalizedBranchPreference),
        ]);

        $mode = $deliveryTarget === 'staging' ? 'staging review flow' : 'PR review flow';

        if ($requiresApproval) {
            $approval = $this->engineeringApprovalService->queueIssueRunApproval(
                $run,
                $workspace,
                $channel,
                $project,
                $repo,
                $issueNumber,
                $deliveryTarget,
                $normalizedBranchPreference,
            );

            return [
                'success' => true,
                'agent' => $agent,
                'run' => $run->fresh(['approvalRequest']),
                'issue' => $issue,
                'project' => $project,
                'repo' => $project->github_repo,
                'requires_approval' => true,
                'approval' => [
                    'id' => $approval->id,
                    'description' => $approval->description,
                    'risk_level' => $approval->risk_level,
                    'action_type' => $approval->action_type,
                    'expires_at' => $approval->expires_at?->toIso8601String(),
                ],
                'message' => "Queued approval #{$approval->id} for {$agent->name} on issue #{$issueNumber} for {$project->github_repo} using the {$mode}.",
            ];
        }

        RunAgentJob::dispatch($run);

        return [
            'success' => true,
            'agent' => $agent,
            'run' => $run->fresh(),
            'issue' => $issue,
            'project' => $project,
            'repo' => $project->github_repo,
            'message' => "Started {$agent->name} on issue #{$issueNumber} for {$project->github_repo} using the {$mode}.",
        ];
    }

    private function findPendingIssueApprovalRun(
        Agent $agent,
        Project $project,
        SlackChannel $channel,
        int $issueNumber,
        string $deliveryTarget,
        ?string $branchPreference,
    ): ?AgentRun {
        return AgentRun::query()
            ->with('approvalRequest')
            ->where('agent_id', $agent->id)
            ->where('project_id', $project->id)
            ->where('status', AgentRun::STATUS_PENDING_APPROVAL)
            ->where('invocation_source', AgentRun::SOURCE_SLACK)
            ->latest('id')
            ->get()
            ->first(function (AgentRun $run) use ($channel, $issueNumber, $deliveryTarget, $branchPreference): bool {
                return (int) data_get($run->context, 'engineering.issue_number', 0) === $issueNumber
                    && (string) data_get($run->context, 'engineering.delivery_target', 'pr') === $deliveryTarget
                    && data_get($run->context, 'engineering.branch_preference') === $branchPreference
                    && data_get($run->context, 'slack.channel_id') === $channel->channel_id
                    && $run->approvalRequest?->status === 'pending';
            });
    }

    private function resolveRepo(Project $project): ?GitHubRepo
    {
        return GitHubRepo::query()
            ->where('project_id', $project->id)
            ->orWhere('full_name', $project->github_repo)
            ->first();
    }

    private function normalizeBranchPreference(?string $branchPreference, string $deliveryTarget): ?string
    {
        if ($branchPreference === null || $branchPreference === '') {
            return $deliveryTarget === 'staging' ? 'staging' : null;
        }

        if ($branchPreference === 'dev') {
            return 'develop';
        }

        return $branchPreference;
    }

    private function buildTaskDescription(string $repo, int $issueNumber, ?GitHubIssue $issue, string $deliveryTarget): string
    {
        $task = "Work GitHub issue #{$issueNumber} in {$repo}";

        if ($issue?->title) {
            $task .= ": {$issue->title}";
        }

        if ($deliveryTarget === 'staging') {
            $task .= ' and make it reviewable on staging';
        } else {
            $task .= ' and open a PR for review';
        }

        return $task;
    }

    private function buildPrompt(AgentRun $run, ?GitHubIssue $issue, string $deliveryTarget, ?string $branchPreference): string
    {
        $clientName = $run->context['client']['name'] ?? 'Unknown client';
        $projectName = $run->context['project']['name'] ?? 'Unknown project';
        $repo = $run->context['project']['github_repo'] ?? ($run->context['engineering']['repo'] ?? 'Unknown repo');
        $issueNumber = $run->context['engineering']['issue_number'] ?? 'unknown';
        $issueTitle = $issue?->title ?? ($run->context['engineering']['issue_title'] ?? 'No synced title available');
        $issueUrl = $run->context['engineering']['issue_url'] ?? null;
        $issueBody = trim((string) ($issue?->body ?? 'No synced issue body is available yet. Investigate the issue from the repo and issue history.'));
        $requestText = trim((string) ($run->context['engineering']['request_text'] ?? ''));
        $sourcePrNumber = $run->context['engineering']['source_pr_number'] ?? null;
        $sourcePrUrl = $run->context['engineering']['source_pr_url'] ?? null;

        $deliveryInstructions = $deliveryTarget === 'staging'
            ? 'Preferred delivery path: get the fix onto a reviewable staging path. If a staging or preview deploy is available, wait for it and include the review URL before you conclude.'
            : 'Preferred delivery path: open a pull request for review as soon as the fix is ready.';

        $branchInstructions = $branchPreference
            ? "Branch preference: {$branchPreference}."
            : 'Branch preference: choose the safest reviewable branch/PR flow for the repository.';

        $requestSection = $requestText !== '' ? "\nSlack request:\n{$requestText}\n" : '';
        $sourcePrSection = $sourcePrNumber
            ? "Related pull request context: PR #{$sourcePrNumber}".($sourcePrUrl ? " ({$sourcePrUrl})" : '').".\n"
            : '';

        return <<<PROMPT
You are handling a Slack-originated engineering request.

Client: {$clientName}
Project: {$projectName}
Repository: {$repo}
GitHub Issue: #{$issueNumber} - {$issueTitle}
Issue URL: {$issueUrl}
{$requestSection}
{$sourcePrSection}
Issue details:
{$issueBody}

Execution requirements:
1. Investigate the issue in the repository and confirm the root cause.
2. Implement the fix.
3. {$deliveryInstructions}
4. {$branchInstructions}
5. In the pull request description, explicitly reference the GitHub issue using "Fixes #{$issueNumber}" or equivalent.
6. Report the outcome clearly for Slack review.

When you finish, include these fields whenever you know them:
- summary
- pr_url
- pr_number
- branch
- preview_url or staging_url
- deployment_status

If you cannot finish the deploy/review step, explain exactly what blocked it.
PROMPT;
    }
}
