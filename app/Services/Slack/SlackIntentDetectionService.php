<?php

namespace App\Services\Slack;

use App\Enums\SlackActionType;
use App\Services\Agents\CompoundEngineeringSkillLoader;

class SlackIntentDetectionService
{
    public function __construct(
        private CompoundEngineeringSkillLoader $skillLoader,
    ) {}

    /**
     * Detect user intent from message using regex patterns.
     * Returns null if no intent could be determined.
     */
    public function detectIntent(string $message): ?array
    {
        // Check for Compound Engineering skill invocations first
        $skillInvocation = $this->skillLoader->parseInvocation($message);
        if ($skillInvocation['matched']) {
            return [
                'type' => SlackActionType::CompoundEngineering->value,
                'skill' => $skillInvocation['skill'],
                'args' => $skillInvocation['args'],
            ];
        }

        // Also check for "run /workflows:*" or "execute /lfg" patterns
        if (preg_match('/(?:run|execute|start)\s+(\/[\w:]+)(?:\s+(.+))?/i', $message, $matches)) {
            $command = $matches[1] ?? '';
            $args = $matches[2] ?? null;

            $parsed = $this->skillLoader->parseInvocation($command);
            if ($parsed['matched']) {
                return [
                    'type' => SlackActionType::CompoundEngineering->value,
                    'skill' => $parsed['skill'],
                    'args' => $args,
                ];
            }
        }

        $normalizedMessage = strtolower($message);
        $threadAction = $this->extractThreadAction($message);

        if ($threadAction !== null) {
            return $threadAction;
        }

        $channelOpsAction = $this->extractChannelOpsAction($message);

        if ($channelOpsAction !== null) {
            return $channelOpsAction;
        }

        $entityReadAction = $this->extractEntityReadAction($message);

        if ($entityReadAction !== null) {
            return $entityReadAction;
        }

        $watchlistAction = $this->extractWatchlistAction($message);

        if ($watchlistAction !== null) {
            return $watchlistAction;
        }

        $crudAction = $this->extractCrudAction($message);

        if ($crudAction !== null) {
            return $crudAction;
        }

        $taskAction = $this->extractTaskAction($message);

        if ($taskAction !== null) {
            return $taskAction;
        }

        $githubIssueReference = $this->extractGitHubIssueReference($message);

        if ($githubIssueReference !== null) {
            return [
                'type' => SlackActionType::TriggerEngineeringAgent->value,
                'agent_slug' => 'dev-agent',
                'issue_number' => $githubIssueReference['issue_number'],
                'delivery_target' => $this->extractEngineeringDeliveryTarget($normalizedMessage),
                'branch_preference' => $this->extractBranchPreference($normalizedMessage),
                'source_pr_number' => $this->extractSourcePullRequestNumber($message),
                'use_thread_pr' => $this->referencesCurrentPullRequest($message),
                'task' => trim($message),
            ];
        }

        if (preg_match('/create\s+(a\s+)?task\s*(to|for|:)?\s*(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::CreateTask->value,
                'title' => trim($matches[3]),
                'priority' => $this->extractPriority($normalizedMessage),
            ];
        }

        if (preg_match('/log\s+(a\s+)?(note|that)\s*:?\s*(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::LogNote->value,
                'content' => trim($matches[3]),
            ];
        }

        if (preg_match('/run\s+(the\s+)?([a-z0-9-]+)\s*(agent)?\s*(on|to|for|:)?\s*(.*)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::TriggerAgent->value,
                'agent_slug' => trim($matches[2]),
                'task' => ! empty($matches[5]) ? trim($matches[5]) : null,
            ];
        }

        if (preg_match('/trigger\s+(the\s+)?([a-z0-9-]+)\s*(agent)?\s*(.*)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::TriggerAgent->value,
                'agent_slug' => trim($matches[2]),
                'task' => ! empty($matches[4]) ? trim($matches[4]) : null,
            ];
        }

        // Email/inbox searches should fall through to control plane which has search-emails tool
        if (preg_match('/\b(email|inbox|gmail|mail)\b/i', $message) && preg_match('/\b(search|find|look|check|review|scan)\b/i', $message)) {
            return null; // Let control plane handle with search-emails tool
        }

        // RFP-related requests should fall through to control plane which has RFP tools
        if (preg_match('/\b(rfp|rfps|proposal|bid|procurement)\b/i', $message) && preg_match('/\b(scan|find|search|review|check|aggregate)\b/i', $message)) {
            return null; // Let control plane handle with RFP tools
        }

        if (preg_match('/(find|search|look\s+for|show\s+me)\s+(all\s+)?(tasks?|projects?|clients?|everything)?\s*(about|for|with|containing|related\s+to)?\s*(.+)/i', $message, $matches)) {
            $searchType = match (strtolower($matches[3] ?? '')) {
                'task', 'tasks' => 'tasks',
                'project', 'projects' => 'projects',
                'client', 'clients' => 'clients',
                default => 'all',
            };

            return [
                'type' => SlackActionType::Search->value,
                'query' => trim($matches[5]),
                'search_type' => $searchType,
            ];
        }

        if (preg_match('/\bwhat\s+should\s+i\s+(?:work|focus)\s+on\s+(?:today|right\s+now)\b/i', $message)
            || preg_match('/\bwhat\s+needs\s+my\s+attention\b/i', $message)
            || preg_match('/\b(?:show|give)\s+me\s+(?:my\s+)?(?:focus|priorities|briefing)\b/i', $message)
            || preg_match('/\bwhat\s+are\s+my\s+top\s+priorities\b/i', $message)
            || preg_match('/\bhelp\s+me\s+prioriti[sz]e\s+(?:today|right\s+now)\b/i', $message)
            || preg_match('/\bwhat(?:\'s| is)\s+most\s+important\s+(?:today|right\s+now)\b/i', $message)
            || preg_match('/\bwhere\s+should\s+i\s+focus\b/i', $message)
            || preg_match('/\bwhat\s+should\s+we\s+tackle\s+next\b/i', $message)) {
            return [
                'type' => SlackActionType::GetFocus->value,
                'priority_filter' => str_contains($normalizedMessage, 'critical')
                    ? 'critical'
                    : (str_contains($normalizedMessage, 'high') ? 'high' : 'all'),
            ];
        }

        if (preg_match('/(what\'?s?\s+(the\s+)?)?(status|progress|update|how\'?s?\s+it\s+going)/i', $message)) {
            return [
                'type' => SlackActionType::GetStatus->value,
            ];
        }

        return null;
    }

    /**
     * Detect watchlist management requests.
     *
     * @return array<string, mixed>|null
     */
    private function extractWatchlistAction(string $message): ?array
    {
        $normalizedMessage = strtolower(trim($message));

        if ($normalizedMessage === '') {
            return null;
        }

        if (preg_match('/\b(?:what(?:\'?s| is)\s+on\s+my\s+watchlist|what am i (?:tracking|watching)|what(?:\'?s| is)\s+my\s+watchlist|show(?: me)?(?: my)? (?:watchlist|tracked channels|watched channels)|list(?: my)? (?:watchlist|tracked channels|watched channels))\b/i', $message)) {
            return [
                'type' => SlackActionType::ListWatchlist->value,
            ];
        }

        if (preg_match('/^\s*(?:can you|could you|please|would you|will you)?\s*(track|watch|follow|remember|add)\s+(.+)$/i', $message, $matches)
            || preg_match('/^\s*add\s+(.+)\s+to\s+(?:my\s+)?watchlist\s*$/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageWatchlist->value,
                'operation' => 'track',
                'query' => trim($matches[2] ?? $matches[1]),
            ];
        }

        if (preg_match('/^\s*(?:can you|could you|please|would you|will you)?\s*(untrack|unwatch|forget|stop tracking|remove)\s+(.+)$/i', $message, $matches)
            || preg_match('/^\s*remove\s+(.+)\s+from\s+(?:my\s+)?watchlist\s*$/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageWatchlist->value,
                'operation' => 'untrack',
                'query' => trim($matches[2] ?? $matches[1]),
            ];
        }

        if (preg_match('/^\s*clear\s+(?:my\s+)?watchlist\s*$/i', $message)) {
            return [
                'type' => SlackActionType::ManageWatchlist->value,
                'operation' => 'clear',
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractThreadAction(string $message): ?array
    {
        $prNumber = $this->extractPullRequestNumber($message);

        if (preg_match('/\b(?:promote|deploy|ship|send)\s+(?:this\s+)?(?:pr|pull\s+request|branch|fix|change)(?:\s+#?\d+)?\s+(?:to|into)\s+staging\b/i', $message)
            || preg_match('/\b(?:redeploy|deploy)\s+(?:the\s+|this\s+)?review\s+build\b/i', $message)
            || preg_match('/\b(?:request|start|run)\s+(?:a\s+)?review\s+deploy\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::RequestReviewDeploy->value,
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:retry|rerun)\s+(?:the\s+|this\s+)?(?:review\s+deploy|review\s+build|deploy|workflow)\b/i', $message)
            || preg_match('/\bretry\s+(?:the\s+)?staging\s+deploy\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::RetryReviewDeploy->value,
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if ((preg_match('/\b(?:cancel|stop|kill|abort)\s+(?:the\s+|this\s+)?(?:current\s+|active\s+)?(?:run|agent\s+run|agent)\b/i', $message))
            && ! preg_match('/\b(?:run|agent\s+run)\s+#?\d+\b/i', $message)) {
            return [
                'type' => SlackActionType::CancelAgentRun->value,
            ];
        }

        if (preg_match('/\b(?:rerun|retry|restart|reopen)\s+(?:the\s+|this\s+)?(?:failed\s+)?(?:run|agent\s+run|agent)\b/i', $message)
            || preg_match('/\brun\s+(?:it|that)\s+again\b/i', $message)) {
            return [
                'type' => SlackActionType::RetryAgentRun->value,
            ];
        }

        if (preg_match('/\b(?:open|show)\s+(?:the\s+|this\s+)?pr\b/i', $message)
            || preg_match('/\bopen\s+pull\s+request\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::GetThreadSummary->value,
                'focus' => 'pull_request',
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:open|show)\s+(?:the\s+|this\s+)?review\s+build\b/i', $message)
            || preg_match('/\bopen\s+(?:the\s+)?preview\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::GetThreadSummary->value,
                'focus' => 'review_build',
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:open|show)\s+(?:the\s+|this\s+)?(?:workflow|ci|actions?)\b/i', $message)
            || preg_match('/\bopen\s+github\s+actions\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::GetThreadSummary->value,
                'focus' => 'workflow',
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:what(?:\'s| is)|show|check|give me)\s+(?:the\s+)?(?:deploy|deployment|review\s+deploy|review\s+build|staging)\s+status(?:\s+(?:on|for)\s+(?:this\s+)?thread)?\b/i', $message)
            || preg_match('/\bis\s+(?:the\s+)?(?:deploy|review\s+build|staging)\s+(?:ready|done|deployed)\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::GetThreadSummary->value,
                'focus' => 'deploy',
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:what\s+happened\s+in|show(?:\s+me)?|give\s+me|what(?:\'s| is))\s+(?:the\s+)?(?:last\s+|latest\s+)?(?:ci|workflow|checks?)\b/i', $message)
            || preg_match('/\b(?:ci|workflow)\s+status\b/i', $message)) {
            return array_filter([
                'type' => SlackActionType::GetThreadSummary->value,
                'focus' => preg_match('/\b(fail(?:ed|ure)?|broken|error)\b/i', $message) ? 'workflow_failure' : 'workflow',
                'pr_number' => $prNumber,
            ], fn ($value) => ! is_null($value));
        }

        if (preg_match('/\b(?:show|summari[sz]e|give me|what(?:\'s| is))\s+(?:this\s+)?thread(?:\'s)?\s+(?:status|summary|context)\b/i', $message)
            || preg_match('/\bwhat\s+(?:are|is)\s+(?:we|this\s+thread)\s+(?:working\s+on|doing)\b/i', $message)
            || preg_match('/\bwhere\s+is\s+this\s+thread\s+at\b/i', $message)) {
            return [
                'type' => SlackActionType::GetThreadSummary->value,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractChannelOpsAction(string $message): ?array
    {
        if (preg_match('/\b(?:show|check|summari[sz]e|what(?:\'s| is)|give me)\s+(?:the\s+)?(?:staging|deploy)\s+(?:setup|status|readiness|workflow)\b/i', $message)
            || preg_match('/\bwhat\s+secrets?\s+(?:are\s+)?missing\s+(?:for\s+)?staging\b/i', $message)
            || preg_match('/\bhow\s+ready\s+is\s+(?:this\s+project|staging)\b/i', $message)) {
            return [
                'type' => SlackActionType::GetStagingStatus->value,
            ];
        }

        if (preg_match('/\b(?:add|save|set|store)\s+(?:secret\s+)?([A-Za-z0-9_][A-Za-z0-9_-]*)\s+(?:for|to)\s+staging\b/i', $message, $matches)
            || preg_match('/\b(?:add|save|set|store)\s+(?:the\s+)?staging\s+secret\s+([A-Za-z0-9_][A-Za-z0-9_-]*)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::PrepareStagingSecret->value,
                'secret_name' => $matches[1],
            ];
        }

        if (preg_match('/\b(?:sync|push|upload|refresh)\s+(?:the\s+)?staging\s+secrets\b/i', $message)
            || preg_match('/\bsync\s+secrets?\s+(?:to|with)\s+github\b/i', $message)) {
            return [
                'type' => SlackActionType::SyncStagingSecrets->value,
            ];
        }

        if (preg_match('/\b(?:publish|deploy|ship|launch)\s+(?:this\s+)?(?:project|site|repo|repository|app)\s+(?:to|into)\s+staging\b/i', $message)
            || preg_match('/\b(?:publish|deploy|ship|launch)\s+to\s+staging\b/i', $message)) {
            return [
                'type' => SlackActionType::PublishStaging->value,
            ];
        }

        if (preg_match('/\b(?:show|what(?:\'s| is)|display|give me)\s+(?:this\s+channel(?:\'s)?\s+)?(?:ops\s+)?context\b/i', $message)
            || preg_match('/\bwhat\s+(?:client|project)\s+is\s+this\s+channel\s+(?:for|linked\s+to)\b/i', $message)) {
            return [
                'type' => SlackActionType::GetChannelContext->value,
            ];
        }

        if (preg_match('/\b(?:show|what(?:\'s| is)|display|check)\s+(?:the\s+)?integrations\b/i', $message)
            || preg_match('/\bwhat\s+(?:repos|sites|integrations)\s+are\s+linked\b/i', $message)) {
            return [
                'type' => SlackActionType::GetIntegrations->value,
            ];
        }

        if (preg_match('/\b(?:sync|refresh)\s+(github|clickup|pm|project\s+management|harvest|wordpress|wp|all|integrations)\b/i', $message, $matches)) {
            $target = strtolower($matches[1]);

            return [
                'type' => SlackActionType::RunIntegrationSync->value,
                'target' => match ($target) {
                    'pm', 'project management' => 'clickup',
                    'wp' => 'wordpress',
                    'integrations' => 'all',
                    default => $target,
                },
            ];
        }

        $clientId = null;
        $projectId = null;

        if (preg_match('/\blink\s+(?:this\s+)?channel\s+to\s+client\s+(\d+)(?:\s+project\s+(\d+))?\b/i', $message, $matches)) {
            $clientId = (int) $matches[1];
            $projectId = isset($matches[2]) ? (int) $matches[2] : null;
        } elseif (preg_match('/\blink\s+(?:this\s+)?channel\s+to\s+project\s+(\d+)\b/i', $message, $matches)) {
            $projectId = (int) $matches[1];
        }

        if ($clientId || $projectId) {
            return [
                'type' => SlackActionType::LinkContext->value,
                'client_id' => $clientId,
                'project_id' => $projectId,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractCrudAction(string $message): ?array
    {
        if (preg_match('/\bcreate\s+lead\s+(.+)/i', $message, $matches)) {
            $tail = trim($matches[1]);
            $website = null;
            $contactEmail = null;

            if (preg_match('/\s+with\s+email\s+([^\s]+@[^\s]+)/i', $tail, $emailMatches)) {
                $contactEmail = $emailMatches[1];
                $tail = trim(str_replace($emailMatches[0], '', $tail));
            }

            if (preg_match('/\s+with\s+website\s+(https?:\/\/\S+)/i', $tail, $websiteMatches)) {
                $website = $websiteMatches[1];
                $tail = trim(str_replace($websiteMatches[0], '', $tail));
            }

            $companyName = $tail;

            return [
                'type' => SlackActionType::CreateLead->value,
                'company_name' => $companyName,
                'contact_name' => $companyName,
                'website' => $website,
                'contact_email' => $contactEmail,
            ];
        }

        if (preg_match('/\bmove\s+lead\s+#?(\d+)\s+to\s+(new|qualified|proposal|negotiation|won|lost)\b/i', $message, $matches)
            || preg_match('/\bmark\s+lead\s+#?(\d+)\s+as\s+(new|qualified|proposal|negotiation|won|lost)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateLeadStage->value,
                'id' => (int) $matches[1],
                'stage' => strtolower($matches[2]),
            ];
        }

        if (preg_match('/\bcreate\s+invoice\s+for\s+client\s+(\d+)\s+item\s+(.+?)\s+amount\s+([0-9]+(?:\.[0-9]{1,2})?)(?:\s+qty\s+([0-9]+(?:\.[0-9]+)?))?\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::CreateInvoice->value,
                'client_id' => (int) $matches[1],
                'subject' => trim($matches[2]),
                'items' => [[
                    'description' => trim($matches[2]),
                    'quantity' => isset($matches[4]) ? (float) $matches[4] : 1.0,
                    'unit_price' => (float) $matches[3],
                    'type' => 'fixed',
                ]],
            ];
        }

        if (preg_match('/\bcreate\s+website\s+project\s+(.+?)\s+type\s+(autonomous|guided|migration|redesign)(?:\s+domain\s+(\S+))?(?:\s+brief\s+(.+))?$/i', $message, $matches)) {
            $brief = $matches[4] ?? null;
            $domain = $matches[3] ?? null;

            return [
                'type' => SlackActionType::CreateWebsiteProject->value,
                'name' => trim($matches[1]),
                'project_type' => strtolower($matches[2]),
                'domain' => $domain,
                'brief' => $brief,
                'source_type' => $brief ? 'brief' : ($domain ? 'domain' : 'manual'),
            ];
        }

        if (preg_match('/\bset\s+website\s+project\s+#?(\d+)\s+status\s+to\s+(created|analyzing|designing|building|reviewing|deploying|complete|failed)\b/i', $message, $matches)
            || preg_match('/\bmark\s+website\s+project\s+#?(\d+)\s+as\s+(created|analyzing|designing|building|reviewing|deploying|complete|failed)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateWebsiteProject->value,
                'id' => (int) $matches[1],
                'status' => strtolower($matches[2]),
            ];
        }

        if (preg_match('/\bset\s+website\s+project\s+#?(\d+)\s+domain\s+to\s+(\S+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateWebsiteProject->value,
                'id' => (int) $matches[1],
                'domain' => $matches[2],
            ];
        }

        if (preg_match('/\bcreate\s+client\s+(.+)/i', $message, $matches)) {
            $tail = trim($matches[1]);
            $website = null;

            if (preg_match('/\s+with\s+website\s+(https?:\/\/\S+)/i', $tail, $websiteMatches)) {
                $website = $websiteMatches[1];
                $tail = trim(str_replace($websiteMatches[0], '', $tail));
            }

            return [
                'type' => SlackActionType::CreateClient->value,
                'name' => $tail,
                'website' => $website,
            ];
        }

        if (preg_match('/\brename\s+client\s+(\d+)\s+to\s+(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateClient->value,
                'id' => (int) $matches[1],
                'name' => trim($matches[2]),
            ];
        }

        if (preg_match('/\bset\s+client\s+(\d+)\s+website\s+to\s+(https?:\/\/\S+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateClient->value,
                'id' => (int) $matches[1],
                'website' => $matches[2],
            ];
        }

        if (preg_match('/\bmark\s+client\s+(\d+)\s+as\s+(active|inactive|prospect|churned|archived)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateClient->value,
                'id' => (int) $matches[1],
                'status' => strtolower($matches[2]),
            ];
        }

        if (preg_match('/\bcreate\s+project\s+(.+?)\s+for\s+client\s+(\d+)(?:\s+with\s+github\s+repo\s+([a-z0-9._-]+\/[a-z0-9._-]+))?\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::CreateProject->value,
                'name' => trim($matches[1]),
                'client_id' => (int) $matches[2],
                'github_repo' => $matches[3] ?? null,
            ];
        }

        if (preg_match('/\brename\s+project\s+(\d+)\s+to\s+(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateProject->value,
                'id' => (int) $matches[1],
                'name' => trim($matches[2]),
            ];
        }

        if (preg_match('/\bset\s+project\s+(\d+)\s+(?:github\s+repo|repo)\s+to\s+([a-z0-9._-]+\/[a-z0-9._-]+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateProject->value,
                'id' => (int) $matches[1],
                'github_repo' => $matches[2],
            ];
        }

        if (preg_match('/\bmark\s+project\s+(\d+)\s+as\s+(active|on[_ -]?hold|completed|archived)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::UpdateProject->value,
                'id' => (int) $matches[1],
                'status' => str_replace([' ', '-'], '_', strtolower($matches[2])),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractEntityReadAction(string $message): ?array
    {
        if (preg_match('/\bshow\s+run\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ShowAgentRun->value,
                'run_id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\b(?:cancel|stop|kill|abort)\s+run\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::CancelAgentRun->value,
                'run_id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\blist(?:\s+(active|running|awaiting_input|pending_approval|failed|completed|cancelled|all))?\s+(?:agent\s+)?runs\b/i', $message, $matches)
            || preg_match('/\bshow\s+(?:me\s+)?(?:the\s+)?(?:agent\s+)?(active|running|awaiting_input|pending_approval|failed|completed|cancelled|all)?\s*runs\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ListAgentRuns->value,
                'filter' => isset($matches[1]) && $matches[1] !== '' ? strtolower($matches[1]) : null,
            ];
        }

        if (preg_match('/\blist(?:\s+(new|qualified|proposal|negotiation|won|lost|all))?\s+leads\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ListLeads->value,
                'stage' => isset($matches[1]) ? strtolower($matches[1]) : null,
            ];
        }

        if (preg_match('/\blist(?:\s+(draft|sent|paid|partial|overdue|cancelled|all))?\s+invoices(?:\s+for\s+client\s+(\d+))?\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ListInvoices->value,
                'status' => isset($matches[1]) ? strtolower($matches[1]) : null,
                'client_id' => isset($matches[2]) ? (int) $matches[2] : null,
            ];
        }

        if (preg_match('/\bshow\s+website\s+project\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ShowWebsiteProject->value,
                'id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\blist(?:\s+website)?\s+projects?(?:\s+status\s+(created|analyzing|designing|building|reviewing|deploying|complete|failed|all))?(?:\s+type\s+(autonomous|guided|migration|redesign|all))?\b/i', $message, $matches)
            && str_contains(strtolower($message), 'website')) {
            return [
                'type' => SlackActionType::ListWebsiteProjects->value,
                'status' => isset($matches[1]) ? strtolower($matches[1]) : null,
                'project_type' => isset($matches[2]) ? strtolower($matches[2]) : null,
            ];
        }

        if (preg_match('/\bshow\s+client\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ShowClient->value,
                'id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\blist(?:\s+(active|inactive|prospect|churned|archived|all))?\s+clients\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ListClients->value,
                'status' => isset($matches[1]) ? strtolower($matches[1]) : null,
            ];
        }

        if (preg_match('/\bshow\s+project\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ShowProject->value,
                'id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\blist(?:\s+(active|completed|on_hold|archived|all))?\s+projects(?:\s+for\s+client\s+(\d+))?\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ListProjects->value,
                'status' => isset($matches[1]) ? strtolower($matches[1]) : null,
                'client_id' => isset($matches[2]) ? (int) $matches[2] : null,
            ];
        }

        $sowUrls = $this->extractGoogleDocUrls($message);
        if ($sowUrls !== [] && (
            preg_match('/\b(?:sow|statement of work|proposal|scope of work|approved docs?)\b/i', $message)
            || preg_match('/\b(?:import|spin\s*up|set\s*up|setup|create|provision|launch|kick\s*off|onboard)\b/i', $message)
        )) {
            return [
                'type' => SlackActionType::ImportSow->value,
                'google_doc_urls' => $sowUrls,
                'link_to_channel' => (bool) preg_match('/\blink\s+(?:this\s+)?channel\b/i', $message),
                'create_invoices' => true,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractTaskAction(string $message): ?array
    {
        if (preg_match('/\b(?:show|open|view)\s+task\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'show',
                'task_id' => (int) $matches[1],
            ];
        }

        if (preg_match('/\b(?:start|begin|resume)\s+task\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'set_status',
                'task_id' => (int) $matches[1],
                'status' => 'in_progress',
            ];
        }

        if (preg_match('/\b(?:review)\s+task\s+#?(\d+)\b/i', $message, $matches)
            || preg_match('/\bmove\s+task\s+#?(\d+)\s+to\s+review\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'set_status',
                'task_id' => (int) $matches[1],
                'status' => 'review',
            ];
        }

        if (preg_match('/\b(?:complete|finish)\s+task\s+#?(\d+)\b/i', $message, $matches)
            || preg_match('/\bmark\s+task\s+#?(\d+)\s+(?:as\s+)?(?:complete|completed|done)\b/i', $message, $matches)
            || preg_match('/\bmove\s+task\s+#?(\d+)\s+to\s+completed\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'set_status',
                'task_id' => (int) $matches[1],
                'status' => 'completed',
            ];
        }

        if (preg_match('/\b(?:set|make)\s+task\s+#?(\d+)\s+(?:to\s+)?(low|medium|high|urgent)\s+priority\b/i', $message, $matches)
            || preg_match('/\btask\s+#?(\d+)\s+(?:is\s+)?(low|medium|high|urgent)\s+priority\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'set_priority',
                'task_id' => (int) $matches[1],
                'priority' => strtolower($matches[2]),
            ];
        }

        if (preg_match('/\brun\s+(?:the\s+)?([a-z0-9-]+)(?:\s+agent)?\s+(?:on|for)\s+task\s+#?(\d+)\b/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'run_agent',
                'task_id' => (int) $matches[2],
                'agent_slug' => strtolower($matches[1]),
            ];
        }

        if (preg_match('/\b(?:run|work|take)\s+task\s+#?(\d+)\b(?:\s+with\s+([a-z0-9-]+))?/i', $message, $matches)) {
            return [
                'type' => SlackActionType::ManageTask->value,
                'operation' => 'run_agent',
                'task_id' => (int) $matches[1],
                'agent_slug' => strtolower($matches[2] ?? 'dev-agent'),
            ];
        }

        return null;
    }

    /**
     * @return array{issue_number: int}|null
     */
    private function extractGitHubIssueReference(string $message): ?array
    {
        if (preg_match('#github\.com/[^/\s]+/[^/\s]+/issues/(\d+)#i', $message, $matches)) {
            return ['issue_number' => (int) $matches[1]];
        }

        if (preg_match('/\bissue\s+#?(\d+)\b/i', $message, $matches)) {
            return ['issue_number' => (int) $matches[1]];
        }

        return null;
    }

    private function extractPullRequestNumber(string $message): ?int
    {
        if (preg_match('#github\.com/[^/\s]+/[^/\s]+/pull/(\d+)#i', $message, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\b(?:pr|pull\s+request)\s+#?(\d+)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractSourcePullRequestNumber(string $message): ?int
    {
        if (! preg_match('/\bfrom\s+(?:pr|pull\s+request)\b/i', $message)) {
            return null;
        }

        return $this->extractPullRequestNumber($message);
    }

    private function referencesCurrentPullRequest(string $message): bool
    {
        return (bool) preg_match('/\bfrom\s+this\s+(?:pr|pull\s+request)\b/i', $message);
    }

    private function extractEngineeringDeliveryTarget(string $message): string
    {
        if (preg_match('/\b(staging|preview|deploy|deployed|review env|review environment|develop|dev branch|staging branch)\b/i', $message)) {
            return 'staging';
        }

        return 'pr';
    }

    private function extractBranchPreference(string $message): ?string
    {
        if (preg_match('/\b(?:staging|preview)\s+branch\b/i', $message)) {
            return 'staging';
        }

        if (preg_match('/\b(?:develop|dev)\s+branch\b/i', $message)) {
            return 'develop';
        }

        if (preg_match('/\b(?:on|to|into)\s+branch\s+([a-z0-9._\/-]+)/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractGoogleDocUrls(string $message): array
    {
        preg_match_all('#https?://[^\s<>()]+#i', $message, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $url): string => rtrim($url, ".,)]}\'\""))
            ->filter(fn (string $url): bool => str_contains($url, 'docs.google.com') || str_contains($url, 'drive.google.com'))
            ->values()
            ->all();
    }

    /**
     * Extract priority from message text.
     */
    public function extractPriority(string $message): string
    {
        if (preg_match('/\b(urgent|critical|asap)\b/i', $message)) {
            return 'urgent';
        }
        if (preg_match('/\bhigh\s*(priority)?\b/i', $message)) {
            return 'high';
        }
        if (preg_match('/\blow\s*(priority)?\b/i', $message)) {
            return 'low';
        }

        return 'medium';
    }

    /**
     * Check if an action requires user confirmation before execution.
     */
    public function requiresConfirmation(array $action): bool
    {
        $type = $action['type'] ?? null;

        // Support both string and enum types
        if ($type instanceof SlackActionType) {
            return in_array($type, [
                SlackActionType::TriggerAgent,
                SlackActionType::TriggerEngineeringAgent,
                SlackActionType::CompoundEngineering,
                SlackActionType::ImportSow,
            ], true);
        }

        return in_array($type, [
            SlackActionType::TriggerAgent->value,
            SlackActionType::TriggerEngineeringAgent->value,
            SlackActionType::CompoundEngineering->value,
            SlackActionType::ImportSow->value,
        ], true);
    }
}
