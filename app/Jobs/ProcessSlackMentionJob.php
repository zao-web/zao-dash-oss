<?php

namespace App\Jobs;

use App\Enums\SlackActionType;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackBotResponseService;
use App\Services\Slack\SlackControlPlaneService;
use App\Services\Slack\SlackMentionOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSlackMentionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 300;

    public function __construct(
        public int $workspaceId,
        public int $channelId,
        public int $contextId,
        public string $userMessage,
        public string $userId,
    ) {
        $this->onQueue('slack-mentions');
    }

    public function handle(
        SlackMentionOrchestrator $orchestrator,
        SlackBotResponseService $responseService,
        ?SlackControlPlaneService $controlPlane = null
    ): void {
        $controlPlane ??= app(SlackControlPlaneService::class);

        $workspace = SlackWorkspace::find($this->workspaceId);
        $channel = SlackChannel::find($this->channelId);
        $context = SlackThreadContext::find($this->contextId);

        if (! $workspace || ! $channel || ! $context) {
            Log::warning('ProcessSlackMentionJob: Missing required models', [
                'workspace_id' => $this->workspaceId,
                'channel_id' => $this->channelId,
                'context_id' => $this->contextId,
            ]);

            return;
        }

        $context->update(['current_state' => 'processing']);

        Log::info('[SlackMention] Job started', [
            'context_id' => $context->id,
            'workspace_id' => $this->workspaceId,
            'channel_id' => $this->channelId,
            'user_id' => $this->userId,
            'message' => $this->userMessage,
        ]);

        try {
            $action = $orchestrator->detectIntent($this->userMessage);

            Log::info('[SlackMention] Intent detection result', [
                'context_id' => $context->id,
                'detected_action' => $action ? ($action['type'] ?? 'unknown') : 'none',
                'action_data' => $action,
            ]);

            if (! $action) {
                Log::info('[SlackMention] No intent detected, falling back to control plane', [
                    'context_id' => $context->id,
                ]);

                $orchestrator->sendResponse($context, ':brain: Thinking...');

                $controlPlaneResult = $controlPlane->handle($context, $this->userMessage);

                Log::info('[SlackMention] Control plane result', [
                    'context_id' => $context->id,
                    'success' => $controlPlaneResult['success'] ?? false,
                ]);

                if ($controlPlaneResult['success'] ?? false) {
                    $orchestrator->sendResponse($context, $controlPlaneResult['message'] ?? 'Done.');
                } else {
                    $error = $controlPlaneResult['error'] ?? '';
                    $fallback = match (true) {
                        str_contains($error, 'not configured') => ':warning: The AI service is not configured. Please check the ANTHROPIC_API_KEY environment variable.',
                        str_contains($error, 'timed out') || str_contains($error, 'timeout') => ':warning: That request took too long to process. Try breaking it into smaller steps.',
                        $error !== '' => ":warning: I ran into an issue: {$error}",
                        default => "I wasn't able to complete that request. Try being more specific — for example:\n• \"Create a task to fix the login page\"\n• \"Show me the invoices for client 15\"\n• \"What should I work on today?\"\n• \"Add a contact for Mt. Washington\"",
                    };
                    $orchestrator->sendResponse($context, $fallback);
                }

                $context->update(['current_state' => 'idle']);

                return;
            }

            $requiresConfirmation = $orchestrator->requiresConfirmation($action);
            Log::info('[SlackMention] Confirmation check', [
                'context_id' => $context->id,
                'action_type' => $action['type'] ?? 'unknown',
                'requires_confirmation' => $requiresConfirmation,
            ]);

            if ($requiresConfirmation) {
                $awaitingConfirmation = $this->requestConfirmation($context, $action, $orchestrator, $responseService);
                Log::info('[SlackMention] Confirmation requested', [
                    'context_id' => $context->id,
                    'awaiting_confirmation' => $awaitingConfirmation,
                ]);
                $context->update(['current_state' => $awaitingConfirmation ? 'awaiting_response' : 'idle']);

                return;
            }

            Log::info('[SlackMention] Executing action directly', [
                'context_id' => $context->id,
                'action_type' => $action['type'] ?? 'unknown',
            ]);

            $result = $orchestrator->executeAction($context, $action);

            Log::info('[SlackMention] Action execution result', [
                'context_id' => $context->id,
                'action_type' => $action['type'] ?? 'unknown',
                'success' => $result['success'] ?? false,
                'error' => $result['error'] ?? null,
            ]);

            if ($result['success']) {
                $this->sendSuccessResponse($context, $action, $result, $orchestrator, $responseService);
            } else {
                $orchestrator->sendResponse($context, ":x: {$result['error']}");
            }

            $context->update(['current_state' => 'idle']);
        } catch (\Exception $e) {
            Log::error('ProcessSlackMentionJob failed', [
                'context_id' => $context->id,
                'error' => $e->getMessage(),
            ]);

            $orchestrator->sendResponse($context, ':x: Sorry, something went wrong. Please try again.');
            $context->update(['current_state' => 'idle']);

            throw $e;
        }
    }

    private function requestConfirmation(
        SlackThreadContext $context,
        array $action,
        SlackMentionOrchestrator $orchestrator,
        SlackBotResponseService $responseService
    ): bool {
        $actionType = SlackActionType::tryFrom($action['type'] ?? '');

        if ($actionType !== SlackActionType::ImportSow) {
            $context->addPendingAction($action['type'], $action);
        }

        $blocks = match ($actionType) {
            SlackActionType::TriggerAgent => $responseService->agentConfirmationBlocks($action),
            SlackActionType::TriggerEngineeringAgent => $responseService->engineeringIssueConfirmationBlocks($action),
            SlackActionType::ImportSow => $this->importSowConfirmationBlocks(
                $context,
                $action,
                $orchestrator,
                $responseService
            ),
            default => [$responseService->section('Please confirm you want to perform this action.')],
        };

        if ($actionType === SlackActionType::ImportSow && $blocks === []) {
            return false;
        }

        $orchestrator->sendBlockResponse($context, $blocks);

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<int, array<string, mixed>>
     */
    private function importSowConfirmationBlocks(
        SlackThreadContext $context,
        array $action,
        SlackMentionOrchestrator $orchestrator,
        SlackBotResponseService $responseService
    ): array {
        Log::info('[SlackMention] Building SOW import confirmation blocks', [
            'context_id' => $context->id,
            'action_urls' => $action['google_doc_urls'] ?? [],
            'has_content' => ! empty($action['content']),
        ]);

        $urlCount = count($action['google_doc_urls'] ?? []);
        $orchestrator->sendResponse($context, ":page_facing_up: Reading {$urlCount} document(s) from Google Drive...");

        $preview = $orchestrator->previewImportSow($context, $action);

        Log::info('[SlackMention] SOW preview result', [
            'context_id' => $context->id,
            'success' => $preview['success'] ?? false,
            'error' => $preview['error'] ?? null,
            'has_parsed' => isset($preview['parsed']),
        ]);

        if (! ($preview['success'] ?? false)) {
            Log::warning('[SlackMention] SOW preview failed, aborting confirmation', [
                'context_id' => $context->id,
                'error' => $preview['error'] ?? 'Unknown error',
            ]);
            $orchestrator->sendResponse($context, ':x: '.($preview['error'] ?? 'Unable to preview the SOW import.'));

            return [];
        }

        $clientName = data_get($preview, 'parsed.client_name', 'Unknown');
        $projectName = data_get($preview, 'parsed.project_name', 'Unknown');
        $milestoneCount = count(data_get($preview, 'parsed.milestones', []));
        $orchestrator->sendResponse($context, ":white_check_mark: SOW parsed — *{$clientName}* / *{$projectName}* with {$milestoneCount} milestone(s). Building confirmation...");

        $preview['parsed']['link_to_channel'] = (bool) ($action['link_to_channel'] ?? false);
        $pendingActionId = $context->addPendingAction(SlackActionType::ImportSow->value, $action);

        return $responseService->sowImportPreviewBlocks(
            $preview['parsed'] ?? [],
            $pendingActionId,
            $context->id
        );
    }

    private function sendSuccessResponse(
        SlackThreadContext $context,
        array $action,
        array $result,
        SlackMentionOrchestrator $orchestrator,
        SlackBotResponseService $responseService
    ): void {
        $actionType = SlackActionType::tryFrom($action['type'] ?? '');

        $blocks = match ($actionType) {
            SlackActionType::CreateTask => $responseService->taskCreatedBlocks($result),
            SlackActionType::ManageTask => isset($result['task'])
                ? $responseService->taskDetailBlocks($result['task'], $result['message'] ?? null)
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::LogNote => [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::Search => $responseService->searchResultsBlocks($result),
            SlackActionType::GetStatus => $responseService->channelStatusBlocks($result),
            SlackActionType::GetFocus => $responseService->focusBriefingBlocks(
                $result['briefing'] ?? [],
                $result['priority_filter'] ?? 'all'
            ),
            SlackActionType::TriggerEngineeringAgent => isset($result['thread_summary'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Engineering run queued.')), $responseService->divider()],
                    $responseService->threadSummaryBlocks($result['thread_summary'])
                )
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::ListAgentRuns => $responseService->agentRunListBlocks(
                $result['runs'] ?? [],
                $result['project']['name'] ?? 'Linked Project',
                $result['filter'] ?? 'active'
            ),
            SlackActionType::ShowAgentRun => isset($result['run'])
                ? $responseService->agentRunDetailBlocks($result['run'], $result['message'] ?? null)
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::GetThreadSummary => $this->threadSummaryResponseBlocks($action, $result, $responseService),
            SlackActionType::CancelAgentRun => isset($result['thread_summary'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Cancelled run.')), $responseService->divider()],
                    $responseService->threadSummaryBlocks($result['thread_summary'])
                )
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::RetryAgentRun => isset($result['thread_summary'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Started rerun.')), $responseService->divider()],
                    $responseService->threadSummaryBlocks($result['thread_summary'])
                )
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::RequestReviewDeploy,
            SlackActionType::RetryReviewDeploy => isset($result['thread_summary'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Updated deploy state.')), $responseService->divider()],
                    $responseService->threadSummaryBlocks($result['thread_summary'])
                )
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::GetStagingStatus,
            SlackActionType::PrepareStagingSecret,
            SlackActionType::SyncStagingSecrets,
            SlackActionType::PublishStaging => isset($result['thread_summary'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Updated staging state.')), $responseService->divider()],
                    $responseService->threadSummaryBlocks($result['thread_summary'])
                )
                : $responseService->stagingStatusBlocks(
                    $result,
                    $actionType === SlackActionType::GetStagingStatus ? null : ($result['message'] ?? null)
                ),
            SlackActionType::GetChannelContext => $responseService->operationsContextBlocks($result),
            SlackActionType::GetIntegrations => $responseService->integrationOverviewBlocks(
                $result['context'] ?? [],
                $result['status'] ?? []
            ),
            SlackActionType::ManageWatchlist => $responseService->watchlistResultBlocks($result),
            SlackActionType::ListWatchlist => $responseService->watchlistBlocks($result),
            SlackActionType::LinkContext => isset($result['context'])
                ? array_merge(
                    [$responseService->section(':white_check_mark: '.($result['message'] ?? 'Channel linked.')), $responseService->divider()],
                    $responseService->operationsContextBlocks($result['context'])
                )
                : [$responseService->section(":white_check_mark: {$result['message']}")],
            SlackActionType::RunIntegrationSync => $responseService->integrationActionResultBlocks($result),
            SlackActionType::ShowClient => $responseService->clientSummaryBlocks($result),
            SlackActionType::ListClients => $responseService->clientListBlocks($result, 'Clients'),
            SlackActionType::CreateClient,
            SlackActionType::UpdateClient => $responseService->clientSummaryBlocks($result, $result['message'] ?? 'Client updated.'),
            SlackActionType::ShowProject => $responseService->projectSummaryBlocks($result),
            SlackActionType::ListProjects => $responseService->projectListBlocks($result, 'Projects'),
            SlackActionType::CreateProject,
            SlackActionType::UpdateProject => $responseService->projectSummaryBlocks($result, $result['message'] ?? 'Project updated.'),
            SlackActionType::ListLeads => $responseService->leadListBlocks($result, 'Leads'),
            SlackActionType::CreateLead,
            SlackActionType::UpdateLeadStage => $responseService->leadSummaryBlocks($result, $result['message'] ?? 'Lead updated.'),
            SlackActionType::ListInvoices => $responseService->invoiceListBlocks($result, 'Invoices'),
            SlackActionType::CreateInvoice => $responseService->invoiceSummaryBlocks($result, $result['message'] ?? 'Invoice created.'),
            SlackActionType::ImportSow => $responseService->sowImportResultBlocks($result, $result['message'] ?? 'SOW imported.'),
            SlackActionType::ShowWebsiteProject => $responseService->websiteProjectSummaryBlocks($result),
            SlackActionType::ListWebsiteProjects => $responseService->websiteProjectListBlocks($result, 'Website Projects'),
            SlackActionType::CreateWebsiteProject,
            SlackActionType::UpdateWebsiteProject => $responseService->websiteProjectSummaryBlocks($result, $result['message'] ?? 'Website project updated.'),
            default => [$responseService->section(":white_check_mark: {$result['message']}")],
        };

        $orchestrator->sendBlockResponse($context, $blocks);
    }

    private function threadSummaryResponseBlocks(
        array $action,
        array $result,
        SlackBotResponseService $responseService
    ): array {
        $focus = $action['focus'] ?? null;
        $run = $result['run'] ?? [];
        $prLabel = isset($run['pr_number']) ? "PR #{$run['pr_number']}" : (isset($result['requested_pr_number']) ? "PR #{$result['requested_pr_number']}" : 'this thread');

        $preface = match ($focus) {
            'pull_request' => ! empty($run['pr_url'])
                ? ":link: {$prLabel}: <{$run['pr_url']}|Open pull request>"
                : null,
            'review_build' => ! empty($run['review_url'])
                ? ":test_tube: {$prLabel} review build: <{$run['review_url']}|Open review build>"
                : null,
            'workflow' => ! empty($run['workflow_url'])
                ? ":gear: {$prLabel} workflow: <{$run['workflow_url']}|Open GitHub Actions>"
                : (! empty($run['workflow_status'])
                    ? ":gear: {$prLabel} workflow status: ".str_replace('_', ' ', (string) $run['workflow_status'])
                    : null),
            'workflow_failure' => ! empty($run['workflow_failure_summary'])
                ? ":x: {$prLabel} last CI failure: {$run['workflow_failure_summary']}"
                : (! empty($run['workflow_conclusion']) && $run['workflow_conclusion'] !== 'success'
                    ? ":x: {$prLabel} workflow conclusion: ".str_replace('_', ' ', (string) $run['workflow_conclusion'])
                    : null),
            'deploy' => ! empty($run['deployment_status'])
                ? ":rocket: {$prLabel} deploy status: ".str_replace('_', ' ', (string) $run['deployment_status'])
                : null,
            default => null,
        };

        if (! $preface) {
            return $responseService->threadSummaryBlocks($result);
        }

        return array_merge([
            $responseService->section($preface),
            $responseService->divider(),
        ], $responseService->threadSummaryBlocks($result));
    }
}
