<?php

namespace App\Services\Reports;

use App\Models\AgentRun;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Email;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\RetainerPeriod;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Models\TimeEntry;
use App\Services\GitHub\GitHubApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetainerHealthService
{
    /**
     * Compute full health snapshot for a retainer period.
     *
     * @param  bool  $persist  Whether to write results back to retainer_periods row.
     * @return array{
     *   human_hours: float,
     *   human_hours_by_type: array<string, float>,
     *   meeting_hours: float,
     *   meeting_count: int,
     *   agent_cost_usd: float,
     *   agent_tasks_completed: int,
     *   agent_equivalent_hours: float,
     *   total_equivalent_hours: float,
     *   hours_budget: float,
     *   hours_remaining: float,
     *   usage_percent: float,
     *   is_over_budget: bool,
     *   cost_to_serve: float,
     *   monthly_amount: float,
     *   effective_margin_percent: float|null,
     *   health_status: string,
     *   last_client_activity_at: string|null,
     *   alerts: array<int, array{type: string, message: string, action: string|null}>,
     * }
     */
    public function computeAndPersistSnapshot(
        RetainerPeriod $retainer,
        Carbon $periodStart,
        Carbon $periodEnd,
        bool $persist = true,
        bool $lite = false,
    ): array {
        // Normalize bounds. Date-cast columns parse to 00:00:00 — without
        // this, whereBetween misses everything after midnight on period_end.
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->endOfDay();

        $humanHoursData = $this->aggregateHumanHours($retainer, $periodStart, $periodEnd);
        $meetingData = $this->aggregateMeetings($retainer, $periodStart, $periodEnd);
        $agentData = $this->aggregateAgentRuns($retainer, $periodStart, $periodEnd);

        // Lite mode skips the GitHub API + email/Slack queries (heavy) for
        // trend computations and other multi-period scans.
        $activityData = $lite
            ? ['github' => ['prs_merged' => 0, 'prs_opened' => 0, 'issues_closed' => 0, 'merged_pull_requests' => [], 'commits' => 0, 'recent_commits' => [], 'commits_by_branch' => []], 'email' => ['inbound_count' => 0, 'outbound_count' => 0, 'threads' => 0, 'action_required_count' => 0, 'top_subjects' => []], 'slack' => array_merge(['total_messages' => 0, 'external_messages' => 0, 'internal_messages' => 0, 'channels' => []], $this->emptySlackSla()), 'tasks' => ['completed_count' => 0, 'completed' => []]]
            : $this->aggregatePeriodActivity($retainer, $periodStart, $periodEnd);

        // Provenance precedence:
        //   1. Manual time entries exist → 'tracked' (highest fidelity).
        //   2. AI-narrative entries exist → 'narrative' (LLM analysis of the
        //      period's actual Slack + email + commits).
        //   3. Nothing logged → fall back to heuristic 'estimated' totals.
        $estimatedHoursData = null;
        $humanHours = $humanHoursData['total'];
        if ($humanHours > 0) {
            $humanHoursSource = $humanHoursData['has_manual'] ? 'tracked' : 'narrative';
        } elseif ($lite) {
            // Skip the heavy heuristic estimation in lite mode.
            $humanHoursSource = 'estimated';
        } else {
            $estimatedHoursData = $this->aggregateEstimatedHours($retainer, $periodStart, $periodEnd);
            $humanHours = $estimatedHoursData['total_hours'];
            $humanHoursSource = 'estimated';
        }
        $agentCostUsd = $agentData['cost_usd'];
        $aiEquivRate = (float) $retainer->ai_equivalent_hourly_rate ?: 50.0;
        $agentEquivHours = $aiEquivRate > 0 ? round($agentCostUsd / $aiEquivRate, 2) : 0;
        $totalEquivHours = round($humanHours + $agentEquivHours, 2);

        $internalRate = (float) $retainer->internal_hourly_rate ?: 250.0;
        $costToServe = round(($humanHours * $internalRate) + $agentCostUsd, 2);
        $monthlyAmount = (float) ($retainer->monthly_amount ?? 0);
        $marginPercent = $monthlyAmount > 0
            ? round((($monthlyAmount - $costToServe) / $monthlyAmount) * 100, 2)
            : null;

        $hoursBudget = $retainer->total_hours;
        $usagePercent = $hoursBudget > 0
            ? round(($totalEquivHours / $hoursBudget) * 100, 1)
            : 0;

        $healthStatus = $this->determineHealthStatus(
            $retainer, $marginPercent, $usagePercent
        );

        $snapshot = [
            'human_hours' => $humanHours,
            'human_hours_by_type' => $humanHoursData['by_type'],
            'meeting_hours' => $meetingData['hours'],
            'meeting_count' => $meetingData['count'],
            'agent_cost_usd' => $agentCostUsd,
            'agent_tasks_completed' => $agentData['tasks_completed'],
            'agent_equivalent_hours' => $agentEquivHours,
            'total_equivalent_hours' => $totalEquivHours,
            'hours_budget' => $hoursBudget,
            'hours_remaining' => max(0, round($hoursBudget - $totalEquivHours, 2)),
            'usage_percent' => $usagePercent,
            'is_over_budget' => $totalEquivHours > $hoursBudget,
            'cost_to_serve' => $costToServe,
            'monthly_amount' => $monthlyAmount,
            'effective_margin_percent' => $marginPercent,
            'health_status' => $healthStatus,
            'last_client_activity_at' => $retainer->last_client_activity_at?->toIso8601String(),
            'period_activity' => $activityData,
            'human_hours_source' => $humanHoursSource,
            'estimated_hours' => $estimatedHoursData,
            'alerts' => [],
        ];

        $snapshot['alerts'] = $this->buildAlerts($retainer, $snapshot);

        if ($persist) {
            $retainer->update([
                'hours_used' => $humanHours,
                'agent_cost_usd' => $agentCostUsd,
                'agent_tasks_completed' => $agentData['tasks_completed'],
                'effective_margin_percent' => $marginPercent,
                'health_status' => $healthStatus,
            ]);
        }

        return $snapshot;
    }

    /**
     * @return array{total: float, by_type: array<string, float>}
     */
    /**
     * @return array{total: float, by_type: array<string, float>, has_ai_estimated: bool, has_manual: bool}
     */
    protected function aggregateHumanHours(RetainerPeriod $retainer, Carbon $start, Carbon $end): array
    {
        // Union of tracked time entries for this period + any AI-narrative-
        // synthesized entries tied to this retainer period.
        $entries = TimeEntry::query()
            ->where(function ($q) use ($retainer, $start, $end) {
                $q->where(function ($inner) use ($retainer, $start, $end) {
                    $inner->where('client_id', $retainer->client_id)
                        ->whereBetween('spent_date', [$start, $end]);
                })->orWhere('retainer_period_id', $retainer->id);
            })
            ->get();

        $total = round((float) $entries->sum('hours'), 2);

        $byType = $entries->groupBy(fn ($e) => $e->effort_type ?? 'other')
            ->map(fn ($group) => round((float) $group->sum('hours'), 2))
            ->toArray();

        return [
            'total' => $total,
            'by_type' => $byType,
            'has_ai_estimated' => $entries->contains(fn ($e) => ($e->source ?? 'manual') === 'ai_estimated'),
            'has_manual' => $entries->contains(fn ($e) => ($e->source ?? 'manual') === 'manual'),
        ];
    }

    /**
     * @return array{hours: float, count: int}
     */
    protected function aggregateMeetings(RetainerPeriod $retainer, Carbon $start, Carbon $end): array
    {
        $meetings = CalendarEvent::where('client_id', $retainer->client_id)
            ->where('is_client_meeting', true)
            ->whereBetween('start_at', [$start, $end])
            ->get();

        return [
            'hours' => round((float) $meetings->sum('duration_hours'), 2),
            'count' => $meetings->count(),
        ];
    }

    /**
     * @return array{cost_usd: float, tasks_completed: int}
     */
    protected function aggregateAgentRuns(RetainerPeriod $retainer, Carbon $start, Carbon $end): array
    {
        $runs = AgentRun::where('client_id', $retainer->client_id)
            ->whereBetween('started_at', [$start, $end])
            ->get();

        return [
            'cost_usd' => round((float) $runs->sum('cost_usd'), 4),
            'tasks_completed' => $runs->where('status', AgentRun::STATUS_COMPLETED)->count(),
        ];
    }

    /**
     * Aggregate qualitative period activity for the retainer report context
     * section. NOT folded into hours math — these are signals of "what
     * happened," not "how many hours were worked."
     *
     * @return array{
     *   github: array{
     *     prs_merged: int,
     *     prs_opened: int,
     *     issues_closed: int,
     *     merged_pull_requests: array<int, array{title: string, pr_number: int, author: string|null, merged_at: string, repo: string|null}>,
     *   },
     *   slack: array{
     *     total_messages: int,
     *     external_messages: int,
     *     internal_messages: int,
     *     channels: array<int, array{name: string, count: int}>,
     *     first_response: array{median_seconds: float|null, average_seconds: float|null, replied_conversations: int, total_conversations: int},
     *     time_to_merge: array{median_seconds: float|null, average_seconds: float|null, shipped_conversations: int, total_conversations: int},
     *   },
     * }
     */
    public function aggregatePeriodActivity(RetainerPeriod $retainer, Carbon $start, Carbon $end): array
    {
        $clientId = $retainer->client_id;

        // GitHub: PRs, issues, and direct commits through repo→client linkage.
        $repos = $this->reposForClient($clientId);

        $repoIds = $repos->pluck('id')->all();

        $prsMergedQuery = GitHubPullRequest::query()
            ->whereIn('repo_id', $repoIds)
            ->whereBetween('merged_at', [$start, $end]);

        $prsMerged = (clone $prsMergedQuery)->count();
        $prsOpened = GitHubPullRequest::query()
            ->whereIn('repo_id', $repoIds)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $mergedPRs = (clone $prsMergedQuery)
            ->with('repo:id,name')
            ->orderBy('merged_at')
            ->get()
            ->map(fn (GitHubPullRequest $pr) => [
                'title' => $pr->title,
                'pr_number' => $pr->pr_number,
                'author' => $pr->author,
                'merged_at' => $pr->merged_at?->toIso8601String(),
                'repo' => $pr->repo?->name,
            ])
            ->all();

        $issuesClosed = GitHubIssue::query()
            ->whereIn('repo_id', $repoIds)
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        // Direct commits to default branch (catches work merged outside PRs).
        $commitData = $this->aggregateCommits($repos, $start, $end);

        // Slack: count messages tagged to this client across all channels.
        // Filter on sent_at (real Slack send time) with a fallback to
        // created_at for legacy rows where sent_at was never populated.
        $slackMessages = SlackMessage::query()
            ->where('slack_messages.client_id', $clientId)
            ->whereRaw('coalesce(slack_messages.sent_at, slack_messages.created_at) >= ?', [$start])
            ->whereRaw('coalesce(slack_messages.sent_at, slack_messages.created_at) <= ?', [$end]);

        $totalMessages = (clone $slackMessages)->count();

        // Classify "from client" vs "from team". The internal team is the
        // union of users.slack_user_id values + services.slack.internal_user_ids
        // config + services.slack.owner_user_id. Anyone in a client channel NOT
        // in that set is treated as the client. Falls back to user_is_external
        // when the set is empty.
        $internalIds = $this->resolveInternalSlackIds();

        if (! empty($internalIds)) {
            $internalMessages = (clone $slackMessages)
                ->whereIn('slack_messages.user_id', $internalIds)
                ->count();
            $externalMessages = $totalMessages - $internalMessages;
        } else {
            $externalMessages = (clone $slackMessages)->where('user_is_external', true)->count();
            $internalMessages = $totalMessages - $externalMessages;
        }

        $channels = (clone $slackMessages)
            ->join('slack_channels', 'slack_messages.channel_id', '=', 'slack_channels.id')
            ->selectRaw('slack_channels.channel_name AS name, COUNT(*) AS count')
            ->groupBy('slack_channels.channel_name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->all();

        $emailData = $this->aggregateEmails($retainer, $start, $end);

        // Tasks completed in-period (e.g. from the client's tracking sheet).
        // Linked via project → client; counted by completed_at so a task lands
        // on the period the work was actually closed in.
        $completedTasks = \App\Models\Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $clientId))
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->orderBy('completed_at')
            ->get(['title', 'completed_at'])
            ->map(fn ($t) => [
                'title' => $t->title,
                'completed_at' => $t->completed_at?->toIso8601String(),
            ])
            ->all();

        return [
            'github' => [
                'prs_merged' => $prsMerged,
                'prs_opened' => $prsOpened,
                'issues_closed' => $issuesClosed,
                'merged_pull_requests' => $mergedPRs,
                'commits' => $commitData['count'],
                'recent_commits' => $commitData['recent'],
                'commits_by_branch' => $commitData['by_branch'],
            ],
            'email' => [
                'inbound_count' => $emailData['inbound_count'],
                'outbound_count' => $emailData['outbound_count'],
                'threads' => $emailData['threads'],
                'action_required_count' => $emailData['action_required_count'],
                'top_subjects' => $emailData['top_subjects'],
            ],
            'slack' => array_merge([
                'total_messages' => $totalMessages,
                'external_messages' => $externalMessages,
                'internal_messages' => $internalMessages,
                'channels' => $channels,
            ], $this->aggregateSlackSla($clientId, $start, $end, $repos)),
            'tasks' => [
                'completed_count' => count($completedTasks),
                'completed' => $completedTasks,
            ],
        ];
    }

    /**
     * First-response and time-to-merge for client-originated Slack conversations.
     *
     * Conversations are keyed by channel_id + COALESCE(thread_ts, message_ts).
     * Origin is the earliest message on that key, including rows before the
     * period window. An in-period follow-up is not a new origin. Client-originated
     * means the origin user is not in resolveInternalSlackIds(). Bot acks
     * (workspace bot_user_id) never count as the first Zao reply. Time-to-merge
     * only averages threads that actually merged — unshipped threads stay in the
     * shipped fraction instead of being treated as resolved.
     *
     * @param  \Illuminate\Support\Collection<int, GitHubRepo>  $repos
     * @return array{
     *   first_response: array{median_seconds: float|null, average_seconds: float|null, replied_conversations: int, total_conversations: int},
     *   time_to_merge: array{median_seconds: float|null, average_seconds: float|null, shipped_conversations: int, total_conversations: int},
     * }
     */
    public function aggregateSlackSla(int $clientId, Carbon $start, Carbon $end, $repos): array
    {
        $empty = $this->emptySlackSla();
        $internalIds = $this->resolveInternalSlackIds();

        if ($internalIds === []) {
            return $empty;
        }

        $internalSet = array_fill_keys($internalIds, true);

        $periodMessages = $this->slackMessagesTouchingPeriod($clientId, $start, $end);
        if ($periodMessages->isEmpty()) {
            return $empty;
        }

        $keysByChannel = [];
        foreach ($periodMessages as $message) {
            $threadKey = $message->thread_ts ?: $message->message_ts;
            if ($threadKey === null || $threadKey === '') {
                continue;
            }
            $keysByChannel[$message->channel_id][] = $threadKey;
        }

        if ($keysByChannel === []) {
            return $empty;
        }

        $messages = $this->slackMessagesForConversationKeys($clientId, $keysByChannel);
        if ($messages->isEmpty()) {
            return $empty;
        }

        $botUserIds = array_fill_keys(
            array_filter(
                SlackWorkspace::query()
                    ->whereIn('id', $messages->pluck('workspace_id')->unique()->all())
                    ->pluck('bot_user_id')
                    ->all()
            ),
            true
        );

        $conversations = [];
        foreach ($messages as $message) {
            $clock = $this->slackMessageClock($message);
            if ($clock === null) {
                continue;
            }

            $threadKey = $message->thread_ts ?: $message->message_ts;
            $key = $message->channel_id.'|'.$threadKey;
            $conversations[$key] ??= [
                'channel_id' => $message->channel_id,
                'slack_channel_ids' => [],
                'thread_ts' => $threadKey,
                'messages' => [],
            ];

            $channelSlackId = $message->channel?->slack_id ?: $message->channel?->channel_id;
            if (is_string($channelSlackId) && $channelSlackId !== '') {
                $conversations[$key]['slack_channel_ids'][$channelSlackId] = true;
            }
            if (is_string($message->channel?->channel_id) && $message->channel->channel_id !== '') {
                $conversations[$key]['slack_channel_ids'][$message->channel->channel_id] = true;
            }

            $conversations[$key]['messages'][] = [
                'user_id' => (string) $message->user_id,
                'clock' => $clock,
                'content' => (string) $message->content,
            ];
        }

        $clientConversations = [];
        foreach ($conversations as $key => $conversation) {
            usort(
                $conversation['messages'],
                fn (array $a, array $b) => $a['clock']->getTimestamp() <=> $b['clock']->getTimestamp()
            );

            $origin = $conversation['messages'][0];
            $originClock = $origin['clock'];
            if ($originClock->lt($start) || $originClock->gt($end)) {
                continue;
            }

            $originUserId = $origin['user_id'];
            if ($originUserId === '' || isset($internalSet[$originUserId]) || isset($botUserIds[$originUserId])) {
                continue;
            }

            $firstReplySeconds = null;
            foreach (array_slice($conversation['messages'], 1) as $later) {
                if ($later['clock']->lte($originClock)) {
                    continue;
                }
                if (! isset($internalSet[$later['user_id']])) {
                    continue;
                }
                if (isset($botUserIds[$later['user_id']])) {
                    continue;
                }

                $firstReplySeconds = $later['clock']->getTimestamp() - $originClock->getTimestamp();
                break;
            }

            $clientConversations[$key] = [
                'origin_clock' => $originClock,
                'thread_ts' => $conversation['thread_ts'],
                'slack_channel_ids' => array_keys($conversation['slack_channel_ids']),
                'contents' => array_column($conversation['messages'], 'content'),
                'first_reply_seconds' => $firstReplySeconds,
            ];
        }

        $total = count($clientConversations);
        $replyDurations = [];
        foreach ($clientConversations as $conversation) {
            if ($conversation['first_reply_seconds'] !== null) {
                $replyDurations[] = (float) $conversation['first_reply_seconds'];
            }
        }

        $empty['first_response'] = [
            'median_seconds' => $this->median($replyDurations),
            'average_seconds' => $this->average($replyDurations),
            'replied_conversations' => count($replyDurations),
            'total_conversations' => $total,
        ];

        if ($total === 0) {
            return $empty;
        }

        $mergeDurations = $this->slackTimeToMergeDurations($clientId, $clientConversations, $repos);

        $empty['time_to_merge'] = [
            'median_seconds' => $this->median($mergeDurations),
            'average_seconds' => $this->average($mergeDurations),
            'shipped_conversations' => count($mergeDurations),
            'total_conversations' => $total,
        ];

        return $empty;
    }

    /**
     * @return array{
     *   first_response: array{median_seconds: float|null, average_seconds: float|null, replied_conversations: int, total_conversations: int},
     *   time_to_merge: array{median_seconds: float|null, average_seconds: float|null, shipped_conversations: int, total_conversations: int},
     * }
     */
    protected function emptySlackSla(): array
    {
        return [
            'first_response' => [
                'median_seconds' => null,
                'average_seconds' => null,
                'replied_conversations' => 0,
                'total_conversations' => 0,
            ],
            'time_to_merge' => [
                'median_seconds' => null,
                'average_seconds' => null,
                'shipped_conversations' => 0,
                'total_conversations' => 0,
            ],
        ];
    }

    protected function slackMessageClock(SlackMessage $message): ?Carbon
    {
        if ($message->sent_at) {
            return Carbon::parse($message->sent_at);
        }

        if (is_numeric($message->message_ts)) {
            return Carbon::createFromTimestamp((float) $message->message_ts);
        }

        return null;
    }

    /**
     * Messages whose SLA clock falls inside the snapshot window. Null sent_at
     * rows are included only when message_ts itself is in-window — not every
     * legacy null forever.
     *
     * @return \Illuminate\Support\Collection<int, SlackMessage>
     */
    protected function slackMessagesTouchingPeriod(int $clientId, Carbon $start, Carbon $end)
    {
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();

        return SlackMessage::query()
            ->with('channel:id,channel_id,slack_id')
            ->where('client_id', $clientId)
            ->where(function ($q) use ($start, $end, $startTs, $endTs) {
                $q->where(function ($inner) use ($start, $end) {
                    $inner->whereNotNull('sent_at')
                        ->where('sent_at', '>=', $start)
                        ->where('sent_at', '<=', $end);
                })->orWhere(function ($inner) use ($startTs, $endTs) {
                    $inner->whereNull('sent_at')
                        ->whereRaw($this->slackMessageTsUnixExpression().' >= ?', [$startTs])
                        ->whereRaw($this->slackMessageTsUnixExpression().' <= ?', [$endTs]);
                });
            })
            ->get($this->slackSlaMessageColumns());
    }

    /**
     * Full history (and later replies) for conversations that already touched
     * the period. Bounded by those keys, not by an unbounded client-wide scan.
     *
     * @param  array<int|string, array<int, string>>  $keysByChannel
     * @return \Illuminate\Support\Collection<int, SlackMessage>
     */
    protected function slackMessagesForConversationKeys(int $clientId, array $keysByChannel)
    {
        $clauses = [];
        foreach ($keysByChannel as $channelId => $threadKeys) {
            $threadKeys = array_values(array_unique($threadKeys));
            if ($threadKeys === []) {
                continue;
            }
            $clauses[] = [$channelId, $threadKeys];
        }

        if ($clauses === []) {
            return SlackMessage::query()->whereRaw('0 = 1')->get($this->slackSlaMessageColumns());
        }

        return SlackMessage::query()
            ->with('channel:id,channel_id,slack_id')
            ->where('client_id', $clientId)
            ->where(function ($q) use ($clauses) {
                foreach ($clauses as [$channelId, $threadKeys]) {
                    $placeholders = implode(',', array_fill(0, count($threadKeys), '?'));
                    $q->orWhere(function ($inner) use ($channelId, $threadKeys, $placeholders) {
                        $inner->where('channel_id', $channelId)
                            ->whereRaw(
                                'COALESCE(thread_ts, message_ts) IN ('.$placeholders.')',
                                $threadKeys
                            );
                    });
                }
            })
            ->get($this->slackSlaMessageColumns());
    }

    /**
     * @return list<string>
     */
    protected function slackSlaMessageColumns(): array
    {
        return [
            'id',
            'channel_id',
            'workspace_id',
            'user_id',
            'message_ts',
            'thread_ts',
            'sent_at',
            'content',
        ];
    }

    protected function slackMessageTsUnixExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(slack_messages.message_ts AS DOUBLE PRECISION)',
            default => 'CAST(slack_messages.message_ts AS REAL)',
        };
    }

    /**
     * @param  array<string, array{origin_clock: Carbon, thread_ts: string, slack_channel_ids: array<int, string>, contents: array<int, string>, first_reply_seconds: int|null}>  $clientConversations
     * @param  \Illuminate\Support\Collection<int, GitHubRepo>  $repos
     * @return array<int, float>
     */
    protected function slackTimeToMergeDurations(int $clientId, array $clientConversations, $repos): array
    {
        $repoIds = $repos->pluck('id')->all();
        $clientRepoNames = $repos
            ->pluck('full_name')
            ->filter()
            ->map(fn ($name) => strtolower((string) $name))
            ->unique()
            ->values()
            ->all();
        $allowedRepos = array_fill_keys($clientRepoNames, true);

        $threadTsList = array_values(array_unique(array_column($clientConversations, 'thread_ts')));
        $earliestOrigin = null;
        foreach ($clientConversations as $conversation) {
            if ($earliestOrigin === null || $conversation['origin_clock']->lt($earliestOrigin)) {
                $earliestOrigin = $conversation['origin_clock'];
            }
        }

        $runsByThread = [];
        if ($threadTsList !== []) {
            $runs = AgentRun::query()
                ->where('invocation_source', AgentRun::SOURCE_SLACK)
                ->where(function ($q) use ($clientId) {
                    $q->where('client_id', $clientId)
                        ->orWhereHas('project', fn ($p) => $p->where('client_id', $clientId));
                })
                ->whereIn('context->slack->thread_ts', $threadTsList)
                ->get(['id', 'client_id', 'project_id', 'context', 'output']);

            foreach ($runs as $run) {
                $threadTs = $run->context['slack']['thread_ts'] ?? null;
                if (! is_string($threadTs) || $threadTs === '') {
                    continue;
                }
                $runsByThread[$threadTs][] = $run;
            }
        }

        $referencedPrNumbers = [];
        foreach ($clientConversations as $conversation) {
            foreach ($conversation['contents'] as $content) {
                foreach ($this->extractRepoQualifiedPullPermalinks($content, $allowedRepos) as $ref) {
                    $referencedPrNumbers[$ref['pr_number']] = true;
                }
            }
        }
        foreach ($runsByThread as $runs) {
            foreach ($runs as $run) {
                $output = is_array($run->output) ? $run->output : [];
                if (isset($output['pr_number']) && (int) $output['pr_number'] > 0) {
                    $referencedPrNumbers[(int) $output['pr_number']] = true;
                }
                foreach (['pr_url', 'pull_request_url', 'result'] as $urlKey) {
                    if (! is_string($output[$urlKey] ?? null)) {
                        continue;
                    }
                    foreach ($this->extractRepoQualifiedPullPermalinks($output[$urlKey], $allowedRepos) as $ref) {
                        $referencedPrNumbers[$ref['pr_number']] = true;
                    }
                }
            }
        }

        $prsByRepoNumber = [];
        $prsByNumber = [];
        if ($repoIds !== [] && $referencedPrNumbers !== [] && $earliestOrigin !== null) {
            $pullRequests = GitHubPullRequest::query()
                ->whereIn('repo_id', $repoIds)
                ->whereIn('pr_number', array_keys($referencedPrNumbers))
                ->whereNotNull('merged_at')
                ->where('merged_at', '>=', $earliestOrigin)
                ->with('repo:id,full_name')
                ->get(['id', 'repo_id', 'pr_number', 'merged_at']);

            foreach ($pullRequests as $pullRequest) {
                $fullName = strtolower((string) ($pullRequest->repo?->full_name ?? ''));
                if ($fullName === '') {
                    continue;
                }
                $entry = [
                    'full_name' => $fullName,
                    'pr_number' => (int) $pullRequest->pr_number,
                    'merged_at' => $pullRequest->merged_at,
                ];
                $prsByRepoNumber[$fullName.'#'.$entry['pr_number']] = $entry;
                $prsByNumber[$entry['pr_number']][] = $entry;
            }
        }

        $durations = [];
        foreach ($clientConversations as $conversation) {
            $originClock = $conversation['origin_clock'];
            $mergedAt = null;

            foreach ($runsByThread[$conversation['thread_ts']] ?? [] as $run) {
                $runChannel = $run->context['slack']['channel_id'] ?? null;
                if (is_string($runChannel) && $runChannel !== '' && $conversation['slack_channel_ids'] !== []) {
                    if (! in_array($runChannel, $conversation['slack_channel_ids'], true)) {
                        continue;
                    }
                }

                $output = is_array($run->output) ? $run->output : [];
                $prNumber = isset($output['pr_number']) ? (int) $output['pr_number'] : 0;
                $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;
                if (is_string($output['result'] ?? null) && $prUrl === null) {
                    $prUrl = $output['result'];
                }

                $matched = null;
                if (is_string($prUrl)) {
                    $refs = $this->extractRepoQualifiedPullPermalinks($prUrl, $allowedRepos);
                    foreach ($refs as $ref) {
                        $matched = $prsByRepoNumber[$ref['full_name'].'#'.$ref['pr_number']] ?? null;
                        if ($matched !== null) {
                            break;
                        }
                    }
                }

                if ($matched === null && $prNumber > 0) {
                    $candidates = $prsByNumber[$prNumber] ?? [];
                    $matched = $this->earliestMergedAfter($candidates, $originClock);
                }

                if ($matched !== null && $matched['merged_at']->gt($originClock)) {
                    $mergedAt = $this->earlierTimestamp($mergedAt, $matched['merged_at']);
                }
            }

            foreach ($conversation['contents'] as $content) {
                foreach ($this->extractRepoQualifiedPullPermalinks($content, $allowedRepos) as $ref) {
                    $matched = $prsByRepoNumber[$ref['full_name'].'#'.$ref['pr_number']] ?? null;
                    if ($matched !== null && $matched['merged_at']->gt($originClock)) {
                        $mergedAt = $this->earlierTimestamp($mergedAt, $matched['merged_at']);
                    }
                }
            }

            if ($mergedAt === null) {
                continue;
            }

            $durations[] = (float) ($mergedAt->getTimestamp() - $originClock->getTimestamp());
        }

        return $durations;
    }

    /**
     * @param  array<string, true>  $allowedRepos
     * @return array<int, array{full_name: string, pr_number: int}>
     */
    protected function extractRepoQualifiedPullPermalinks(string $text, array $allowedRepos): array
    {
        if ($text === '' || $allowedRepos === []) {
            return [];
        }

        preg_match_all(
            '#https?://(?:www\.)?github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/pull/(\d+)#i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        $refs = [];
        foreach ($matches as $match) {
            $fullName = strtolower($match[1]);
            if (! isset($allowedRepos[$fullName])) {
                continue;
            }
            $refs[] = [
                'full_name' => $fullName,
                'pr_number' => (int) $match[2],
            ];
        }

        return $refs;
    }

    /**
     * @param  array<int, array{full_name: string, pr_number: int, merged_at: Carbon}>  $candidates
     * @return array{full_name: string, pr_number: int, merged_at: Carbon}|null
     */
    protected function earliestMergedAfter(array $candidates, Carbon $originClock): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (! $candidate['merged_at']->gt($originClock)) {
                continue;
            }
            if ($best === null || $candidate['merged_at']->lt($best['merged_at'])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    protected function earlierTimestamp(?Carbon $current, Carbon $candidate): Carbon
    {
        if ($current === null || $candidate->lt($current)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param  array<int, float>  $values
     */
    protected function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /**
     * @param  array<int, float>  $values
     */
    protected function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Estimate hours worked from observable activity when no manual time
     * entries exist. Aggregates:
     *   - Slack on-task time: per internal user per day in client channels,
     *     (last_message - first_message + 15min buffer), capped at 6h/day.
     *     One-off messages count as 0.25h.
     *   - GitHub commit effort: existing CommitEffortEstimationService.
     *
     * Used when time_entries is empty for the period — keeps the retainer
     * "hours used" honest with reality instead of always showing 0.0.
     *
     * @return array{
     *   total_hours: float,
     *   slack_hours: float,
     *   commit_hours: float,
     *   slack_by_user: array<int, array{user_id: string, user_name: string, days: int, hours: float}>,
     *   commit_repos: array<int, array{repo: string, hours: float, count: int}>,
     * }
     */
    public function aggregateEstimatedHours(
        RetainerPeriod $retainer,
        Carbon $start,
        Carbon $end,
        ?\App\Services\GitHub\CommitEffortEstimationService $commitEstimator = null,
    ): array {
        $internalIds = $this->resolveInternalSlackIds();
        $clientId = $retainer->client_id;

        $slackHours = 0.0;
        $slackByUser = [];

        if (! empty($internalIds)) {
            $messages = SlackMessage::query()
                ->where('slack_messages.client_id', $clientId)
                ->whereIn('slack_messages.user_id', $internalIds)
                ->whereRaw('coalesce(slack_messages.sent_at, slack_messages.created_at) >= ?', [$start])
                ->whereRaw('coalesce(slack_messages.sent_at, slack_messages.created_at) <= ?', [$end])
                ->orderByRaw('coalesce(sent_at, created_at)')
                ->get(['user_id', 'user_name', 'sent_at', 'created_at']);

            // Look up human-readable names from users.slack_user_id so the
            // report doesn't expose raw Slack IDs to clients.
            $idToName = \App\Models\User::query()
                ->whereIn('slack_user_id', $messages->pluck('user_id')->unique()->all())
                ->pluck('name', 'slack_user_id')
                ->all();

            // Group messages by user, collect sorted timestamps.
            $byUser = [];
            foreach ($messages as $m) {
                $when = $m->sent_at ?? $m->created_at;
                $byUser[$m->user_id]['name'] ??= $m->user_name;
                $byUser[$m->user_id]['times'][] = $when;
            }

            // Session-based estimation: messages within 30 min of each other
            // belong to one focused work session. A session contributes its
            // actual span + 5 min wrap-up buffer. Lone messages = 5 min.
            // No daily cap — that was over-counting; if there are genuinely
            // 8 hours of focused sessions in a day, that's 8 hours.
            $sessionGapSeconds = 30 * 60;
            $minSessionSeconds = 5 * 60;
            $wrapBufferSeconds = 5 * 60;

            foreach ($byUser as $uid => $info) {
                $times = $info['times'];
                usort($times, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

                $sessionsSeconds = 0;
                $sessionStart = null;
                $sessionLast = null;
                $sessionCount = 0;

                foreach ($times as $t) {
                    $ts = $t->getTimestamp();
                    if ($sessionLast === null || ($ts - $sessionLast) > $sessionGapSeconds) {
                        if ($sessionStart !== null) {
                            $sessionsSeconds += max($minSessionSeconds, $sessionLast - $sessionStart + $wrapBufferSeconds);
                            $sessionCount++;
                        }
                        $sessionStart = $ts;
                    }
                    $sessionLast = $ts;
                }
                if ($sessionStart !== null) {
                    $sessionsSeconds += max($minSessionSeconds, $sessionLast - $sessionStart + $wrapBufferSeconds);
                    $sessionCount++;
                }

                $userHours = round($sessionsSeconds / 3600, 2);
                $resolved = $idToName[$uid] ?? null;
                if (! $resolved) {
                    $raw = $info['name'] ?? '';
                    $looksLikeId = $raw === '' || $raw === $uid || (bool) preg_match('/^[UW][A-Z0-9]{6,}$/', $raw);
                    $resolved = $looksLikeId ? 'Internal team' : $raw;
                }

                $slackByUser[] = [
                    'user_id' => $uid,
                    'user_name' => $resolved,
                    'sessions' => $sessionCount,
                    'hours' => $userHours,
                ];
                $slackHours += $userHours;
            }
        }

        // GitHub commits → estimated hours (per repo, filtered to in-period).
        $commitHours = 0.0;
        $commitRepos = [];
        $estimator = $commitEstimator ?? app(\App\Services\GitHub\CommitEffortEstimationService::class);

        $repos = $this->reposForClient($clientId);
        foreach ($repos as $repo) {
            if (! $repo->installation_id) {
                continue;
            }

            try {
                $result = $estimator->estimateRepoEffort($repo, $start->toIso8601String());
            } catch (\Throwable $e) {
                Log::warning('Commit effort estimation failed', [
                    'repo' => $repo->full_name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Filter commits to those within end bound as well.
            $inPeriod = collect($result['commits'] ?? [])
                ->filter(fn ($c) => $c['date'] && Carbon::parse($c['date'])->between($start, $end));
            $humanCommits = $inPeriod->where('is_ai_authored', false);
            $aiCommits = $inPeriod->where('is_ai_authored', true);
            $humanHoursForRepo = (float) $humanCommits->sum('hours');
            $aiHoursForRepo = (float) $aiCommits->sum('hours');
            $repoHours = $humanHoursForRepo + $aiHoursForRepo;

            if ($repoHours > 0) {
                $commitRepos[] = [
                    'repo' => $repo->full_name,
                    'hours' => round($repoHours, 2),
                    'human_hours' => round($humanHoursForRepo, 2),
                    'ai_hours' => round($aiHoursForRepo, 2),
                    'human_commits' => $humanCommits->count(),
                    'ai_commits' => $aiCommits->count(),
                    'count' => $inPeriod->count(),
                ];
                $commitHours += $repoHours;
            }
        }

        // Email-based estimation. Each inbound email from the client implies a
        // request needing review/action — count 10min, capped at 30min if it
        // has a long body. Each outbound email from us is a reply/action —
        // count 5min, no cap. These are rough but match what an operator does
        // when manually logging time for back-and-forth correspondence.
        $emailData = $this->aggregateEmails($retainer, $start, $end);
        $emailHours = 0.0;
        if (! empty($emailData['inbound_email_ids'])) {
            $inboundEmails = Email::query()->whereIn('id', $emailData['inbound_email_ids'])->get(['id', 'body_text']);
            foreach ($inboundEmails as $email) {
                $minutes = strlen((string) $email->body_text) > 1500 ? 30 : 10;
                $emailHours += $minutes / 60;
            }
        }
        $emailHours += count($emailData['outbound_email_ids']) * (5 / 60);
        $emailHours = round($emailHours, 2);

        // Naive sum. The earlier weighted blend (max + min*0.3) overcorrected
        // for assumed overlap and undershot user-validated gut estimates by
        // ~30%. Commits and Slack often discuss adjacent but DISTINCT work
        // (scoping vs implementation), so they each deserve their full weight.
        // The tamed commit base hours (CommitEffortEstimationService) already
        // does most of the work to keep this honest.
        $total = round($commitHours + $slackHours + $emailHours, 2);

        return [
            'total_hours' => $total,
            'slack_hours' => round($slackHours, 2),
            'commit_hours' => round($commitHours, 2),
            'email_hours' => $emailHours,
            'slack_by_user' => $slackByUser,
            'commit_repos' => $commitRepos,
            'email_counts' => [
                'inbound' => count($emailData['inbound_email_ids']),
                'outbound' => count($emailData['outbound_email_ids']),
            ],
        ];
    }

    /**
     * Compute the canonical set of "internal team" Slack user IDs.
     *
     * @return array<int, string>
     */
    public function resolveInternalSlackIds(): array
    {
        $fromUsers = \App\Models\User::query()
            ->whereNotNull('slack_user_id')
            ->pluck('slack_user_id')
            ->all();

        $fromConfig = (array) config('services.slack.internal_user_ids', []);
        $ownerId = config('services.slack.owner_user_id');

        $all = array_merge($fromUsers, $fromConfig);
        if ($ownerId) {
            $all[] = $ownerId;
        }

        return array_values(array_unique(array_filter($all)));
    }

    /**
     * Fetch commits on each repo's default branch within the period. Catches work
     * that lands as direct merges (push to main / fast-forward / rebase merges)
     * rather than through PRs, which are tracked separately.
     *
     * Cached per (repo, period) for 1 hour so multiple report views don't
     * thrash the GitHub API.
     *
     * @return array{
     *   count: int,
     *   by_branch: array<string, int>,
     *   recent: array<int, array{sha: string, message: string, author: string|null, repo: string, branch: string, committed_at: string|null}>,
     * }
     */
    /**
     * Repos linked to a client either directly (github_repos.client_id) or
     * transitively through a project (github_repos.project_id → projects.client_id).
     * In practice most repos are linked via project, so the direct-only query
     * missed almost everything.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, GitHubRepo>
     */
    public function reposForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return GitHubRepo::query()
            ->where(function ($q) use ($clientId) {
                $q->where('client_id', $clientId)
                    ->orWhereIn('project_id', function ($sub) use ($clientId) {
                        $sub->select('id')
                            ->from('projects')
                            ->where('client_id', $clientId);
                    });
            })
            ->get();
    }

    /**
     * Return the email domains associated with a client. Used to match emails
     * whose client_id is null but whose sender/recipient is clearly the
     * client — fixes the 2.4% client_id tag rate problem.
     *
     * @return array<int, string>
     */
    public function clientEmailDomains(?Client $client): array
    {
        if (! $client) {
            return [];
        }

        $emails = collect([$client->billing_email])
            ->merge($client->contacts()->pluck('email'))
            ->filter()
            ->map(fn ($e) => strtolower(trim($e)));

        return $emails
            ->map(function ($e) {
                $at = strrpos($e, '@');

                return $at === false ? null : substr($e, $at + 1);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Aggregate emails belonging to a client during the period.
     * Combines the explicit client_id tag with domain-based matching.
     *
     * @return array{
     *   inbound_count: int,
     *   outbound_count: int,
     *   threads: int,
     *   action_required_count: int,
     *   top_subjects: array<int, array{subject: string, from: string, received_at: string|null, action_required: bool}>,
     *   inbound_email_ids: array<int, int>,
     *   outbound_email_ids: array<int, int>,
     * }
     */
    protected function aggregateEmails(RetainerPeriod $retainer, Carbon $start, Carbon $end): array
    {
        $client = $retainer->client;
        $domains = $this->clientEmailDomains($client);

        $query = Email::query()
            ->whereBetween('created_at', [$start, $end]);

        $query->where(function ($q) use ($retainer, $domains) {
            $q->where('client_id', $retainer->client_id);
            foreach ($domains as $domain) {
                $needle = '%@'.strtolower($domain);
                $q->orWhereRaw('lower(from_address) like ?', [$needle])
                    ->orWhereRaw('lower(cast(to_addresses as text)) like ?', [$needle.'%']);
            }
        });

        $emails = $query->orderBy('created_at')->get();

        $inboundIds = [];
        $outboundIds = [];
        $actionRequired = 0;
        $threads = $emails->pluck('thread_id')->filter()->unique()->count();

        foreach ($emails as $email) {
            $isFromClientDomain = false;
            $fromAddr = strtolower((string) $email->from_address);
            foreach ($domains as $domain) {
                if (str_ends_with($fromAddr, "@{$domain}")) {
                    $isFromClientDomain = true;
                    break;
                }
            }

            if ($isFromClientDomain) {
                $inboundIds[] = $email->id;
            } else {
                $outboundIds[] = $email->id;
            }

            if ($email->action_required) {
                $actionRequired++;
            }
        }

        $topSubjects = $emails->sortByDesc('created_at')->take(10)->map(fn (Email $e) => [
            'subject' => $e->subject,
            'from' => $e->from_name ?: $e->from_address,
            'received_at' => $e->received_at?->toIso8601String() ?? $e->created_at?->toIso8601String(),
            'action_required' => (bool) $e->action_required,
        ])->values()->all();

        return [
            'inbound_count' => count($inboundIds),
            'outbound_count' => count($outboundIds),
            'threads' => $threads,
            'action_required_count' => $actionRequired,
            'top_subjects' => $topSubjects,
            'inbound_email_ids' => $inboundIds,
            'outbound_email_ids' => $outboundIds,
        ];
    }

    protected function aggregateCommits($repos, Carbon $start, Carbon $end): array
    {
        $totalCount = 0;
        $byBranch = [];
        $recent = [];

        if ($repos->isEmpty()) {
            return ['count' => 0, 'by_branch' => [], 'recent' => []];
        }

        $api = app(GitHubApiService::class);

        foreach ($repos as $repo) {
            // Skip repos with no GitHub App installation — listCommits would
            // crash on the installation token lookup. These are usually repos
            // added manually or owned by an org the Zao GitHub App isn't
            // installed on yet (client's own org, etc.).
            if (! $repo->installation_id) {
                Log::info('Skipping commit fetch — no GitHub App installation on repo', [
                    'repo_id' => $repo->id,
                    'repo' => $repo->full_name,
                ]);

                continue;
            }

            $branch = $repo->default_branch ?? 'main';
            $cacheKey = sprintf(
                'retainer.commits.%d.%s.%s',
                $repo->id,
                $start->toDateString(),
                $end->toDateString(),
            );

            try {
                $commits = Cache::remember(
                    $cacheKey,
                    now()->addHour(),
                    fn () => $api->listCommits(
                        $repo,
                        since: $start->toIso8601String(),
                        until: $end->toIso8601String(),
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch commits for retainer report', [
                    'repo_id' => $repo->id,
                    'repo' => $repo->full_name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_array($commits)) {
                continue;
            }

            $count = count($commits);
            $totalCount += $count;
            $byBranch[$repo->full_name.' @ '.$branch] = $count;

            foreach ($commits as $c) {
                $recent[] = [
                    'sha' => substr($c['sha'] ?? '', 0, 7),
                    'message' => strtok($c['commit']['message'] ?? '', "\n") ?: '',
                    'author' => $c['author']['login'] ?? ($c['commit']['author']['name'] ?? null),
                    'repo' => $repo->name,
                    'branch' => $branch,
                    'committed_at' => $c['commit']['committer']['date'] ?? null,
                ];
            }
        }

        // Sort recent by committed_at desc, keep top 20.
        usort($recent, fn ($a, $b) => strcmp($b['committed_at'] ?? '', $a['committed_at'] ?? ''));
        $recent = array_slice($recent, 0, 20);

        return [
            'count' => $totalCount,
            'by_branch' => $byBranch,
            'recent' => $recent,
        ];
    }

    protected function determineHealthStatus(
        RetainerPeriod $retainer,
        ?float $marginPercent,
        float $usagePercent,
    ): string {
        $lastActivity = $retainer->last_client_activity_at;
        if (! $lastActivity || $lastActivity->diffInDays(now()) > 45) {
            return 'silent';
        }
        if ($marginPercent !== null && $marginPercent < 0) {
            return 'critical';
        }
        if (($marginPercent !== null && $marginPercent < 20) || $usagePercent > 110) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @return array<int, array{type: string, message: string, action: string|null}>
     */
    protected function buildAlerts(RetainerPeriod $retainer, array $snapshot): array
    {
        $alerts = [];
        $clientName = $retainer->client?->name ?? 'client';

        if ($snapshot['is_over_budget']) {
            $overBy = round($snapshot['total_equivalent_hours'] - $snapshot['hours_budget'], 1);
            $alerts[] = [
                'type' => 'danger',
                'message' => "Over budget by {$overBy} hrs",
                'action' => "Draft retainer repricing conversation for {$clientName}",
            ];
        }

        if ($snapshot['effective_margin_percent'] !== null && $snapshot['effective_margin_percent'] < 0) {
            $loss = abs(round($snapshot['monthly_amount'] - $snapshot['cost_to_serve'], 2));
            $alerts[] = [
                'type' => 'danger',
                'message' => "Losing \${$loss}/mo on this retainer",
                'action' => "Draft retainer repricing conversation for {$clientName}",
            ];
        }

        if ($snapshot['effective_margin_percent'] !== null
            && $snapshot['effective_margin_percent'] >= 0
            && $snapshot['effective_margin_percent'] < 20) {
            $margin = $snapshot['effective_margin_percent'];
            $alerts[] = [
                'type' => 'warning',
                'message' => "Thin margin — {$margin}%",
                'action' => null,
            ];
        }

        if ($snapshot['usage_percent'] >= 80 && $snapshot['usage_percent'] <= 100) {
            $alerts[] = [
                'type' => 'warning',
                'message' => '80%+ of hour budget consumed',
                'action' => null,
            ];
        }

        if ($snapshot['meeting_count'] === 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => 'No meetings this month — consider check-in',
                'action' => "Draft a personal check-in message for {$clientName}",
            ];
        }

        $lastActivity = $retainer->last_client_activity_at;
        if ($lastActivity && $lastActivity->diffInDays(now()) > 30) {
            $days = $lastActivity->diffInDays(now());
            $alerts[] = [
                'type' => 'warning',
                'message' => "No client activity in {$days} days",
                'action' => "Draft a personal check-in for {$clientName} — silent {$days} days",
            ];
        }

        $failedRuns = AgentRun::where('client_id', $retainer->client_id)
            ->where('status', AgentRun::STATUS_FAILED)
            ->whereBetween('started_at', [$retainer->period_start, $retainer->period_end])
            ->count();
        if ($failedRuns > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Recurring integration failure this period',
                'action' => "Show full failure log for {$clientName}",
            ];
        }

        if (now()->month === 10) {
            $alerts[] = [
                'type' => 'info',
                'message' => 'Pre-season audit due — run checklist',
                'action' => "Run pre-season audit for {$clientName}",
            ];
        }

        return $alerts;
    }
}
