<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportSettings;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\HarvestTaskCategory;
use App\Models\RetainerPeriod;
use App\Models\SlackMessage;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\AI\AnthropicService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ClientReportService
{
    public function __construct(
        protected AnthropicService $ai
    ) {}

    /**
     * Generate a complete report for a client and period.
     */
    public function generateReport(
        Client $client,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $reportType = 'monthly',
        ?int $generatedBy = null
    ): ClientReport {
        // Gather all data
        $timeData = $this->aggregateTimeData($client, $periodStart, $periodEnd);
        $githubData = $this->aggregateGitHubData($client, $periodStart, $periodEnd);
        $taskData = $this->aggregateTaskData($client, $periodStart, $periodEnd);
        $activityData = $this->aggregateActivityMetrics($client, $periodStart, $periodEnd);
        $retainerData = $this->aggregateRetainerData($client, $periodStart, $periodEnd);

        // Create data snapshot for historical reference
        $dataSnapshot = [
            'time' => $timeData,
            'github' => $githubData,
            'tasks' => $taskData,
            'activity' => $activityData,
            'retainer' => $retainerData,
            'generated_at' => now()->toIso8601String(),
        ];

        // Generate AI content
        $aiContent = $this->generateAIContent($client, $dataSnapshot, $periodStart, $periodEnd);

        // Create the report
        return ClientReport::create([
            'client_id' => $client->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'report_type' => $reportType,
            'data_snapshot' => $dataSnapshot,
            'executive_summary' => $aiContent['summary'],
            'highlights' => $aiContent['highlights'],
            'metrics' => $aiContent['metrics'],
            'total_hours' => $timeData['total_hours'],
            'hours_by_category' => $timeData['by_category'],
            'hours_by_project' => $timeData['by_project'],
            'tasks_completed' => $taskData['completed_count'],
            'prs_merged' => $githubData['prs_merged'],
            'issues_closed' => $githubData['issues_closed'],
            'meetings_held' => $timeData['meetings_count'],
            'human_hours' => $retainerData['human_hours'] ?? 0,
            'total_agent_cost_usd' => $retainerData['agent_cost_usd'] ?? 0,
            'agent_tasks_completed' => $retainerData['agent_tasks_completed'] ?? 0,
            'effective_margin_percent' => $retainerData['effective_margin_percent'] ?? null,
            'generated_by' => $generatedBy,
            'status' => ClientReport::STATUS_DRAFT,
        ]);
    }

    /**
     * Aggregate time entry data from Harvest.
     */
    protected function aggregateTimeData(Client $client, Carbon $start, Carbon $end): array
    {
        $entries = TimeEntry::where('client_id', $client->id)
            ->whereBetween('spent_date', [$start, $end])
            ->get();

        $totalHours = $entries->sum('hours');
        $billableHours = $entries->where('is_billable', true)->sum('hours');

        // Group by category (Harvest task)
        $byCategory = $entries->groupBy('harvest_task_id')
            ->map(function ($group, $taskId) {
                $category = HarvestTaskCategory::where('harvest_id', $taskId)->first();

                return [
                    'name' => $category?->name ?? 'Other',
                    'hours' => round($group->sum('hours'), 2),
                    'entries_count' => $group->count(),
                ];
            })
            ->sortByDesc('hours')
            ->values()
            ->toArray();

        // Group by project
        $byProject = $entries->groupBy('project_id')
            ->map(function ($group, $projectId) {
                $project = $group->first()->project;

                return [
                    'name' => $project?->name ?? 'General',
                    'hours' => round($group->sum('hours'), 2),
                    'entries_count' => $group->count(),
                ];
            })
            ->sortByDesc('hours')
            ->values()
            ->toArray();

        // Estimate meetings (entries with "meeting" in notes or specific task categories)
        $meetingsCount = $entries->filter(function ($entry) {
            $notes = strtolower($entry->notes ?? '');

            return str_contains($notes, 'meeting') ||
                   str_contains($notes, 'call') ||
                   str_contains($notes, 'sync');
        })->count();

        return [
            'total_hours' => round($totalHours, 2),
            'billable_hours' => round($billableHours, 2),
            'non_billable_hours' => round($totalHours - $billableHours, 2),
            'entries_count' => $entries->count(),
            'meetings_count' => $meetingsCount,
            'by_category' => $byCategory,
            'by_project' => $byProject,
            'daily_breakdown' => $this->getDailyBreakdown($entries),
        ];
    }

    /**
     * Get daily hours breakdown for charts.
     */
    protected function getDailyBreakdown(Collection $entries): array
    {
        return $entries->groupBy(fn ($e) => $e->spent_date->format('Y-m-d'))
            ->map(fn ($group) => round($group->sum('hours'), 2))
            ->toArray();
    }

    /**
     * Aggregate GitHub activity data.
     */
    protected function aggregateGitHubData(Client $client, Carbon $start, Carbon $end): array
    {
        // Get repos linked to this client's projects
        $projectIds = $client->projects()->pluck('id');

        // PRs merged
        $prsMerged = GitHubPullRequest::whereHas('repo', function ($q) use ($projectIds) {
            $q->whereIn('project_id', $projectIds);
        })
            ->whereBetween('merged_at', [$start, $end])
            ->get();

        // Issues closed
        $issuesClosed = GitHubIssue::whereHas('repo', function ($q) use ($projectIds) {
            $q->whereIn('project_id', $projectIds);
        })
            ->where('state', 'closed')
            ->whereBetween('updated_at', [$start, $end])
            ->get();

        // PR details for highlights
        $prDetails = $prsMerged->map(fn ($pr) => [
            'title' => $pr->title,
            'number' => $pr->pr_number,
            'repo' => $pr->repo?->name,
            'author' => $pr->author,
            'lines_added' => $pr->additions ?? 0,
            'lines_removed' => $pr->deletions ?? 0,
        ])->toArray();

        return [
            'prs_merged' => $prsMerged->count(),
            'issues_closed' => $issuesClosed->count(),
            'total_lines_added' => $prsMerged->sum('additions'),
            'total_lines_removed' => $prsMerged->sum('deletions'),
            'pr_details' => $prDetails,
            'top_contributors' => $prsMerged->groupBy('author')
                ->map(fn ($g) => $g->count())
                ->sortDesc()
                ->take(5)
                ->toArray(),
        ];
    }

    /**
     * Aggregate task completion data.
     */
    protected function aggregateTaskData(Client $client, Carbon $start, Carbon $end): array
    {
        $projectIds = $client->projects()->pluck('id');

        $completedTasks = Task::whereIn('project_id', $projectIds)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$start, $end])
            ->get();

        $openedTasks = Task::whereIn('project_id', $projectIds)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // Group by priority
        $byPriority = $completedTasks->groupBy('priority')
            ->map(fn ($g) => $g->count())
            ->toArray();

        // Task details for highlights
        $taskDetails = $completedTasks->map(fn ($task) => [
            'title' => $task->title,
            'project' => $task->project?->name,
            'priority' => $task->priority,
            'completed_at' => $task->updated_at->toIso8601String(),
        ])->toArray();

        return [
            'completed_count' => $completedTasks->count(),
            'opened_count' => $openedTasks->count(),
            'by_priority' => $byPriority,
            'task_details' => $taskDetails,
        ];
    }

    /**
     * Aggregate Slack and communication activity metrics.
     */
    protected function aggregateActivityMetrics(Client $client, Carbon $start, Carbon $end): array
    {
        // Slack messages for this client
        $slackMessages = SlackMessage::where('client_id', $client->id)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // Messages from our team (not external)
        $ourMessages = $slackMessages->where('user_is_external', false);
        // Messages from client (external)
        $clientMessages = $slackMessages->where('user_is_external', true);

        // Thread participation - unique thread_ts values
        $threadsParticipated = $slackMessages->whereNotNull('thread_ts')
            ->pluck('thread_ts')
            ->unique()
            ->count();

        // Action items detected
        $actionItems = $slackMessages->where('has_action_item', true)->count();

        // Response time analysis (simplified - messages per day)
        $dailyActivity = $slackMessages->groupBy(fn ($m) => $m->created_at->format('Y-m-d'))
            ->map(fn ($g) => $g->count())
            ->toArray();

        $activeDays = count($dailyActivity);
        $avgMessagesPerActiveDay = $activeDays > 0
            ? round($slackMessages->count() / $activeDays, 1)
            : 0;

        return [
            'slack' => [
                'total_messages' => $slackMessages->count(),
                'our_messages' => $ourMessages->count(),
                'client_messages' => $clientMessages->count(),
                'threads_participated' => $threadsParticipated,
                'action_items_detected' => $actionItems,
                'active_days' => $activeDays,
                'avg_messages_per_day' => $avgMessagesPerActiveDay,
                'daily_breakdown' => $dailyActivity,
            ],
            'engagement_score' => $this->calculateEngagementScore(
                $slackMessages->count(),
                $threadsParticipated,
                $actionItems
            ),
        ];
    }

    /**
     * Calculate a simple engagement score (0-100).
     */
    protected function calculateEngagementScore(int $messages, int $threads, int $actionItems): int
    {
        // Simple weighted score
        // - Messages: up to 30 points (1 point per 5 messages, max 30)
        // - Threads: up to 40 points (2 points per thread, max 40)
        // - Action items resolved: up to 30 points (3 points per item, max 30)

        $messagePoints = min(30, intdiv($messages, 5));
        $threadPoints = min(40, $threads * 2);
        $actionPoints = min(30, $actionItems * 3);

        return $messagePoints + $threadPoints + $actionPoints;
    }

    /**
     * Aggregate retainer usage data — delegates to RetainerHealthService.
     */
    protected function aggregateRetainerData(Client $client, Carbon $start, Carbon $end): array
    {
        $retainer = RetainerPeriod::where('client_id', $client->id)
            ->where('period_start', '<=', $end)
            ->where('period_end', '>=', $start)
            ->first();

        if (! $retainer) {
            return [
                'has_retainer' => false,
                'included_hours' => 0,
                'used_hours' => 0,
                'remaining_hours' => 0,
                'usage_percent' => 0,
                'is_over' => false,
                'overage_hours' => 0,
            ];
        }

        $snapshot = app(RetainerHealthService::class)
            ->computeAndPersistSnapshot($retainer, $start, $end, persist: false);

        return array_merge(['has_retainer' => true], $snapshot);
    }

    /**
     * Generate AI content (summary, highlights, metrics).
     */
    protected function generateAIContent(
        Client $client,
        array $dataSnapshot,
        Carbon $start,
        Carbon $end
    ): array {
        $periodLabel = $start->format('F Y');

        $prompt = $this->buildAIPrompt($client, $dataSnapshot, $periodLabel);

        try {
            $response = $this->ai->message(
                prompt: $prompt,
                systemPrompt: $this->getReportSystemPrompt(),
                model: 'claude-sonnet-4-20250514'
            );

            $content = $response['content'][0]['text'] ?? '';

            return $this->parseAIResponse($content);
        } catch (\Exception $e) {
            Log::warning('Failed to generate AI content for report', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            // Return fallback content
            return $this->generateFallbackContent($dataSnapshot, $periodLabel);
        }
    }

    /**
     * Build the AI prompt with data context.
     */
    protected function buildAIPrompt(Client $client, array $data, string $periodLabel): string
    {
        $time = $data['time'];
        $github = $data['github'];
        $tasks = $data['tasks'];
        $activity = $data['activity'] ?? ['slack' => ['total_messages' => 0]];
        $retainer = $data['retainer'] ?? ['has_retainer' => false];

        $retainerContext = 'No retainer (hourly billing)';
        if ($retainer['has_retainer']) {
            $usedHrs = $retainer['total_equivalent_hours'] ?? $retainer['used_hours'] ?? 0;
            $budget = $retainer['hours_budget'] ?? $retainer['included_hours'] ?? 0;
            $usage = $retainer['usage_percent'] ?? 0;
            $retainerContext = "Retainer: {$usedHrs}/{$budget} hours used ({$usage}%)";
        }
        $agentContext = '';
        if (($retainer['agent_tasks_completed'] ?? 0) > 0) {
            $agentTasks = $retainer['agent_tasks_completed'];
            $agentCost = $retainer['agent_cost_usd'] ?? 0;
            $humanHrs = $retainer['human_hours'] ?? 0;
            $meetingHrs = $retainer['meeting_hours'] ?? 0;
            $agentContext = "\n- Agent tasks completed: {$agentTasks} (AI cost: \${$agentCost})"
                ."\n- Human hours: {$humanHrs}hrs | Meeting hours: {$meetingHrs}hrs";
        }

        return <<<PROMPT
Generate a monthly client report summary for {$client->name} for {$periodLabel}.

DATA:
- Total Hours: {$time['total_hours']} hours ({$time['billable_hours']} billable)
- {$retainerContext}{$agentContext}
- Time Entries: {$time['entries_count']} logged entries
- Meetings/Calls: {$time['meetings_count']}
- PRs Merged: {$github['prs_merged']}
- Issues Closed: {$github['issues_closed']}
- Lines of Code: +{$github['total_lines_added']} / -{$github['total_lines_removed']}
- Tasks Completed: {$tasks['completed_count']}
- Tasks Opened: {$tasks['opened_count']}
- Slack Messages: {$activity['slack']['total_messages']} ({$activity['slack']['our_messages']} from our team)
- Active Communication Days: {$activity['slack']['active_days']}

TOP WORK CATEGORIES:
{$this->formatCategories($time['by_category'])}

TOP PROJECTS:
{$this->formatProjects($time['by_project'])}

NOTABLE DELIVERABLES:
{$this->formatPRs($github['pr_details'])}

Please provide:
1. EXECUTIVE_SUMMARY: A 2-3 sentence professional summary highlighting the most impactful work done this month. Focus on business value delivered.

2. HIGHLIGHTS: 3-4 key achievements as bullet points. Each should have a title and brief description. Focus on shipped features, resolved issues, and measurable improvements.

3. HERO_METRICS: 3 impressive stats to highlight (format: label|value|context). Pick the most impressive numbers that show productivity and value.

Format your response as:
===EXECUTIVE_SUMMARY===
[summary text]
===HIGHLIGHTS===
- [Title]: [Description]
- [Title]: [Description]
- [Title]: [Description]
===HERO_METRICS===
[label]|[value]|[context]
[label]|[value]|[context]
[label]|[value]|[context]
PROMPT;
    }

    protected function formatCategories(array $categories): string
    {
        return collect($categories)
            ->take(5)
            ->map(fn ($c) => "- {$c['name']}: {$c['hours']} hours")
            ->implode("\n");
    }

    protected function formatProjects(array $projects): string
    {
        return collect($projects)
            ->take(5)
            ->map(fn ($p) => "- {$p['name']}: {$p['hours']} hours")
            ->implode("\n");
    }

    protected function formatPRs(array $prs): string
    {
        return collect($prs)
            ->take(10)
            ->map(fn ($pr) => "- {$pr['title']} ({$pr['repo']})")
            ->implode("\n") ?: '(No PRs this period)';
    }

    /**
     * Get the system prompt for report generation.
     */
    protected function getReportSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert at writing professional client reports for a software development agency.

Your reports should:
- Be professional but warm and personable
- Focus on business value and outcomes, not just activities
- Use specific numbers and metrics when available
- Highlight the most impactful work first
- Be concise - clients are busy executives

Tone: Professional, confident, results-oriented. Like a trusted partner sharing good news about progress made.
PROMPT;
    }

    /**
     * Parse the AI response into structured data.
     */
    protected function parseAIResponse(string $content): array
    {
        $summary = '';
        $highlights = [];
        $metrics = [];

        // Extract executive summary
        if (preg_match('/===EXECUTIVE_SUMMARY===\s*(.+?)(?===|$)/s', $content, $matches)) {
            $summary = trim($matches[1]);
        }

        // Extract highlights
        if (preg_match('/===HIGHLIGHTS===\s*(.+?)(?===|$)/s', $content, $matches)) {
            $highlightText = trim($matches[1]);
            preg_match_all('/- ([^:]+):\s*(.+)/', $highlightText, $bulletMatches, PREG_SET_ORDER);

            foreach ($bulletMatches as $match) {
                $highlights[] = [
                    'title' => trim($match[1]),
                    'description' => trim($match[2]),
                    'icon' => $this->pickHighlightIcon(trim($match[1])),
                ];
            }
        }

        // Extract hero metrics
        if (preg_match('/===HERO_METRICS===\s*(.+?)(?===|$)/s', $content, $matches)) {
            $metricsText = trim($matches[1]);
            $lines = array_filter(explode("\n", $metricsText));

            foreach ($lines as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 2) {
                    $metrics[] = [
                        'label' => trim($parts[0]),
                        'value' => trim($parts[1]),
                        'context' => trim($parts[2] ?? ''),
                    ];
                }
            }
        }

        return [
            'summary' => $summary,
            'highlights' => $highlights,
            'metrics' => array_slice($metrics, 0, 3),
        ];
    }

    /**
     * Pick an appropriate icon based on highlight title.
     */
    protected function pickHighlightIcon(string $title): string
    {
        $title = strtolower($title);

        if (str_contains($title, 'ship') || str_contains($title, 'launch') || str_contains($title, 'release')) {
            return '🚀';
        }
        if (str_contains($title, 'fix') || str_contains($title, 'bug') || str_contains($title, 'resolve')) {
            return '🔧';
        }
        if (str_contains($title, 'performance') || str_contains($title, 'speed') || str_contains($title, 'fast')) {
            return '⚡';
        }
        if (str_contains($title, 'security') || str_contains($title, 'auth')) {
            return '🔒';
        }
        if (str_contains($title, 'design') || str_contains($title, 'ui') || str_contains($title, 'ux')) {
            return '🎨';
        }
        if (str_contains($title, 'test') || str_contains($title, 'quality')) {
            return '✅';
        }
        if (str_contains($title, 'document') || str_contains($title, 'doc')) {
            return '📚';
        }
        if (str_contains($title, 'integrat')) {
            return '🔗';
        }

        return '✨';
    }

    /**
     * Generate fallback content if AI fails.
     */
    protected function generateFallbackContent(array $data, string $periodLabel): array
    {
        $time = $data['time'];
        $github = $data['github'];
        $tasks = $data['tasks'];

        return [
            'summary' => "In {$periodLabel}, we invested {$time['total_hours']} hours delivering value for your team. ".
                "We merged {$github['prs_merged']} pull requests, closed {$github['issues_closed']} issues, ".
                "and completed {$tasks['completed_count']} tasks.",
            'highlights' => [
                [
                    'title' => 'Consistent Delivery',
                    'description' => "Logged {$time['entries_count']} work sessions across the month.",
                    'icon' => '📈',
                ],
                [
                    'title' => 'Code Quality',
                    'description' => "Merged {$github['prs_merged']} pull requests with thorough review.",
                    'icon' => '✅',
                ],
                [
                    'title' => 'Issue Resolution',
                    'description' => "Closed {$github['issues_closed']} issues and completed {$tasks['completed_count']} tasks.",
                    'icon' => '🎯',
                ],
            ],
            'metrics' => [
                ['label' => 'Hours Invested', 'value' => "{$time['total_hours']}", 'context' => 'hours of focused work'],
                ['label' => 'PRs Shipped', 'value' => "{$github['prs_merged']}", 'context' => 'merged to production'],
                ['label' => 'Tasks Done', 'value' => "{$tasks['completed_count']}", 'context' => 'completed this month'],
            ],
        ];
    }

    /**
     * Get or create report settings for a client.
     */
    public function getOrCreateSettings(Client $client): ClientReportSettings
    {
        return ClientReportSettings::firstOrCreate(
            ['client_id' => $client->id],
            [
                'is_enabled' => true,
                'frequency' => 'monthly',
                'send_day' => 1,
                'include_time_breakdown' => true,
                'include_github_activity' => true,
                'include_tasks_completed' => true,
                'include_financials' => false,
                'include_upcoming' => true,
            ]
        );
    }

    /**
     * Get clients due for reports today.
     */
    public function getClientsDueForReports(): Collection
    {
        return ClientReportSettings::where('is_enabled', true)
            ->get()
            ->filter(fn ($settings) => $settings->shouldSendToday())
            ->map(fn ($settings) => $settings->client);
    }
}
