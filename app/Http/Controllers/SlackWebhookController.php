<?php

namespace App\Http\Controllers;

use App\Enums\SlackActionType;
use App\Events\InteractionResponseReceived;
use App\Jobs\AnalyzeSlackMessageJob;
use App\Jobs\ProcessAgentTasksJob;
use App\Jobs\ProcessSlackOpsCommandJob;
use App\Jobs\RunInteractiveAgentJob;
use App\Jobs\SelfHealingJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\ApprovalRequest;
use App\Models\ClientNote;
use App\Models\InteractionRequest;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Approval\ApprovalDecisionService;
use App\Services\CapabilitySynthesisService;
use App\Services\SelfHealing\NightwatchErrorDetector;
use App\Services\SelfHealing\SelfHealingService;
use App\Services\Slack\SlackAgentRunService;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use App\Services\Slack\SlackEngineeringAgentService;
use App\Services\Slack\SlackEngineeringApprovalService;
use App\Services\Slack\SlackLinkedProjectService;
use App\Services\Slack\SlackMcpToolBridge;
use App\Services\Slack\SlackMentionOrchestrator;
use App\Services\Slack\SlackStagingThreadService;
use App\Services\Slack\SlackStagingWorkflowService;
use App\Services\Slack\SlackThreadRunService;
use App\Services\TaskAgentService;
use App\Services\Tax\Agency\TaxAgencySlackMfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackWebhookController extends Controller
{
    public function __construct(
        private SlackApiService $api,
        private NightwatchErrorDetector $nightwatchDetector,
        private SelfHealingService $selfHealingService,
        private SlackMentionOrchestrator $mentionOrchestrator,
        private SlackBotResponseService $responseService,
        private SlackAgentRunService $agentRunService,
        private SlackEngineeringAgentService $engineeringAgentService,
        private SlackEngineeringApprovalService $engineeringApprovalService,
        private SlackLinkedProjectService $linkedProjectService,
        private SlackStagingWorkflowService $stagingWorkflowService,
        private SlackStagingThreadService $stagingThreadService,
        private SlackThreadRunService $threadRunService,
        private SlackMcpToolBridge $mcpBridge,
        private ApprovalDecisionService $approvalDecisionService,
        private TaskAgentService $taskAgentService,
        private CapabilitySynthesisService $capabilitySynthesis,
        private TaxAgencySlackMfaService $taxAgencySlackMfaService,
    ) {}

    /**
     * Handle Slack Events API
     */
    public function events(Request $request)
    {
        $payload = $request->all();

        // URL verification challenge
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        // Verify the request is from Slack
        if (! $this->verifySlackRequest($request)) {
            Log::warning('Slack webhook: Invalid signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Handle event callback
        if ($request->input('type') === 'event_callback') {
            $event = $request->input('event', []);
            $teamId = $request->input('team_id');
            $eventId = $request->input('event_id', '');

            // Deduplicate: Slack retries events if we don't respond within 3s.
            // Also reject explicit retries via the retry header.
            if ($request->header('X-Slack-Retry-Num')) {
                Log::debug('Slack event: Ignoring retry', [
                    'retry' => $request->header('X-Slack-Retry-Num'),
                    'event_id' => $eventId,
                ]);

                return response()->json(['ok' => true]);
            }

            // Cache-based deduplication for the same event_id
            if ($eventId && ! Cache::add("slack_event:{$eventId}", true, 300)) {
                Log::debug('Slack event: Duplicate ignored', ['event_id' => $eventId]);

                return response()->json(['ok' => true]);
            }

            Log::info('Slack event received', [
                'type' => $event['type'] ?? 'unknown',
                'team' => $teamId,
            ]);

            // Process event after the response is sent to Slack (within 3s window)
            app()->terminating(function () use ($teamId, $event) {
                $this->processEvent($teamId, $event);
            });
        }

        // Always respond quickly to Slack
        return response()->json(['ok' => true]);
    }

    /**
     * Verify the request came from Slack
     */
    private function verifySlackRequest(Request $request): bool
    {
        $signingSecret = config('services.slack.signing_secret');
        if (! $signingSecret) {
            // Fail closed - only allow bypass in dev/testing environments
            if (app()->environment('local', 'testing')) {
                Log::warning('Slack signing secret not configured - bypassing verification in development');

                return true;
            }
            Log::critical('Slack signing secret not configured - rejecting request in production');

            return false;
        }

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        // Check timestamp is recent (within 5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $sigBaseString = "v0:{$timestamp}:".$request->getContent();
        $computedSignature = 'v0='.hash_hmac('sha256', $sigBaseString, $signingSecret);

        return hash_equals($computedSignature, $signature);
    }

    /**
     * Process a Slack event
     */
    private function processEvent(string $teamId, array $event): void
    {
        $eventType = $event['type'] ?? '';

        // Check for self-healing FIRST, before workspace validation
        // This allows error detection from any workspace, even unregistered ones
        if ($eventType === 'message' && $this->checkForNightwatchError($event)) {
            return; // Handled by self-healing system
        }

        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            Log::warning('Slack event: Unknown workspace', ['team' => $teamId]);

            return;
        }

        switch ($eventType) {
            case 'message':
                $this->handleMessageEvent($workspace, $event);
                break;

            case 'reaction_added':
                $this->handleReactionEvent($workspace, $event);
                break;

            case 'member_joined_channel':
            case 'member_left_channel':
                $this->handleMembershipEvent($workspace, $event);
                break;

            default:
                Log::debug('Slack event: Unhandled type', ['type' => $eventType]);
        }
    }

    /**
     * Handle new message events
     */
    private function handleMessageEvent(SlackWorkspace $workspace, array $event): void
    {
        // Note: Self-healing check (checkForNightwatchError) happens in processEvent()
        // before workspace validation, so it's not duplicated here

        // Ignore bot messages (including our own) and subtypes (except thread_broadcast)
        if (! empty($event['bot_id'])) {
            return;
        }

        $subtype = $event['subtype'] ?? null;
        if ($subtype && $subtype !== 'thread_broadcast') {
            return;
        }

        $channelId = $event['channel'] ?? '';
        $channel = SlackChannel::where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $channel) {
            Log::debug('Slack message from unknown channel - attempting to create', [
                'channel_id' => $channelId,
                'workspace' => $workspace->workspace_name,
            ]);

            $channel = $this->createChannelFromEvent($workspace, $channelId, $event);

            if (! $channel) {
                Log::warning('Slack message dropped: could not create channel', [
                    'channel_id' => $channelId,
                ]);

                return;
            }
        }

        if (! $channel->monitoring_enabled) {
            Log::debug('Slack message from non-monitored channel', [
                'channel' => $channel->name,
                'channel_id' => $channelId,
            ]);

            return;
        }

        Log::info('Processing Slack message', [
            'channel' => $channel->channel_name,
            'ts' => $event['ts'] ?? '',
        ]);

        $messageText = $event['text'] ?? '';

        if ($channel->is_dm) {
            if ($this->taxAgencySlackMfaService->capturePendingCodeFromSlackMessage($workspace, $channel, $event)) {
                return;
            }

            // Check if this is a reply in a proposal notification thread
            $threadTs = $event['thread_ts'] ?? null;
            $messageTs = $event['ts'] ?? null;
            if ($threadTs && $threadTs !== $messageTs) {
                $proposal = \App\Models\RfpProposal::where('slack_notification_ts', $threadTs)->first();
                if ($proposal) {
                    \App\Jobs\HandleProposalSlackRevisionJob::dispatch(
                        $proposal->id,
                        $event['text'] ?? '',
                        $threadTs,
                        $channelId,
                    );

                    return;
                }
            }

            Log::info('Processing DM as conversational bot request', [
                'channel' => $channel->channel_name,
            ]);

            $this->mentionOrchestrator->handleDirectMessage($workspace, $channel, $event);

            return;
        }

        if ($this->mentionOrchestrator->isBotMentioned($messageText, $workspace->bot_user_id)) {
            Log::info('Bot mentioned in message', ['channel' => $channel->channel_name]);
            $this->mentionOrchestrator->handleMention($workspace, $channel, $event);

            return;
        }

        $message = $this->api->storeMessage($workspace, $channel, $event);

        if (isset($event['thread_ts']) && $event['thread_ts'] !== $event['ts']) {
            $this->api->syncThread($workspace, $channel, $event['thread_ts']);
        }

        if ($message->user_is_external && $channel->isClientChannel()) {
            $this->queueActionItemExtraction($message);
        }
    }

    private function createChannelFromEvent(SlackWorkspace $workspace, string $channelId, array $event): ?SlackChannel
    {
        try {
            // Detect channel type by prefix
            // D = 1:1 DM, G = group DM (mpim) or legacy private, C = channel
            $isDmByPrefix = str_starts_with($channelId, 'D');
            $isGroupDmByPrefix = str_starts_with($channelId, 'G');

            $response = \Illuminate\Support\Facades\Http::withToken($workspace->access_token)
                ->get('https://slack.com/api/conversations.info', ['channel' => $channelId]);

            $apiSuccess = $response->successful() && $response->json('ok');
            $apiError = $response->json('error');

            // If API fails for DMs, create a minimal record anyway
            // This handles cases where bot lacks im:read/mpim:read scopes
            if (! $apiSuccess && ($isDmByPrefix || $isGroupDmByPrefix)) {
                Log::info('Creating DM channel with minimal info (API unavailable)', [
                    'channel_id' => $channelId,
                    'api_error' => $apiError,
                ]);

                // Try to get user info from the message event to name the DM
                $dmName = $this->getDmNameFromEvent($workspace, $channelId, $event);

                $channel = SlackChannel::create([
                    'workspace_id' => $workspace->id,
                    'slack_id' => $channelId,
                    'channel_id' => $channelId,
                    'name' => $dmName,
                    'channel_name' => $dmName,
                    'is_private' => true,
                    'is_shared' => false,
                    'is_dm' => true,
                    'is_archived' => false,
                    'member_count' => $isDmByPrefix ? 2 : 0,
                    'monitoring_enabled' => true,  // Monitor DMs by default
                    'is_monitored' => true,
                    'classification' => 'client',
                ]);

                Log::info('Auto-created DM channel from webhook (minimal)', [
                    'channel' => $channel->name,
                    'channel_id' => $channelId,
                ]);

                return $channel;
            }

            if (! $apiSuccess) {
                Log::warning('Failed to get channel info from Slack', [
                    'channel_id' => $channelId,
                    'error' => $apiError,
                ]);

                return null;
            }

            $channelData = $response->json('channel', []);
            $isShared = $channelData['is_shared'] ?? $channelData['is_ext_shared'] ?? false;
            $isPrivate = $channelData['is_private'] ?? false;
            $isDm = $channelData['is_im'] ?? $channelData['is_mpim'] ?? false;

            $shouldMonitor = $isShared || $isDm;

            $channel = SlackChannel::create([
                'workspace_id' => $workspace->id,
                'slack_id' => $channelId,
                'channel_id' => $channelId,
                'name' => $channelData['name'] ?? $channelData['id'] ?? $channelId,
                'channel_name' => $channelData['name'] ?? $channelId,
                'is_private' => $isPrivate,
                'is_shared' => $isShared,
                'is_dm' => $isDm,
                'is_archived' => $channelData['is_archived'] ?? false,
                'member_count' => $channelData['num_members'] ?? 0,
                'monitoring_enabled' => $shouldMonitor,
                'is_monitored' => $shouldMonitor,
                'classification' => $isShared || $isDm ? 'client' : 'general',
            ]);

            Log::info('Auto-created channel from webhook', [
                'channel' => $channel->name,
                'is_shared' => $isShared,
                'monitoring_enabled' => $shouldMonitor,
            ]);

            return $channel;
        } catch (\Exception $e) {
            Log::error('Failed to create channel from event', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get a descriptive name for a DM channel from the event data.
     */
    private function getDmNameFromEvent(SlackWorkspace $workspace, string $channelId, array $event): string
    {
        // Try to get the user who sent the message
        $userId = $event['user'] ?? null;

        if ($userId) {
            try {
                $response = \Illuminate\Support\Facades\Http::withToken($workspace->access_token)
                    ->get('https://slack.com/api/users.info', ['user' => $userId]);

                if ($response->successful() && $response->json('ok')) {
                    $user = $response->json('user', []);
                    $realName = $user['real_name'] ?? $user['name'] ?? null;
                    if ($realName) {
                        return "DM: {$realName}";
                    }
                }
            } catch (\Exception $e) {
                // Fall through to default
            }
        }

        // Default: use channel ID
        return "DM: {$channelId}";
    }

    private function checkForNightwatchError(array $event): bool
    {
        // Only check messages in the configured self-healing channel
        $selfHealingChannelId = config('self-healing.slack_channel_id');
        $eventChannel = $event['channel'] ?? '';

        // Debug logging for self-healing detection
        Log::debug('Self-healing: Checking message', [
            'configured_channel' => $selfHealingChannelId,
            'event_channel' => $eventChannel,
            'event_bot_id' => $event['bot_id'] ?? null,
            'event_user' => $event['user'] ?? null,
            'has_text' => ! empty($event['text']),
            'has_attachments' => ! empty($event['attachments']),
        ]);

        if (! $selfHealingChannelId || $eventChannel !== $selfHealingChannelId) {
            if ($selfHealingChannelId) {
                Log::debug('Self-healing: Channel mismatch', [
                    'expected' => $selfHealingChannelId,
                    'got' => $eventChannel,
                ]);
            }

            return false;
        }

        // Check if this looks like a Nightwatch error
        if (! $this->nightwatchDetector->isNightwatchEvent($event)) {
            return false;
        }

        // Parse the error
        $error = $this->nightwatchDetector->parseFromEvent($event);
        if (! $error) {
            Log::warning('Self-healing: Failed to parse Nightwatch error from event');

            return false;
        }

        Log::info('Self-healing: Nightwatch error detected', [
            'exception' => $error->exceptionClass,
            'message' => substr($error->message, 0, 100),
            'signature' => $error->getSignature(),
        ]);

        // Check if we should attempt a fix
        if (! $this->selfHealingService->shouldAttemptFix($error)) {
            Log::info('Self-healing: Skipping fix attempt (safety rails)');

            return true; // Still return true - we handled it, just chose not to fix
        }

        // Dispatch the self-healing job
        SelfHealingJob::dispatch($error);

        Log::info('Self-healing: Dispatched SelfHealingJob', [
            'signature' => $error->getSignature(),
        ]);

        return true;
    }

    /**
     * Handle reaction events (for acknowledgment tracking)
     */
    private function handleReactionEvent(SlackWorkspace $workspace, array $event): void
    {
        // Could be used to track acknowledgments
        Log::debug('Slack reaction', [
            'reaction' => $event['reaction'] ?? '',
            'item_ts' => $event['item']['ts'] ?? '',
        ]);
    }

    /**
     * Handle channel membership changes
     */
    private function handleMembershipEvent(SlackWorkspace $workspace, array $event): void
    {
        // Re-sync channel to check if it became shared
        $channelId = $event['channel'] ?? '';
        $channel = $workspace->channels()->where('channel_id', $channelId)->first();

        if ($channel) {
            // Update classification if needed
            $channel->update([
                'classification' => $channel->classifyAutomatically(),
            ]);
        }
    }

    /**
     * Queue message for real-time AI analysis.
     *
     * Only queues external user messages from client channels
     * to minimize API costs while catching important requests.
     */
    private function queueActionItemExtraction(SlackMessage $message): void
    {
        // Dispatch single-message analysis job for real-time processing
        AnalyzeSlackMessageJob::dispatch($message->id)
            ->onQueue('slack-analysis')
            ->delay(now()->addSeconds(2)); // Small delay to batch rapid messages

        Log::info('Queued Slack message for AI analysis', [
            'id' => $message->id,
            'from' => $message->user_name,
            'channel_id' => $message->channel_id,
        ]);
    }

    /**
     * Handle slash commands
     */
    public function slashCommand(Request $request)
    {
        // Verify request
        if (! $this->verifySlackRequest($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $command = $request->input('command');
        $text = $request->input('text');
        $userId = $request->input('user_id');
        $channelId = $request->input('channel_id');

        Log::info('Slack slash command', [
            'command' => $command,
            'text' => $text,
            'user' => $userId,
        ]);

        // Handle /zao command
        if ($command === '/zao') {
            return $this->handleZaoCommand($text, $userId, $channelId, $request);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => "Unknown command: {$command}",
        ]);
    }

    /**
     * Handle /zao slash command
     */
    private function handleZaoCommand(?string $text, string $userId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $text = $text ?? '';
        $parts = explode(' ', $text, 2);
        $subcommand = $parts[0] ?? '';
        $args = $parts[1] ?? '';

        switch ($subcommand) {
            case 'task':
                return $this->handleZaoTask($args, $userId, $channelId, $request);

            case 'client':
            case 'clients':
                return $this->handleZaoClient($args);

            case 'project':
            case 'projects':
                return $this->handleZaoProject($args);

            case 'lead':
            case 'leads':
                return $this->handleZaoLead($args);

            case 'invoice':
            case 'invoices':
                return $this->handleZaoInvoice($args);

            case 'website':
            case 'websites':
            case 'site':
            case 'sites':
                return $this->handleZaoWebsiteProject($args);

            case 'log':
                return $this->handleZaoLog($args, $userId, $channelId, $request);

            case 'agent':
                return $this->handleZaoAgent($args, $userId, $channelId, $request);

            case 'issue':
                return $this->handleZaoIssue($args, $userId, $channelId, $request);

            case 'run':
                return $this->handleZaoRun($args, $userId, $channelId, $request);

            case 'staging':
                return $this->handleZaoStaging($args, $channelId, $request);

            case 'status':
                return $this->handleZaoStatus($userId, $channelId, $request);

            case 'focus':
            case 'briefing':
                return $this->handleZaoFocus($args);

            case 'thread':
                return $this->handleZaoThread($channelId, $request);

            case 'ops':
                return $this->handleZaoOps($args, $userId, $channelId, $request);

            case 'context':
                return $this->handleZaoContext($channelId, $request);

            case 'tasks':
                return $this->handleZaoTasks($args, $channelId, $request);

            case 'runs':
                return $this->handleZaoRuns($args, $channelId, $request);

            case 'approval':
            case 'approvals':
                return $this->handleZaoApprovals($args);

            case 'integration':
            case 'integrations':
                return $this->handleZaoIntegrations($channelId, $request);

            case 'sync':
                return $this->handleZaoSync($args, $channelId, $request);

            case 'find':
            case 'search':
                return $this->handleZaoSearch($args);

            case 'link':
                return $this->handleZaoLink($args, $channelId, $request);

            default:
                if ($this->looksLikeFocusQuery($text)) {
                    return $this->handleZaoFocus($text);
                }

                if (trim($text) !== '') {
                    return $this->handleZaoOps($text, $userId, $channelId, $request);
                }

                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => "Usage:\n• `/zao task <description>` - Create a task\n• `/zao task show <id>` - View a task in this channel's project\n• `/zao task start|review|complete <id>` - Move a task through the workflow\n• `/zao task priority <id> <low|medium|high|urgent>` - Update task priority\n• `/zao task run <id> [agent-slug]` - Assign and run an agent on a task\n• `/zao client show <id>` - View a client\n• `/zao client list [status]` - List clients\n• `/zao client create <name> [website <url>]` - Create a client\n• `/zao client rename|website|status ...` - Update a client\n• `/zao project show <id>` - View a project\n• `/zao project list [client <id>] [status <status>]` - List projects\n• `/zao project create client <id> <name> [repo <owner/repo>]` - Create a project\n• `/zao project rename|repo|status ...` - Update a project\n• `/zao lead list [stage]` - List leads\n• `/zao lead create <company> [website <url>] [email <email>]` - Create a lead\n• `/zao lead stage <id> <stage>` - Move a lead through the pipeline\n• `/zao invoice list [client <id>] [status <status>]` - List invoices\n• `/zao invoice create client <id> item <description> amount <amt> [qty <n>]` - Create a draft invoice\n• `/zao website show <id>` - View a website project\n• `/zao website list [status <status>] [type <type>]` - List website projects\n• `/zao website create <name> type <type> [domain <domain>] [brief <brief>]` - Create a website project\n• `/zao website status|domain ...` - Update a website project\n• `/zao log <note>` - Log an action item\n• `/zao agent [name] [task]` - Trigger an agent\n• `/zao issue <number> [staging|pr] [branch <name>]` - Run Dev Agent on a GitHub issue for this channel's project\n• `/zao runs [active|running|awaiting_input|pending_approval|failed|completed|cancelled|all]` - List recent agent runs for this channel's project\n• `/zao run show <id>` - Inspect a specific project run\n• `/zao run retry <id>` - Restart a specific project run from Slack\n• `/zao run cancel <id>` - Cancel a specific in-flight project run\n• `/zao staging` - Show staging readiness for this channel's project\n• `/zao staging secret [NAME]` - Privately save and sync a staging secret\n• `/zao staging sync` - Sync required staging secrets to GitHub\n• `/zao staging publish` - Trigger the staging GitHub Actions workflow\n• `/zao focus [critical|high]` - Show what needs your attention today\n• `/zao status` - Check connection status\n• `/zao thread` - Show the most relevant active Slack thread summary for this channel\n• `/zao context` - Show the current channel's ops context\n• `/zao integrations` - Show linked integration status for this channel\n• `/zao sync <github|clickup|harvest|wordpress|all>` - Queue integration syncs\n• `/zao tasks [status]` - List tasks for the linked project\n• `/zao approvals [status|risk]` - Review pending approvals\n• `/zao find <query>` - Search clients, projects, tasks, leads, agents\n• `/zao link client <id> [project <id>]` - Link this channel\n• `/zao ops <request>` - Run a broader Slack-native ops request",
                ]);
        }
    }

    private function handleZaoFocus(string $args): \Illuminate\Http\JsonResponse
    {
        $normalizedArgs = strtolower(trim($args));
        $priorityFilter = match (true) {
            str_contains($normalizedArgs, 'critical') => 'critical',
            str_contains($normalizedArgs, 'high') => 'high',
            default => 'all',
        };

        $briefing = $this->capabilitySynthesis->getMorningBriefing();
        $items = $this->capabilitySynthesis->getHumanRequiredItems();

        if ($priorityFilter !== 'all') {
            $items = array_values(array_filter($items, fn (array $item): bool => $priorityFilter === 'critical'
                ? ($item['priority'] ?? 'medium') === 'critical'
                : in_array($item['priority'] ?? 'medium', ['critical', 'high'], true)
            ));
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->focusBriefingBlocks([
                'greeting' => $briefing['greeting'],
                'summary' => $briefing['summary'],
                'recommendations' => $briefing['recommendations'],
                'top_priorities' => array_slice($items, 0, 5),
            ], $priorityFilter),
        ]);
    }

    private function looksLikeFocusQuery(string $text): bool
    {
        return (bool) preg_match('/\bwhat\s+should\s+i\s+(?:work|focus)\s+on\s+(?:today|right\s+now)\b/i', $text)
            || (bool) preg_match('/\bwhat\s+needs\s+my\s+attention\b/i', $text)
            || (bool) preg_match('/\bwhat\s+are\s+my\s+top\s+priorities\b/i', $text)
            || (bool) preg_match('/\bgive\s+me\s+(?:my\s+)?(?:focus|briefing)\b/i', $text)
            || (bool) preg_match('/\bhelp\s+me\s+prioriti[sz]e\s+(?:today|right\s+now)\b/i', $text)
            || (bool) preg_match('/\bwhat(?:\'s| is)\s+most\s+important\s+(?:today|right\s+now)\b/i', $text)
            || (bool) preg_match('/\bwhere\s+should\s+i\s+focus\b/i', $text);
    }

    private function handleZaoClient(string $args): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao client show <id>`, `/zao client list [status]`, `/zao client create <name> [website <url>]`, `/zao client rename|website|status ...`',
            ]);
        }

        if (preg_match('/^show\s+(\d+)\b/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('get-client', [
                'id' => (int) $matches[1],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientSummaryBlocks($payload)
            );
        }

        if (preg_match('/^list(?:\s+(active|inactive|prospect|churned|archived|all))?$/i', $args, $matches)) {
            $status = $matches[1] ?? null;
            $result = $this->mcpBridge->execute('list-clients', array_filter([
                'status' => $status ? strtolower($status) : null,
                'limit' => 10,
            ], fn ($value) => ! is_null($value)));
            $title = $status ? ucfirst(strtolower($status)).' Clients' : 'Clients';

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientListBlocks($payload, $title)
            );
        }

        if (preg_match('/^create\s+(.+?)(?:\s+website\s+(https?:\/\/\S+))?$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('create-client', array_filter([
                'name' => trim($matches[1]),
                'website' => $matches[2] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''));

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientSummaryBlocks($payload, $payload['message'] ?? 'Client created.'),
                'in_channel'
            );
        }

        if (preg_match('/^rename\s+(\d+)\s+(.+)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-client', [
                'id' => (int) $matches[1],
                'name' => trim($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientSummaryBlocks($payload, $payload['message'] ?? 'Client updated.'),
                'in_channel'
            );
        }

        if (preg_match('/^website\s+(\d+)\s+(https?:\/\/\S+)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-client', [
                'id' => (int) $matches[1],
                'website' => $matches[2],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientSummaryBlocks($payload, $payload['message'] ?? 'Client updated.'),
                'in_channel'
            );
        }

        if (preg_match('/^status\s+(\d+)\s+(active|inactive|prospect|churned|archived)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-client', [
                'id' => (int) $matches[1],
                'status' => strtolower($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->clientSummaryBlocks($payload, $payload['message'] ?? 'Client updated.'),
                'in_channel'
            );
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao client show <id>`, `/zao client list [status]`, `/zao client create <name> [website <url>]`, `/zao client rename|website|status ...`',
        ]);
    }

    private function handleZaoProject(string $args): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao project show <id>`, `/zao project list [client <id>] [status <status>]`, `/zao project create client <id> <name> [repo <owner/repo>]`, `/zao project rename|repo|status ...`',
            ]);
        }

        if (preg_match('/^show\s+(\d+)\b/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('get-project', [
                'id' => (int) $matches[1],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectSummaryBlocks($payload)
            );
        }

        if (preg_match('/^list\b/i', $args)) {
            preg_match('/\bclient\s+(\d+)\b/i', $args, $clientMatches);
            preg_match('/\bstatus\s+(active|completed|on_hold|archived|all)\b/i', $args, $statusMatches);

            $clientId = isset($clientMatches[1]) ? (int) $clientMatches[1] : null;
            $status = $statusMatches[1] ?? null;

            $result = $this->mcpBridge->execute('list-projects', array_filter([
                'client_id' => $clientId,
                'status' => $status ? strtolower($status) : null,
                'limit' => 10,
            ], fn ($value) => ! is_null($value)));
            $title = $status ? ucfirst(strtolower($status)).' Projects' : 'Projects';

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectListBlocks($payload, $title)
            );
        }

        if (preg_match('/^create\s+client\s+(\d+)\s+(.+?)(?:\s+repo\s+([a-z0-9._-]+\/[a-z0-9._-]+))?$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('create-project', array_filter([
                'client_id' => (int) $matches[1],
                'name' => trim($matches[2]),
                'github_repo' => $matches[3] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''));

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectSummaryBlocks($payload, $payload['message'] ?? 'Project created.'),
                'in_channel'
            );
        }

        if (preg_match('/^rename\s+(\d+)\s+(.+)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-project', [
                'id' => (int) $matches[1],
                'name' => trim($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectSummaryBlocks($payload, $payload['message'] ?? 'Project updated.'),
                'in_channel'
            );
        }

        if (preg_match('/^repo\s+(\d+)\s+([a-z0-9._-]+\/[a-z0-9._-]+)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-project', [
                'id' => (int) $matches[1],
                'github_repo' => $matches[2],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectSummaryBlocks($payload, $payload['message'] ?? 'Project updated.'),
                'in_channel'
            );
        }

        if (preg_match('/^status\s+(\d+)\s+(active|completed|on_hold|archived)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-project', [
                'id' => (int) $matches[1],
                'status' => strtolower($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->projectSummaryBlocks($payload, $payload['message'] ?? 'Project updated.'),
                'in_channel'
            );
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao project show <id>`, `/zao project list [client <id>] [status <status>]`, `/zao project create client <id> <name> [repo <owner/repo>]`, `/zao project rename|repo|status ...`',
        ]);
    }

    private function handleZaoLead(string $args): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao lead list [stage]`, `/zao lead create <company> [website <url>] [email <email>]`, `/zao lead stage <id> <stage>`',
            ]);
        }

        if (preg_match('/^list(?:\s+(new|qualified|proposal|negotiation|won|lost|all))?$/i', $args, $matches)) {
            $stage = $matches[1] ?? null;
            $result = $this->mcpBridge->execute('list-leads', array_filter([
                'stage' => $stage ? strtolower($stage) : null,
                'limit' => 10,
            ], fn ($value) => ! is_null($value)));
            $title = $stage ? ucfirst(strtolower($stage)).' Leads' : 'Leads';

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->leadListBlocks($payload, $title)
            );
        }

        if (preg_match('/^create\s+(.+?)(?:\s+website\s+(https?:\/\/\S+))?(?:\s+email\s+([^\s]+@[^\s]+))?$/i', $args, $matches)) {
            $companyName = trim($matches[1]);
            $result = $this->mcpBridge->execute('create-lead', array_filter([
                'company_name' => $companyName,
                'contact_name' => $companyName,
                'website' => $matches[2] ?? null,
                'contact_email' => $matches[3] ?? null,
            ], fn ($value) => ! is_null($value) && $value !== ''));

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->leadSummaryBlocks($payload, $payload['message'] ?? 'Lead created.'),
                'in_channel'
            );
        }

        if (preg_match('/^stage\s+(\d+)\s+(new|qualified|proposal|negotiation|won|lost)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-lead-stage', [
                'id' => (int) $matches[1],
                'stage' => strtolower($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->leadSummaryBlocks($payload, $payload['message'] ?? 'Lead updated.'),
                'in_channel'
            );
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao lead list [stage]`, `/zao lead create <company> [website <url>] [email <email>]`, `/zao lead stage <id> <stage>`',
        ]);
    }

    private function handleZaoInvoice(string $args): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao invoice list [client <id>] [status <status>]`, `/zao invoice create client <id> item <description> amount <amt> [qty <n>]`',
            ]);
        }

        if (preg_match('/^list\b/i', $args)) {
            preg_match('/\bclient\s+(\d+)\b/i', $args, $clientMatches);
            preg_match('/\bstatus\s+(draft|sent|paid|partial|overdue|cancelled|all)\b/i', $args, $statusMatches);

            $clientId = isset($clientMatches[1]) ? (int) $clientMatches[1] : null;
            $status = $statusMatches[1] ?? null;

            $result = $this->mcpBridge->execute('list-invoices', array_filter([
                'client_id' => $clientId,
                'status' => $status ? strtolower($status) : null,
                'limit' => 10,
            ], fn ($value) => ! is_null($value)));
            $title = $status ? ucfirst(strtolower($status)).' Invoices' : 'Invoices';

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->invoiceListBlocks($payload, $title)
            );
        }

        if (preg_match('/^create\s+client\s+(\d+)\s+item\s+(.+?)\s+amount\s+([0-9]+(?:\.[0-9]{1,2})?)(?:\s+qty\s+([0-9]+(?:\.[0-9]+)?))?$/i', $args, $matches)) {
            $quantity = isset($matches[4]) ? (float) $matches[4] : 1.0;
            $amount = (float) $matches[3];
            $description = trim($matches[2]);

            $result = $this->mcpBridge->execute('create-invoice', [
                'client_id' => (int) $matches[1],
                'items' => [[
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $amount,
                    'type' => 'fixed',
                ]],
                'subject' => $description,
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->invoiceSummaryBlocks($payload, $payload['message'] ?? 'Invoice created.'),
                'in_channel'
            );
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao invoice list [client <id>] [status <status>]`, `/zao invoice create client <id> item <description> amount <amt> [qty <n>]`',
        ]);
    }

    private function handleZaoWebsiteProject(string $args): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao website show <id>`, `/zao website list [status <status>] [type <type>]`, `/zao website create <name> type <type> [domain <domain>] [brief <brief>]`, `/zao website status|domain ...`',
            ]);
        }

        if (preg_match('/^show\s+(\d+)\b/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('get-website-project', [
                'id' => (int) $matches[1],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->websiteProjectSummaryBlocks($payload)
            );
        }

        if (preg_match('/^list\b/i', $args)) {
            preg_match('/\bstatus\s+(created|analyzing|designing|building|reviewing|deploying|complete|failed|all)\b/i', $args, $statusMatches);
            preg_match('/\btype\s+(autonomous|guided|migration|redesign|all)\b/i', $args, $typeMatches);

            $status = $statusMatches[1] ?? null;
            $projectType = $typeMatches[1] ?? null;

            $result = $this->mcpBridge->execute('list-website-projects', array_filter([
                'status' => $status ? strtolower($status) : null,
                'project_type' => $projectType ? strtolower($projectType) : null,
                'limit' => 10,
            ], fn ($value) => ! is_null($value)));
            $title = 'Website Projects';

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->websiteProjectListBlocks($payload, $title)
            );
        }

        if (preg_match('/^create\s+(.+?)\s+type\s+(autonomous|guided|migration|redesign)(?:\s+domain\s+(\S+))?(?:\s+brief\s+(.+))?$/i', $args, $matches)) {
            $domain = $matches[3] ?? null;
            $brief = $matches[4] ?? null;

            $result = $this->mcpBridge->execute('create-website-project', array_filter([
                'name' => trim($matches[1]),
                'project_type' => strtolower($matches[2]),
                'domain' => $domain,
                'brief' => $brief,
                'source_type' => $brief ? 'brief' : ($domain ? 'domain' : 'manual'),
            ], fn ($value) => ! is_null($value) && $value !== ''));

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->websiteProjectSummaryBlocks($payload, $payload['message'] ?? 'Website project created.'),
                'in_channel'
            );
        }

        if (preg_match('/^status\s+(\d+)\s+(created|analyzing|designing|building|reviewing|deploying|complete|failed)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-website-project', [
                'id' => (int) $matches[1],
                'status' => strtolower($matches[2]),
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->websiteProjectSummaryBlocks($payload, $payload['message'] ?? 'Website project updated.'),
                'in_channel'
            );
        }

        if (preg_match('/^domain\s+(\d+)\s+(\S+)$/i', $args, $matches)) {
            $result = $this->mcpBridge->execute('update-website-project', [
                'id' => (int) $matches[1],
                'domain' => $matches[2],
            ]);

            return $this->respondWithSlackToolBlocks(
                $result,
                fn (array $payload): array => $this->responseService->websiteProjectSummaryBlocks($payload, $payload['message'] ?? 'Website project updated.'),
                'in_channel'
            );
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao website show <id>`, `/zao website list [status <status>] [type <type>]`, `/zao website create <name> type <type> [domain <domain>] [brief <brief>]`, `/zao website status|domain ...`',
        ]);
    }

    private function handleZaoOps(string $args, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $prompt = trim($args);

        if ($prompt === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Please provide an ops request: `/zao ops <request>`',
            ]);
        }

        $teamId = (string) $request->input('team_id');
        $workspace = SlackWorkspace::query()->where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        $channel = SlackChannel::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'channel_id' => $channelId,
            ],
            [
                'slack_id' => $channelId,
                'name' => $channelId,
                'channel_name' => $channelId,
                'classification' => 'general',
                'monitoring_enabled' => false,
                'is_monitored' => false,
            ]
        );

        $threadTs = 'slash:'.\Illuminate\Support\Str::uuid();
        $context = SlackThreadContext::findOrCreateForThread($channel, $threadTs, (string) ($workspace->bot_user_id ?? ''));
        $context->addToHistory('user', $prompt);
        $context->update([
            'context_data' => array_merge($context->context_data ?? [], [
                'slack_user_id' => $slackUserId,
                'invoked_via' => 'slash_command',
            ]),
        ]);

        ProcessSlackOpsCommandJob::dispatch(
            contextId: $context->id,
            prompt: $prompt,
            responseUrl: (string) $request->input('response_url')
        );

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => 'Working on it...',
        ]);
    }

    private function handleZaoContext(string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = (string) $request->input('team_id');

        $result = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $teamId,
            'channel_id' => $channelId,
        ]);

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->operationsContextBlocks($result),
        ]);
    }

    private function handleZaoThread(string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = (string) $request->input('team_id');
        $workspace = SlackWorkspace::query()->where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        $channel = SlackChannel::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'channel_id' => $channelId,
            ],
            [
                'slack_id' => $channelId,
                'name' => $channelId,
                'channel_name' => $channelId,
                'classification' => 'general',
                'monitoring_enabled' => false,
                'is_monitored' => false,
            ]
        );

        $threadTs = (string) $request->input('thread_ts', '');
        $context = $this->resolveThreadContext($channel, $threadTs);

        if (! $context) {
            $result = $this->mcpBridge->execute('get-channel-operations-context', [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
            ]);

            if (isset($result['error'])) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ No Slack thread context is stored for this channel yet.',
                ]);
            }

            return response()->json([
                'response_type' => 'ephemeral',
                'blocks' => array_merge(
                    [$this->responseService->section(':information_source: No active Slack thread context is stored for this channel yet.'), $this->responseService->divider()],
                    $this->responseService->operationsContextBlocks($result)
                ),
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->buildThreadSummaryBlocks($context),
        ]);
    }

    private function resolveThreadContext(SlackChannel $channel, string $threadTs = ''): ?SlackThreadContext
    {
        $query = SlackThreadContext::query()->where('channel_id', $channel->id);

        if ($threadTs !== '') {
            return $query->where('thread_ts', $threadTs)->latest('id')->first();
        }

        return $query
            ->orderByRaw("CASE WHEN current_state IN ('processing', 'awaiting_response') THEN 0 ELSE 1 END")
            ->latest('last_interaction_at')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildThreadSummaryBlocks(SlackThreadContext $context, ?string $notice = null): array
    {
        $result = $this->mentionOrchestrator->executeAction($context, [
            'type' => SlackActionType::GetThreadSummary->value,
        ]);

        if (! ($result['success'] ?? false)) {
            return [$this->responseService->section('❌ '.($result['error'] ?? 'Unable to load thread summary.'))];
        }

        $blocks = $this->responseService->threadSummaryBlocks($result);

        if (! $notice) {
            return $blocks;
        }

        return array_merge([
            $this->responseService->section(":white_check_mark: {$notice}"),
            $this->responseService->divider(),
        ], $blocks);
    }

    private function replaceWithThreadSummary(int $contextId, string $responseUrl, ?string $notice = null): void
    {
        if ($responseUrl === '') {
            return;
        }

        $context = SlackThreadContext::query()->find($contextId);

        if (! $context) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Slack thread context not found.',
                'blocks' => [$this->responseService->section('❌ Slack thread context not found.')],
            ]);

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => $notice ?? 'Thread summary updated.',
            'blocks' => $this->buildThreadSummaryBlocks($context, $notice),
        ]);
    }

    private function postThreadSummaryFollowUp(int $contextId, ?string $notice = null): void
    {
        $context = SlackThreadContext::query()
            ->with('channel.workspace')
            ->find($contextId);

        if (! $context || ! $context->channel || ! $context->channel->workspace) {
            return;
        }

        $this->api->postMessage($context->channel->workspace, $context->channel->channel_id, '', [
            'thread_ts' => $context->thread_ts,
            'blocks' => $this->buildThreadSummaryBlocks($context, $notice),
        ]);
    }

    private function handleZaoTasks(string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = (string) $request->input('team_id');
        $status = trim($args);
        $allowedStatuses = ['pending', 'in_progress', 'review', 'completed', 'all'];

        if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Task status must be one of: pending, in_progress, review, completed, all',
            ]);
        }

        $context = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => $teamId,
            'channel_id' => $channelId,
        ]);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$context['error']}",
            ]);
        }

        $projectId = $context['project']['id'] ?? null;

        if (! $projectId) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ This channel is not linked to an active project yet. Link it first with `/zao link client <id> project <id>`.',
            ]);
        }

        $result = $this->mcpBridge->execute('list-tasks', array_filter([
            'project_id' => $projectId,
            'status' => $status !== '' ? $status : null,
            'limit' => 10,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        $projectName = $context['project']['name'] ?? 'Linked Project';
        $heading = $status !== ''
            ? ucfirst(str_replace('_', ' ', $status))." Tasks for {$projectName}"
            : "Tasks for {$projectName}";

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->taskListBlocks($result, $heading),
        ]);
    }

    private function handleZaoApprovals(string $args): \Illuminate\Http\JsonResponse
    {
        $filter = trim($args);
        $status = 'pending';
        $riskLevel = null;

        if ($filter !== '') {
            if (in_array($filter, ['pending', 'approved', 'rejected', 'expired', 'all'], true)) {
                $status = $filter;
            } elseif (in_array($filter, ['low', 'medium', 'high', 'critical'], true)) {
                $riskLevel = $filter;
            } else {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Approval filter must be a status (`pending`, `approved`, `rejected`, `expired`, `all`) or a risk level (`low`, `medium`, `high`, `critical`).',
                ]);
            }
        }

        $result = $this->mcpBridge->execute('list-approval-requests', array_filter([
            'status' => $status,
            'risk_level' => $riskLevel,
            'limit' => 5,
        ]));

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        $title = $riskLevel
            ? ucfirst($riskLevel).' Risk Approvals'
            : ucfirst($status).' Approvals';

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->approvalQueueBlocks($result, $title),
        ]);
    }

    private function handleZaoIntegrations(string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $context = $this->mcpBridge->execute('get-channel-operations-context', [
            'workspace_id' => (string) $request->input('team_id'),
            'channel_id' => $channelId,
        ]);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$context['error']}",
            ]);
        }

        $status = $this->mcpBridge->execute('get-integration-status', []);

        if (isset($status['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$status['error']}",
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->integrationOverviewBlocks($context, $status),
        ]);
    }

    private function handleZaoSync(string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $target = strtolower(trim($args));

        $action = match ($target) {
            'github' => 'sync-github',
            'clickup', 'pm', 'project-management' => 'sync-clickup',
            'harvest' => 'sync-harvest',
            'wordpress', 'wp' => 'sync-wordpress',
            'all' => 'sync-all',
            default => null,
        };

        if (! $action) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao sync <github|clickup|harvest|wordpress|all>`',
            ]);
        }

        $result = $this->mcpBridge->execute('run-channel-integration-action', [
            'workspace_id' => (string) $request->input('team_id'),
            'channel_id' => $channelId,
            'action' => $action,
        ]);

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->integrationActionResultBlocks($result),
        ]);
    }

    private function handleZaoSearch(string $args): \Illuminate\Http\JsonResponse
    {
        $query = trim($args);

        if ($query === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Please provide a search query: `/zao find <query>`',
            ]);
        }

        $result = $this->mcpBridge->execute('search', [
            'query' => $query,
            'limit' => 5,
        ]);

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->searchResultsBlocks($result),
        ]);
    }

    /**
     * @param  callable(array<string, mixed>): array<int, array<string, mixed>>  $blockBuilder
     */
    private function respondWithSlackToolBlocks(array $result, callable $blockBuilder, string $responseType = 'ephemeral'): \Illuminate\Http\JsonResponse
    {
        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        return response()->json([
            'response_type' => $responseType,
            'blocks' => $blockBuilder($result),
        ]);
    }

    private function handleZaoLink(string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao link client <id> [project <id>]` or `/zao link project <id>`',
            ]);
        }

        $clientId = null;
        $projectId = null;

        if (preg_match('/client\s+(\d+)/i', $args, $clientMatches)) {
            $clientId = (int) $clientMatches[1];
        }

        if (preg_match('/project\s+(\d+)/i', $args, $projectMatches)) {
            $projectId = (int) $projectMatches[1];
        }

        if (! $clientId && ! $projectId) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao link client <id> [project <id>]` or `/zao link project <id>`',
            ]);
        }

        $result = $this->mcpBridge->execute('link-slack-context', array_filter([
            'workspace_id' => (string) $request->input('team_id'),
            'channel_id' => $channelId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'monitoring_enabled' => true,
        ], fn ($value) => ! is_null($value)));

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        $clientName = $result['client']['name'] ?? 'Unknown client';
        $projectName = $result['project']['name'] ?? null;
        $summary = "✅ Linked this channel to *{$clientName}*".($projectName ? " / *{$projectName}*" : '').'.';

        return response()->json([
            'response_type' => 'in_channel',
            'blocks' => [
                $this->responseService->section($summary),
                $this->responseService->context([
                    'Monitoring enabled for this channel',
                ]),
            ],
        ]);
    }

    /**
     * Handle /zao task command - create a real task
     */
    private function handleZaoTask(string $description, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $description = trim($description);

        if ($description === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Please provide a task description: `/zao task <description>`',
            ]);
        }

        if (preg_match('/^(show|start|review|complete|run|assign-agent|priority)\s+#?\d+\b/i', $description, $matches)) {
            return $this->handleZaoTaskActionCommand(
                command: strtolower($matches[1]),
                args: trim(substr($description, strlen($matches[1]))),
                channelId: $channelId,
                request: $request
            );
        }

        $context = $this->resolveSlackTaskContext((string) $request->input('team_id'), $channelId);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ '.$context['error'],
            ]);
        }

        $project = $context['project'];

        $task = Task::create([
            'title' => $description,
            'project_id' => $project->id,
            'status' => 'pending',
            'priority' => 'medium',
            'source' => 'manual',
            'position' => ((int) Task::query()->where('project_id', $project->id)->max('position')) + 1,
        ]);

        return response()->json($this->responseService->taskCreatedResponse($task, $project));
    }

    private function handleZaoTaskActionCommand(string $command, string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $context = $this->resolveSlackTaskContext((string) $request->input('team_id'), $channelId);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ '.$context['error'],
            ]);
        }

        $taskId = $this->extractSlackTaskId($args);

        if (! $taskId) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Please specify a task ID, like `/zao task show 123`.',
            ]);
        }

        $task = $this->findScopedSlackTask($taskId, $context['project']->id);

        if (! $task) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ Task #{$taskId} was not found in this channel's linked project.",
            ]);
        }

        return match ($command) {
            'show' => response()->json([
                'response_type' => 'ephemeral',
                'blocks' => $this->responseService->taskDetailBlocks($task),
            ]),
            'start' => $this->updateSlackTaskStatus($task, 'in_progress'),
            'review' => $this->updateSlackTaskStatus($task, 'review'),
            'complete' => $this->updateSlackTaskStatus($task, 'completed'),
            'priority' => $this->updateSlackTaskPriority($task, $args),
            'run', 'assign-agent' => $this->runSlackTaskAgent(
                task: $task,
                args: $args,
                teamId: (string) $request->input('team_id'),
                channelId: $channelId
            ),
            default => response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Unsupported task action.',
            ]),
        };
    }

    /**
     * @return array{project?: Project, error?: string}
     */
    private function resolveSlackTaskContext(string $teamId, string $channelId): array
    {
        $workspace = SlackWorkspace::query()->where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return ['error' => 'Workspace not found. Please reconnect Slack integration.'];
        }

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $channel) {
            return ['error' => 'Slack channel not found in Zao Dash yet. Link the channel first.'];
        }

        $projectResolution = $this->linkedProjectService->resolve($channel);
        $project = $projectResolution['project'] ?? null;

        if (! $project) {
            return ['error' => $projectResolution['error'] ?? 'Linked project could not be found in Zao Dash.'];
        }

        return ['project' => $project];
    }

    private function extractSlackTaskId(string $value): ?int
    {
        if (! preg_match('/#?(\d+)/', $value, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function findScopedSlackTask(int $taskId, int $projectId): ?Task
    {
        return Task::query()
            ->with(['project.client', 'latestAgentTask.agent'])
            ->whereKey($taskId)
            ->where('project_id', $projectId)
            ->first();
    }

    private function updateSlackTaskStatus(Task $task, string $status): \Illuminate\Http\JsonResponse
    {
        $actor = $this->resolveSlackActor();
        $previousStatus = $task->status;

        if ($previousStatus !== $status) {
            $task->update(['status' => $status]);
            TaskComment::logStatusChange($task, $actor?->id, $previousStatus, $status);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->taskDetailBlocks(
                $task->fresh(['project.client', 'latestAgentTask.agent']),
                $previousStatus === $status
                    ? ":information_source: *Task #{$task->id} is already ".str_replace('_', ' ', $status).'.*'
                    : ":white_check_mark: *Task #{$task->id} moved to ".str_replace('_', ' ', $status).'.*'
            ),
        ]);
    }

    private function updateSlackTaskPriority(Task $task, string $args): \Illuminate\Http\JsonResponse
    {
        if (! preg_match('/#?\d+\s+(low|medium|high|urgent)\b/i', $args, $matches)) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao task priority <id> <low|medium|high|urgent>`',
            ]);
        }

        $priority = strtolower($matches[1]);
        $task->update(['priority' => $priority]);

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->taskDetailBlocks(
                $task->fresh(['project.client', 'latestAgentTask.agent']),
                ":white_check_mark: *Task #{$task->id} priority set to {$priority}.*"
            ),
        ]);
    }

    private function runSlackTaskAgent(Task $task, string $args, string $teamId, string $channelId): \Illuminate\Http\JsonResponse
    {
        if ($task->hasActiveAgentTask()) {
            return response()->json([
                'response_type' => 'ephemeral',
                'blocks' => $this->responseService->taskDetailBlocks(
                    $task,
                    ":information_source: *Task #{$task->id} already has an active agent workflow.*"
                ),
            ]);
        }

        preg_match('/#?\d+\s+([a-z0-9_-]+)/i', $args, $matches);
        $agentSlug = strtolower($matches[1] ?? 'dev-agent');

        $agent = Agent::query()
            ->where('slug', $agentSlug)
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ Agent `{$agentSlug}` not found or not active.",
            ]);
        }

        $actor = $this->resolveSlackActor();
        $oldAssigneeId = $task->assigned_to;
        $oldAssigneeType = $task->assignee_type;
        $oldStatus = $task->status;
        $newStatus = 'in_progress';

        $task->update([
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
            'status' => $newStatus,
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

        if ($oldStatus !== $newStatus) {
            TaskComment::logStatusChange($task, $actor?->id, $oldStatus, $newStatus);
        }

        $agentTask = $this->taskAgentService->assignAgentToTask($task, $agent, $actor);
        $this->attachSlackContextToAgentTask($agentTask, $teamId, $channelId);
        $this->queueTaskAgentProcessing();

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->taskDetailBlocks(
                $task->fresh(['project.client', 'latestAgentTask.agent']),
                ":robot_face: *{$agent->name} queued for Task #{$task->id}.*\nAgent task #{$agentTask->id} is ready to run."
            ),
        ]);
    }

    /**
     * Handle /zao log command - create a client note
     */
    private function handleZaoLog(string $note, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        if (empty($note)) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Please provide a note: `/zao log <note>`',
            ]);
        }

        // Get the Slack channel to link it to a client
        $teamId = $request->input('team_id');
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        $channel = SlackChannel::where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        $client = $channel?->client;

        if (! $client) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ No client found for this channel.\n\nPlease link this Slack channel to a client in Zao Dash.",
            ]);
        }

        // For now, we'll use the first user as the note creator
        // In the future, we can map Slack users to dashboard users
        $user = \App\Models\User::first();

        if (! $user) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ No users found in the system.',
            ]);
        }

        // Create the client note
        $clientNote = ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => $note,
        ]);

        return response()->json([
            'response_type' => 'in_channel',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "✅ *Note logged* for client *{$client->name}*",
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "_{$note}_",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Note ID: #{$clientNote->id} • <".config('app.url')."/clients/{$client->id}|View Client>",
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Handle /zao agent command - trigger an agent with optional task
     */
    private function handleZaoAgent(string $args, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        // If no args, list all active agents
        if (empty($args)) {
            $agents = \App\Models\Agent::where('status', 'active')
                ->orderBy('name')
                ->get();

            if ($agents->isEmpty()) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ No active agents found.',
                ]);
            }

            $agentList = $agents->map(function ($agent) {
                return "• `{$agent->slug}` - {$agent->name}";
            })->join("\n");

            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "*Available Agents:*\n\n{$agentList}\n\n*Usage:* `/zao agent <slug> [task description]`",
            ]);
        }

        // Parse agent slug and optional task
        $parts = explode(' ', $args, 2);
        $agentSlug = $parts[0];
        $task = $parts[1] ?? null;

        // Find the agent
        $agent = \App\Models\Agent::where('slug', $agentSlug)
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ Agent `{$agentSlug}` not found or not active.\n\nUse `/zao agent` to see available agents.",
            ]);
        }

        // Check for circuit breaker
        if ($agent->circuit_broken_at) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ Agent `{$agent->name}` is currently unavailable (circuit broken).",
            ]);
        }

        // Get workspace and channel for context
        $teamId = $request->input('team_id');
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();

        $context = [
            'slack' => [
                'channel_id' => $channelId,
                'user_id' => $slackUserId,
                'workspace_id' => $teamId,
            ],
        ];

        // If there's a linked project, add it to context
        if ($workspace) {
            $channel = SlackChannel::where('workspace_id', $workspace->id)
                ->where('channel_id', $channelId)
                ->with('client')
                ->first();

            if ($channel?->client) {
                $context['client'] = [
                    'id' => $channel->client->id,
                    'name' => $channel->client->name,
                ];
            }

            $projectResolution = $channel ? $this->linkedProjectService->resolve($channel) : [];
            $project = $projectResolution['project'] ?? null;
            if ($project) {
                $context['project'] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'github_repo' => $project->github_repo,
                ];
            }
        }

        // Create the agent run
        $run = $agent->runs()->create([
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => $task ?? 'Triggered via Slack /zao command',
            'context' => $context,
            'project_id' => $context['project']['id'] ?? null,
            'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
            'invoked_by' => $slackUserId,
            'started_at' => now(),
        ]);

        if ($this->shouldRunInteractivelyFromSlack($agent, $context)) {
            RunInteractiveAgentJob::dispatch($run);
        } else {
            \App\Jobs\RunAgentJob::dispatch($run);
        }

        // Build response
        $taskDescription = $task ? "\n\n_{$task}_" : '';

        return response()->json([
            'response_type' => 'in_channel',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "🤖 *Agent triggered:* {$agent->name}{$taskDescription}",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Run ID: #{$run->id} • Status: {$run->status} • <".config('app.url')."/agents/{$agent->id}/runs/{$run->id}|View Progress>",
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldRunInteractivelyFromSlack(Agent $agent, array $context = []): bool
    {
        return $agent->slug === 'compound-engineering'
            || isset($context['skill']);
    }

    private function handleZaoIssue(string $args, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $issueNumber = null;
        if (preg_match('/(\d+)/', $args, $matches)) {
            $issueNumber = (int) $matches[1];
        }

        if (! $issueNumber) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao issue <number> [staging|pr] [branch <name>]`',
            ]);
        }

        $deliveryTarget = preg_match('/\b(staging|preview|deploy)\b/i', $args) ? 'staging' : 'pr';
        preg_match('/\b(?:on\s+)?branch\s+([A-Za-z0-9._\/-]+)\b/i', $args, $branchMatches);
        $branchPreference = $branchMatches[1] ?? null;
        $teamId = (string) $request->input('team_id');
        $workspace = SlackWorkspace::query()->where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $channel) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Slack channel not found in Zao Dash yet. Link the channel first.',
            ]);
        }

        $result = $this->engineeringAgentService->startIssueRun(
            workspace: $workspace,
            channel: $channel,
            slackUserId: $slackUserId,
            threadTs: null,
            issueNumber: $issueNumber,
            deliveryTarget: $deliveryTarget,
            branchPreference: $branchPreference,
            requestText: trim($args),
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ '.($result['error'] ?? 'Unable to start issue workflow.'),
            ]);
        }

        $run = $result['run'];
        $mode = $deliveryTarget === 'staging' ? 'staging review flow' : 'PR review flow';
        $approval = $result['approval'] ?? null;
        $message = ($result['requires_approval'] ?? false)
            ? ":shield: *Queued approval for Dev Agent on issue #{$issueNumber}*\n_{$mode}_"
            : ":github: *Started Dev Agent on issue #{$issueNumber}*\n_{$mode}_";
        $contextText = ($result['requires_approval'] ?? false)
            ? "Approval #{$approval['id']} • Run ID: #{$run->id} • Repo: {$result['repo']} • <".config('app.url')."/agents/{$run->agent_id}/runs/{$run->id}|View Progress>"
            : "Run ID: #{$run->id} • Repo: {$result['repo']} • <".config('app.url')."/agents/{$run->agent_id}/runs/{$run->id}|View Progress>";

        return response()->json([
            'response_type' => 'in_channel',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => $contextText,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function handleZaoRuns(string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $context = $this->resolveSlackTaskContext((string) $request->input('team_id'), $channelId);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$context['error']}",
            ]);
        }

        $project = $context['project'];
        $filter = $this->agentRunService->normalizeFilter(trim($args));
        $runs = $this->agentRunService->listProjectRuns($project, $filter);

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $this->responseService->agentRunListBlocks($runs, $project->name, $filter),
        ]);
    }

    private function handleZaoRun(string $args, string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $context = $this->resolveSlackTaskContext((string) $request->input('team_id'), $channelId);

        if (isset($context['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$context['error']}",
            ]);
        }

        $project = $context['project'];
        $args = trim($args);

        if ($args === '') {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Usage: `/zao run show <id>`, `/zao run retry <id>`, or `/zao run cancel <id>`',
            ]);
        }

        if (preg_match('/^(?:show\s+)?#?(\d+)$/i', $args, $matches)
            || preg_match('/^show\s+#?(\d+)$/i', $args, $matches)) {
            $run = $this->agentRunService->findProjectRun($project, (int) $matches[1]);

            if (! $run) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Agent run not found for this project.',
                ]);
            }

            return response()->json([
                'response_type' => 'ephemeral',
                'blocks' => $this->responseService->agentRunDetailBlocks($run),
            ]);
        }

        if (preg_match('/^(?:retry|rerun|restart)\s+#?(\d+)$/i', $args, $matches)) {
            $run = $this->agentRunService->findProjectRun($project, (int) $matches[1]);

            if (! $run) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Agent run not found for this project.',
                ]);
            }

            $newRun = $this->threadRunService->restartRun(
                $run,
                $slackUserId,
                (string) $request->input('team_id'),
                $channelId,
                null,
            );

            return response()->json([
                'response_type' => 'in_channel',
                'blocks' => $this->responseService->agentRunDetailBlocks(
                    $newRun->fresh(['agent']),
                    "Restarted Run #{$run->id} as Run #{$newRun->id}."
                ),
            ]);
        }

        if (preg_match('/^(?:cancel|stop|kill|abort)\s+#?(\d+)$/i', $args, $matches)) {
            $run = $this->agentRunService->findProjectRun($project, (int) $matches[1]);

            if (! $run) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Agent run not found for this project.',
                ]);
            }

            if (! $this->agentRunService->isCancellable($run)) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => "❌ Run #{$run->id} cannot be cancelled from status `{$run->status}`.",
                ]);
            }

            $cancelledRun = $this->agentRunService->cancelRun($run, "Cancelled from Slack by {$slackUserId}");

            return response()->json([
                'response_type' => 'in_channel',
                'blocks' => $this->responseService->agentRunDetailBlocks(
                    $cancelledRun->fresh(['agent']),
                    "Cancelled Run #{$cancelledRun->id}."
                ),
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao run show <id>`, `/zao run retry <id>`, or `/zao run cancel <id>`',
        ]);
    }

    private function handleZaoStaging(string $args, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $command = trim($args);
        $teamId = (string) $request->input('team_id');

        if ($command === '' || preg_match('/^(status|setup)$/i', $command)) {
            $result = $this->stagingWorkflowService->describeChannelStaging($teamId, $channelId);

            if (isset($result['error'])) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => "❌ {$result['error']}",
                ]);
            }

            return response()->json([
                'response_type' => 'ephemeral',
                'blocks' => $this->responseService->stagingStatusBlocks($result),
            ]);
        }

        if (preg_match('/^secret(?:\s+([A-Za-z0-9._-]+))?$/i', $command, $matches)) {
            $triggerId = (string) $request->input('trigger_id', '');

            if ($triggerId === '') {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Slack did not provide a trigger to open the secure secret dialog.',
                ]);
            }

            $prefillSecretName = $matches[1] ?? null;

            return $this->openStagingSecretModal($teamId, $channelId, $triggerId, $prefillSecretName);
        }

        if (preg_match('/^sync$/i', $command)) {
            $result = $this->stagingWorkflowService->syncRequiredSecrets($teamId, $channelId);

            if (isset($result['error'])) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => "❌ {$result['error']}",
                ]);
            }

            return response()->json([
                'response_type' => 'ephemeral',
                'blocks' => $this->responseService->stagingStatusBlocks($result, $result['message'] ?? null),
            ]);
        }

        if (preg_match('/^(publish|deploy)(?:\s+to\s+staging)?$/i', $command)) {
            $result = $this->engineeringApprovalService->publishToStaging($teamId, $channelId);

            if (isset($result['error'])) {
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => "❌ {$result['error']}",
                ]);
            }

            return response()->json([
                'response_type' => 'in_channel',
                'blocks' => $this->responseService->stagingStatusBlocks($result, $result['message'] ?? null),
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => '❌ Usage: `/zao staging`, `/zao staging secret [NAME]`, `/zao staging sync`, or `/zao staging publish`',
        ]);
    }

    /**
     * Handle /zao status command - show system status and health
     */
    private function handleZaoStatus(string $slackUserId, string $channelId, Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = $request->input('team_id');
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        // Get channel info
        $channel = SlackChannel::where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        // Get system metrics
        $activeAgents = \App\Models\Agent::where('status', 'active')->count();
        $runningAgents = \App\Models\AgentRun::where('status', 'running')->count();
        $recentFailures = \App\Models\AgentRun::where('status', 'failed')
            ->where('started_at', '>=', now()->subHours(24))
            ->count();

        $activeProjects = \App\Models\Project::where('status', 'active')->count();
        $pendingTasks = \App\Models\Task::where('status', 'pending')->count();
        $inProgressTasks = \App\Models\Task::where('status', 'in_progress')->count();

        // Check queue health
        $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

        // Determine overall health
        $healthEmoji = '🟢';
        $healthText = 'Healthy';

        if ($failedJobs > 10 || $recentFailures > 5) {
            $healthEmoji = '🔴';
            $healthText = 'Degraded';
        } elseif ($failedJobs > 0 || $recentFailures > 0) {
            $healthEmoji = '🟡';
            $healthText = 'Warning';
        }

        // Build blocks
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => "{$healthEmoji} Zao Dash System Status",
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Health:*\n{$healthText}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Workspace:*\n{$workspace->workspace_name}",
                    ],
                ],
            ],
            [
                'type' => 'divider',
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*🤖 Agents*',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Active Agents:*\n{$activeAgents}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Currently Running:*\n{$runningAgents}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Failed (24h):*\n{$recentFailures}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Failed Jobs:*\n{$failedJobs}",
                    ],
                ],
            ],
            [
                'type' => 'divider',
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*📋 Projects & Tasks*',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Active Projects:*\n{$activeProjects}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Pending Tasks:*\n{$pendingTasks}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*In Progress:*\n{$inProgressTasks}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "\u{200B}",
                    ],
                ],
            ],
        ];

        // Add channel-specific info if available
        if ($channel) {
            $blocks[] = [
                'type' => 'divider',
            ];
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*📡 This Channel*',
                ],
            ];

            $monitoringStatus = $channel->monitoring_enabled ? '✅ Enabled' : '❌ Disabled';
            $clientName = $channel->client ? $channel->client->name : 'Not linked';

            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Monitoring:*\n{$monitoringStatus}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Linked Client:*\n{$clientName}",
                    ],
                ],
            ];
        }

        // Add footer with links
        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => '<'.config('app.url').'/dashboard|Open Dashboard> • <'.config('app.url').'/agents|View Agents> • <'.config('app.url').'/projects|View Projects>',
                ],
            ],
        ];

        return response()->json([
            'response_type' => 'ephemeral',
            'blocks' => $blocks,
        ]);
    }

    /**
     * Handle Slack interactivity (button clicks, modal submissions, message actions).
     */
    public function interactivity(Request $request): \Illuminate\Http\JsonResponse
    {
        if (! $this->verifySlackRequest($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($request->input('payload'), true);

        if (! $payload) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        Log::info('Slack interactivity', [
            'type' => $payload['type'] ?? 'unknown',
            'callback_id' => $payload['callback_id'] ?? $payload['view']['callback_id'] ?? null,
        ]);

        return match ($payload['type'] ?? '') {
            'view_submission' => $this->handleViewSubmission($payload),
            'block_actions' => $this->handleBlockActions($payload),
            'shortcut' => $this->handleShortcut($payload),
            'message_action' => $this->handleMessageAction($payload),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Handle modal view submissions.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleViewSubmission(array $payload): \Illuminate\Http\JsonResponse
    {
        $callbackId = $payload['view']['callback_id'] ?? '';
        $values = $payload['view']['state']['values'] ?? [];
        $userId = $payload['user']['id'] ?? '';
        $teamId = $payload['team']['id'] ?? '';

        return match ($callbackId) {
            'create_task_modal' => $this->handleCreateTaskModal($values, $userId, $teamId, $payload),
            'log_note_modal' => $this->handleLogNoteModal($values, $userId, $teamId, $payload),
            'create_invoice_modal' => $this->handleCreateInvoiceModal($values, $userId, $teamId, $payload),
            'create_lead_modal' => $this->handleCreateLeadModal($values, $userId, $teamId, $payload),
            'staging_secret_modal' => $this->handleStagingSecretModal($values, $userId, $teamId, $payload),
            'interaction_text_response' => $this->handleInteractionTextSubmission($values, $userId, $teamId, $payload),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Handle button clicks and other block actions.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleBlockActions(array $payload): \Illuminate\Http\JsonResponse
    {
        $actions = $payload['actions'] ?? [];
        $userId = $payload['user']['id'] ?? '';
        $channelId = $payload['channel']['id'] ?? '';
        $teamId = $payload['team']['id'] ?? '';

        foreach ($actions as $action) {
            $actionId = $action['action_id'] ?? '';

            match ($actionId) {
                'cancel_action' => null,
                'create_task_from_action_item' => $this->handleCreateTaskFromActionItem($action, $userId, $channelId, $teamId, $payload),
                'dismiss_action_item' => $this->handleDismissActionItem($action, $payload),
                'confirm_agent_trigger' => $this->handleConfirmAgentTrigger($action, $userId, $channelId, $teamId, $payload),
                'confirm_sow_import' => $this->handleSowImportDecision($action, $payload, true),
                'cancel_sow_import' => $this->handleSowImportDecision($action, $payload, false),
                'task_mark_in_progress' => $this->handleTaskStatusAction($action, $teamId, $channelId, $payload, 'in_progress'),
                'task_mark_review' => $this->handleTaskStatusAction($action, $teamId, $channelId, $payload, 'review'),
                'task_mark_completed' => $this->handleTaskStatusAction($action, $teamId, $channelId, $payload, 'completed'),
                'task_run_agent' => $this->handleTaskRunAgentAction($action, $teamId, $channelId, $payload),
                'staging_open_secret_modal' => $this->handleStagingOpenSecretModal($action, $teamId, $channelId, $payload),
                'staging_sync_secrets' => $this->handleStagingSyncSecretsAction($teamId, $channelId, $payload),
                'staging_publish' => $this->handleStagingPublishAction($teamId, $channelId, $payload),
                'thread_request_review_deploy' => $this->handleThreadRequestReviewDeploy($action, $payload),
                'thread_retry_review_deploy' => $this->handleThreadRetryReviewDeploy($action, $payload),
                'thread_retry_run' => $this->handleThreadRetryRun($action, $userId, $teamId, $channelId, $payload),
                'cancel_agent_run' => $this->handleCancelAgentRun($action, $userId, $teamId, $channelId, $payload),
                'approval_approve' => $this->handleApprovalDecision($action, $userId, $payload, true),
                'approval_reject' => $this->handleApprovalDecision($action, $userId, $payload, false),
                'integration_sync_github' => $this->handleIntegrationSyncAction('sync-github', $teamId, $channelId, $payload),
                'integration_sync_clickup' => $this->handleIntegrationSyncAction('sync-clickup', $teamId, $channelId, $payload),
                'integration_sync_harvest' => $this->handleIntegrationSyncAction('sync-harvest', $teamId, $channelId, $payload),
                'integration_sync_wordpress' => $this->handleIntegrationSyncAction('sync-wordpress', $teamId, $channelId, $payload),
                // Agent interaction handlers
                'interaction_respond_yes' => $this->handleInteractionResponse($action, $userId, $teamId, $payload),
                'interaction_respond_no' => $this->handleInteractionResponse($action, $userId, $teamId, $payload),
                'interaction_respond_select' => $this->handleInteractionSelectResponse($action, $userId, $teamId, $payload),
                'interaction_open_modal' => $this->handleInteractionOpenModal($action, $teamId, $payload),
                'interaction_skip' => $this->handleInteractionSkip($action, $payload),
                'rfp_send_proposal' => $this->handleRfpSendProposal($action, $userId, $teamId, $channelId, $payload),
                default => null,
            };
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleTaskStatusAction(array $action, string $teamId, string $channelId, array $payload, string $status): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');
        $task = $this->resolveSlackActionTask($action, $teamId, $channelId);

        if (! $task) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Task not found for this Slack channel.',
                'blocks' => [$this->responseService->section('❌ Task not found for this Slack channel.')],
            ]);

            return;
        }

        $previousStatus = $task->status;
        $actor = $this->resolveSlackActor();

        if ($previousStatus !== $status) {
            $task->update(['status' => $status]);
            TaskComment::logStatusChange($task, $actor?->id, $previousStatus, $status);
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => "Task #{$task->id} updated.",
            'blocks' => $this->responseService->taskDetailBlocks(
                $task->fresh(['project.client', 'latestAgentTask.agent']),
                $previousStatus === $status
                    ? ":information_source: *Task #{$task->id} is already ".str_replace('_', ' ', $status).'.*'
                    : ":white_check_mark: *Task #{$task->id} moved to ".str_replace('_', ' ', $status).'.*'
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleTaskRunAgentAction(array $action, string $teamId, string $channelId, array $payload): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');
        $task = $this->resolveSlackActionTask($action, $teamId, $channelId);

        if (! $task) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Task not found for this Slack channel.',
                'blocks' => [$this->responseService->section('❌ Task not found for this Slack channel.')],
            ]);

            return;
        }

        if ($task->hasActiveAgentTask()) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "Task #{$task->id} already has an active agent workflow.",
                'blocks' => $this->responseService->taskDetailBlocks(
                    $task,
                    ":information_source: *Task #{$task->id} already has an active agent workflow.*"
                ),
            ]);

            return;
        }

        $value = $this->decodeSlackActionValue($action);
        $agentSlug = strtolower((string) ($value['agent_slug'] ?? 'dev-agent'));
        $agent = Agent::query()->where('slug', $agentSlug)->where('status', 'active')->first();

        if (! $agent) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ Agent {$agentSlug} not found.",
                'blocks' => [$this->responseService->section("❌ Agent `{$agentSlug}` not found or not active.")],
            ]);

            return;
        }

        $actor = $this->resolveSlackActor();
        $oldAssigneeId = $task->assigned_to;
        $oldAssigneeType = $task->assignee_type;
        $oldStatus = $task->status;
        $newStatus = 'in_progress';

        $task->update([
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
            'status' => $newStatus,
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

        if ($oldStatus !== $newStatus) {
            TaskComment::logStatusChange($task, $actor?->id, $oldStatus, $newStatus);
        }

        $agentTask = $this->taskAgentService->assignAgentToTask($task, $agent, $actor);
        $threadTs = $payload['container']['thread_ts']
            ?? $payload['message']['thread_ts']
            ?? $payload['container']['message_ts']
            ?? null;
        $this->attachSlackContextToAgentTask($agentTask, $teamId, $channelId, is_string($threadTs) ? $threadTs : null);
        $this->queueTaskAgentProcessing();

        $this->postSlackReplacement($responseUrl, [
            'text' => "Queued {$agent->name} for Task #{$task->id}.",
            'blocks' => $this->responseService->taskDetailBlocks(
                $task->fresh(['project.client', 'latestAgentTask.agent']),
                ":robot_face: *{$agent->name} queued for Task #{$task->id}.*\nAgent task #{$agentTask->id} is ready to run."
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function resolveSlackActionTask(array $action, string $teamId, string $channelId): ?Task
    {
        $value = $this->decodeSlackActionValue($action);
        $taskId = isset($value['task_id']) ? (int) $value['task_id'] : 0;

        if (! $taskId) {
            return null;
        }

        $context = $this->resolveSlackTaskContext($teamId, $channelId);
        $project = $context['project'] ?? null;

        if (! $project) {
            return null;
        }

        return $this->findScopedSlackTask($taskId, $project->id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function decodeSlackActionValue(array $action): array
    {
        $value = $action['value'] ?? '';
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function attachSlackContextToAgentTask(AgentTask $agentTask, string $teamId, string $channelId, ?string $threadTs = null): void
    {
        $context = $agentTask->context ?? [];
        $context['slack'] = array_filter([
            'workspace_id' => $teamId,
            'channel_id' => $channelId,
            'thread_ts' => $threadTs,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $agentTask->update(['context' => $context]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleApprovalDecision(array $action, string $slackUserId, array $payload, bool $approved): void
    {
        $value = $this->decodeSlackActionValue($action);
        $approvalId = (int) ($value['approval_id'] ?? $action['value'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $responseUrl = $payload['response_url'] ?? '';

        if (! $approvalId) {
            return;
        }

        $approval = ApprovalRequest::query()->with(['agentRun.agent', 'decidedBy:id,name'])->find($approvalId);

        if (! $approval) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Approval request not found.',
                'blocks' => [$this->responseService->section('❌ Approval request not found.')],
            ]);

            return;
        }

        try {
            $actor = $this->resolveSlackDecisionActor();
            $note = ($approved ? 'Approved' : 'Rejected')." from Slack by {$slackUserId}";

            $approval = $approved
                ? $this->approvalDecisionService->approve($approval, $actor, $note)
                : $this->approvalDecisionService->reject($approval, $actor, $note);

            if ($contextId) {
                $this->replaceWithThreadSummary(
                    $contextId,
                    $responseUrl,
                    "Approval #{$approval->id} {$approval->status}."
                );

                return;
            }

            $this->postSlackReplacement($responseUrl, [
                'text' => "Approval #{$approval->id} {$approval->status}.",
                'blocks' => $this->responseService->approvalDecisionBlocks($approval),
            ]);
        } catch (\RuntimeException $e) {
            $approval = $approval->fresh(['agentRun.agent', 'decidedBy:id,name']);

            if ($contextId) {
                $this->replaceWithThreadSummary(
                    $contextId,
                    $responseUrl,
                    "Approval #{$approval->id} was already processed."
                );

                return;
            }

            $this->postSlackReplacement($responseUrl, [
                'text' => "Approval #{$approval->id} has already been processed.",
                'blocks' => $this->responseService->approvalDecisionBlocks($approval),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleThreadRetryRun(array $action, string $userId, string $teamId, string $channelId, array $payload): void
    {
        $value = $this->decodeSlackActionValue($action);
        $runId = (int) ($value['run_id'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $responseUrl = (string) ($payload['response_url'] ?? '');

        $run = AgentRun::query()->with('agent')->find($runId);

        if (! $run || ! $run->agent) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Agent run not found.',
                'blocks' => [$this->responseService->section('❌ Agent run not found.')],
            ]);

            return;
        }

        if ($run->agent->status !== 'active' || $run->agent->circuit_broken_at) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ Agent {$run->agent->name} is not available.",
                'blocks' => [$this->responseService->section("❌ Agent {$run->agent->name} is not available.")],
            ]);

            return;
        }

        $newRun = $this->threadRunService->restartRun(
            $run,
            $userId,
            $teamId,
            $channelId,
            $contextId ? SlackThreadContext::query()->find($contextId) : null,
        );

        if ($contextId) {
            $this->replaceWithThreadSummary($contextId, $responseUrl, "Started rerun as Run #{$newRun->id}.");

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => "Started rerun as Run #{$newRun->id}.",
            'blocks' => [
                $this->responseService->section(":robot_face: *Started rerun as Run #{$newRun->id}.*"),
                $this->responseService->context([
                    '<'.config('app.url')."/agents/{$newRun->agent_id}/runs/{$newRun->id}|View Progress>",
                ]),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleCancelAgentRun(array $action, string $userId, string $teamId, string $channelId, array $payload): void
    {
        $value = $this->decodeSlackActionValue($action);
        $runId = (int) ($value['run_id'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $responseUrl = (string) ($payload['response_url'] ?? '');

        $run = null;

        if ($contextId) {
            $threadContext = SlackThreadContext::query()->find($contextId);
            $run = $runId > 0
                ? AgentRun::query()->with('agent')->find($runId)
                : ($threadContext?->agent_run_id ? AgentRun::query()->with('agent')->find($threadContext->agent_run_id) : null);
        } else {
            $context = $this->resolveSlackTaskContext($teamId, $channelId);
            $project = $context['project'] ?? null;

            if ($project) {
                $run = $runId > 0 ? $this->agentRunService->findProjectRun($project, $runId) : null;
            }
        }

        if (! $run) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Agent run not found.',
                'blocks' => [$this->responseService->section('❌ Agent run not found.')],
            ]);

            return;
        }

        if (! $this->agentRunService->isCancellable($run)) {
            $message = "Run #{$run->id} cannot be cancelled from status `{$run->status}`.";

            if ($contextId) {
                $this->replaceWithThreadSummaryFailure($contextId, $responseUrl, $message);

                return;
            }

            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$message}",
                'blocks' => [$this->responseService->section("❌ {$message}")],
            ]);

            return;
        }

        $cancelledRun = $this->agentRunService->cancelRun($run, "Cancelled from Slack by {$userId}");

        if ($contextId) {
            $this->replaceWithThreadSummary($contextId, $responseUrl, "Cancelled Run #{$cancelledRun->id}.");

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => "Cancelled Run #{$cancelledRun->id}.",
            'blocks' => $this->responseService->agentRunDetailBlocks(
                $cancelledRun->fresh(['agent']),
                "Cancelled Run #{$cancelledRun->id}."
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleThreadRequestReviewDeploy(array $action, array $payload): void
    {
        $this->handleThreadGitHubDeployAction(
            $action,
            $payload,
            fn (AgentRun $run): array => $this->engineeringApprovalService->requestReviewDeploy($run)
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleThreadRetryReviewDeploy(array $action, array $payload): void
    {
        $this->handleThreadGitHubDeployAction(
            $action,
            $payload,
            fn (AgentRun $run): array => $this->engineeringApprovalService->retryReviewDeploy($run)
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleThreadGitHubDeployAction(array $action, array $payload, callable $callback): void
    {
        $value = $this->decodeSlackActionValue($action);
        $runId = (int) ($value['run_id'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $responseUrl = (string) ($payload['response_url'] ?? '');

        $run = AgentRun::query()->find($runId);

        if (! $run) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Agent run not found.',
                'blocks' => [$this->responseService->section('❌ Agent run not found.')],
            ]);

            return;
        }

        try {
            $result = $callback($run);

            if (! ($result['success'] ?? false)) {
                $this->replaceWithThreadSummaryFailure(
                    $contextId,
                    $responseUrl,
                    $result['error'] ?? 'Unable to run the GitHub deployment action.'
                );

                return;
            }

            if ($contextId) {
                $this->replaceWithThreadSummary($contextId, $responseUrl, $result['message'] ?? 'GitHub deploy action requested.');

                return;
            }

            $this->postSlackReplacement($responseUrl, [
                'text' => $result['message'] ?? 'GitHub deploy action requested.',
                'blocks' => [$this->responseService->section(':white_check_mark: '.($result['message'] ?? 'GitHub deploy action requested.'))],
            ]);
        } catch (\Throwable $exception) {
            $this->replaceWithThreadSummaryFailure(
                $contextId,
                $responseUrl,
                'GitHub deploy action failed: '.$exception->getMessage()
            );
        }
    }

    private function replaceWithThreadSummaryFailure(?int $contextId, string $responseUrl, string $message): void
    {
        if (! $contextId) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$message}",
                'blocks' => [$this->responseService->section("❌ {$message}")],
            ]);

            return;
        }

        $context = SlackThreadContext::query()->find($contextId);

        if (! $context) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$message}",
                'blocks' => [$this->responseService->section("❌ {$message}")],
            ]);

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => "❌ {$message}",
            'blocks' => array_merge([
                $this->responseService->section("❌ {$message}"),
                $this->responseService->divider(),
            ], $this->buildThreadSummaryBlocks($context)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSlackReplacement(string $responseUrl, array $payload): void
    {
        if ($responseUrl === '') {
            return;
        }

        Http::post($responseUrl, array_merge([
            'replace_original' => true,
        ], $payload));
    }

    private function resolveSlackActor(): ?User
    {
        return User::query()->whereIn('role', ['owner', 'admin'])->first()
            ?? User::query()->first();
    }

    private function resolveSlackDecisionActor(): ?User
    {
        return $this->resolveSlackActor();
    }

    private function queueTaskAgentProcessing(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        ProcessAgentTasksJob::dispatchAfterResponse();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleIntegrationSyncAction(string $action, string $teamId, string $channelId, array $payload): void
    {
        $responseUrl = $payload['response_url'] ?? '';

        $result = $this->mcpBridge->execute('run-channel-integration-action', [
            'workspace_id' => $teamId,
            'channel_id' => $channelId,
            'action' => $action,
        ]);

        if (isset($result['error'])) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$result['error']}",
                'blocks' => [$this->responseService->section("❌ {$result['error']}")],
            ]);

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => $result['message'] ?? 'Integration sync queued.',
            'blocks' => $this->responseService->integrationActionResultBlocks($result),
        ]);
    }

    /**
     * Handle global shortcuts.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleShortcut(array $payload): \Illuminate\Http\JsonResponse
    {
        $callbackId = $payload['callback_id'] ?? '';
        $triggerId = $payload['trigger_id'] ?? '';
        $userId = $payload['user']['id'] ?? '';
        $teamId = $payload['team']['id'] ?? '';

        return match ($callbackId) {
            'create_task_shortcut' => $this->openTaskCreationModal($triggerId, $teamId),
            'log_note_shortcut' => $this->openLogNoteModal($triggerId, $teamId),
            'create_invoice_shortcut' => $this->openCreateInvoiceModal($triggerId, $teamId),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Handle message shortcuts (context menu actions).
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleMessageAction(array $payload): \Illuminate\Http\JsonResponse
    {
        $callbackId = $payload['callback_id'] ?? '';
        $triggerId = $payload['trigger_id'] ?? '';
        $message = $payload['message'] ?? [];
        $channelId = $payload['channel']['id'] ?? '';
        $teamId = $payload['team']['id'] ?? '';

        return match ($callbackId) {
            'create_task_from_message' => $this->openTaskModalFromMessage($triggerId, $message, $channelId, $teamId),
            'log_action_item_from_message' => $this->openLogNoteFromMessage($triggerId, $message, $channelId, $teamId),
            'create_lead_from_message' => $this->openCreateLeadFromMessage($triggerId, $message, $channelId, $teamId),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Handle task creation modal submission.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleCreateTaskModal(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $title = $values['title_block']['task_title']['value'] ?? '';
        $description = $values['description_block']['task_description']['value'] ?? null;
        $projectId = $values['project_block']['project_select']['selected_option']['value'] ?? null;
        $priority = $values['priority_block']['priority_select']['selected_option']['value'] ?? 'medium';
        $dueDate = $values['due_date_block']['due_date']['selected_date'] ?? null;

        if (empty($title) || empty($projectId)) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'title_block' => empty($title) ? 'Title is required' : null,
                    'project_block' => empty($projectId) ? 'Project is required' : null,
                ],
            ]);
        }

        $project = \App\Models\Project::find($projectId);
        if (! $project) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['project_block' => 'Project not found'],
            ]);
        }

        $task = Task::create([
            'title' => $title,
            'description' => $description,
            'project_id' => $project->id,
            'status' => 'pending',
            'priority' => $priority,
            'due_date' => $dueDate,
            'source' => 'slack_modal',
            'position' => Task::where('project_id', $project->id)->max('position') + 1,
        ]);

        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if ($workspace) {
            $channelId = $payload['view']['private_metadata'] ?? null;
            if ($channelId) {
                try {
                    $this->api->postMessage($workspace, $channelId, '', [
                        'blocks' => [
                            [
                                'type' => 'section',
                                'text' => [
                                    'type' => 'mrkdwn',
                                    'text' => "✅ *Task created* in project *{$project->name}*\n_{$title}_",
                                ],
                            ],
                            [
                                'type' => 'context',
                                'elements' => [
                                    [
                                        'type' => 'mrkdwn',
                                        'text' => "Task ID: #{$task->id} • Priority: {$priority} • <".config('app.url')."/projects/{$project->id}|View in Dashboard>",
                                    ],
                                ],
                            ],
                        ],
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to post task creation confirmation to channel', [
                        'channel_id' => $channelId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['response_action' => 'clear']);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleStagingSecretModal(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $secretName = (string) ($values['secret_name_block']['secret_name']['value'] ?? '');
        $secretValue = (string) ($values['secret_value_block']['secret_value']['value'] ?? '');
        $targetScope = (string) ($values['target_scope_block']['target_scope']['selected_option']['value'] ?? 'environment');
        $metadata = json_decode((string) ($payload['view']['private_metadata'] ?? '{}'), true);
        $channelId = (string) ($metadata['channel_id'] ?? '');

        if ($secretName === '' || $secretValue === '') {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'secret_name_block' => $secretName === '' ? 'Secret name is required' : null,
                    'secret_value_block' => $secretValue === '' ? 'Secret value is required' : null,
                ],
            ]);
        }

        if ($channelId === '') {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'secret_name_block' => 'Slack channel context is missing for this modal.',
                ],
            ]);
        }

        $result = $this->stagingWorkflowService->storeSecretFromSlack(
            teamId: $teamId,
            channelId: $channelId,
            secretName: $secretName,
            secretValue: $secretValue,
            targetScope: $targetScope,
        );

        if (isset($result['error'])) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'secret_value_block' => $result['error'],
                ],
            ]);
        }

        return response()->json([
            'response_action' => 'update',
            'view' => $this->responseService->stagingSecretSavedModal($result),
        ]);
    }

    /**
     * Open task creation modal.
     */
    private function openTaskCreationModal(string $triggerId, string $teamId, ?string $channelId = null, ?string $prefillTitle = null): \Illuminate\Http\JsonResponse
    {
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            return response()->json(['ok' => false, 'error' => 'Workspace not found']);
        }

        $projects = \App\Models\Project::where('status', 'active')
            ->orderBy('name')
            ->get()
            ->toArray();

        $responseService = app(\App\Services\Slack\SlackBotResponseService::class);
        $modal = $responseService->taskCreationModal($projects, $prefillTitle);

        if ($channelId) {
            $modal['private_metadata'] = $channelId;
        }

        $response = \Illuminate\Support\Facades\Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Failed to open modal', [
                'error' => $response->json('error') ?? $response->body(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function openStagingSecretModal(
        string $teamId,
        string $channelId,
        string $triggerId,
        ?string $prefillSecretName = null
    ): \Illuminate\Http\JsonResponse {
        $workspace = SlackWorkspace::query()->where('workspace_id', $teamId)->first();

        if (! $workspace) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Workspace not found. Please reconnect Slack integration.',
            ]);
        }

        $result = $this->stagingWorkflowService->describeChannelStaging($teamId, $channelId);

        if (isset($result['error'])) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => "❌ {$result['error']}",
            ]);
        }

        $modal = $this->responseService->stagingSecretModal($result, $prefillSecretName);
        $modal['private_metadata'] = json_encode(array_filter([
            'channel_id' => $channelId,
            'secret_name' => $prefillSecretName,
        ], fn ($value) => $value !== null && $value !== ''));

        $response = Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Failed to open staging secret modal', [
                'error' => $response->json('error') ?? $response->body(),
            ]);

            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Slack could not open the secure secret dialog.',
            ]);
        }

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => 'Opening secure staging secret dialog...',
        ]);
    }

    /**
     * Open task modal pre-filled from a message.
     *
     * @param  array<string, mixed>  $message
     */
    private function openTaskModalFromMessage(string $triggerId, array $message, string $channelId, string $teamId): \Illuminate\Http\JsonResponse
    {
        $messageText = $message['text'] ?? '';
        $truncatedText = strlen($messageText) > 100 ? substr($messageText, 0, 100).'...' : $messageText;

        return $this->openTaskCreationModal($triggerId, $teamId, $channelId, $truncatedText);
    }

    /**
     * Open the Log Note modal (global shortcut).
     */
    private function openLogNoteModal(string $triggerId, string $teamId, ?string $channelId = null, ?string $prefillNote = null): \Illuminate\Http\JsonResponse
    {
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            return response()->json(['ok' => false, 'error' => 'Workspace not found']);
        }

        $clients = \App\Models\Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        if (empty($clients)) {
            return response()->json(['ok' => false, 'error' => 'No active clients found']);
        }

        $responseService = app(SlackBotResponseService::class);
        $modal = $responseService->logNoteModal($clients, $prefillNote);

        if ($channelId) {
            $modal['private_metadata'] = json_encode(['channel_id' => $channelId]);
        }

        Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Open the Log Note modal pre-filled from a message (message shortcut).
     *
     * @param  array<string, mixed>  $message
     */
    private function openLogNoteFromMessage(string $triggerId, array $message, string $channelId, string $teamId): \Illuminate\Http\JsonResponse
    {
        $messageText = $message['text'] ?? '';
        $truncatedText = strlen($messageText) > 500 ? substr($messageText, 0, 500).'...' : $messageText;

        return $this->openLogNoteModal($triggerId, $teamId, $channelId, $truncatedText);
    }

    /**
     * Open the Create Invoice modal (global shortcut).
     */
    private function openCreateInvoiceModal(string $triggerId, string $teamId): \Illuminate\Http\JsonResponse
    {
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            return response()->json(['ok' => false, 'error' => 'Workspace not found']);
        }

        $clients = \App\Models\Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        $projects = \App\Models\Project::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        if (empty($clients)) {
            return response()->json(['ok' => false, 'error' => 'No active clients found']);
        }

        $responseService = app(SlackBotResponseService::class);
        $modal = $responseService->createInvoiceModal($clients, $projects);

        Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Open the Create Lead modal pre-filled from a message (message shortcut).
     *
     * @param  array<string, mixed>  $message
     */
    private function openCreateLeadFromMessage(string $triggerId, array $message, string $channelId, string $teamId): \Illuminate\Http\JsonResponse
    {
        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            return response()->json(['ok' => false, 'error' => 'Workspace not found']);
        }

        $messageText = $message['text'] ?? '';
        $truncatedText = strlen($messageText) > 300 ? substr($messageText, 0, 300).'...' : $messageText;

        $responseService = app(SlackBotResponseService::class);
        $modal = $responseService->createLeadModal(null, $truncatedText);
        $modal['private_metadata'] = json_encode(['channel_id' => $channelId]);

        Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Handle Log Note modal submission.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleLogNoteModal(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $clientId = $values['client_block']['client_select']['selected_option']['value'] ?? null;
        $noteContent = $values['note_block']['note_content']['value'] ?? '';

        if (empty($clientId) || empty($noteContent)) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => array_filter([
                    'client_block' => empty($clientId) ? 'Client is required' : null,
                    'note_block' => empty($noteContent) ? 'Note content is required' : null,
                ]),
            ]);
        }

        $result = $this->mcpBridge->execute('create-client-note', [
            'client_id' => (int) $clientId,
            'content' => $noteContent,
        ]);

        if (isset($result['error'])) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['note_block' => $result['error']],
            ]);
        }

        $metadata = json_decode($payload['view']['private_metadata'] ?? '{}', true);
        $channelId = $metadata['channel_id'] ?? null;

        if ($channelId) {
            $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
            if ($workspace) {
                $clientName = $result['client_name'] ?? 'Unknown';
                $this->api->postMessage($workspace, $channelId, '', [
                    'blocks' => [
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "📝 *Note logged* for client *{$clientName}*",
                            ],
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "_{$noteContent}_",
                            ],
                        ],
                    ],
                ]);
            }
        }

        return response()->json(['response_action' => 'clear']);
    }

    /**
     * Handle Create Invoice modal submission.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleCreateInvoiceModal(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $clientId = $values['client_block']['client_select']['selected_option']['value'] ?? null;
        $projectId = $values['project_block']['project_select']['selected_option']['value'] ?? null;
        $subject = $values['subject_block']['invoice_subject']['value'] ?? '';
        $lineDescription = $values['description_block']['line_description']['value'] ?? '';
        $amount = $values['amount_block']['line_amount']['value'] ?? '';
        $quantity = $values['quantity_block']['line_quantity']['value'] ?? '1';
        $dueDays = $values['due_days_block']['due_days']['value'] ?? '30';

        $errors = [];
        if (empty($clientId)) {
            $errors['client_block'] = 'Client is required';
        }
        if (empty($subject)) {
            $errors['subject_block'] = 'Subject is required';
        }
        if (empty($lineDescription)) {
            $errors['description_block'] = 'Line item description is required';
        }
        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors['amount_block'] = 'Please enter a valid amount';
        }
        if (! empty($errors)) {
            return response()->json(['response_action' => 'errors', 'errors' => $errors]);
        }

        $result = $this->mcpBridge->execute('create-invoice', array_filter([
            'client_id' => (int) $clientId,
            'project_id' => $projectId ? (int) $projectId : null,
            'subject' => $subject,
            'due_days' => (int) $dueDays,
            'items' => [[
                'description' => $lineDescription,
                'quantity' => is_numeric($quantity) ? (float) $quantity : 1.0,
                'unit_price' => (float) $amount,
                'type' => 'fixed',
            ]],
        ], fn ($v) => $v !== null));

        if (isset($result['error'])) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['amount_block' => $result['error']],
            ]);
        }

        return response()->json(['response_action' => 'clear']);
    }

    /**
     * Handle Create Lead modal submission.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleCreateLeadModal(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $companyName = $values['company_block']['company_name']['value'] ?? '';
        $contactName = $values['contact_block']['contact_name']['value'] ?? null;
        $contactEmail = $values['email_block']['contact_email']['value'] ?? null;
        $website = $values['website_block']['lead_website']['value'] ?? null;
        $dealValue = $values['deal_value_block']['deal_value']['value'] ?? null;
        $notes = $values['notes_block']['lead_notes']['value'] ?? null;

        if (empty($companyName)) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['company_block' => 'Company name is required'],
            ]);
        }

        $result = $this->mcpBridge->execute('create-lead', array_filter([
            'company_name' => $companyName,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'website' => $website,
            'description' => $notes,
            'deal_value' => $dealValue && is_numeric($dealValue) ? (float) $dealValue : null,
            'source' => 'slack',
        ], fn ($v) => $v !== null && $v !== ''));

        if (isset($result['error'])) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['company_block' => $result['error']],
            ]);
        }

        $metadata = json_decode($payload['view']['private_metadata'] ?? '{}', true);
        $channelId = $metadata['channel_id'] ?? null;

        if ($channelId) {
            $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
            if ($workspace) {
                $this->api->postMessage($workspace, $channelId, '', [
                    'blocks' => [
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "🎯 *Lead created:* {$companyName}",
                            ],
                        ],
                        [
                            'type' => 'context',
                            'elements' => [
                                [
                                    'type' => 'mrkdwn',
                                    'text' => 'Stage: New • Source: Slack • <'.config('app.url').'/leads|View Leads>',
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        }

        return response()->json(['response_action' => 'clear']);
    }

    /**
     * Handle creating a task from a detected action item.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleCreateTaskFromActionItem(array $action, string $userId, string $channelId, string $teamId, array $payload): void
    {
        $triggerId = $payload['trigger_id'] ?? '';
        if ($triggerId) {
            $this->openTaskCreationModal($triggerId, $teamId, $channelId);
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleStagingOpenSecretModal(array $action, string $teamId, string $channelId, array $payload): void
    {
        $triggerId = (string) ($payload['trigger_id'] ?? '');
        $responseUrl = (string) ($payload['response_url'] ?? '');
        $value = $this->decodeSlackActionValue($action);
        $prefillSecretName = isset($value['secret_name']) ? (string) $value['secret_name'] : null;

        if ($triggerId === '') {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Slack did not provide a trigger to open the secure secret dialog.',
                'blocks' => [$this->responseService->section('❌ Slack did not provide a trigger to open the secure secret dialog.')],
            ]);

            return;
        }

        $response = $this->openStagingSecretModal($teamId, $channelId, $triggerId, $prefillSecretName);
        $payload = $response->getData(true);

        if (($payload['response_type'] ?? null) === 'ephemeral' && str_starts_with((string) ($payload['text'] ?? ''), '❌')) {
            $this->postSlackReplacement($responseUrl, [
                'text' => (string) $payload['text'],
                'blocks' => [$this->responseService->section((string) $payload['text'])],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleStagingSyncSecretsAction(string $teamId, string $channelId, array $payload): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');
        $result = $this->stagingWorkflowService->syncRequiredSecrets($teamId, $channelId);

        if (isset($result['error'])) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$result['error']}",
                'blocks' => [$this->responseService->section("❌ {$result['error']}")],
            ]);

            return;
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => $result['message'] ?? 'Synced staging secrets.',
            'blocks' => $this->responseService->stagingStatusBlocks($result, $result['message'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleStagingPublishAction(string $teamId, string $channelId, array $payload): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');
        $threadContext = $this->resolveSlackThreadContextFromPayload($teamId, $channelId, $payload);
        $result = $this->engineeringApprovalService->publishToStaging($teamId, $channelId, $threadContext);

        if (isset($result['error'])) {
            $this->postSlackReplacement($responseUrl, [
                'text' => "❌ {$result['error']}",
                'blocks' => [$this->responseService->section("❌ {$result['error']}")],
            ]);

            return;
        }

        if ($threadContext && ! ($result['approval_required'] ?? false)) {
            $this->stagingThreadService->rememberPublishedThread($threadContext, $result);
        }

        $this->postSlackReplacement($responseUrl, [
            'text' => $result['message'] ?? 'Queued staging publish.',
            'blocks' => $this->responseService->stagingStatusBlocks($result, $result['message'] ?? null),
        ]);
    }

    /**
     * Handle dismissing an action item suggestion.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleDismissActionItem(array $action, array $payload): void
    {
        $responseUrl = $payload['response_url'] ?? '';
        if ($responseUrl) {
            \Illuminate\Support\Facades\Http::post($responseUrl, [
                'delete_original' => true,
            ]);
        }
    }

    /**
     * Handle confirming an agent trigger from a button.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleConfirmAgentTrigger(array $action, string $userId, string $channelId, string $teamId, array $payload): void
    {
        $value = json_decode($action['value'] ?? '{}', true);
        $agentSlug = $value['agent_slug'] ?? '';
        $task = $value['task'] ?? null;
        $issueNumber = isset($value['issue_number']) ? (int) $value['issue_number'] : null;
        $deliveryTarget = $value['delivery_target'] ?? 'pr';
        $branchPreference = $value['branch_preference'] ?? null;

        if (! $agentSlug) {
            return;
        }

        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();

        if ($issueNumber && $workspace) {
            $channel = SlackChannel::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel_id', $channelId)
                ->first();

            if ($channel) {
                $threadTs = $payload['container']['thread_ts'] ?? $payload['message']['thread_ts'] ?? $payload['message']['ts'] ?? null;
                $result = $this->engineeringAgentService->startIssueRun(
                    workspace: $workspace,
                    channel: $channel,
                    slackUserId: $userId,
                    threadTs: $threadTs,
                    issueNumber: $issueNumber,
                    deliveryTarget: $deliveryTarget,
                    branchPreference: $branchPreference,
                    requestText: $task,
                );

                $responseUrl = $payload['response_url'] ?? '';
                if ($responseUrl) {
                    $text = ($result['success'] ?? false)
                        ? "🤖 *Started Dev Agent on issue #{$issueNumber}*"
                        : '❌ '.($result['error'] ?? 'Unable to start issue workflow.');

                    Http::post($responseUrl, [
                        'replace_original' => true,
                        'blocks' => [
                            [
                                'type' => 'section',
                                'text' => [
                                    'type' => 'mrkdwn',
                                    'text' => $text,
                                ],
                            ],
                            [
                                'type' => 'context',
                                'elements' => [
                                    [
                                        'type' => 'mrkdwn',
                                        'text' => ($result['success'] ?? false)
                                            ? "Run ID: #{$result['run']->id} • Repo: {$result['repo']} • <".config('app.url')."/agents/{$result['run']->agent_id}/runs/{$result['run']->id}|View Progress>"
                                            : 'Issue workflow was not started.',
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }

                return;
            }
        }

        $agent = \App\Models\Agent::where('slug', $agentSlug)->where('status', 'active')->first();
        if (! $agent) {
            return;
        }

        $context = [
            'slack' => [
                'channel_id' => $channelId,
                'user_id' => $userId,
                'workspace_id' => $teamId,
            ],
        ];

        if ($workspace) {
            $channel = SlackChannel::where('workspace_id', $workspace->id)
                ->where('channel_id', $channelId)
                ->first();

            $project = $channel?->client?->projects()->where('status', 'active')->first();
            if ($project) {
                $context['project'] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'github_repo' => $project->github_repo,
                ];
            }
        }

        $run = $agent->runs()->create([
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => $task ?? 'Triggered via Slack confirmation',
            'context' => $context,
            'project_id' => $context['project']['id'] ?? null,
            'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
            'invoked_by' => $userId,
            'started_at' => now(),
        ]);

        \App\Jobs\RunAgentJob::dispatch($run);

        $responseUrl = $payload['response_url'] ?? '';
        if ($responseUrl) {
            \Illuminate\Support\Facades\Http::post($responseUrl, [
                'replace_original' => true,
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "🤖 *Agent triggered:* {$agent->name}",
                        ],
                    ],
                    [
                        'type' => 'context',
                        'elements' => [
                            [
                                'type' => 'mrkdwn',
                                'text' => "Run ID: #{$run->id} • Status: running • <".config('app.url')."/agents/{$agent->id}/runs/{$run->id}|View Progress>",
                            ],
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * Handle confirmation or cancellation of a pending SOW import.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleSowImportDecision(array $action, array $payload, bool $approved): void
    {
        $value = $this->decodeSlackActionValue($action);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $pendingActionId = (string) ($value['pending_action_id'] ?? '');
        $responseUrl = (string) ($payload['response_url'] ?? '');

        if (! $contextId || $pendingActionId === '') {
            return;
        }

        $context = SlackThreadContext::query()
            ->with('channel.workspace')
            ->find($contextId);

        if (! $context) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ Slack thread context not found.',
                'blocks' => [$this->responseService->section('❌ Slack thread context not found.')],
            ]);

            return;
        }

        $pendingAction = collect($context->pending_actions ?? [])
            ->first(fn (array $pending): bool => ($pending['id'] ?? '') === $pendingActionId && ($pending['type'] ?? '') === SlackActionType::ImportSow->value);

        if (! $pendingAction) {
            $this->postSlackReplacement($responseUrl, [
                'text' => '❌ SOW import preview not found.',
                'blocks' => [$this->responseService->section('❌ SOW import preview not found.')],
            ]);

            return;
        }

        if (! $approved) {
            $context->markActionCompleted($pendingActionId, ['cancelled' => true]);
            $context->update(['current_state' => 'idle']);

            $this->postSlackReplacement($responseUrl, [
                'text' => 'SOW import cancelled.',
                'blocks' => [$this->responseService->section(':no_entry_sign: SOW import cancelled.')],
            ]);

            return;
        }

        $context->update(['current_state' => 'processing']);

        // Immediately acknowledge the confirm click, then provision asynchronously
        $this->postSlackReplacement($responseUrl, [
            'text' => 'Provisioning SOW...',
            'blocks' => [$this->responseService->section(':rocket: *SOW confirmed* — provisioning client, project, milestones, tasks, and invoices. This may take a moment...')],
        ]);

        $actionData = array_merge($pendingAction['data'] ?? [], [
            'type' => SlackActionType::ImportSow->value,
        ]);

        dispatch(function () use ($context, $actionData, $pendingActionId) {
            $orchestrator = app(\App\Services\Slack\SlackMentionOrchestrator::class);
            $responseService = app(\App\Services\Slack\SlackBotResponseService::class);

            $result = $orchestrator->executeAction($context, $actionData);

            $context->markActionCompleted($pendingActionId, $result);
            $context->update(['current_state' => 'idle']);

            if ($result['success']) {
                $orchestrator->sendBlockResponse($context, $responseService->sowImportResultBlocks($result, $result['message'] ?? 'SOW imported.'));
            } else {
                $orchestrator->sendResponse($context, ':x: '.($result['error'] ?? 'Unable to import the SOW.'));
            }
        })->onQueue('slack-mentions');
    }

    /**
     * Handle Yes/No button click responses for agent interactions.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleInteractionResponse(array $action, string $userId, string $teamId, array $payload): void
    {
        $actionId = $action['action_id'] ?? '';
        $value = $this->decodeSlackActionValue($action);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;

        $interactionId = (int) ($value['interaction_id'] ?? 0);
        $response = (string) ($value['response'] ?? ($actionId === 'interaction_respond_yes' ? 'yes' : 'no'));

        if (! $interactionId) {
            $rawValue = (string) ($action['value'] ?? '');
            $parts = explode(':', $rawValue, 2);
            $interactionId = (int) ($parts[0] ?? 0);
            $response = $parts[1] ?? $response;
        }

        if (! $interactionId) {
            Log::warning('Slack interaction: Missing interaction ID', ['action' => $action]);

            return;
        }

        $this->submitInteractionResponse($interactionId, $response, $userId, $teamId, $payload, $contextId);
    }

    /**
     * Handle select menu responses for agent interactions.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleInteractionSelectResponse(array $action, string $userId, string $teamId, array $payload): void
    {
        $selectedOption = $action['selected_option'] ?? null;
        if (! $selectedOption) {
            Log::warning('Slack interaction: No option selected', ['action' => $action]);

            return;
        }

        $decoded = json_decode((string) ($selectedOption['value'] ?? ''), true);
        $interactionId = (int) ($decoded['interaction_id'] ?? 0);
        $response = (string) ($decoded['response'] ?? '');
        $contextId = isset($decoded['context_id']) ? (int) $decoded['context_id'] : null;

        if (! $interactionId || $response === '') {
            $value = (string) ($selectedOption['value'] ?? '');
            $parts = explode(':', $value, 2);
            $interactionId = (int) ($parts[0] ?? 0);
            $response = $parts[1] ?? '';
        }

        if (! $interactionId || empty($response)) {
            Log::warning('Slack interaction: Invalid select value', ['value' => $selectedOption['value'] ?? null]);

            return;
        }

        $this->submitInteractionResponse($interactionId, $response, $userId, $teamId, $payload, $contextId);
    }

    /**
     * Handle opening the text input modal for agent interactions.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleInteractionOpenModal(array $action, string $teamId, array $payload): void
    {
        $value = $this->decodeSlackActionValue($action);
        $interactionId = (int) ($value['interaction_id'] ?? $action['value'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $triggerId = $payload['trigger_id'] ?? '';

        if (! $interactionId || ! $triggerId) {
            Log::warning('Slack interaction: Missing data for modal', [
                'interaction_id' => $interactionId,
                'has_trigger' => ! empty($triggerId),
            ]);

            return;
        }

        $interaction = InteractionRequest::find($interactionId);
        if (! $interaction || $interaction->isExpired() || $interaction->isResponded()) {
            $responseUrl = $payload['response_url'] ?? '';
            if ($contextId) {
                $notice = ! $interaction
                    ? 'Interaction not found.'
                    : ($interaction->isResponded() ? 'This interaction was already answered.' : 'This interaction expired.');

                $this->replaceWithThreadSummary($contextId, $responseUrl, $notice);

                return;
            }

            if ($responseUrl) {
                Http::post($responseUrl, [
                    'replace_original' => true,
                    ...($interaction?->isResponded()
                        ? $this->responseService->interactionAlreadyRespondedMessage()
                        : $this->responseService->interactionExpiredMessage()),
                ]);
            }

            return;
        }

        $workspace = SlackWorkspace::where('workspace_id', $teamId)->first();
        if (! $workspace) {
            return;
        }

        $modal = $this->responseService->interactionTextInputModal($interaction, $contextId);

        $response = \Illuminate\Support\Facades\Http::withToken($workspace->access_token)
            ->post('https://slack.com/api/views.open', [
                'trigger_id' => $triggerId,
                'view' => $modal,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Failed to open interaction text modal', [
                'error' => $response->json('error') ?? $response->body(),
            ]);
        }
    }

    /**
     * Handle skip/dismiss button for agent interactions.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function handleInteractionSkip(array $action, array $payload): void
    {
        $value = $this->decodeSlackActionValue($action);
        $interactionId = (int) ($value['interaction_id'] ?? $action['value'] ?? 0);
        $contextId = isset($value['context_id']) ? (int) $value['context_id'] : null;
        $responseUrl = $payload['response_url'] ?? '';

        if ($contextId) {
            $this->replaceWithThreadSummary($contextId, $responseUrl, 'Interaction skipped.');

            Log::info('Slack interaction: Skipped', ['interaction_id' => $interactionId]);

            return;
        }

        if ($responseUrl) {
            // Update the message to show skipped state
            \Illuminate\Support\Facades\Http::post($responseUrl, [
                'replace_original' => true,
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => '⏭️ *Interaction skipped*',
                        ],
                    ],
                    [
                        'type' => 'context',
                        'elements' => [
                            [
                                'type' => 'mrkdwn',
                                'text' => 'The agent will continue without your input or timeout.',
                            ],
                        ],
                    ],
                ],
            ]);
        }

        Log::info('Slack interaction: Skipped', ['interaction_id' => $interactionId]);
    }

    /**
     * Handle text input modal submission for agent interactions.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $payload
     */
    private function handleInteractionTextSubmission(array $values, string $userId, string $teamId, array $payload): \Illuminate\Http\JsonResponse
    {
        $textValue = $values['response_block']['response_input']['value'] ?? '';
        $privateMetadata = json_decode($payload['view']['private_metadata'] ?? '{}', true);
        $interactionId = (int) ($privateMetadata['interaction_id'] ?? 0);
        $contextId = isset($privateMetadata['context_id']) ? (int) $privateMetadata['context_id'] : null;
        $actor = $this->resolveSlackActor();

        if (! $interactionId || empty($textValue)) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'response_block' => empty($textValue) ? 'Response is required' : 'Invalid interaction',
                ],
            ]);
        }

        $interaction = InteractionRequest::find($interactionId);
        if (! $interaction) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'response_block' => 'Interaction not found',
                ],
            ]);
        }

        if ($interaction->isExpired()) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'response_block' => 'This interaction has expired',
                ],
            ]);
        }

        if ($interaction->isResponded()) {
            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'response_block' => 'This interaction has already been answered',
                ],
            ]);
        }

        // Use row-level locking to prevent race conditions
        try {
            $result = DB::transaction(function () use ($interaction, $textValue, $actor) {
                $lockedInteraction = InteractionRequest::lockForUpdate()->find($interaction->id);

                if ($lockedInteraction->isResponded()) {
                    return ['already_responded' => true];
                }

                if ($lockedInteraction->isExpired()) {
                    return ['expired' => true];
                }

                $lockedInteraction->update([
                    'response' => $textValue,
                    'responded_at' => now(),
                    'responded_via' => 'slack_modal',
                    'responded_by_id' => $actor?->id,
                ]);

                // Dispatch job to resume the agent
                RunInteractiveAgentJob::dispatch($lockedInteraction->agentRun, [], $textValue);

                // Broadcast to other listeners (dashboard)
                broadcast(new InteractionResponseReceived($lockedInteraction));

                return ['success' => true, 'interaction' => $lockedInteraction];
            });

            if ($result['already_responded'] ?? false) {
                return response()->json([
                    'response_action' => 'errors',
                    'errors' => [
                        'response_block' => 'This interaction was already answered',
                    ],
                ]);
            }

            if ($result['expired'] ?? false) {
                return response()->json([
                    'response_action' => 'errors',
                    'errors' => [
                        'response_block' => 'This interaction has expired',
                    ],
                ]);
            }

            Log::info('Slack interaction: Text response submitted', [
                'interaction_id' => $interactionId,
                'user' => $userId,
            ]);

            if ($contextId) {
                $this->postThreadSummaryFollowUp($contextId, 'Interaction response submitted.');
            }

            return response()->json(['response_action' => 'clear']);
        } catch (\Exception $e) {
            Log::error('Slack interaction: Failed to submit text response', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'response_action' => 'errors',
                'errors' => [
                    'response_block' => 'Failed to submit response. Please try again.',
                ],
            ]);
        }
    }

    /**
     * Submit an interaction response with row-level locking.
     *
     * @param  array<string, mixed>  $payload
     */
    private function submitInteractionResponse(
        int $interactionId,
        string $response,
        string $userId,
        string $teamId,
        array $payload,
        ?int $contextId = null
    ): void {
        $responseUrl = $payload['response_url'] ?? '';
        $actor = $this->resolveSlackActor();

        try {
            $result = DB::transaction(function () use ($interactionId, $response, $actor) {
                $interaction = InteractionRequest::lockForUpdate()->find($interactionId);

                if (! $interaction) {
                    return ['error' => 'not_found'];
                }

                if ($interaction->isResponded()) {
                    return ['already_responded' => true, 'interaction' => $interaction];
                }

                if ($interaction->isExpired()) {
                    return ['expired' => true];
                }

                $interaction->update([
                    'response' => $response,
                    'responded_at' => now(),
                    'responded_via' => 'slack',
                    'responded_by_id' => $actor?->id,
                ]);

                // Dispatch job to resume the agent
                RunInteractiveAgentJob::dispatch($interaction->agentRun, [], $response);

                // Broadcast to other listeners (dashboard)
                broadcast(new InteractionResponseReceived($interaction));

                return ['success' => true, 'interaction' => $interaction];
            });

            // Update the Slack message
            if ($responseUrl) {
                if ($contextId) {
                    if (($result['error'] ?? null) === 'not_found') {
                        $this->postSlackReplacement($responseUrl, [
                            'text' => '❌ Interaction not found.',
                            'blocks' => [$this->responseService->section('❌ Interaction not found.')],
                        ]);

                        return;
                    }

                    if ($result['expired'] ?? false) {
                        $this->replaceWithThreadSummary($contextId, $responseUrl, 'This interaction expired.');

                        return;
                    }

                    if ($result['already_responded'] ?? false) {
                        $this->replaceWithThreadSummary($contextId, $responseUrl, 'This interaction was already answered.');

                        return;
                    }

                    $this->replaceWithThreadSummary($contextId, $responseUrl, 'Interaction response recorded.');

                    return;
                }

                if (($result['error'] ?? null) === 'not_found') {
                    Http::post($responseUrl, [
                        'replace_original' => true,
                        'blocks' => [
                            [
                                'type' => 'section',
                                'text' => [
                                    'type' => 'mrkdwn',
                                    'text' => '❌ *Interaction not found*',
                                ],
                            ],
                        ],
                    ]);

                    return;
                }

                if ($result['expired'] ?? false) {
                    Http::post($responseUrl, [
                        'replace_original' => true,
                        ...$this->responseService->interactionExpiredMessage(),
                    ]);

                    return;
                }

                if ($result['already_responded'] ?? false) {
                    Http::post($responseUrl, [
                        'replace_original' => true,
                        ...$this->responseService->interactionAlreadyRespondedMessage(),
                    ]);

                    return;
                }

                // Success - show acknowledgment
                Http::post($responseUrl, [
                    'replace_original' => true,
                    ...$this->responseService->interactionResponseAcknowledged($result['interaction'], $response),
                ]);
            }

            Log::info('Slack interaction: Response submitted', [
                'interaction_id' => $interactionId,
                'response' => $response,
                'user' => $userId,
            ]);
        } catch (\Exception $e) {
            Log::error('Slack interaction: Failed to submit response', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
            ]);

            if ($responseUrl) {
                Http::post($responseUrl, [
                    'replace_original' => true,
                    'blocks' => [
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => '❌ *Failed to submit response*',
                            ],
                        ],
                        [
                            'type' => 'context',
                            'elements' => [
                                [
                                    'type' => 'mrkdwn',
                                    'text' => 'Please try again or respond via the dashboard.',
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSlackThreadContextFromPayload(string $teamId, string $channelId, array $payload): ?SlackThreadContext
    {
        $threadTs = $payload['container']['thread_ts']
            ?? $payload['message']['thread_ts']
            ?? $payload['message']['ts']
            ?? null;

        if (! is_string($threadTs) || $threadTs === '') {
            return null;
        }

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $teamId)
            ->first();

        if (! $workspace) {
            return null;
        }

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $channel) {
            return null;
        }

        return SlackThreadContext::findOrCreateForThread($channel, $threadTs, (string) ($workspace->bot_user_id ?? ''));
    }

    /**
     * Send an RFP proposal in response to the Slack "Send Proposal" button.
     *
     * Mirrors RfpController::sendProposalEmail without requiring the web UI:
     * generates the PDF if needed, mails it to the opportunity's
     * submission_email, and updates statuses.
     */
    private function handleRfpSendProposal(array $action, string $userId, string $teamId, string $channelId, array $payload): void
    {
        $proposalId = (int) ($action['value'] ?? 0);

        $proposal = \App\Models\RfpProposal::with('opportunity')->find($proposalId);

        if (! $proposal || ! $proposal->opportunity) {
            Log::warning('SlackWebhook: rfp_send_proposal — proposal not found', ['proposal_id' => $proposalId]);

            return;
        }

        $opportunity = $proposal->opportunity;

        if (! $opportunity->submission_email) {
            Log::warning('SlackWebhook: rfp_send_proposal — no submission email', ['proposal_id' => $proposalId]);

            return;
        }

        try {
            app(\App\Services\Rfp\RfpProposalSender::class)->send($proposal, $opportunity);

            Log::info('SlackWebhook: proposal sent via Slack button', [
                'proposal_id' => $proposal->id,
                'submission_email' => $opportunity->submission_email,
                'triggered_by_slack_user' => $userId,
            ]);
        } catch (\Throwable $e) {
            Log::error('SlackWebhook: rfp_send_proposal failed', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
