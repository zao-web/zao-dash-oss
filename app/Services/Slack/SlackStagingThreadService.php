<?php

namespace App\Services\Slack;

use App\Models\GitHubRepo;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;

class SlackStagingThreadService
{
    public function __construct(
        private SlackApiService $api,
        private SlackBotResponseService $responseService,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public function rememberPublishedThread(SlackThreadContext $context, array $result): void
    {
        $contextData = $context->context_data ?? [];
        $contextData['staging_workflow'] = $this->buildWorkflowState(
            repoId: $result['repo']['id'] ?? null,
            repoName: $result['repo']['full_name'] ?? null,
            branch: $result['publish']['branch'] ?? $result['deployment']['publish_branch'] ?? null,
            workflowIdentifier: $result['publish']['workflow_identifier'] ?? $result['deployment']['workflow_identifier'] ?? null,
            workflowUrl: null,
            stagingUrl: $result['deployment']['staging_url'] ?? null,
            action: 'requested',
            workflowRun: [],
        );

        $context->update([
            'context_data' => $contextData,
            'current_state' => 'processing',
            'last_interaction_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $workflowRun
     */
    public function notifyWorkflowRun(GitHubRepo $repo, array $workflowRun, string $action): ?SlackThreadContext
    {
        $context = $this->findRelatedThreadContext($repo, $workflowRun);

        if (! $context) {
            return null;
        }

        $workflow = $this->buildWorkflowState(
            repoId: $repo->id,
            repoName: $repo->full_name,
            branch: $workflowRun['head_branch'] ?? ($context->context_data['staging_workflow']['branch'] ?? null),
            workflowIdentifier: $context->context_data['staging_workflow']['workflow_identifier'] ?? null,
            workflowUrl: $workflowRun['html_url'] ?? null,
            stagingUrl: $repo->deploymentConfig?->staging_url ?? ($repo->deployment_config['staging_url'] ?? null),
            action: $action,
            workflowRun: $workflowRun,
        );

        $contextData = $context->context_data ?? [];
        $contextData['staging_workflow'] = $workflow;

        $context->update([
            'context_data' => $contextData,
            'current_state' => $action === 'completed' ? 'idle' : 'processing',
            'last_interaction_at' => now(),
        ]);

        $this->postToSlackThread($context->fresh(['channel.workspace']), $workflow);

        return $context->fresh();
    }

    /**
     * @param  array<string, mixed>  $workflowRun
     */
    private function findRelatedThreadContext(GitHubRepo $repo, array $workflowRun): ?SlackThreadContext
    {
        $headBranch = (string) ($workflowRun['head_branch'] ?? '');

        return SlackThreadContext::query()
            ->with('channel.workspace')
            ->latest('last_interaction_at')
            ->limit(100)
            ->get()
            ->first(function (SlackThreadContext $context) use ($repo, $headBranch): bool {
                $stagingWorkflow = $context->context_data['staging_workflow'] ?? [];
                $storedRepoId = (int) ($stagingWorkflow['repo_id'] ?? 0);
                $storedRepoName = (string) ($stagingWorkflow['repo'] ?? '');
                $storedBranch = (string) ($stagingWorkflow['branch'] ?? '');

                if (! $context->channel?->workspace) {
                    return false;
                }

                if ($storedRepoId !== 0 && $storedRepoId !== $repo->id) {
                    return false;
                }

                if ($storedRepoId === 0 && $storedRepoName !== '' && $storedRepoName !== $repo->full_name) {
                    return false;
                }

                if ($headBranch !== '' && $storedBranch !== '' && $storedBranch !== $headBranch) {
                    return false;
                }

                return $storedRepoId === $repo->id || $storedRepoName === $repo->full_name;
            });
    }

    /**
     * @param  array<string, mixed>  $workflowRun
     * @return array<string, mixed>
     */
    private function buildWorkflowState(
        ?int $repoId,
        ?string $repoName,
        ?string $branch,
        ?string $workflowIdentifier,
        ?string $workflowUrl,
        ?string $stagingUrl,
        string $action,
        array $workflowRun,
    ): array {
        $conclusion = strtolower((string) ($workflowRun['conclusion'] ?? ''));
        $status = match ($action) {
            'requested' => 'requested',
            'in_progress' => 'in_progress',
            default => 'completed',
        };
        $deploymentStatus = match ($action) {
            'requested' => 'queued',
            'in_progress' => 'deploying',
            default => $conclusion !== '' ? ($conclusion === 'success' ? 'deployed' : $conclusion) : 'completed',
        };

        return array_filter([
            'repo_id' => $repoId,
            'repo' => $repoName,
            'branch' => $branch,
            'workflow_identifier' => $workflowIdentifier,
            'workflow_run_id' => $workflowRun['id'] ?? null,
            'workflow_status' => $status,
            'workflow_url' => $workflowUrl,
            'deployment_status' => $deploymentStatus,
            'workflow_conclusion' => $conclusion !== '' ? $conclusion : null,
            'workflow_failure_summary' => $this->extractWorkflowFailureSummary($workflowRun, $conclusion),
            'staging_url' => $stagingUrl,
            'last_updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
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
     * @param  array<string, mixed>  $workflow
     */
    private function postToSlackThread(SlackThreadContext $context, array $workflow): void
    {
        $workspace = $context->channel?->workspace;

        if (! $workspace instanceof SlackWorkspace) {
            return;
        }

        $this->api->postMessage($workspace, $context->channel->channel_id, '', [
            'thread_ts' => $context->thread_ts,
            'blocks' => $this->responseService->stagingWorkflowUpdateBlocks($workflow),
        ]);
    }
}
