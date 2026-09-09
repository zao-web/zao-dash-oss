<?php

namespace App\Listeners;

use App\Events\AgentRunStatusChanged;
use App\Models\AgentRun;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifySlackOnAgentRunComplete implements ShouldQueue
{
    public string $queue = 'slack-notifications';

    public function __construct(
        private SlackApiService $api,
        private SlackBotResponseService $responseService,
    ) {}

    public function handle(AgentRunStatusChanged $event): void
    {
        $run = $event->run;

        if ($run->status === AgentRun::STATUS_RUNNING && $event->previousStatus === AgentRun::STATUS_PENDING_APPROVAL) {
            $this->notifyExecutionStarted($run);

            return;
        }

        if (! in_array($run->status, ['completed', 'failed', AgentRun::STATUS_CANCELLED], true)) {
            return;
        }

        $slackContext = $run->context['slack'] ?? null;
        if (! $slackContext) {
            return;
        }

        $channelId = $slackContext['channel_id'] ?? null;
        $threadTs = $slackContext['thread_ts'] ?? null;
        $workspaceId = $slackContext['workspace_id'] ?? null;

        if (! $channelId || ! $workspaceId) {
            Log::warning('NotifySlackOnAgentRunComplete: Missing Slack context', [
                'run_id' => $run->id,
            ]);

            return;
        }

        $workspace = SlackWorkspace::where('workspace_id', $workspaceId)->first();
        if (! $workspace) {
            Log::warning('NotifySlackOnAgentRunComplete: Workspace not found', [
                'run_id' => $run->id,
                'workspace_id' => $workspaceId,
            ]);

            return;
        }

        try {
            $blocks = $this->buildCompletionBlocks($run);

            $this->api->postMessage($workspace, $channelId, '', [
                'thread_ts' => $threadTs,
                'blocks' => $blocks,
            ]);

            $threadContext = SlackThreadContext::where('agent_run_id', $run->id)->first();
            if ($threadContext) {
                $summary = $run->status === 'completed'
                    ? "Agent {$run->agent->name} completed successfully."
                    : ($run->status === AgentRun::STATUS_CANCELLED
                        ? "Agent {$run->agent->name} was cancelled."
                        : "Agent {$run->agent->name} failed.");
                $threadContext->addToHistory('system', $summary);
                $threadContext->update(['current_state' => 'idle']);
            }

            Log::info('NotifySlackOnAgentRunComplete: Notification sent', [
                'run_id' => $run->id,
                'status' => $run->status,
                'channel_id' => $channelId,
            ]);
        } catch (\Exception $e) {
            Log::error('NotifySlackOnAgentRunComplete: Failed to send notification', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyExecutionStarted(AgentRun $run): void
    {
        $slackContext = $run->context['slack'] ?? null;
        if (! $slackContext) {
            return;
        }

        $channelId = $slackContext['channel_id'] ?? null;
        $threadTs = $slackContext['thread_ts'] ?? null;
        $workspaceId = $slackContext['workspace_id'] ?? null;

        if (! $channelId || ! $workspaceId) {
            return;
        }

        $workspace = SlackWorkspace::where('workspace_id', $workspaceId)->first();
        if (! $workspace) {
            return;
        }

        try {
            $statusBlocks = $this->responseService->agentRunStatusUpdate($run)['blocks'];
            $blocks = array_merge([
                $this->responseService->section(':white_check_mark: Approval granted. Starting execution.'),
                $this->responseService->divider(),
            ], $statusBlocks);

            $this->api->postMessage($workspace, $channelId, '', [
                'thread_ts' => $threadTs,
                'blocks' => $blocks,
            ]);

            $threadContext = SlackThreadContext::where('agent_run_id', $run->id)->first();
            if ($threadContext) {
                $threadContext->addToHistory('system', "Approval granted. Agent {$run->agent->name} is starting.");
                $threadContext->update(['current_state' => 'processing']);
            }
        } catch (\Exception $e) {
            Log::error('NotifySlackOnAgentRunComplete: Failed to send execution-start notification', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildCompletionBlocks(AgentRun $run): array
    {
        $agent = $run->agent;
        $isSuccess = $run->status === 'completed';
        $isCancelled = $run->status === AgentRun::STATUS_CANCELLED;
        $engineeringOutcome = $this->resolveEngineeringOutcome($run);

        $statusEmoji = $engineeringOutcome['status_emoji'] ?? ($isSuccess ? ':white_check_mark:' : ($isCancelled ? ':no_entry:' : ':x:'));
        $statusText = $engineeringOutcome['status_text'] ?? ($isSuccess ? 'Completed' : ($isCancelled ? 'Cancelled' : 'Failed'));

        $blocks = [
            $this->responseService->section("{$statusEmoji} *Agent Run {$statusText}*\n\n*{$agent->name}*"),
        ];

        $taskId = $run->task_id ?? $run->context['task_id'] ?? $run->trigger_metadata['task_id'] ?? null;
        $taskTitle = $run->context['task_title'] ?? $run->task?->title ?? null;

        if ($taskId || $taskTitle) {
            $label = $taskId ? "Task #{$taskId}" : 'Task';

            if ($taskTitle) {
                $label .= ": {$taskTitle}";
            }

            $blocks[] = $this->responseService->section(":clipboard: *{$label}*");
        }

        if ($engineeringOutcome['headline'] ?? null) {
            $blocks[] = $this->responseService->section($engineeringOutcome['headline']);
        }

        if ($isSuccess && $run->output) {
            $output = is_array($run->output)
                ? ($run->output['summary'] ?? $run->output['result'] ?? json_encode($run->output, JSON_PRETTY_PRINT))
                : $run->output;

            $truncated = strlen($output) > 800 ? substr($output, 0, 800).'...' : $output;
            $blocks[] = $this->responseService->section("*Output:*\n```{$truncated}```");
        }

        if (! $isSuccess && $run->error) {
            $error = strlen($run->error) > 500 ? substr($run->error, 0, 500).'...' : $run->error;
            $label = $isCancelled ? 'Reason' : 'Error';
            $blocks[] = $this->responseService->section("*{$label}:*\n```{$error}```");
        }

        if ($engineeringOutcome['link_context'] ?? []) {
            $blocks[] = $this->responseService->context($engineeringOutcome['link_context']);
        }

        if ($engineeringOutcome['actions'] ?? []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $engineeringOutcome['actions'],
            ];
        }

        $duration = $run->completed_at && $run->started_at
            ? $run->completed_at->diffForHumans($run->started_at, true)
            : 'N/A';

        $contextElements = [
            'Run #'.$run->id,
            "Duration: {$duration}",
        ];

        if ($run->cost_usd) {
            $contextElements[] = 'Cost: $'.number_format($run->cost_usd, 4);
        }

        $contextElements[] = '<'.config('app.url')."/agents/{$agent->id}/runs/{$run->id}|View Details>";

        $blocks[] = $this->responseService->context($contextElements);

        return $blocks;
    }

    /**
     * @return array{status_emoji?: string, status_text?: string, headline?: string, link_context?: array<int, string>, actions?: array<int, array<string, mixed>>}
     */
    private function resolveEngineeringOutcome(AgentRun $run): array
    {
        $output = is_array($run->output) ? $run->output : [];
        $context = $run->context['engineering'] ?? [];

        $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;
        $branch = $output['branch'] ?? $output['branch_name'] ?? null;
        $reviewUrl = $output['staging_url'] ?? $output['preview_url'] ?? null;
        $deploymentStatus = $output['deployment_status'] ?? null;
        $issueNumber = $context['issue_number'] ?? null;
        $repo = $context['repo'] ?? ($run->context['project']['github_repo'] ?? null);

        if (! $prUrl && isset($output['result']) && is_string($output['result'])) {
            if (preg_match('#https://github\.com/[^/]+/[^/]+/pull/\d+#', $output['result'], $matches)) {
                $prUrl = $matches[0];
            }
        }

        if (! $prUrl && ! $reviewUrl && $issueNumber === null) {
            return [];
        }

        $labels = [];
        if ($issueNumber && $repo) {
            $labels[] = "Issue #{$issueNumber}";
            $labels[] = $repo;
        }

        if ($branch) {
            $labels[] = "Branch: {$branch}";
        }

        if ($deploymentStatus) {
            $labels[] = 'Deploy: '.$deploymentStatus;
        }

        $actions = [];
        if ($prUrl) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open PR',
                ],
                'url' => $prUrl,
            ];
        }

        if ($reviewUrl) {
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

        if ($prUrl && $reviewUrl) {
            return [
                'status_emoji' => ':rocket:',
                'status_text' => 'Review Ready',
                'headline' => ':rocket: *PR and review build are ready.*',
                'link_context' => $labels,
                'actions' => $actions,
            ];
        }

        if ($prUrl) {
            return [
                'status_emoji' => ':white_check_mark:',
                'status_text' => 'PR Ready',
                'headline' => ':white_check_mark: *A pull request is ready for review.*',
                'link_context' => $labels,
                'actions' => $actions,
            ];
        }

        if ($reviewUrl) {
            return [
                'status_emoji' => ':rocket:',
                'status_text' => 'Review Build Ready',
                'headline' => ':rocket: *A review build is ready.*',
                'link_context' => $labels,
                'actions' => $actions,
            ];
        }

        return [];
    }
}
