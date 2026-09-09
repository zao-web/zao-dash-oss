<?php

use App\Models\Agent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Agent Execution
|--------------------------------------------------------------------------
|
| Agents with a schedule configuration are automatically triggered at their
| configured intervals. Schedule format follows standard cron expressions.
| The command parses each agent's cron expression to determine if it's due.
|
*/
Schedule::command('agents:run-scheduled')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Expire Old Approval Requests
|--------------------------------------------------------------------------
*/
Schedule::call(function () {
    \App\Models\ApprovalRequest::where('status', 'pending')
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired']);
})->everyFiveMinutes()->name('expire-approvals');

/*
|--------------------------------------------------------------------------
| Expire Interaction Requests
|--------------------------------------------------------------------------
|
| Expire pending interaction requests and fail associated agent runs.
| Runs every 15 minutes to handle timeouts and free up workers.
|
*/
Schedule::command('interactions:expire --slack')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('expire-interactions');

/*
|--------------------------------------------------------------------------
| Agent Health Check
|--------------------------------------------------------------------------
|
| Reset circuit breakers for agents that have been broken for over 1 hour.
|
*/
Schedule::call(function () {
    Agent::whereNotNull('circuit_broken_at')
        ->where('circuit_broken_at', '<', now()->subHour())
        ->update(['circuit_broken_at' => null]);
})->hourly()->name('agent-health-check');

/*
|--------------------------------------------------------------------------
| Claude OAuth Token Expiration Check
|--------------------------------------------------------------------------
|
| Check if the Claude OAuth token needs refresh and trigger refresh job if
| needed. Runs daily to proactively refresh tokens before they expire.
| Tokens are checked for expiration and refreshed when 7 days remain.
|
*/
Schedule::command('claude:check-token-expiration')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->name('claude-token-check');

/*
|--------------------------------------------------------------------------
| Health Alert Escalation
|--------------------------------------------------------------------------
|
| Process unacknowledged health alerts and escalate as needed.
| Runs every 15 minutes to check for alerts that need escalation.
|
*/
Schedule::command('health:process-escalations')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('health-escalations');

/*
|--------------------------------------------------------------------------
| Strategic Goal Progress Updates
|--------------------------------------------------------------------------
|
| Update goal period actuals from revenue, leads, and pipeline data.
|
*/
Schedule::job(new \App\Jobs\UpdateGoalProgressJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('update-goal-progress');

/*
|--------------------------------------------------------------------------
| Funnel Metrics Snapshots
|--------------------------------------------------------------------------
|
| Capture daily funnel metrics snapshots for trend analysis.
|
*/
Schedule::job(new \App\Jobs\CalculateFunnelMetricsJob)
    ->dailyAt('23:55')
    ->withoutOverlapping()
    ->name('calculate-funnel-metrics');

/*
|--------------------------------------------------------------------------
| Process Agent Tasks
|--------------------------------------------------------------------------
|
| Process pending tasks assigned by the Business Strategist agent.
| Runs every 5 minutes to pick up delegated work.
|
*/
Schedule::job(new \App\Jobs\ProcessAgentTasksJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('process-agent-tasks');

/*
|--------------------------------------------------------------------------
| Integration Sync Jobs
|--------------------------------------------------------------------------
|
| Sync data from external services. Frequency varies by service importance
| and rate limits. All jobs use withoutOverlapping to prevent duplicate runs.
|
*/

// GSuite (Gmail + Calendar) - Every 4 hours, or real-time via webhooks
Schedule::job(new \App\Jobs\SyncGSuiteJob)
    ->everyFourHours()
    ->withoutOverlapping()
    ->name('sync-gsuite');

// Meeting Notifications - Process upcoming and completed meetings
Schedule::job(new \App\Jobs\ProcessMeetingNotificationsJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('process-meeting-notifications');

// Slack - Real-time via Events API webhooks, this is backup sync for missed events
// Runs every 30 minutes to catch anything webhooks missed
Schedule::job(new \App\Jobs\SyncSlackJob)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('sync-slack');

// RFP Email Scanning - Scan configured email sources for new RFP opportunities
Schedule::job(new \App\Jobs\ScanRfpEmailsJob)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('scan-rfp-emails');

// RFP Aggregation - Discover opportunities from government APIs, boards, and web sources
Schedule::job(new \App\Jobs\AggregateRfpsJob)
    ->everyFourHours()
    ->withoutOverlapping()
    ->name('aggregate-rfps');

// RFP Expiration Cleanup - Auto-decline RFPs whose submission deadlines have passed
Schedule::call(function () {
    $activeStatuses = ['discovered', 'evaluating', 'qualified', 'pursuing', 'proposal_drafting', 'proposal_review'];

    $expired = \App\Models\RfpOpportunity::query()
        ->whereIn('status', $activeStatuses)
        ->whereNotNull('submission_deadline')
        ->where('submission_deadline', '<', now())
        ->get();

    foreach ($expired as $rfp) {
        $rfp->update([
            'status' => 'declined',
            'priority' => 'low',
            'decline_reason' => "Deadline passed ({$rfp->submission_deadline->toDateString()}).",
        ]);
    }

    if ($expired->count() > 0) {
        \Illuminate\Support\Facades\Log::info('RFP expiration cleanup: declined expired opportunities', [
            'count' => $expired->count(),
        ]);
    }
})->dailyAt('06:30')->name('rfp-expiration-cleanup');

// RFP Stuck Proposal Watchdog - Reset proposals stuck in proposal_drafting > 30 minutes
Schedule::job(new \App\Jobs\WatchStuckRfpProposalsJob)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('watch-stuck-rfp-proposals');

// RFP Reply Monitoring - Detect replies from organizations we sent proposals to
Schedule::job(new \App\Jobs\MonitorRfpRepliesJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('monitor-rfp-replies');

// RFP Source Discovery - AI-powered search for new RFP boards and listing sites
Schedule::job(new \App\Jobs\DiscoverRfpSourcesJob)
    ->weeklyOn(1, '08:00')
    ->withoutOverlapping()
    ->name('discover-rfp-sources');

// RFP Outcome Analysis - Analyze unprocessed feedback and generate learning insights
Schedule::job(new \App\Jobs\AnalyzeRfpOutcomesJob)
    ->weeklyOn(5, '15:00')
    ->withoutOverlapping()
    ->name('analyze-rfp-outcomes');

// Slack AI Analysis - Process unanalyzed messages, detect action items, summarize threads
// Runs hourly as a catch-up for messages not processed in real-time
// Auto-creates tasks from high-confidence action items (>= 0.75 confidence)
Schedule::job(new \App\Jobs\ProcessSlackMessagesJob(autoCreateTasks: true))
    ->hourly()
    ->withoutOverlapping()
    ->name('process-slack-messages');

// Email AI Analysis - Process client emails for action items and urgency
// Runs hourly after GSuite sync, auto-creates tasks from high-confidence items
Schedule::job(new \App\Jobs\ProcessEmailsJob(autoCreateTasks: true))
    ->hourly()
    ->withoutOverlapping()
    ->name('process-client-emails');

// Meeting Automations - Pre-briefs 30min before meetings, post-followups after
Schedule::job(new \App\Jobs\ProcessMeetingAutomationsJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('process-meeting-automations');

// GitHub - Every 4 hours for repos/issues/PRs
Schedule::job(new \App\Jobs\SyncGitHubJob)
    ->everyFourHours()
    ->withoutOverlapping()
    ->name('sync-github');

// Harvest - Every 2 hours for time entries
Schedule::job(new \App\Jobs\SyncHarvestJob)
    ->everyTwoHours()
    ->withoutOverlapping()
    ->name('sync-harvest');

// Revenue Sync - Daily backup to ensure goal periods have accurate revenue data
// Primary sync happens automatically during Harvest reconciliation
Schedule::command('revenue:sync')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->name('sync-revenue');

// Notion - Every 6 hours for database items
Schedule::job(new \App\Jobs\SyncNotionJob)
    ->everySixHours()
    ->withoutOverlapping()
    ->name('sync-notion');

// WordPress - Every 4 hours for posts/pages
Schedule::job(new \App\Jobs\SyncWordPressJob)
    ->everyFourHours()
    ->withoutOverlapping()
    ->name('sync-wordpress');




// QuickBooks - Daily for financial data (less frequent to respect rate limits)
Schedule::job(new \App\Jobs\SyncQuickBooksJob)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->name('sync-quickbooks');

// Google Drive - Daily for document sync
Schedule::job(new \App\Jobs\SyncGoogleDriveJob)
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->name('sync-google-drive');

// ClickUp - Every hour for task sync from client workspaces
Schedule::job(new \App\Jobs\SyncClickUpJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('sync-clickup');

// X Bookmarks - Daily sync (free tier: 1 request per 24h)
// Syncs the 100 most recent bookmarks for AI analysis
// Note: The job itself enforces true 24h intervals from last sync
// This schedule just ensures it runs daily; the job will skip if within 24h
Schedule::command('x:sync-bookmarks')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->name('sync-x-bookmarks');

// Meta Ads - Every 2 hours for performance data, syncs campaigns/adsets/ads insights
Schedule::job(new \App\Jobs\SyncMetaAdsJob)
    ->everyTwoHours()
    ->withoutOverlapping()
    ->name('sync-meta-ads');

// SEO Performance - Daily sync of Search Console and GA4 data for programmatic SEO pages
Schedule::job(new \App\Jobs\SyncSeoPerformanceJob)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->name('sync-seo-performance');

// SEO Content Queue - Process queued SEO pages for autonomous content generation
Schedule::job(new \App\Jobs\ProcessSeoContentQueueJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('process-seo-content-queue');

// SEO Alerts - Check for stuck page generations
Schedule::job(new \App\Jobs\AlertStuckSeoGenerationsJob)
    ->hourly()
    ->name('seo-alert-stuck-pages');

/*
|--------------------------------------------------------------------------
| Contractor Invoice Detection
|--------------------------------------------------------------------------
|
| Scan emails from contractors to auto-detect and create invoices.
| Runs after GSuite sync (at 07:00) to process newly synced emails.
|
*/
Schedule::job(new \App\Jobs\DetectContractorInvoicesJob)
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->name('detect-contractor-invoices');

/*
|--------------------------------------------------------------------------
| Monthly Client Reports
|--------------------------------------------------------------------------
|
| Generate and send monthly client reports on the 1st of each month.
| Individual clients can have different send days configured in their
| report settings. This command checks each client's settings.
|
*/
Schedule::command('reports:generate --send --queue')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->name('generate-client-reports');

/*
|--------------------------------------------------------------------------
| Retainer Health
|--------------------------------------------------------------------------
|
| Roll retainer periods (create the current month, close last month) before
| computing snapshots, so the active period always reflects the current month.
| Compute retainer snapshots daily and match calendar events to clients.
| ClassifyTimeEntryEffort runs periodically to catch unclassified entries.
|
*/
Schedule::command('retainers:sync')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->name('sync-retainer-periods');

Schedule::job(new \App\Jobs\ComputeAllRetainerSnapshots)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->name('compute-retainer-snapshots');

Schedule::job(new \App\Jobs\MatchCalendarEventsToClients)
    ->hourly()
    ->withoutOverlapping()
    ->name('match-calendar-events');

Schedule::job(new \App\Jobs\ClassifyTimeEntryEffort)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('classify-time-entry-effort');

/*
|--------------------------------------------------------------------------
| Invoice Reminders
|--------------------------------------------------------------------------
|
| Send invoice payment reminders based on due dates.
| Runs every hour to process scheduled reminders.
|
*/
Schedule::job(new \App\Jobs\SendInvoiceRemindersJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('send-invoice-reminders');

/*
|--------------------------------------------------------------------------
| Recurring Invoices
|--------------------------------------------------------------------------
|
| Generate monthly recurring invoices for retainer clients.
| Runs daily at 6am to create invoices on each client's configured day,
| creating the PayPal invoice and auto-sending where configured.
|
| NOTE: the old `invoices:generate-recurring` command was ALSO scheduled here
| (at 08:00). Its send step was never implemented (a `// TODO`), so it minted
| unsendable duplicate drafts every billing day. It has been removed in favour
| of this job, which is the single source of recurring invoices.
|
*/
Schedule::job(new \App\Jobs\GenerateRecurringInvoicesJob)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->name('generate-recurring-invoices');

/*
|--------------------------------------------------------------------------
| Overdue Invoice Detection
|--------------------------------------------------------------------------
|
| Mark unpaid invoices as overdue when past their due date.
| Runs daily at 6:15am after recurring invoice generation.
|
*/
Schedule::job(new \App\Jobs\UpdateOverdueInvoicesJob)
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->name('update-overdue-invoices');

Schedule::job(new \App\Jobs\SyncPayPalInvoiceStatusJob)
    ->everyTwoHours()
    ->withoutOverlapping()
    ->name('sync-paypal-invoice-status');


Schedule::job(new \App\Jobs\MonitorContractorThresholdsJob)
    ->monthlyOn(15, '08:00')
    ->withoutOverlapping()
    ->name('monitor-contractor-thresholds');


Schedule::job(new \App\Jobs\WeeklyFinancialDigestJob)
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->name('weekly-financial-digest');

/*
|--------------------------------------------------------------------------
| Financial Slingshot: Urgency Alerts
|--------------------------------------------------------------------------
|
| Scan financial data for critical alerts (overdue invoices, upcoming
| payments, tax deadlines, cash shortfalls, revenue gaps). Sends Slack
| DMs for critical/high severity alerts.
|
*/
Schedule::job(new \App\Jobs\CheckFinancialAlertsJob)
    ->everyFourHours()
    ->withoutOverlapping()
    ->name('check-financial-alerts');

/*
|--------------------------------------------------------------------------
| Financial Slingshot: Profitability Views
|--------------------------------------------------------------------------
|
| Populate the profitability_views table with client profitability data
| from invoices and time entries for reporting dashboards.
|
*/
Schedule::job(new \App\Jobs\PopulateProfitabilityViewsJob)
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->name('populate-profitability-views');

/*
|--------------------------------------------------------------------------
| Financial Slingshot: Net Worth Snapshot
|--------------------------------------------------------------------------
|
| Capture a daily net worth snapshot from current account balances and
| debt data. Runs nightly to track net worth trends over time.
|
*/
Schedule::job(new \App\Jobs\CaptureNetWorthSnapshotJob)
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->name('capture-net-worth-snapshot');

/*
|--------------------------------------------------------------------------
| Client Activity Feed: Current Work Synthesis
|--------------------------------------------------------------------------
|
| Hourly during business hours, walks every active-retainer client and
| synthesises their current in-flight work from Slack + email + GitHub
| + internal tasks. Output lands as Task rows (source='activity-feed')
| visible in the Tasks tab and the Activity view. Per-client debounce
| (30 min) inside the command prevents overlap with manual runs.
|
*/
Schedule::command('activity:sync')
    ->cron('0 8-18 * * 1-5')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->name('sync-client-activity');

/*
|--------------------------------------------------------------------------
| Client Sheet Task Sync
|--------------------------------------------------------------------------
|
| Bidirectional sync between configured per-client Google Sheets and the
| internal Task table. We pull Pending rows in as Tasks, push Underway /
| Reviewing back as we work. Clients keep ownership of Pending (initial)
| and Done (final verdict).
|
*/
Schedule::command('sheets:sync')
    ->cron('*/15 8-18 * * 1-5')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->name('sync-sheet-tasks');
