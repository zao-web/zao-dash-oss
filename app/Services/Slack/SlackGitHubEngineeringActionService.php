<?php

namespace App\Services\Slack;

use App\Models\AgentRun;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubApiService;

class SlackGitHubEngineeringActionService
{
    public function __construct(
        private GitHubApiService $github,
    ) {}

    /**
     * @return array{success: bool, error?: string, message?: string}
     */
    public function requestReviewDeploy(AgentRun $run): array
    {
        $context = $this->resolveDeployContext($run);

        if (! ($context['success'] ?? false)) {
            return $context;
        }

        /** @var GitHubRepo $repo */
        $repo = $context['repo'];
        /** @var GitHubPullRequest $pullRequest */
        $pullRequest = $context['pull_request'];
        $workflowIdentifier = $context['workflow_identifier'];
        $branch = $context['branch'];

        $this->github->dispatchWorkflow($repo, $workflowIdentifier, $branch, []);

        $run->update([
            'output' => array_merge($run->output ?? [], [
                'deployment_status' => 'queued',
                'workflow_name' => $workflowIdentifier,
                'workflow_status' => 'requested',
                'workflow_dispatch_branch' => $branch,
                'workflow_dispatch_requested_at' => now()->toIso8601String(),
            ]),
        ]);

        return [
            'success' => true,
            'message' => "Requested review deploy for PR #{$pullRequest->pr_number} on `{$branch}`.",
        ];
    }

    /**
     * @return array{success: bool, error?: string, message?: string}
     */
    public function retryReviewDeploy(AgentRun $run): array
    {
        $context = $this->resolveDeployContext($run);

        if (! ($context['success'] ?? false)) {
            return $context;
        }

        /** @var GitHubRepo $repo */
        $repo = $context['repo'];
        /** @var GitHubPullRequest $pullRequest */
        $pullRequest = $context['pull_request'];

        $workflowRunId = (int) (($run->output['workflow_run_id'] ?? 0));

        if ($workflowRunId > 0) {
            $this->github->rerunWorkflowRun($repo, $workflowRunId);
        } else {
            $this->github->dispatchWorkflow($repo, $context['workflow_identifier'], $context['branch'], []);
        }

        $run->update([
            'output' => array_merge($run->output ?? [], [
                'deployment_status' => 'queued',
                'workflow_status' => 'requested',
                'workflow_retry_requested_at' => now()->toIso8601String(),
            ]),
        ]);

        return [
            'success' => true,
            'message' => $workflowRunId > 0
                ? "Retried review deploy for PR #{$pullRequest->pr_number}."
                : "Requested a fresh review deploy for PR #{$pullRequest->pr_number}.",
        ];
    }

    /**
     * @return array{success: bool, error?: string, repo?: GitHubRepo, pull_request?: GitHubPullRequest, workflow_identifier?: string, branch?: string}
     */
    private function resolveDeployContext(AgentRun $run): array
    {
        $repoFullName = $run->context['engineering']['repo']
            ?? $run->context['project']['github_repo']
            ?? null;

        if (! $repoFullName && ! $run->project_id) {
            return ['success' => false, 'error' => 'This run is not linked to a GitHub repository.'];
        }

        $repo = GitHubRepo::query()
            ->with('deploymentConfig')
            ->where(function ($query) use ($repoFullName, $run): void {
                if ($repoFullName) {
                    $query->where('full_name', $repoFullName);
                }

                if ($run->project_id) {
                    if ($repoFullName) {
                        $query->orWhere('project_id', $run->project_id);
                    } else {
                        $query->where('project_id', $run->project_id);
                    }
                }
            })
            ->first();

        if (! $repo) {
            return ['success' => false, 'error' => 'No linked GitHub repository was found for this run.'];
        }

        $prNumber = (int) ($run->output['pr_number'] ?? 0);

        if (! $prNumber) {
            return ['success' => false, 'error' => 'No pull request is linked to this engineering run yet.'];
        }

        $pullRequest = GitHubPullRequest::query()
            ->where('repo_id', $repo->id)
            ->where('pr_number', $prNumber)
            ->first();

        if (! $pullRequest) {
            return ['success' => false, 'error' => "Pull request #{$prNumber} could not be found for {$repo->full_name}."];
        }

        $workflowFilePath = $repo->deploymentConfig?->workflow_file_path
            ?? ($repo->deployment_config['workflow_file_path'] ?? null);

        if (! $workflowFilePath) {
            return ['success' => false, 'error' => 'This repository does not have a deployment workflow configured yet.'];
        }

        $branch = $pullRequest->head_branch
            ?: ($run->output['branch'] ?? $run->output['branch_name'] ?? null);

        if (! $branch) {
            return ['success' => false, 'error' => 'No deployable branch could be resolved for this run.'];
        }

        return [
            'success' => true,
            'repo' => $repo,
            'pull_request' => $pullRequest,
            'workflow_identifier' => basename($workflowFilePath),
            'branch' => $branch,
        ];
    }
}
