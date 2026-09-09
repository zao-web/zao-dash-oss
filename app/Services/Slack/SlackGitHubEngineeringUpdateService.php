<?php

namespace App\Services\Slack;

use App\Models\AgentRun;
use App\Models\GitHubPullRequest;
use App\Models\SlackWorkspace;

class SlackGitHubEngineeringUpdateService
{
    public function __construct(
        private SlackApiService $api,
        private SlackBotResponseService $responseService,
    ) {}

    public function syncPullRequestToSlackRun(GitHubPullRequest $pullRequest, ?int $issueNumber = null): ?AgentRun
    {
        $run = $this->findRelatedRun($pullRequest, $issueNumber);

        if (! $run) {
            return null;
        }

        $this->mergeRunOutput($run, [
            'pr_url' => $pullRequest->url,
            'pull_request_url' => $pullRequest->url,
            'pr_number' => $pullRequest->pr_number,
            'branch' => $pullRequest->head_branch,
            'branch_name' => $pullRequest->head_branch,
        ]);

        return $run->fresh();
    }

    public function notifyWorkflowRun(GitHubPullRequest $pullRequest, array $workflowRun): ?AgentRun
    {
        $run = $this->findRelatedRun($pullRequest, $this->extractIssueNumber(
            $pullRequest->body,
            $pullRequest->title
        ));

        if (! $run) {
            return null;
        }

        $conclusion = strtolower((string) ($workflowRun['conclusion'] ?? ''));
        $workflowName = $workflowRun['name'] ?? $workflowRun['display_title'] ?? 'GitHub Actions workflow';
        $workflowUrl = $workflowRun['html_url'] ?? null;
        $reviewUrl = $this->resolveReviewUrl($pullRequest);

        $this->mergeRunOutput($run, $this->buildWorkflowRunOutput(
            $pullRequest,
            $workflowRun,
            status: 'completed',
            deploymentStatus: $conclusion !== '' ? ($conclusion === 'success' ? 'deployed' : $conclusion) : null,
            reviewUrl: $reviewUrl,
            workflowName: $workflowName,
            workflowUrl: $workflowUrl,
            workflowConclusion: $conclusion !== '' ? $conclusion : null,
            failureSummary: $this->extractWorkflowFailureSummary($workflowRun, $conclusion),
        ));

        $this->postToSlackThread(
            $run->fresh(),
            $this->buildWorkflowBlocks(
                $pullRequest,
                $workflowName,
                $workflowUrl,
                $reviewUrl,
                $conclusion,
                $this->extractWorkflowFailureSummary($workflowRun, $conclusion)
            )
        );

        return $run->fresh();
    }

    public function notifyWorkflowRunProgress(GitHubPullRequest $pullRequest, array $workflowRun, string $action): ?AgentRun
    {
        $run = $this->findRelatedRun($pullRequest, $this->extractIssueNumber(
            $pullRequest->body,
            $pullRequest->title
        ));

        if (! $run) {
            return null;
        }

        $workflowName = $workflowRun['name'] ?? $workflowRun['display_title'] ?? 'GitHub Actions workflow';
        $workflowUrl = $workflowRun['html_url'] ?? null;
        $reviewUrl = $this->resolveReviewUrl($pullRequest);
        $status = $action === 'requested' ? 'requested' : 'in_progress';
        $deploymentStatus = $status === 'requested' ? 'queued' : 'deploying';

        $this->mergeRunOutput($run, $this->buildWorkflowRunOutput(
            $pullRequest,
            $workflowRun,
            status: $status,
            deploymentStatus: $deploymentStatus,
            reviewUrl: $reviewUrl,
            workflowName: $workflowName,
            workflowUrl: $workflowUrl,
        ));

        $this->postToSlackThread(
            $run->fresh(),
            $this->buildWorkflowProgressBlocks($pullRequest, $workflowName, $workflowUrl, $status)
        );

        return $run->fresh();
    }

    public function extractIssueNumber(?string ...$texts): ?int
    {
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            if (preg_match('/(?:fix(?:e[sd])?|close[sd]?|resolve[sd]?)\s+[^#\n\r]*#(\d+)/i', $text, $matches)) {
                return (int) $matches[1];
            }

            if (preg_match('/(?:^|\s)#(\d+)\b/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function findRelatedRun(GitHubPullRequest $pullRequest, ?int $issueNumber = null): ?AgentRun
    {
        $repo = $pullRequest->repo?->full_name;

        if (! $repo) {
            return null;
        }

        $candidates = AgentRun::query()
            ->where('invocation_source', AgentRun::SOURCE_SLACK)
            ->where(function ($query) use ($repo, $pullRequest) {
                $query->where('context->engineering->repo', $repo)
                    ->orWhere('context->project->github_repo', $repo);

                if ($pullRequest->repo?->project_id) {
                    $query->orWhere('project_id', $pullRequest->repo->project_id);
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        return $candidates->first(function (AgentRun $run) use ($pullRequest, $issueNumber): bool {
            $output = $run->output ?? [];
            $engineeringContext = $run->context['engineering'] ?? [];

            if (($output['pr_number'] ?? null) === $pullRequest->pr_number) {
                return true;
            }

            if (($output['pr_url'] ?? $output['pull_request_url'] ?? null) === $pullRequest->url) {
                return true;
            }

            if (($output['branch'] ?? $output['branch_name'] ?? null) === $pullRequest->head_branch) {
                return true;
            }

            if ($issueNumber !== null && (int) ($engineeringContext['issue_number'] ?? 0) === $issueNumber) {
                return true;
            }

            return false;
        });
    }

    private function mergeRunOutput(AgentRun $run, array $attributes): void
    {
        $run->update([
            'output' => array_merge($run->output ?? [], $attributes),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkflowRunOutput(
        GitHubPullRequest $pullRequest,
        array $workflowRun,
        string $status,
        ?string $deploymentStatus,
        ?string $reviewUrl,
        string $workflowName,
        ?string $workflowUrl,
        ?string $workflowConclusion = null,
        ?string $failureSummary = null,
    ): array {
        return array_filter([
            'workflow_run_id' => $workflowRun['id'] ?? null,
            'workflow_status' => $status,
            'workflow_conclusion' => $workflowConclusion,
            'workflow_failure_summary' => $failureSummary,
            'deployment_status' => $deploymentStatus,
            'staging_url' => $reviewUrl,
            'workflow_url' => $workflowUrl,
            'workflow_name' => $workflowName,
            'pr_url' => $pullRequest->url,
            'pull_request_url' => $pullRequest->url,
            'pr_number' => $pullRequest->pr_number,
            'branch' => $pullRequest->head_branch,
            'branch_name' => $pullRequest->head_branch,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function resolveReviewUrl(GitHubPullRequest $pullRequest): ?string
    {
        return $pullRequest->repo?->deploymentConfig?->staging_url
            ?? ($pullRequest->repo?->deployment_config['staging_url'] ?? null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowBlocks(
        GitHubPullRequest $pullRequest,
        string $workflowName,
        ?string $workflowUrl,
        ?string $reviewUrl,
        string $conclusion,
        ?string $failureSummary = null,
    ): array {
        $success = $conclusion === 'success';
        $headline = $success
            ? ':rocket: *GitHub Actions deployed this branch and the review flow is ready.*'
            : ":x: *GitHub Actions reported `{$conclusion}` for this branch.*";

        $blocks = [
            $this->responseService->section($headline),
            $this->responseService->context(array_filter([
                $pullRequest->repo?->full_name,
                "PR #{$pullRequest->pr_number}",
                "Branch: {$pullRequest->head_branch}",
                "Workflow: {$workflowName}",
            ])),
        ];

        if ($reviewUrl && $success) {
            $blocks[] = $this->responseService->section("Review URL: <{$reviewUrl}|{$reviewUrl}>");
        } elseif ($failureSummary && ! $success) {
            $blocks[] = $this->responseService->section("Failure details: {$failureSummary}");
        } elseif ($workflowUrl) {
            $blocks[] = $this->responseService->section("Workflow run: <{$workflowUrl}|Open in GitHub Actions>");
        }

        $actions = [];

        if ($pullRequest->url) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open PR',
                ],
                'url' => $pullRequest->url,
            ];
        }

        if ($reviewUrl && $success) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Review Build',
                ],
                'style' => 'primary',
                'url' => $reviewUrl,
            ];
        }

        if ($workflowUrl) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Workflow',
                ],
                'url' => $workflowUrl,
            ];
        }

        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $actions,
            ];
        }

        return $blocks;
    }

    private function extractWorkflowFailureSummary(array $workflowRun, string $conclusion): ?string
    {
        if ($conclusion === '' || $conclusion === 'success') {
            return null;
        }

        return $workflowRun['display_title']
            ?? $workflowRun['head_commit']['message']
            ?? $workflowRun['name']
            ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowProgressBlocks(
        GitHubPullRequest $pullRequest,
        string $workflowName,
        ?string $workflowUrl,
        string $status,
    ): array {
        $headline = $status === 'requested'
            ? ':hourglass_flowing_sand: *GitHub Actions queued a review deploy for this branch.*'
            : ':hammer_and_wrench: *GitHub Actions is building the review deploy for this branch.*';

        $blocks = [
            $this->responseService->section($headline),
            $this->responseService->context(array_filter([
                $pullRequest->repo?->full_name,
                "PR #{$pullRequest->pr_number}",
                "Branch: {$pullRequest->head_branch}",
                "Workflow: {$workflowName}",
            ])),
        ];

        if ($workflowUrl) {
            $blocks[] = $this->responseService->section("Workflow run: <{$workflowUrl}|Open in GitHub Actions>");
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open PR',
                        ],
                        'url' => $pullRequest->url,
                    ],
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open Workflow',
                        ],
                        'url' => $workflowUrl,
                    ],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function postToSlackThread(AgentRun $run, array $blocks): void
    {
        $slackContext = $run->context['slack'] ?? [];
        $workspaceId = $slackContext['workspace_id'] ?? null;
        $channelId = $slackContext['channel_id'] ?? null;
        $threadTs = $slackContext['thread_ts'] ?? null;

        if (! $workspaceId || ! $channelId || ! $threadTs) {
            return;
        }

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $workspace) {
            return;
        }

        $this->api->postMessage($workspace, $channelId, '', [
            'thread_ts' => $threadTs,
            'blocks' => $blocks,
        ]);
    }
}
