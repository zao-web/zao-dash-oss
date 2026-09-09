<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteAgentJob;
use App\Jobs\SyncAgentsJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\GitHubInstallation;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use App\Services\Slack\SlackGitHubEngineeringUpdateService;
use App\Services\Slack\SlackStagingThreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GitHubWebhookController extends Controller
{
    public function __construct(
        private GitHubAppService $app,
        private GitHubApiService $api,
        private SlackGitHubEngineeringUpdateService $slackEngineeringUpdates,
        private SlackStagingThreadService $slackStagingThreadService,
    ) {}

    /**
     * Handle all GitHub webhooks
     */
    public function handle(Request $request)
    {
        // Verify webhook signature
        if (! $this->verifySignature($request)) {
            Log::warning('GitHub webhook: Invalid signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();

        Log::info('GitHub webhook received', [
            'event' => $event,
            'action' => $payload['action'] ?? null,
        ]);

        switch ($event) {
            case 'installation':
                $this->handleInstallation($payload);
                break;

            case 'installation_repositories':
                $this->handleInstallationRepos($payload);
                break;

            case 'issues':
                $this->handleIssue($payload);
                break;

            case 'pull_request':
                $this->handlePullRequest($payload);
                break;

            case 'pull_request_review':
                $this->handlePullRequestReview($payload);
                break;

            case 'push':
                $this->handlePush($payload);
                break;

            case 'check_run':
            case 'check_suite':
                $this->handleChecks($payload);
                break;

            case 'workflow_run':
                $this->handleWorkflowRun($payload);
                break;

            default:
                Log::debug('GitHub webhook: Unhandled event', ['event' => $event]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verify webhook signature
     */
    private function verifySignature(Request $request): bool
    {
        $secret = config('services.github.webhook_secret');
        if (! $secret) {
            return true; // Skip in dev
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle installation events
     */
    private function handleInstallation(array $payload): void
    {
        $action = $payload['action'] ?? '';
        $installation = $payload['installation'] ?? [];

        $this->app->handleInstallationWebhook($action, $installation);
    }

    /**
     * Handle repository added/removed from installation
     */
    private function handleInstallationRepos(array $payload): void
    {
        $installationId = $payload['installation']['id'] ?? null;
        $installation = GitHubInstallation::where('installation_id', $installationId)->first();

        if (! $installation) {
            return;
        }

        // Re-sync all repos
        $this->app->syncRepos($installation);
    }

    /**
     * Handle issue events
     */
    private function handleIssue(array $payload): void
    {
        $action = $payload['action'] ?? '';
        $issue = $payload['issue'] ?? [];
        $repoData = $payload['repository'] ?? [];

        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();
        if (! $repo || ! $repo->monitoring_enabled) {
            return;
        }

        // Store/update the issue
        $record = $this->api->storeIssue($repo, $issue);

        // Handle specific actions
        if (in_array($action, ['opened', 'reopened'])) {
            // Sync to task
            $this->api->syncIssueToTask($record);

            // Check if this should trigger DevAgent
            if ($record->isAgentTask()) {
                $this->queueDevAgentForIssue($record);
            }
        } elseif ($action === 'closed') {
            // Update linked task
            if ($record->task) {
                $record->task->update(['status' => 'completed']);
            }
        }
    }

    /**
     * Handle pull request events
     */
    private function handlePullRequest(array $payload): void
    {
        $action = $payload['action'] ?? '';
        $pr = $payload['pull_request'] ?? [];
        $repoData = $payload['repository'] ?? [];

        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();
        if (! $repo || ! $repo->monitoring_enabled) {
            return;
        }

        // Store/update the PR
        $record = $this->api->storePullRequest($repo, $pr);
        $this->slackEngineeringUpdates->syncPullRequestToSlackRun(
            $record,
            $this->slackEngineeringUpdates->extractIssueNumber(
                $pr['body'] ?? null,
                $pr['title'] ?? null
            )
        );

        if ($action === 'opened') {
            // PR opened - determine workflow
            if ($record->targetsMain()) {
                // Needs approval for production
                $this->createApprovalRequestForPr($record);
            } elseif ($record->targetsDevelop()) {
                // Queue QA agent for testing
                $this->queueQaAgentForPr($record);
            }
        } elseif ($action === 'closed' && $pr['merged']) {
            $record->update([
                'state' => 'merged',
                'merged_at' => now()->parse($pr['merged_at']),
            ]);

            // If merged to main, could trigger deploy
            if ($record->targetsMain()) {
                $this->queueDeployAgentForRepo($repo);
            }
        }
    }

    /**
     * Handle pull request review events
     */
    private function handlePullRequestReview(array $payload): void
    {
        $action = $payload['action'] ?? '';
        $review = $payload['review'] ?? [];
        $pr = $payload['pull_request'] ?? [];
        $repoData = $payload['repository'] ?? [];

        if ($action !== 'submitted') {
            return;
        }

        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();
        if (! $repo) {
            return;
        }

        $record = GitHubPullRequest::where('repo_id', $repo->id)
            ->where('pr_number', $pr['number'])
            ->first();

        if (! $record) {
            return;
        }

        $reviewState = strtolower($review['state'] ?? '');

        if ($reviewState === 'approved') {
            // If targeting develop and approved, could auto-merge
            if ($record->targetsDevelop() && $record->checks_passed) {
                try {
                    $this->api->mergePullRequest($repo, $record->pr_number);
                    $record->update(['state' => 'merged', 'merged_at' => now()]);
                } catch (\Exception $e) {
                    Log::error('Failed to auto-merge PR', [
                        'pr' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handlePush(array $payload): void
    {
        $ref = $payload['ref'] ?? '';
        $repoData = $payload['repository'] ?? [];
        $commits = $payload['commits'] ?? [];

        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();

        $branch = str_replace('refs/heads/', '', $ref);

        if ($this->isZaoDashRepo($repoData)) {
            $this->checkForAgentDefinitionChanges($commits);
        }

        if (! $repo) {
            return;
        }

        if (in_array($branch, ['main', 'master'])) {
            Log::info('Push to main detected', ['repo' => $repo->full_name]);
        } elseif (in_array($branch, ['develop', 'dev', 'development'])) {
            Log::info('Push to develop detected', ['repo' => $repo->full_name]);
        }
    }

    private function isZaoDashRepo(array $repoData): bool
    {
        $fullName = $repoData['full_name'] ?? '';

        return str_contains($fullName, 'zao-dash') || str_contains($fullName, 'zao/dash');
    }

    private function checkForAgentDefinitionChanges(array $commits): void
    {
        $changedAgentFiles = [];

        foreach ($commits as $commit) {
            $allFiles = array_merge(
                $commit['added'] ?? [],
                $commit['modified'] ?? [],
                $commit['removed'] ?? []
            );

            foreach ($allFiles as $file) {
                if (str_starts_with($file, 'app/Agents/Definitions/') && str_ends_with($file, '.php')) {
                    $changedAgentFiles[] = $file;
                }
            }
        }

        $changedAgentFiles = array_unique($changedAgentFiles);

        if (! empty($changedAgentFiles)) {
            Log::info('Agent definition files changed - will sync after deploy completes', [
                'files' => $changedAgentFiles,
            ]);

            SyncAgentsJob::dispatch($changedAgentFiles);
        }
    }

    /**
     * Handle check run/suite events
     */
    private function handleChecks(array $payload): void
    {
        $checkSuite = $payload['check_suite'] ?? $payload['check_run']['check_suite'] ?? null;
        if (! $checkSuite) {
            return;
        }

        $repoData = $payload['repository'] ?? [];
        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();
        if (! $repo) {
            return;
        }

        // Find associated PRs
        foreach ($checkSuite['pull_requests'] ?? [] as $prRef) {
            $pr = GitHubPullRequest::where('repo_id', $repo->id)
                ->where('pr_number', $prRef['number'])
                ->first();

            if ($pr) {
                $conclusion = $checkSuite['conclusion'] ?? null;
                $pr->update([
                    'checks_passed' => $conclusion === 'success',
                ]);
            }
        }
    }

    private function handleWorkflowRun(array $payload): void
    {
        $action = $payload['action'] ?? '';
        $workflowRun = $payload['workflow_run'] ?? [];

        if (! in_array($action, ['requested', 'in_progress', 'completed'], true)) {
            return;
        }

        $repoData = $payload['repository'] ?? [];
        $repo = GitHubRepo::where('repo_id', $repoData['id'])->first();
        if (! $repo) {
            return;
        }

        $pullRequest = $this->resolvePullRequestForWorkflowRun($repo, $workflowRun);
        if (! $pullRequest) {
            $this->slackStagingThreadService->notifyWorkflowRun($repo->load('deploymentConfig'), $workflowRun, $action);

            return;
        }

        if ($action === 'completed') {
            $this->slackEngineeringUpdates->notifyWorkflowRun($pullRequest, $workflowRun);

            return;
        }

        $this->slackEngineeringUpdates->notifyWorkflowRunProgress($pullRequest, $workflowRun, $action);
    }

    private function resolvePullRequestForWorkflowRun(GitHubRepo $repo, array $workflowRun): ?GitHubPullRequest
    {
        $pullRequestNumber = $workflowRun['pull_requests'][0]['number'] ?? null;

        if ($pullRequestNumber) {
            return GitHubPullRequest::query()
                ->where('repo_id', $repo->id)
                ->where('pr_number', $pullRequestNumber)
                ->first();
        }

        $headBranch = $workflowRun['head_branch'] ?? null;

        if (! $headBranch) {
            return null;
        }

        return GitHubPullRequest::query()
            ->where('repo_id', $repo->id)
            ->where('head_branch', $headBranch)
            ->latest('id')
            ->first();
    }

    /**
     * Create approval request for PR to main
     */
    private function createApprovalRequestForPr(GitHubPullRequest $pr): void
    {
        $approval = ApprovalRequest::create([
            'agent_run_id' => null, // Manual approval, not from agent
            'action_type' => 'production_deployment',
            'description' => "PR #{$pr->pr_number} wants to merge to production: {$pr->title}",
            'risk_level' => 'high',
            'payload' => [
                'pr_id' => $pr->id,
                'pr_url' => $pr->url,
                'author' => $pr->author,
                'base_branch' => $pr->base_branch,
            ],
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        $pr->update(['approval_request_id' => $approval->id]);

        Log::info('Created approval request for PR', [
            'pr' => $pr->id,
            'approval' => $approval->id,
        ]);
    }

    /**
     * Queue DevAgent to work on an issue
     */
    private function queueDevAgentForIssue(GitHubIssue $issue): void
    {
        $agent = Agent::where('slug', 'dev-agent')->where('status', 'active')->first();
        if (! $agent) {
            Log::warning('DevAgent not found or not active');

            return;
        }

        $config = [
            'prompt' => $this->buildDevAgentPrompt($issue),
            'context' => [
                'github_issue_id' => $issue->id,
                'issue_number' => $issue->issue_number,
                'repo' => $issue->repo?->full_name,
                'labels' => $issue->labels,
                'source' => 'github_webhook',
            ],
        ];

        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_WEBHOOK,
            invokedBy: 'github:issue:'.$issue->issue_number,
            triggerMetadata: ['github_event' => 'issues', 'action' => 'opened'],
        );

        // Link the issue to the upcoming agent run
        if (Schema::hasColumn('github_issues', 'queued_for_agent')) {
            $issue->update(['queued_for_agent' => true]);
        }

        Log::info('Queued DevAgent for issue', [
            'issue' => $issue->id,
            'title' => $issue->title,
        ]);
    }

    /**
     * Build prompt for DevAgent based on issue
     */
    private function buildDevAgentPrompt(GitHubIssue $issue): string
    {
        $labels = implode(', ', $issue->labels ?? []);

        return <<<PROMPT
GitHub Issue #{$issue->issue_number}: {$issue->title}

Repository: {$issue->repo?->full_name}
Labels: {$labels}

Description:
{$issue->body}

Please analyze this issue and implement a solution. Create a pull request with your changes.
PROMPT;
    }

    /**
     * Queue QAAgent to test a PR
     */
    private function queueQaAgentForPr(GitHubPullRequest $pr): void
    {
        // Look for a QA or code-review agent
        $agent = Agent::whereIn('slug', ['qa-agent', 'code-reviewer', 'dev-agent'])
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            Log::info('No QA agent configured for PR review');

            return;
        }

        $config = [
            'prompt' => "Review PR #{$pr->pr_number}: {$pr->title}\n\nDescription:\n{$pr->body}\n\nChanges: {$pr->additions} additions, {$pr->deletions} deletions",
            'context' => [
                'github_pr_id' => $pr->id,
                'pr_number' => $pr->pr_number,
                'repo' => $pr->repo?->full_name,
                'branch' => $pr->head_branch,
                'source' => 'github_webhook',
            ],
        ];

        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_WEBHOOK,
            invokedBy: 'github:pr:'.$pr->pr_number,
            triggerMetadata: ['github_event' => 'pull_request', 'action' => 'opened'],
        );

        Log::info('Queued QA agent for PR', [
            'pr' => $pr->id,
            'title' => $pr->title,
        ]);
    }

    /**
     * Queue DeployAgent for a repo
     */
    private function queueDeployAgentForRepo(GitHubRepo $repo): void
    {
        // Check if repo has deployment config
        $config = $repo->deployment_config ?? [];
        if (empty($config)) {
            Log::info('No deployment config for repo', ['repo' => $repo->full_name]);

            return;
        }

        // Look for deploy agent
        $agent = Agent::whereIn('slug', ['deploy-agent', 'dev-agent'])
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            Log::info('No deploy agent configured');

            return;
        }

        $agentConfig = [
            'prompt' => "Deploy {$repo->full_name} to production. Follow the deployment config.",
            'context' => [
                'repo_id' => $repo->id,
                'repo' => $repo->full_name,
                'branch' => 'main',
                'deployment_config' => $config,
                'source' => 'github_webhook',
            ],
        ];

        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $agentConfig,
            invocationSource: AgentRun::SOURCE_WEBHOOK,
            invokedBy: 'github:push:main',
            triggerMetadata: ['github_event' => 'push', 'branch' => 'main'],
        );

        Log::info('Queued deploy agent for repo', ['repo' => $repo->full_name]);
    }
}
