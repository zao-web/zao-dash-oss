<?php

namespace App\Services\Slack;

use App\Enums\SlackActionType;
use App\Jobs\ProcessAgentTasksJob;
use App\Jobs\ProcessSlackMentionJob;
use App\Jobs\RunAgentJob;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Agents\CompoundEngineeringSkillLoader;
use App\Services\CapabilitySynthesisService;
use App\Services\TaskAgentService;
use Illuminate\Support\Facades\Log;

class SlackMentionOrchestrator
{
    public function __construct(
        private SlackApiService $api,
        private SlackBotResponseService $responseService,
        private CompoundEngineeringSkillLoader $skillLoader,
        private SlackIntentDetectionService $intentDetection,
        private CapabilitySynthesisService $capabilitySynthesis,
        private SlackEngineeringAgentService $engineeringAgentService,
        private SlackEngineeringApprovalService $engineeringApprovalService,
        private SlackAgentRunService $agentRunService,
        private TaskAgentService $taskAgentService,
        private SlackGitHubEngineeringActionService $gitHubEngineeringActionService,
        private SlackLinkedProjectService $linkedProjectService,
        private SlackThreadRunService $threadRunService,
        private SlackMcpToolBridge $mcpBridge,
        private SlackStagingWorkflowService $stagingWorkflowService,
        private SlackStagingThreadService $stagingThreadService,
        private SlackWatchlistService $watchlistService,
    ) {}

    public function handleMention(SlackWorkspace $workspace, SlackChannel $channel, array $event): void
    {
        $messageText = $event['text'] ?? '';
        $userId = $event['user'] ?? '';
        $messageTs = $event['ts'] ?? '';
        $threadTs = $event['thread_ts'] ?? $messageTs;

        $cleanedText = $this->removeBotMention($messageText, $workspace->bot_user_id);

        if (empty(trim($cleanedText))) {
            $this->sendHelpMessage($workspace, $channel->channel_id, $threadTs);

            return;
        }

        $this->queueConversation($workspace, $channel, $threadTs, $cleanedText, $userId, 'mention');
    }

    public function handleDirectMessage(SlackWorkspace $workspace, SlackChannel $channel, array $event): void
    {
        $messageText = trim((string) ($event['text'] ?? ''));
        $userId = (string) ($event['user'] ?? '');
        $messageTs = (string) ($event['ts'] ?? '');
        $threadTs = (string) ($event['thread_ts'] ?? $messageTs);

        Log::info('[SlackOrchestrator] DM received', [
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'slack_channel_id' => $channel->channel_id,
            'user_id' => $userId,
            'message_length' => strlen($messageText),
            'message_preview' => substr($messageText, 0, 200),
            'thread_ts' => $threadTs,
        ]);

        if ($messageText === '') {
            Log::info('[SlackOrchestrator] Empty DM, sending help message');
            $this->sendHelpMessage($workspace, $channel->channel_id, $threadTs);

            return;
        }

        $this->queueConversation($workspace, $channel, $threadTs, $messageText, $userId, 'direct_message');
    }

    public function isBotMentioned(string $text, ?string $botUserId): bool
    {
        if (! $botUserId) {
            return false;
        }

        return str_contains($text, "<@{$botUserId}>");
    }

    private function removeBotMention(string $text, ?string $botUserId): string
    {
        if (! $botUserId) {
            return $text;
        }

        return trim(preg_replace("/<@{$botUserId}>/", '', $text));
    }

    private function queueConversation(
        SlackWorkspace $workspace,
        SlackChannel $channel,
        string $threadTs,
        string $cleanedText,
        string $userId,
        string $invokedVia
    ): void {
        $context = SlackThreadContext::findOrCreateForThread($channel, $threadTs, $workspace->bot_user_id);
        $context->update([
            'context_data' => array_merge($context->context_data ?? [], [
                'slack_user_id' => $userId,
                'invoked_via' => $invokedVia,
            ]),
        ]);
        $context->addToHistory('user', $cleanedText);

        $this->sendAcknowledgement($workspace, $channel->channel_id, $threadTs);

        Log::info('[SlackOrchestrator] Dispatching ProcessSlackMentionJob', [
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'context_id' => $context->id,
            'user_id' => $userId,
            'invoked_via' => $invokedVia,
            'message_preview' => substr($cleanedText, 0, 200),
        ]);

        ProcessSlackMentionJob::dispatch($workspace->id, $channel->id, $context->id, $cleanedText, $userId);
    }

    private function sendAcknowledgement(SlackWorkspace $workspace, string $channelId, string $threadTs): void
    {
        try {
            $this->api->postMessage($workspace, $channelId, '', [
                'thread_ts' => $threadTs,
                'blocks' => [
                    $this->responseService->section(':hourglass_flowing_sand: Working on it...'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send acknowledgement', ['error' => $e->getMessage()]);
        }
    }

    private function sendHelpMessage(SlackWorkspace $workspace, string $channelId, string $threadTs): void
    {
        try {
            $this->api->postMessage($workspace, $channelId, '', [
                'thread_ts' => $threadTs,
                'blocks' => [
                    $this->responseService->header('Hi! I\'m Zao Bot'),
                    $this->responseService->section("I can help you with:\n\n*Create tasks* - \"Create a task to update the docs\"\n*Check status* - \"What's the project status?\"\n*Run agents* - \"Run the dev-agent on this issue\"\n*Log notes* - \"Log that client approved the design\"\n*Search* - \"Find all tasks about authentication\"\n*Watch channels* - \"Track acme-client, beta-launch, and ops\""),
                    $this->responseService->divider(),
                    $this->responseService->context(['Just mention me with your request!']),
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send help message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Gather message context for processing.
     */
    public function getMessageContext(SlackThreadContext $context, string $userMessage): array
    {
        $recentHistory = $context->getRecentHistory(5);

        $historyText = collect($recentHistory)
            ->map(fn ($h) => "{$h['role']}: {$h['content']}")
            ->join("\n");

        return [
            'message' => $userMessage,
            'history' => $historyText,
            'channel_context' => $this->getChannelContext($context->channel),
        ];
    }

    /**
     * Detect user intent from message using regex patterns.
     * Delegates to SlackIntentDetectionService.
     */
    public function detectIntent(string $message): ?array
    {
        return $this->intentDetection->detectIntent($message);
    }

    /**
     * Check if an action requires user confirmation before execution.
     * Delegates to SlackIntentDetectionService.
     */
    public function requiresConfirmation(array $action): bool
    {
        return $this->intentDetection->requiresConfirmation($action);
    }

    private function getChannelContext(SlackChannel $channel): array
    {
        $contextData = [
            'channel_name' => $channel->channel_name,
            'classification' => $channel->classification,
        ];

        if ($channel->client) {
            $contextData['client'] = [
                'id' => $channel->client->id,
                'name' => $channel->client->name,
            ];

            $activeProject = $channel->client->projects()->where('status', 'active')->first();
            if ($activeProject) {
                $contextData['project'] = [
                    'id' => $activeProject->id,
                    'name' => $activeProject->name,
                    'github_repo' => $activeProject->github_repo,
                ];
            }
        }

        return $contextData;
    }

    public function executeAction(SlackThreadContext $context, array $action): array
    {
        $type = $action['type'] ?? null;

        // Support both string and enum types for backward compatibility
        $actionType = $type instanceof SlackActionType
            ? $type
            : SlackActionType::tryFrom($type ?? '');

        $result = match ($actionType) {
            SlackActionType::CreateTask => $this->executeCreateTask($context, $action),
            SlackActionType::ManageTask => $this->executeManageTask($context, $action),
            SlackActionType::LogNote => $this->executeLogNote($context, $action),
            SlackActionType::TriggerAgent => $this->executeTriggerAgent($context, $action),
            SlackActionType::TriggerEngineeringAgent => $this->executeTriggerEngineeringAgent($context, $action),
            SlackActionType::ListAgentRuns => $this->executeListAgentRuns($context, $action),
            SlackActionType::ShowAgentRun => $this->executeShowAgentRun($context, $action),
            SlackActionType::RetryAgentRun => $this->executeRetryAgentRun($context),
            SlackActionType::CancelAgentRun => $this->executeCancelAgentRun($context, $action),
            SlackActionType::RequestReviewDeploy => $this->executeRequestReviewDeploy($context, $action),
            SlackActionType::RetryReviewDeploy => $this->executeRetryReviewDeploy($context, $action),
            SlackActionType::CompoundEngineering => $this->executeTriggerCompoundEngineering($context, $action),
            SlackActionType::Search => $this->executeSearch($context, $action),
            SlackActionType::GetStatus => $this->executeGetStatus($context, $action),
            SlackActionType::GetFocus => $this->executeGetFocus($action),
            SlackActionType::GetThreadSummary => $this->executeGetThreadSummary($context, $action),
            SlackActionType::GetChannelContext => $this->executeGetChannelContext($context),
            SlackActionType::GetIntegrations => $this->executeGetIntegrations($context),
            SlackActionType::GetStagingStatus => $this->executeGetStagingStatus($context),
            SlackActionType::PrepareStagingSecret => $this->executePrepareStagingSecret($context, $action),
            SlackActionType::SyncStagingSecrets => $this->executeSyncStagingSecrets($context),
            SlackActionType::PublishStaging => $this->executePublishStaging($context),
            SlackActionType::ManageWatchlist => $this->executeManageWatchlist($context, $action),
            SlackActionType::ListWatchlist => $this->executeListWatchlist($context),
            SlackActionType::LinkContext => $this->executeLinkContext($context, $action),
            SlackActionType::RunIntegrationSync => $this->executeRunIntegrationSync($context, $action),
            SlackActionType::ShowClient => $this->executeShowClient($action),
            SlackActionType::ListClients => $this->executeListClients($action),
            SlackActionType::CreateClient => $this->executeCreateClient($action),
            SlackActionType::UpdateClient => $this->executeUpdateClient($action),
            SlackActionType::ShowProject => $this->executeShowProject($action),
            SlackActionType::ListProjects => $this->executeListProjects($action),
            SlackActionType::CreateProject => $this->executeCreateProject($action),
            SlackActionType::UpdateProject => $this->executeUpdateProject($action),
            SlackActionType::ImportSow => $this->executeImportSow($context, $action),
            SlackActionType::ListLeads => $this->executeListLeads($action),
            SlackActionType::CreateLead => $this->executeCreateLead($action),
            SlackActionType::UpdateLeadStage => $this->executeUpdateLeadStage($action),
            SlackActionType::ListInvoices => $this->executeListInvoices($action),
            SlackActionType::CreateInvoice => $this->executeCreateInvoice($action),
            SlackActionType::ShowWebsiteProject => $this->executeShowWebsiteProject($action),
            SlackActionType::ListWebsiteProjects => $this->executeListWebsiteProjects($action),
            SlackActionType::CreateWebsiteProject => $this->executeCreateWebsiteProject($action),
            SlackActionType::UpdateWebsiteProject => $this->executeUpdateWebsiteProject($action),
            default => ['success' => false, 'error' => "Unknown action type: {$type}"],
        };

        if (($result['success'] ?? false) && $actionType !== SlackActionType::GetThreadSummary) {
            $this->rememberActionContext($context, $actionType, $action, $result);
        }

        return $result;
    }

    public function previewImportSow(SlackThreadContext $context, array $action): array
    {
        $previewAction = $action;
        $previewAction['type'] = SlackActionType::ImportSow->value;
        $previewAction['preview_only'] = true;

        return $this->executeImportSow($context, $previewAction);
    }

    private function executeCreateTask(SlackThreadContext $context, array $action): array
    {
        $title = $action['title'] ?? '';
        $description = $action['description'] ?? null;
        $priority = $action['priority'] ?? 'medium';

        $channel = $context->channel;
        $project = $channel->client?->projects()->where('status', 'active')->first();

        if (! $project) {
            return ['success' => false, 'error' => 'No active project linked to this channel'];
        }

        $task = \App\Models\Task::create([
            'title' => $title,
            'description' => $description,
            'project_id' => $project->id,
            'status' => 'pending',
            'priority' => $priority,
            'source' => 'manual',
            'position' => \App\Models\Task::where('project_id', $project->id)->max('position') + 1,
        ]);

        return [
            'success' => true,
            'task_id' => $task->id,
            'task' => $task->fresh(['project.client', 'latestAgentTask.agent']),
            'message' => "Created task #{$task->id}: {$title} in project {$project->name}",
        ];
    }

    private function executeManageTask(SlackThreadContext $context, array $action): array
    {
        $taskId = (int) ($action['task_id'] ?? 0);
        $operation = $action['operation'] ?? 'show';

        if (! $taskId) {
            return ['success' => false, 'error' => 'Task ID is required'];
        }

        $task = $this->resolveScopedTask($context, $taskId);

        if (! $task) {
            return ['success' => false, 'error' => "Task #{$taskId} was not found in this channel's linked project"];
        }

        return match ($operation) {
            'show' => [
                'success' => true,
                'task_id' => $task->id,
                'task' => $task,
                'message' => ":clipboard: *Task #{$task->id} loaded from Slack.*",
            ],
            'set_status' => $this->executeSetTaskStatus($task, (string) ($action['status'] ?? ''), $context),
            'set_priority' => $this->executeSetTaskPriority($task, (string) ($action['priority'] ?? ''), $context),
            'run_agent' => $this->executeRunTaskAgent($task, (string) ($action['agent_slug'] ?? 'dev-agent'), $context),
            default => ['success' => false, 'error' => 'Unsupported task action'],
        };
    }

    private function executeSetTaskStatus(Task $task, string $status, SlackThreadContext $context): array
    {
        if (! in_array($status, ['in_progress', 'review', 'completed'], true)) {
            return ['success' => false, 'error' => 'Unsupported task status'];
        }

        $actor = $this->resolveSlackActor();
        $oldStatus = $task->status;

        if ($oldStatus !== $status) {
            $task->update(['status' => $status]);
            TaskComment::logStatusChange($task, $actor?->id, $oldStatus, $status);
        }

        return [
            'success' => true,
            'task_id' => $task->id,
            'task' => $task->fresh(['project.client', 'latestAgentTask.agent']),
            'message' => $oldStatus === $status
                ? ":information_source: *Task #{$task->id} is already ".str_replace('_', ' ', $status).'.*'
                : ":white_check_mark: *Task #{$task->id} moved to ".str_replace('_', ' ', $status).'.*',
        ];
    }

    private function executeSetTaskPriority(Task $task, string $priority, SlackThreadContext $context): array
    {
        if (! in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            return ['success' => false, 'error' => 'Unsupported task priority'];
        }

        $task->update(['priority' => $priority]);

        return [
            'success' => true,
            'task_id' => $task->id,
            'task' => $task->fresh(['project.client', 'latestAgentTask.agent']),
            'message' => ":white_check_mark: *Task #{$task->id} priority set to {$priority}.*",
        ];
    }

    private function executeRunTaskAgent(Task $task, string $agentSlug, SlackThreadContext $context): array
    {
        if ($task->hasActiveAgentTask()) {
            return [
                'success' => true,
                'task_id' => $task->id,
                'task' => $task->fresh(['project.client', 'latestAgentTask.agent']),
                'message' => ":information_source: *Task #{$task->id} already has an active agent workflow.*",
            ];
        }

        $agent = Agent::query()
            ->where('slug', $agentSlug)
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return ['success' => false, 'error' => "Agent '{$agentSlug}' not found or not active"];
        }

        $actor = $this->resolveSlackActor();
        $oldAssigneeId = $task->assigned_to;
        $oldAssigneeType = $task->assignee_type;
        $oldStatus = $task->status;

        $task->update([
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
            'status' => 'in_progress',
        ]);

        if ($oldAssigneeId !== $agent->id || $oldAssigneeType !== 'agent') {
            TaskComment::logAssignment(
                $task,
                $actor?->id,
                $oldAssigneeId,
                $agent->id,
                $oldAssigneeType ?? 'user',
                'agent'
            );
        }

        if ($oldStatus !== 'in_progress') {
            TaskComment::logStatusChange($task, $actor?->id, $oldStatus, 'in_progress');
        }

        $agentTask = $this->taskAgentService->assignAgentToTask($task, $agent, $actor);
        $this->attachSlackContextToAgentTask($agentTask, $context);
        $this->queueTaskAgentProcessing();

        return [
            'success' => true,
            'task_id' => $task->id,
            'task' => $task->fresh(['project.client', 'latestAgentTask.agent']),
            'message' => ":robot_face: *{$agent->name} queued for Task #{$task->id}.*\nAgent task #{$agentTask->id} is ready to run.",
        ];
    }

    private function resolveScopedTask(SlackThreadContext $context, int $taskId): ?Task
    {
        $project = $this->resolveChannelProject($context->channel);

        if (! $project) {
            return null;
        }

        return Task::query()
            ->with(['project.client', 'latestAgentTask.agent'])
            ->whereKey($taskId)
            ->where('project_id', $project->id)
            ->first();
    }

    private function resolveChannelProject(SlackChannel $channel): ?Project
    {
        return $channel->project ?? $channel->client?->projects()->where('status', 'active')->first();
    }

    private function resolveSlackActor(): ?User
    {
        return User::query()->whereIn('role', ['owner', 'admin'])->first()
            ?? User::query()->first();
    }

    private function attachSlackContextToAgentTask(AgentTask $agentTask, SlackThreadContext $context): void
    {
        $slackContext = [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
            'thread_ts' => $context->thread_ts,
        ];

        $agentTask->update([
            'context' => array_merge($agentTask->context ?? [], [
                'slack' => $slackContext,
            ]),
        ]);
    }

    private function queueTaskAgentProcessing(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        ProcessAgentTasksJob::dispatchAfterResponse();
    }

    private function executeLogNote(SlackThreadContext $context, array $action): array
    {
        $content = $action['content'] ?? '';

        $channel = $context->channel;
        $client = $channel->client;

        if (! $client) {
            return ['success' => false, 'error' => 'No client linked to this channel'];
        }

        $user = \App\Models\User::first();
        if (! $user) {
            return ['success' => false, 'error' => 'No user available'];
        }

        $note = \App\Models\ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => $content,
        ]);

        return [
            'success' => true,
            'note_id' => $note->id,
            'message' => "Logged note for {$client->name}",
        ];
    }

    private function executeTriggerAgent(SlackThreadContext $context, array $action): array
    {
        $agentSlug = $action['agent_slug'] ?? '';
        $task = $action['task'] ?? null;

        $agent = \App\Models\Agent::where('slug', $agentSlug)
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return ['success' => false, 'error' => "Agent '{$agentSlug}' not found or not active"];
        }

        if ($agent->circuit_broken_at) {
            return ['success' => false, 'error' => "Agent '{$agent->name}' is currently unavailable"];
        }

        $channel = $context->channel;
        $workspace = $channel->workspace;
        $projectResolution = $this->linkedProjectService->resolve($channel);
        $project = $projectResolution['project'] ?? null;
        $slackUserId = (string) ($context->context_data['slack_user_id'] ?? 'slack_mention');

        $runContext = array_filter([
            'slack' => array_filter([
                'channel_id' => $channel->channel_id,
                'thread_ts' => $context->thread_ts,
                'workspace_id' => $workspace->workspace_id,
                'user_id' => $slackUserId,
            ]),
            'client' => $channel->client ? [
                'id' => $channel->client->id,
                'name' => $channel->client->name,
            ] : null,
        ]);

        if ($project) {
            $runContext['project'] = [
                'id' => $project->id,
                'name' => $project->name,
                'github_repo' => $project->github_repo,
            ];
        }

        $run = $agent->runs()->create([
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => $task ?? 'Triggered via @Zao mention',
            'context' => $runContext,
            'project_id' => $project?->id,
            'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
            'invoked_by' => $slackUserId,
            'started_at' => now(),
        ]);

        $context->update(['agent_run_id' => $run->id]);

        if ($this->shouldRunInteractively($agent, $runContext)) {
            RunInteractiveAgentJob::dispatch($run);
        } else {
            RunAgentJob::dispatch($run);
        }

        return [
            'success' => true,
            'run_id' => $run->id,
            'message' => "Triggered {$agent->name} (Run #{$run->id})",
        ];
    }

    /**
     * @param  array<string, mixed>  $runContext
     */
    private function shouldRunInteractively(Agent $agent, array $runContext = []): bool
    {
        return $agent->slug === 'compound-engineering'
            || isset($runContext['skill']);
    }

    private function executeTriggerEngineeringAgent(SlackThreadContext $context, array $action): array
    {
        $sourcePullRequest = $this->resolveSourcePullRequestContext($context, $action);

        $result = $this->engineeringAgentService->startIssueRun(
            workspace: $context->channel->workspace,
            channel: $context->channel,
            slackUserId: (string) ($context->context_data['slack_user_id'] ?? 'slack_mention'),
            threadTs: $context->thread_ts,
            issueNumber: (int) ($action['issue_number'] ?? 0),
            deliveryTarget: (string) ($action['delivery_target'] ?? 'pr'),
            branchPreference: $action['branch_preference'] ?? null,
            requestText: $action['task'] ?? null,
            sourcePrNumber: $sourcePullRequest['pr_number'] ?? null,
            sourcePrUrl: $sourcePullRequest['pr_url'] ?? null,
        );

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $run = $result['run'];
        $context->update(['agent_run_id' => $run->id]);

        return [
            'success' => true,
            'run_id' => $run->id,
            'thread_summary' => ($result['requires_approval'] ?? false)
                ? $this->executeGetThreadSummary($context->fresh())
                : null,
            'message' => $result['message'] ?? "Started Dev Agent on issue #{$action['issue_number']}.",
        ];
    }

    private function executeListAgentRuns(SlackThreadContext $context, array $action): array
    {
        $projectResolution = $this->linkedProjectService->resolve($context->channel);
        $project = $projectResolution['project'] ?? null;

        if (! $project) {
            return ['success' => false, 'error' => $projectResolution['error'] ?? 'No active project is linked to this Slack channel.'];
        }

        $filter = $this->agentRunService->normalizeFilter((string) ($action['filter'] ?? 'active'));
        $runs = $this->agentRunService->listProjectRuns($project, $filter);

        return [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'filter' => $filter,
            'runs' => $runs->all(),
            'message' => 'Loaded recent agent runs for the linked project.',
        ];
    }

    private function executeShowAgentRun(SlackThreadContext $context, array $action): array
    {
        $projectResolution = $this->linkedProjectService->resolve($context->channel);
        $project = $projectResolution['project'] ?? null;

        if (! $project) {
            return ['success' => false, 'error' => $projectResolution['error'] ?? 'No active project is linked to this Slack channel.'];
        }

        $runId = (int) ($action['run_id'] ?? 0);
        $run = $runId > 0 ? $this->agentRunService->findProjectRun($project, $runId) : null;

        if (! $run) {
            return ['success' => false, 'error' => 'Agent run not found for the linked project.'];
        }

        return [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'run' => $run,
            'message' => "Loaded Run #{$run->id}.",
        ];
    }

    private function executeGetFocus(array $action): array
    {
        $priorityFilter = strtolower((string) ($action['priority_filter'] ?? 'all'));
        $briefing = $this->capabilitySynthesis->getMorningBriefing();
        $items = $this->capabilitySynthesis->getHumanRequiredItems();

        if ($priorityFilter !== 'all') {
            $items = array_values(array_filter($items, fn (array $item): bool => $priorityFilter === 'critical'
                ? ($item['priority'] ?? 'medium') === 'critical'
                : in_array($item['priority'] ?? 'medium', ['critical', 'high'], true)
            ));
        }

        return [
            'success' => true,
            'message' => 'Loaded your focus briefing.',
            'priority_filter' => $priorityFilter,
            'briefing' => [
                'greeting' => $briefing['greeting'],
                'summary' => $briefing['summary'],
                'recommendations' => $briefing['recommendations'],
                'top_priorities' => array_slice($items, 0, 5),
            ],
        ];
    }

    private function executeRetryAgentRun(SlackThreadContext $context): array
    {
        $run = $this->resolveThreadRun($context);

        if (! $run || ! $run->agent) {
            return ['success' => false, 'error' => 'No agent run is linked to this Slack thread yet.'];
        }

        if ($run->agent->status !== 'active' || $run->agent->circuit_broken_at) {
            return ['success' => false, 'error' => "Agent {$run->agent->name} is not available."];
        }

        $newRun = $this->threadRunService->restartRun(
            $run,
            (string) ($context->context_data['slack_user_id'] ?? 'slack_mention'),
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
            $context,
        );

        $threadSummary = $this->executeGetThreadSummary($context->fresh());

        return [
            'success' => true,
            'run_id' => $newRun->id,
            'message' => "Started rerun as Run #{$newRun->id}.",
            'thread_summary' => ($threadSummary['success'] ?? false) ? $threadSummary : null,
        ];
    }

    private function executeCancelAgentRun(SlackThreadContext $context, array $action): array
    {
        $runId = (int) ($action['run_id'] ?? 0);
        $run = null;

        if ($runId > 0) {
            $projectResolution = $this->linkedProjectService->resolve($context->channel);
            $project = $projectResolution['project'] ?? null;

            if (! $project) {
                return ['success' => false, 'error' => $projectResolution['error'] ?? 'No active project is linked to this Slack channel.'];
            }

            $run = $this->agentRunService->findProjectRun($project, $runId);
        } else {
            $run = $this->resolveThreadRun($context, $action);
        }

        if (! $run) {
            return ['success' => false, 'error' => 'Agent run not found for this Slack context.'];
        }

        if (! $this->agentRunService->isCancellable($run)) {
            return ['success' => false, 'error' => "Run #{$run->id} cannot be cancelled from status `{$run->status}`."];
        }

        $slackUserId = (string) ($context->context_data['slack_user_id'] ?? 'slack_mention');
        $cancelledRun = $this->agentRunService->cancelRun($run, "Cancelled from Slack by {$slackUserId}");
        $threadSummary = $this->executeGetThreadSummary($context->fresh(), $action);

        return [
            'success' => true,
            'run_id' => $cancelledRun->id,
            'message' => "Cancelled Run #{$cancelledRun->id}.",
            'thread_summary' => ($threadSummary['success'] ?? false) ? $threadSummary : null,
        ];
    }

    private function executeRequestReviewDeploy(SlackThreadContext $context, array $action = []): array
    {
        return $this->executeGitHubDeployAction(
            $context,
            $action,
            fn (AgentRun $run): array => $this->engineeringApprovalService->requestReviewDeploy($run)
        );
    }

    private function executeRetryReviewDeploy(SlackThreadContext $context, array $action = []): array
    {
        return $this->executeGitHubDeployAction(
            $context,
            $action,
            fn (AgentRun $run): array => $this->engineeringApprovalService->retryReviewDeploy($run)
        );
    }

    /**
     * @param  callable(AgentRun): array{success: bool, error?: string, message?: string}  $callback
     */
    private function executeGitHubDeployAction(SlackThreadContext $context, array $action, callable $callback): array
    {
        $run = $this->resolveThreadRun($context, $action);

        if (! $run) {
            $prNumber = (int) ($action['pr_number'] ?? 0);

            return ['success' => false, 'error' => $prNumber > 0
                ? "No engineering run for PR #{$prNumber} is linked to this Slack context yet."
                : 'No engineering run is linked to this Slack thread yet.'];
        }

        $result = $callback($run);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $threadSummary = $this->executeGetThreadSummary($context->fresh(), $action);

        return array_merge($result, [
            'run_id' => $run->id,
            'thread_summary' => ($threadSummary['success'] ?? false) ? $threadSummary : null,
        ]);
    }

    /**
     * Execute a Compound Engineering skill invocation.
     *
     * This triggers an interactive agent run that can pause for user input
     * via the AskUserQuestion tool.
     */
    private function executeTriggerCompoundEngineering(SlackThreadContext $context, array $action): array
    {
        $skill = $action['skill'] ?? null;
        $args = $action['args'] ?? null;

        if (! $skill) {
            return ['success' => false, 'error' => 'No skill specified'];
        }

        if (! $this->skillLoader->skillExists($skill)) {
            $available = implode(', ', array_map(fn ($s) => "/{$s}", $this->skillLoader->availableSkills()));

            return [
                'success' => false,
                'error' => "Skill '/{$skill}' not found or not available.\n\nAvailable skills: {$available}",
            ];
        }

        // Get the Compound Engineering agent
        $agent = Agent::where('slug', 'compound-engineering')
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return ['success' => false, 'error' => 'Compound Engineering agent not found or not active'];
        }

        $channel = $context->channel;
        $workspace = $channel->workspace;
        $project = $channel->client?->projects()->where('status', 'active')->first();

        // Build context for the skill
        $runContext = [
            'skill' => $skill,
            'args' => $args,
            'prompt' => "/{$skill}".($args ? " {$args}" : ''),
            'slack' => [
                'channel_id' => $channel->channel_id,
                'thread_ts' => $context->thread_ts,
                'workspace_id' => $workspace->workspace_id,
            ],
        ];

        if ($project) {
            $runContext['project'] = [
                'id' => $project->id,
                'name' => $project->name,
                'github_repo' => $project->github_repo,
            ];
        }

        // Create the agent run
        $run = $agent->runs()->create([
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => "/{$skill}".($args ? " {$args}" : ''),
            'context' => $runContext,
            'project_id' => $project?->id,
            'invocation_source' => AgentRun::SOURCE_SLACK,
            'invoked_by' => 'slack_mention',
            'started_at' => now(),
        ]);

        $context->update(['agent_run_id' => $run->id]);

        // Dispatch the interactive agent job instead of the regular one
        RunInteractiveAgentJob::dispatch($run);

        $skillName = str_replace(':', ': ', $skill);

        return [
            'success' => true,
            'run_id' => $run->id,
            'message' => "Started /{$skillName}".($args ? " with: {$args}" : '')." (Run #{$run->id})\n\n_This is an interactive session - I may ask you questions as I work._",
        ];
    }

    private function executeSearch(SlackThreadContext $context, array $action): array
    {
        $query = $action['query'] ?? '';
        $type = $action['search_type'] ?? 'all';

        $results = [];

        if (in_array($type, ['all', 'tasks'])) {
            $tasks = \App\Models\Task::where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'title', 'status', 'priority']);

            $results['tasks'] = $tasks->toArray();
        }

        if (in_array($type, ['all', 'projects'])) {
            $projects = \App\Models\Project::where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'status']);

            $results['projects'] = $projects->toArray();
        }

        if (in_array($type, ['all', 'clients'])) {
            $clients = \App\Models\Client::where('name', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'status']);

            $results['clients'] = $clients->toArray();
        }

        $totalCount = collect($results)->flatten(1)->count();

        return [
            'success' => true,
            'results' => $results,
            'message' => "Found {$totalCount} results for '{$query}'",
        ];
    }

    private function executeGetStatus(SlackThreadContext $context, array $action): array
    {
        $channel = $context->channel;
        $client = $channel->client;

        $status = [
            'channel' => $channel->channel_name,
            'monitoring' => $channel->monitoring_enabled,
        ];

        if ($client) {
            $status['client'] = [
                'name' => $client->name,
                'health_score' => $client->health_score,
            ];

            $activeProjects = $client->projects()
                ->where('status', 'active')
                ->withCount([
                    'tasks as pending_tasks' => fn ($q) => $q->where('status', 'pending'),
                    'tasks as in_progress_tasks' => fn ($q) => $q->where('status', 'in_progress'),
                ])
                ->get();
            $status['projects'] = $activeProjects->map(fn ($p) => [
                'name' => $p->name,
                'pending_tasks' => $p->pending_tasks,
                'in_progress_tasks' => $p->in_progress_tasks,
            ])->toArray();
        }

        return [
            'success' => true,
            'status' => $status,
            'message' => 'Status retrieved successfully',
        ];
    }

    private function executeGetStagingStatus(SlackThreadContext $context): array
    {
        $result = $this->stagingWorkflowService->describeChannelStaging(
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
        );

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, [
            'success' => true,
            'message' => 'Loaded the staging workflow status for this project.',
        ]);
    }

    private function executePrepareStagingSecret(SlackThreadContext $context, array $action): array
    {
        $result = $this->stagingWorkflowService->describeChannelStaging(
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
        );

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        $prefillSecretName = strtoupper((string) ($action['secret_name'] ?? ''));

        return array_merge($result, [
            'success' => true,
            'prefill_secret_name' => $prefillSecretName,
            'message' => $prefillSecretName !== ''
                ? "Ready to securely add `{$prefillSecretName}`. Click *Add Secret* below to open the private credential modal."
                : 'Ready to securely add a staging secret. Click *Add Secret* below to open the private credential modal.',
        ]);
    }

    private function executeSyncStagingSecrets(SlackThreadContext $context): array
    {
        $result = $this->stagingWorkflowService->syncRequiredSecrets(
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
        );

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executePublishStaging(SlackThreadContext $context): array
    {
        $result = $this->engineeringApprovalService->publishToStaging(
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
            $context,
        );

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        if (! ($result['approval_required'] ?? false)) {
            $this->stagingThreadService->rememberPublishedThread($context, $result);
        }

        return array_merge($result, [
            'success' => true,
            'thread_summary' => ($result['approval_required'] ?? false)
                ? $this->executeGetThreadSummary($context->fresh())
                : null,
        ]);
    }

    private function executeGetThreadSummary(SlackThreadContext $context, array $action = []): array
    {
        $operationsContext = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
        ]);

        if (isset($operationsContext['error'])) {
            $operationsContext = [];
        }

        $run = $this->resolveThreadRun($context, $action);

        $taskId = (int) ($context->context_data['task_id'] ?? $run?->task_id ?? $run?->context['task_id'] ?? 0);
        $task = $taskId > 0
            ? Task::query()->with(['project.client', 'latestAgentTask.agent'])->find($taskId)
            : null;

        $invoiceId = (int) ($context->context_data['invoice_id'] ?? 0);
        $invoice = $invoiceId > 0
            ? Invoice::query()->with('client')->find($invoiceId)
            : null;

        $websiteProject = null;
        $websiteProjectId = (int) ($context->context_data['website_project_id'] ?? 0);
        if ($websiteProjectId > 0) {
            $websiteProjectResult = $this->mcpBridge->execute('get-website-project', [
                'id' => $websiteProjectId,
            ]);

            if (! isset($websiteProjectResult['error'])) {
                $websiteProject = $websiteProjectResult;
            }
        }

        $approvals = ApprovalRequest::query()
            ->where('status', 'pending')
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at')
            ->get()
            ->filter(function (ApprovalRequest $approval) use ($context, $run): bool {
                return ($run && $approval->agent_run_id === $run->id)
                    || data_get($approval->payload, 'slack.thread_context_id') === $context->id;
            })
            ->take(5)
            ->map(fn (ApprovalRequest $approval) => [
                'id' => $approval->id,
                'description' => $approval->description,
                'risk_level' => $approval->risk_level,
                'action_type' => $approval->action_type,
                'expires_at' => $approval->expires_at?->toIso8601String(),
            ])
            ->values();

        $pendingInteraction = $run?->pendingInteraction;
        $staging = $this->stagingWorkflowService->describeChannelStaging(
            $context->channel->workspace->workspace_id,
            $context->channel->channel_id,
        );

        if (isset($staging['error'])) {
            $staging = null;
        } elseif (is_array($staging)) {
            $staging['workflow_run'] = $context->context_data['staging_workflow'] ?? null;
        }

        return [
            'success' => true,
            'message' => 'Loaded the current Slack thread summary.',
            'focus' => $action['focus'] ?? null,
            'requested_pr_number' => $action['pr_number'] ?? null,
            'thread' => [
                'context_id' => $context->id,
                'thread_ts' => $context->thread_ts,
                'current_state' => $context->current_state,
                'last_interaction_at' => $context->last_interaction_at?->toIso8601String(),
                'history_count' => count($context->conversation_history ?? []),
                'pending_actions_count' => count($context->pending_actions ?? []),
                'completed_actions_count' => count($context->completed_actions ?? []),
                'pending_action_types' => collect($context->pending_actions ?? [])
                    ->pluck('type')
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'channel_context' => $operationsContext,
            'staging' => $staging,
            'task' => $task ? [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'project_name' => $task->project?->name,
                'client_name' => $task->project?->client?->name,
            ] : null,
            'run' => $run ? $this->formatRunSummary($run) : null,
            'approvals' => $approvals->values()->all(),
            'pending_interaction' => $pendingInteraction ? [
                'id' => $pendingInteraction->id,
                'question_type' => $pendingInteraction->question_type,
                'question_content' => $pendingInteraction->question_content,
                'options' => $pendingInteraction->options ?? [],
                'expires_at' => $pendingInteraction->expires_at?->toIso8601String(),
            ] : null,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'total' => (float) $invoice->total,
                'amount_due' => (float) $invoice->amount_due,
                'due_date' => $invoice->due_date?->toDateString(),
                'client_name' => $invoice->client?->name,
            ] : null,
            'website_project' => $websiteProject,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSourcePullRequestContext(SlackThreadContext $context, array $action): array
    {
        $sourcePrNumber = (int) ($action['source_pr_number'] ?? 0);
        $useThreadPr = (bool) ($action['use_thread_pr'] ?? false);

        if (! $sourcePrNumber && ! $useThreadPr) {
            return [];
        }

        $sourceRun = $this->resolveThreadRun($context, $sourcePrNumber > 0 ? ['pr_number' => $sourcePrNumber] : []);

        if (! $sourceRun) {
            return $sourcePrNumber > 0 ? ['pr_number' => $sourcePrNumber] : [];
        }

        return array_filter([
            'pr_number' => $sourceRun->output['pr_number'] ?? ($sourcePrNumber > 0 ? $sourcePrNumber : null),
            'pr_url' => $sourceRun->output['pr_url'] ?? $sourceRun->output['pull_request_url'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== '');
    }

    private function resolveThreadRun(SlackThreadContext $context, array $action = []): ?AgentRun
    {
        $requestedPrNumber = (int) ($action['pr_number'] ?? 0);
        $runId = (int) ($context->agent_run_id ?? $context->context_data['agent_run_id'] ?? 0);
        $threadRun = null;

        if ($runId <= 0) {
            $threadRun = null;
        } else {
            $threadRun = AgentRun::query()
                ->with(['agent', 'task.project.client', 'project.client', 'approvalRequest', 'pendingInteraction'])
                ->find($runId);
        }

        if ($threadRun && ($requestedPrNumber <= 0 || (int) (($threadRun->output['pr_number'] ?? 0)) === $requestedPrNumber)) {
            return $threadRun;
        }

        $projectId = $context->channel->project_id
            ?? $context->context_data['project_id']
            ?? $context->channel->client?->projects()->where('status', 'active')->value('id');
        $repo = $context->context_data['project_repo']
            ?? $context->channel->project?->github_repo
            ?? $context->channel->client?->projects()->where('status', 'active')->value('github_repo');

        $candidates = AgentRun::query()
            ->with(['agent', 'task.project.client', 'project.client', 'approvalRequest', 'pendingInteraction'])
            ->where('invocation_source', AgentRun::SOURCE_SLACK)
            ->where(function ($query) use ($context, $projectId, $repo): void {
                $query->where('context->slack->channel_id', $context->channel->channel_id);

                if ($projectId) {
                    $query->orWhere('project_id', $projectId);
                }

                if ($repo) {
                    $query->orWhere('context->engineering->repo', $repo)
                        ->orWhere('context->project->github_repo', $repo);
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        if ($requestedPrNumber > 0) {
            return $candidates->first(fn (AgentRun $run): bool => (int) (($run->output['pr_number'] ?? 0)) === $requestedPrNumber);
        }

        return $threadRun ?? $candidates->first();
    }

    private function executeGetChannelContext(SlackThreadContext $context): array
    {
        $result = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, [
            'success' => true,
            'message' => 'Loaded the current channel operations context.',
        ]);
    }

    private function executeGetIntegrations(SlackThreadContext $context): array
    {
        $contextResult = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
        ]);

        if (isset($contextResult['error'])) {
            return ['success' => false, 'error' => $contextResult['error']];
        }

        $statusResult = $this->mcpBridge->execute('get-integration-status', []);

        if (isset($statusResult['error'])) {
            return ['success' => false, 'error' => $statusResult['error']];
        }

        return [
            'success' => true,
            'context' => $contextResult,
            'status' => $statusResult,
            'message' => 'Loaded integrations for this Slack channel.',
        ];
    }

    private function executeListWatchlist(SlackThreadContext $context): array
    {
        $result = $this->mcpBridge->execute('list-slack-watchlist', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'slack_user_id' => (string) ($context->context_data['slack_user_id'] ?? $context->context_data['user_id'] ?? ''),
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return [
            'success' => true,
            'message' => $result['message'] ?? 'Loaded Slack watchlist.',
            'items' => $result['items'] ?? [],
        ];
    }

    private function executeManageWatchlist(SlackThreadContext $context, array $action): array
    {
        $slackUserId = (string) ($context->context_data['slack_user_id'] ?? $context->context_data['user_id'] ?? '');

        if ($slackUserId === '') {
            return ['success' => false, 'error' => 'Unable to determine which Slack user this watchlist belongs to.'];
        }

        $operation = strtolower((string) ($action['operation'] ?? 'track'));
        $query = trim((string) ($action['query'] ?? ''));

        if ($operation === 'clear') {
            $result = $this->watchlistService->clear($context->channel->workspace, $slackUserId);

            return [
                'success' => true,
                'operation' => 'clear',
                'message' => $result['message'],
                'items' => $result['items'],
            ];
        }

        if ($query === '') {
            return ['success' => false, 'error' => 'Provide a channel, client, or project name to track or untrack.'];
        }

        $result = $operation === 'untrack'
            ? $this->watchlistService->untrackByQuery($context->channel->workspace, $slackUserId, $query)
            : $this->watchlistService->trackByQuery($context->channel->workspace, $slackUserId, $query);

        return [
            'success' => $result['success'] ?? false,
            'operation' => $operation,
            'message' => $result['message'] ?? 'Updated Slack watchlist.',
            'items' => $result['items'] ?? [],
            'unresolved' => $result['unresolved'] ?? [],
            'ambiguous' => $result['ambiguous'] ?? [],
        ];
    }

    private function executeLinkContext(SlackThreadContext $context, array $action): array
    {
        $result = $this->mcpBridge->execute('link-slack-context', array_filter([
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
            'client_id' => $action['client_id'] ?? null,
            'project_id' => $action['project_id'] ?? null,
            'monitoring_enabled' => true,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        $contextResult = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
        ]);

        if (isset($contextResult['error'])) {
            return [
                'success' => true,
                'message' => $result['message'] ?? 'Linked Slack channel context.',
            ];
        }

        return [
            'success' => true,
            'message' => $result['message'] ?? 'Linked Slack channel context.',
            'context' => $contextResult,
        ];
    }

    private function executeRunIntegrationSync(SlackThreadContext $context, array $action): array
    {
        $target = strtolower((string) ($action['target'] ?? 'all'));

        $toolAction = match ($target) {
            'github' => 'sync-github',
            'clickup' => 'sync-clickup',
            'harvest' => 'sync-harvest',
            'wordpress' => 'sync-wordpress',
            'all' => 'sync-all',
            default => null,
        };

        if (! $toolAction) {
            return ['success' => false, 'error' => 'Unsupported integration sync target'];
        }

        $result = $this->mcpBridge->execute('run-channel-integration-action', [
            'workspace_id' => $context->channel->workspace->workspace_id,
            'channel_id' => $context->channel->channel_id,
            'action' => $toolAction,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => ($result['success'] ?? false)]);
    }

    private function executeCreateClient(array $action): array
    {
        $result = $this->mcpBridge->execute('create-client', array_filter([
            'name' => $action['name'] ?? null,
            'website' => $action['website'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeShowClient(array $action): array
    {
        $result = $this->mcpBridge->execute('get-client', [
            'id' => $action['id'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeListClients(array $action): array
    {
        $result = $this->mcpBridge->execute('list-clients', array_filter([
            'status' => $action['status'] ?? null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeUpdateClient(array $action): array
    {
        $result = $this->mcpBridge->execute('update-client', array_filter([
            'id' => $action['id'] ?? null,
            'name' => $action['name'] ?? null,
            'website' => $action['website'] ?? null,
            'status' => $action['status'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeShowProject(array $action): array
    {
        $result = $this->mcpBridge->execute('get-project', [
            'id' => $action['id'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeListProjects(array $action): array
    {
        $result = $this->mcpBridge->execute('list-projects', array_filter([
            'client_id' => $action['client_id'] ?? null,
            'status' => $action['status'] ?? null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeCreateProject(array $action): array
    {
        $result = $this->mcpBridge->execute('create-project', array_filter([
            'name' => $action['name'] ?? null,
            'client_id' => $action['client_id'] ?? null,
            'github_repo' => $action['github_repo'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeImportSow(SlackThreadContext $context, array $action): array
    {
        Log::info('[SlackOrchestrator] executeImportSow called', [
            'context_id' => $context->id,
            'preview_only' => $action['preview_only'] ?? false,
            'has_urls' => ! empty($action['google_doc_urls']),
            'has_content' => ! empty($action['content']),
            'link_to_channel' => $action['link_to_channel'] ?? false,
        ]);

        $workspace = $context->channel->workspace;
        $channel = $context->channel;

        $result = $this->mcpBridge->execute('import-sow', array_filter([
            'google_doc_urls' => $action['google_doc_urls'] ?? null,
            'content' => $action['content'] ?? null,
            'additional_notes' => $action['additional_notes'] ?? null,
            'create_invoices' => $action['create_invoices'] ?? true,
            'link_to_channel' => $action['link_to_channel'] ?? false,
            'workspace_id' => $workspace->workspace_id,
            'channel_id' => $channel->channel_id,
            'preview_only' => $action['preview_only'] ?? false,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        Log::info('[SlackOrchestrator] executeImportSow MCP bridge result', [
            'context_id' => $context->id,
            'has_error' => isset($result['error']),
            'error' => $result['error'] ?? null,
            'preview_only' => $result['preview_only'] ?? false,
            'has_parsed' => isset($result['parsed']),
            'result_keys' => array_keys($result),
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        if (($result['preview_only'] ?? false) === true) {
            return array_merge($result, ['success' => true]);
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeUpdateProject(array $action): array
    {
        $result = $this->mcpBridge->execute('update-project', array_filter([
            'id' => $action['id'] ?? null,
            'name' => $action['name'] ?? null,
            'github_repo' => $action['github_repo'] ?? null,
            'status' => $action['status'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeListLeads(array $action): array
    {
        $result = $this->mcpBridge->execute('list-leads', array_filter([
            'stage' => $action['stage'] ?? null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeCreateLead(array $action): array
    {
        $result = $this->mcpBridge->execute('create-lead', array_filter([
            'company_name' => $action['company_name'] ?? null,
            'contact_name' => $action['contact_name'] ?? null,
            'website' => $action['website'] ?? null,
            'contact_email' => $action['contact_email'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeUpdateLeadStage(array $action): array
    {
        $result = $this->mcpBridge->execute('update-lead-stage', [
            'id' => $action['id'] ?? null,
            'stage' => $action['stage'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeListInvoices(array $action): array
    {
        $result = $this->mcpBridge->execute('list-invoices', array_filter([
            'client_id' => $action['client_id'] ?? null,
            'status' => $action['status'] ?? null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeCreateInvoice(array $action): array
    {
        $result = $this->mcpBridge->execute('create-invoice', array_filter([
            'client_id' => $action['client_id'] ?? null,
            'subject' => $action['subject'] ?? null,
            'items' => $action['items'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeShowWebsiteProject(array $action): array
    {
        $result = $this->mcpBridge->execute('get-website-project', [
            'id' => $action['id'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeListWebsiteProjects(array $action): array
    {
        $result = $this->mcpBridge->execute('list-website-projects', array_filter([
            'status' => $action['status'] ?? null,
            'project_type' => $action['project_type'] ?? null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeCreateWebsiteProject(array $action): array
    {
        $result = $this->mcpBridge->execute('create-website-project', array_filter([
            'name' => $action['name'] ?? null,
            'project_type' => $action['project_type'] ?? null,
            'domain' => $action['domain'] ?? null,
            'brief' => $action['brief'] ?? null,
            'source_type' => $action['source_type'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function executeUpdateWebsiteProject(array $action): array
    {
        $result = $this->mcpBridge->execute('update-website-project', array_filter([
            'id' => $action['id'] ?? null,
            'status' => $action['status'] ?? null,
            'domain' => $action['domain'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return array_merge($result, ['success' => true]);
    }

    private function rememberActionContext(
        SlackThreadContext $context,
        ?SlackActionType $actionType,
        array $action,
        array $result
    ): void {
        if (! $actionType) {
            return;
        }

        $contextData = $context->context_data ?? [];
        $updates = match ($actionType) {
            SlackActionType::CreateTask, SlackActionType::ManageTask => $this->taskContextData(
                $result['task'] ?? null,
                (int) ($result['task_id'] ?? $action['task_id'] ?? 0)
            ),
            SlackActionType::TriggerAgent, SlackActionType::CompoundEngineering => array_filter([
                'agent_run_id' => $result['run_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::TriggerEngineeringAgent => array_filter([
                'agent_run_id' => $result['run_id'] ?? null,
                'issue_number' => $action['issue_number'] ?? null,
                'delivery_target' => $action['delivery_target'] ?? null,
                'source_pr_number' => $action['source_pr_number'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::ShowAgentRun => array_filter([
                'agent_run_id' => $result['run']?->id ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::CancelAgentRun => array_filter([
                'agent_run_id' => $result['run_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::RetryAgentRun => array_filter([
                'agent_run_id' => $result['run_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::RequestReviewDeploy, SlackActionType::RetryReviewDeploy => array_filter([
                'agent_run_id' => $result['run_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::GetStagingStatus, SlackActionType::PrepareStagingSecret, SlackActionType::SyncStagingSecrets, SlackActionType::PublishStaging => array_filter([
                'project_id' => $result['project']['id'] ?? null,
                'project_name' => $result['project']['name'] ?? null,
                'client_id' => $result['client']['id'] ?? null,
                'client_name' => $result['client']['name'] ?? null,
                'project_repo' => $result['repo']['full_name'] ?? null,
                'staging_secret_name' => $result['prefill_secret_name'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::GetChannelContext, SlackActionType::LinkContext => $this->channelContextDataFromResult($result),
            SlackActionType::ShowClient, SlackActionType::CreateClient, SlackActionType::UpdateClient => array_filter([
                'client_id' => $result['id'] ?? $action['id'] ?? null,
                'client_name' => $result['name'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::ShowProject, SlackActionType::CreateProject, SlackActionType::UpdateProject => array_filter([
                'project_id' => $result['id'] ?? $action['id'] ?? null,
                'project_name' => $result['name'] ?? null,
                'project_repo' => $result['github_repo'] ?? $action['github_repo'] ?? null,
                'client_id' => $result['client']['id'] ?? $action['client_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::ImportSow => array_filter([
                'client_id' => $result['provisioned']['client_id'] ?? $action['client_id'] ?? null,
                'project_id' => $result['provisioned']['project_id'] ?? $action['project_id'] ?? null,
                'client_name' => $result['parsed']['client_name'] ?? null,
                'project_name' => $result['parsed']['project_name'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::CreateInvoice => array_filter([
                'invoice_id' => $result['id'] ?? null,
                'invoice_number' => $result['number'] ?? null,
                'client_id' => $action['client_id'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            SlackActionType::ShowWebsiteProject, SlackActionType::CreateWebsiteProject, SlackActionType::UpdateWebsiteProject => array_filter([
                'website_project_id' => $result['id'] ?? $result['project_id'] ?? $action['id'] ?? null,
                'website_project_name' => $result['name'] ?? $result['project_name'] ?? $action['name'] ?? null,
                'website_project_status' => $result['current_status'] ?? $result['status'] ?? $action['status'] ?? null,
                'website_project_domain' => $result['domain'] ?? $action['domain'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''),
            default => [],
        };

        if ($updates === []) {
            return;
        }

        $context->update([
            'context_data' => array_merge($contextData, $updates),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskContextData(mixed $task, int $fallbackTaskId = 0): array
    {
        if ($task instanceof Task) {
            return array_filter([
                'task_id' => $task->id,
                'task_title' => $task->title,
                'task_status' => $task->status,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'client_id' => $task->project?->client?->id,
                'client_name' => $task->project?->client?->name,
            ], fn ($value) => ! is_null($value) && $value !== '');
        }

        if (is_array($task)) {
            $taskId = $task['id'] ?? null;
            if (! $taskId && $fallbackTaskId > 0) {
                $taskId = $fallbackTaskId;
            }

            return array_filter([
                'task_id' => $taskId,
                'task_title' => $task['title'] ?? null,
                'task_status' => $task['status'] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== '');
        }

        if ($fallbackTaskId > 0) {
            return ['task_id' => $fallbackTaskId];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function channelContextDataFromResult(array $result): array
    {
        $contextResult = $result['context'] ?? $result;
        $client = $contextResult['client'] ?? [];
        $project = $contextResult['project'] ?? [];

        return array_filter([
            'client_id' => $client['id'] ?? null,
            'client_name' => $client['name'] ?? null,
            'project_id' => $project['id'] ?? null,
            'project_name' => $project['name'] ?? null,
            'project_repo' => $project['github_repo'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRunSummary(AgentRun $run): array
    {
        $output = is_array($run->output) ? $run->output : [];
        $engineeringContext = $run->context['engineering'] ?? [];

        return array_filter([
            'id' => $run->id,
            'agent_id' => $run->agent_id,
            'status' => $run->status,
            'agent_name' => $run->agent?->name,
            'task' => $run->task,
            'task_id' => $run->task_id,
            'issue_number' => $engineeringContext['issue_number'] ?? null,
            'repo' => $engineeringContext['repo'] ?? ($run->context['project']['github_repo'] ?? null),
            'branch' => $output['branch'] ?? $output['branch_name'] ?? null,
            'pr_url' => $output['pr_url'] ?? $output['pull_request_url'] ?? null,
            'pr_number' => $output['pr_number'] ?? null,
            'review_url' => $output['staging_url'] ?? $output['preview_url'] ?? null,
            'deployment_status' => $output['deployment_status'] ?? null,
            'workflow_run_id' => $output['workflow_run_id'] ?? null,
            'workflow_status' => $output['workflow_status'] ?? null,
            'workflow_conclusion' => $output['workflow_conclusion'] ?? null,
            'workflow_failure_summary' => $output['workflow_failure_summary'] ?? null,
            'workflow_name' => $output['workflow_name'] ?? null,
            'workflow_url' => $output['workflow_url'] ?? null,
            'awaiting_input' => $run->isAwaitingInput(),
            'needs_approval' => $run->status === AgentRun::STATUS_PENDING_APPROVAL,
        ], fn ($value) => ! is_null($value) && $value !== '');
    }

    public function sendResponse(SlackThreadContext $context, string $response): void
    {
        $channel = $context->channel;
        $workspace = $channel->workspace;

        try {
            $this->api->postMessage($workspace, $channel->channel_id, $response, [
                'thread_ts' => $context->thread_ts,
            ]);

            $context->addToHistory('assistant', $response);
        } catch (\Exception $e) {
            Log::error('Failed to send Slack response', [
                'context_id' => $context->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendBlockResponse(SlackThreadContext $context, array $blocks): void
    {
        $channel = $context->channel;
        $workspace = $channel->workspace;

        try {
            $this->api->postMessage($workspace, $channel->channel_id, '', [
                'thread_ts' => $context->thread_ts,
                'blocks' => $blocks,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send Slack block response', [
                'context_id' => $context->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
