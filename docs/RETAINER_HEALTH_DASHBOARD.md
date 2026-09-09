# Retainer Health Dashboard

## Overview

This document specifies the design, data model, and implementation plan for the Retainer Health Dashboard — a portfolio-level view of all active client retainers showing effort consumption, margin, AI vs. human work split, and automated health signals.

The system extends and integrates with existing infrastructure:
- `RetainerPeriod` model (already exists — `hours_included`, `hours_used`, `rollover_hours`, `overage_rate`)
- `ClientReportService` (already aggregates retainer data via `aggregateRetainerData()`)
- `CalendarEvent` model with `is_client_meeting` flag and `attendees` JSON (already exists)
- `AgentRun` model with `cost_usd`, `project_id`, `task_id` (already exists)
- `TimeEntry` model with Harvest sync (already exists)

The goal: Justin sees the full portfolio health at a glance, can identify margin outliers instantly, and can trigger client reports or AI-drafted actions directly from the dashboard — all with zero manual data entry.

---

## Design Principles

1. **Margin is the north star metric.** Not hours, not tickets — margin. It's the number that tells you whether a retainer is healthy or needs attention.
2. **AI effort is tracked in dollars, displayed as equivalent hours.** Agent runs have real `cost_usd`. We normalize to "equivalent hours" at a configurable rate so everything reads in one unit on screen.
3. **Zero manual time entry.** Meeting time is captured automatically via Google Calendar attendee matching. Development time comes from Harvest sync + GitHub. Agent time comes from `agent_runs`.
4. **One data pipeline, two views.** The same `RetainerHealthService` snapshot powers the internal dashboard AND feeds directly into the existing `ClientReportService` — no duplicated aggregation logic.
5. **Actionable, not just informational.** Every card surfaces context-aware action buttons. Outliers get AI-drafted responses ready to review.

---

## Stack Reference

- PHP 8.4 / Laravel 12
- Inertia.js v2 + Vue 3
- Tailwind CSS v4
- Pest v3 for tests
- Horizon for queued jobs

Follow all conventions in `CLAUDE.md` and sibling files. Use `php artisan make:` for all generated files. Run `vendor/bin/pint --dirty` before finalizing PHP changes.

---

## Database Changes

### 1. Extend `retainer_periods` table

The existing `RetainerPeriod` model already tracks hours but lacks: service tier, monthly dollar value, agentic cost totals, and health status.

**Migration name:** `add_service_and_health_fields_to_retainer_periods_table`

```php
Schema::table('retainer_periods', function (Blueprint $table) {
    // Service tier
    $table->string('tier')->nullable()->after('status');
    // Values: 'app_only' | 'web_only' | 'app_and_web'

    $table->decimal('monthly_amount', 12, 2)->nullable()->after('tier');
    // The contracted monthly retainer value in USD

    $table->json('included_services')->nullable()->after('monthly_amount');
    // e.g. ['app_maintenance', 'web_maintenance', 'hosting', 'monitoring']

    $table->decimal('internal_hourly_rate', 8, 2)->default(125.00)->after('included_services');
    // Rate used to convert human hours → dollar cost for margin calculation

    $table->decimal('ai_equivalent_hourly_rate', 8, 2)->default(50.00)->after('internal_hourly_rate');
    // Rate used to normalize agent cost_usd into "equivalent hours" for display
    // Formula: equivalent_hours = cost_usd / ai_equivalent_hourly_rate

    // Agentic effort totals (updated by RetainerHealthService::computeAndPersistSnapshot())
    $table->decimal('agent_cost_usd', 10, 4)->default(0)->after('ai_equivalent_hourly_rate');
    $table->integer('agent_tasks_completed')->default(0)->after('agent_cost_usd');

    // Computed margin snapshot (updated daily by ComputeAllRetainerSnapshots job)
    $table->decimal('effective_margin_percent', 5, 2)->nullable()->after('agent_tasks_completed');
    // Formula: ((monthly_amount - total_cost_to_serve) / monthly_amount) * 100

    // Health status — drives dashboard badge and filter
    $table->string('health_status')->default('healthy')->after('effective_margin_percent');
    // Values: 'healthy' | 'warning' | 'critical' | 'silent'

    $table->timestamp('last_client_activity_at')->nullable()->after('health_status');
    // Updated whenever: client submits ticket, attends meeting, opens report, replies to comms

    $table->index(['health_status', 'period_start']);
});
```

### 2. Add `effort_type` to `time_entries`

**Migration name:** `add_effort_type_to_time_entries_table`

```php
Schema::table('time_entries', function (Blueprint $table) {
    $table->string('effort_type')->nullable()->after('notes');
    // Values: 'meeting' | 'development' | 'review' | 'deployment' | 'communication' | 'planning' | 'other'
    // Auto-classified by ClassifyTimeEntryEffort job
    // Can be manually overridden in the UI
});
```

### 3. Add `effort_type` and `client_id` to `agent_runs`

**Migration name:** `add_effort_type_and_client_to_agent_runs_table`

```php
Schema::table('agent_runs', function (Blueprint $table) {
    $table->foreignId('client_id')->nullable()->after('task_id')
        ->constrained()->nullOnDelete();
    // Denormalized for fast portfolio queries — mirrors project→client relationship

    $table->string('effort_type')->nullable()->after('client_id');
    // Values: 'development' | 'monitoring' | 'deployment' | 'triage' | 'reporting' | 'onboarding'

    $table->index('client_id');
});
```

### 4. Add agentic columns to `client_reports`

**Migration name:** `add_agentic_fields_to_client_reports_table`

```php
Schema::table('client_reports', function (Blueprint $table) {
    $table->decimal('human_hours', 8, 2)->default(0)->after('meetings_held');
    // Explicit human-only hours, separate from total_hours
    $table->decimal('total_agent_cost_usd', 10, 4)->default(0)->after('human_hours');
    $table->integer('agent_tasks_completed')->default(0)->after('total_agent_cost_usd');
    $table->decimal('effective_margin_percent', 5, 2)->nullable()->after('agent_tasks_completed');
});
```

### Backfill note

After running migrations, backfill `agent_runs.client_id` from existing project relationships:

```php
// Run via php artisan tinker
AgentRun::whereNull('client_id')
    ->whereNotNull('project_id')
    ->with('project')
    ->each(function ($run) {
        if ($run->project?->client_id) {
            $run->update(['client_id' => $run->project->client_id]);
        }
    });
```

---

## New Service: `RetainerHealthService`

**Location:** `app/Services/Reports/RetainerHealthService.php`

This is the core computation engine. It produces a structured snapshot for any retainer period and is called by both the dashboard controller and `ClientReportService`. Single source of truth — no duplicated aggregation.

### Responsibilities

1. Aggregate human hours from `time_entries` by `effort_type` for the period
2. Aggregate meeting hours from `calendar_events` where `is_client_meeting = true`
3. Aggregate agent cost and task count from `agent_runs` by `client_id` for the period
4. Compute total cost to serve: `(human_hours × internal_hourly_rate) + agent_cost_usd`
5. Compute effective margin: `((monthly_amount - cost_to_serve) / monthly_amount) × 100`
6. Determine health status (see Health Status Logic below)
7. Build alerts array from computed data
8. Optionally persist snapshot back to the `retainer_periods` row
9. Return typed array for use by dashboard and report service

### Method Signature

```php
namespace App\Services\Reports;

use App\Models\RetainerPeriod;
use Carbon\Carbon;

class RetainerHealthService
{
    /**
     * Compute full health snapshot for a retainer period.
     *
     * @param  bool  $persist  Whether to write results back to retainer_periods row.
     *                         Pass false when called from ClientReportService to avoid
     *                         mid-month overwrites during report generation.
     *
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
     *   effective_margin_percent: float,
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
    ): array {
        $humanHoursData  = $this->aggregateHumanHours($retainer, $periodStart, $periodEnd);
        $meetingData     = $this->aggregateMeetings($retainer, $periodStart, $periodEnd);
        $agentData       = $this->aggregateAgentRuns($retainer, $periodStart, $periodEnd);

        $humanHours           = $humanHoursData['total'];
        $agentCostUsd         = $agentData['cost_usd'];
        $aiEquivRate          = (float) $retainer->ai_equivalent_hourly_rate ?: 50.0;
        $agentEquivHours      = $aiEquivRate > 0 ? round($agentCostUsd / $aiEquivRate, 2) : 0;
        $totalEquivHours      = round($humanHours + $agentEquivHours, 2);

        $internalRate         = (float) $retainer->internal_hourly_rate ?: 125.0;
        $costToServe          = round(($humanHours * $internalRate) + $agentCostUsd, 2);
        $monthlyAmount        = (float) ($retainer->monthly_amount ?? 0);
        $marginPercent        = $monthlyAmount > 0
            ? round((($monthlyAmount - $costToServe) / $monthlyAmount) * 100, 2)
            : null;

        $hoursBudget          = $retainer->total_hours;  // uses existing getTotalHoursAttribute()
        $usagePercent         = $hoursBudget > 0
            ? round(($totalEquivHours / $hoursBudget) * 100, 1)
            : 0;

        $healthStatus         = $this->determineHealthStatus(
            $retainer, $marginPercent, $usagePercent
        );

        $snapshot = [
            'human_hours'            => $humanHours,
            'human_hours_by_type'    => $humanHoursData['by_type'],
            'meeting_hours'          => $meetingData['hours'],
            'meeting_count'          => $meetingData['count'],
            'agent_cost_usd'         => $agentCostUsd,
            'agent_tasks_completed'  => $agentData['tasks_completed'],
            'agent_equivalent_hours' => $agentEquivHours,
            'total_equivalent_hours' => $totalEquivHours,
            'hours_budget'           => $hoursBudget,
            'hours_remaining'        => max(0, round($hoursBudget - $totalEquivHours, 2)),
            'usage_percent'          => $usagePercent,
            'is_over_budget'         => $totalEquivHours > $hoursBudget,
            'cost_to_serve'          => $costToServe,
            'monthly_amount'         => $monthlyAmount,
            'effective_margin_percent' => $marginPercent,
            'health_status'          => $healthStatus,
            'last_client_activity_at'=> $retainer->last_client_activity_at?->toIso8601String(),
            'alerts'                 => [],
        ];

        $snapshot['alerts'] = $this->buildAlerts($retainer, $snapshot);

        if ($persist) {
            $retainer->update([
                'agent_cost_usd'          => $agentCostUsd,
                'agent_tasks_completed'   => $agentData['tasks_completed'],
                'effective_margin_percent'=> $marginPercent,
                'health_status'           => $healthStatus,
            ]);
        }

        return $snapshot;
    }
```

### Health Status Logic

Health status is evaluated in priority order — the first matching condition wins:

| Priority | Condition | Status |
|---|---|---|
| 1 | `last_client_activity_at` is null OR > 45 days ago | `silent` |
| 2 | `effective_margin_percent` < 0 | `critical` |
| 3 | `effective_margin_percent` < 20 OR `usage_percent` > 110 | `warning` |
| 4 | Everything else | `healthy` |

```php
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
```

### Alerts Array

Each alert in the returned `alerts` array is a structured object:

```php
[
    'type'    => 'danger' | 'warning' | 'info',
    'message' => string,   // Human-readable, shown as chip on dashboard card
    'action'  => string | null,  // If set, shown as an action button; value is the prompt text
]
```

**Auto-generated alerts (evaluated in `buildAlerts()`):**

| Condition | Type | Message | Action |
|---|---|---|---|
| `is_over_budget` | danger | "Over budget by X hrs" | "Draft retainer repricing conversation for {client}" |
| `margin < 0` | danger | "Losing ${X}/mo on this retainer" | "Draft retainer repricing conversation for {client}" |
| `margin < 20 && margin >= 0` | warning | "Thin margin — {X}%" | null |
| `usage_percent >= 80 && <= 100` | warning | "80%+ of hour budget consumed" | null |
| `meeting_count == 0` | info | "No meetings this month — consider check-in" | "Draft a personal check-in message for {client}" |
| `last_activity > 30 days` | warning | "No client activity in {X} days" | "Draft a personal check-in for {client} — silent {X} days" |
| Failed agent runs in period | warning | "Recurring integration failure this period" | "Show full failure log for {client}" |
| Same ticket type 3+ times | info | "Upsell signal: repeated {type} request" | "Draft an upsell proposal for {client}" |
| Month is October | info | "Pre-season audit due — run checklist" | "Run pre-season audit for {client}" |

---

## Google Calendar Attendee Matching

**Location:** `app/Jobs/MatchCalendarEventsToClients.php`

Scheduled hourly. Processes past `calendar_events` where `is_client_meeting = false` and `end_at < now()`.

### Logic

```php
// For each unmatched past calendar event:
// 1. Decode attendees JSON — array of {email, name, responseStatus}
// 2. Extract email addresses
// 3. Query: ClientContact::whereIn('email', $attendeeEmails)->with('client')->get()
// 4. If match found:
//    a. $event->update(['is_client_meeting' => true, 'client_id' => $match->client_id])
//    b. Set project_id if client has exactly one active retainer project
//    c. Create time_entry ONLY if none exists with external_reference containing this google_event_id:
//       TimeEntry::create([
//           'client_id'          => $match->client_id,
//           'project_id'         => $projectId,
//           'hours'              => $event->duration_hours,     // uses getDurationHoursAttribute()
//           'effort_type'        => 'meeting',
//           'spent_date'         => $event->start_at->toDateString(),
//           'notes'              => "Client meeting: {$event->title} (auto-captured)",
//           'billable'           => false,                      // meetings not billable separately
//           'external_reference' => ['source' => 'calendar', 'google_event_id' => $event->google_event_id],
//       ])
//    d. Update retainer->last_client_activity_at = $event->start_at
```

**Duplicate guard:** Before creating a `TimeEntry`, check:
```php
TimeEntry::where('external_reference->google_event_id', $event->google_event_id)->exists()
```

---

## Time Entry Effort Classifier

**Location:** `app/Jobs/ClassifyTimeEntryEffort.php`

Dispatched by a `TimeEntry` observer on `created` and `updated` (when `effort_type` is null). Classification rules evaluated in priority order:

```php
protected function classify(TimeEntry $entry): string
{
    $notes    = strtolower($entry->notes ?? '');
    $category = strtolower($entry->harvestTaskCategory?->name ?? '');
    $combined = $notes . ' ' . $category;

    // 1. Meeting signals
    if (str_contains_any($combined, ['meeting', 'call', 'sync', 'standup', 'demo', 'review call'])) {
        return 'meeting';
    }
    // 2. Review / QA
    if (str_contains_any($combined, ['review', 'qa', 'test', 'audit'])) {
        return 'review';
    }
    // 3. Deployment
    if (str_contains_any($combined, ['deploy', 'migration', 'release', 'launch'])) {
        return 'deployment';
    }
    // 4. Planning
    if (str_contains_any($combined, ['plan', 'spec', 'scope', 'estimate', 'discovery'])) {
        return 'planning';
    }
    // 5. Communication
    if (str_contains_any($combined, ['email', 'slack', 'message', 'comms', 'response'])) {
        return 'communication';
    }
    // 6. Default
    return 'development';
}
```

Helper: `str_contains_any(string $haystack, array $needles): bool` — returns true if any needle found.

---

## Dashboard Controller

**Location:** `app/Http/Controllers/RetainerController.php`

```
php artisan make:controller RetainerController --no-interaction
```

### `index()` — Portfolio view

Returns portfolio metrics strip + list of active retainer periods ordered by health status (critical → warning → silent → healthy).

Uses Inertia v2 deferred props for per-card health snapshots so the page renders immediately with cached data while fresh snapshots load asynchronously.

```php
public function index(): \Inertia\Response
{
    $retainers = RetainerPeriod::withHealthSummary()->get();  // uses scopeWithHealthSummary()

    $portfolioMetrics = [
        'total_mrr'          => $retainers->sum('monthly_amount'),
        'avg_margin_percent' => $retainers->whereNotNull('effective_margin_percent')
                                           ->avg('effective_margin_percent'),
        'active_count'       => $retainers->count(),
        'critical_count'     => $retainers->where('health_status', 'critical')->count(),
    ];

    return Inertia::render('Retainers/Index', [
        'retainers'        => RetainerResource::collection($retainers),
        'portfolioMetrics' => $portfolioMetrics,
        'currentMonth'     => now()->format('F Y'),
    ]);
}
```

### `show(RetainerPeriod $retainer)` — Single retainer deep-dive

```php
public function show(RetainerPeriod $retainer): \Inertia\Response
{
    $retainer->load('client.contacts');

    // 6-month trend: one snapshot per month for sparkline
    $trend = collect(range(5, 0))->map(function ($monthsAgo) use ($retainer) {
        $start = now()->subMonths($monthsAgo)->startOfMonth();
        $end   = now()->subMonths($monthsAgo)->endOfMonth();
        return app(RetainerHealthService::class)
            ->computeAndPersistSnapshot($retainer, $start, $end, persist: false);
    });

    return Inertia::render('Retainers/Show', [
        'retainer'     => new RetainerResource($retainer),
        'trend'        => $trend,
        'agentRuns'    => AgentRunResource::collection(
            AgentRun::where('client_id', $retainer->client_id)
                ->latest()->limit(20)->get()
        ),
        'meetings'     => CalendarEventResource::collection(
            CalendarEvent::where('client_id', $retainer->client_id)
                ->where('is_client_meeting', true)
                ->where('start_at', '>=', $retainer->period_start)
                ->orderByDesc('start_at')->get()
        ),
        'openTasks'    => TaskResource::collection(
            Task::whereIn('project_id', $retainer->client->projects->pluck('id'))
                ->where('status', '!=', 'done')
                ->orderBy('priority')->get()
        ),
        'reportHistory'=> ClientReportResource::collection(
            ClientReport::where('client_id', $retainer->client_id)
                ->orderByDesc('period_start')->limit(12)->get()
        ),
    ]);
}
```

### `computeSnapshot(RetainerPeriod $retainer)` — On-demand refresh

```php
// POST /retainers/{retainer}/snapshot
public function computeSnapshot(RetainerPeriod $retainer): \Illuminate\Http\JsonResponse
{
    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $retainer,
        $retainer->period_start,
        $retainer->period_end,
        persist: true,
    );

    return response()->json($snapshot);
}
```

---

## Routes

Add to `routes/web.php` within the authenticated middleware group:

```php
Route::prefix('retainers')->name('retainers.')->group(function () {
    Route::get('/', [RetainerController::class, 'index'])->name('index');
    Route::get('/{retainer}', [RetainerController::class, 'show'])->name('show');
    Route::post('/{retainer}/snapshot', [RetainerController::class, 'computeSnapshot'])->name('snapshot');
});
```

---

## Frontend: `Retainers/Index.vue`

### Layout

```
<AppLayout>
  ├── Page header
  │     ├── "Retainers" h1
  │     ├── Month label (e.g. "April 2026")
  │     ├── Active count badge
  │     └── "Send all reports" button → POST to bulk report generation
  ├── <PortfolioMetricsStrip :metrics="portfolioMetrics" />
  ├── <FilterBar> (All | Needs attention | Healthy | Over budget | Silent 30+ days)
  └── <RetainerCard v-for="retainer in filteredRetainers" :retainer="retainer" />
```

### `PortfolioMetricsStrip`

**File:** `resources/js/Components/Retainers/PortfolioMetricsStrip.vue`

Four metric cards in a 4-column grid:
1. **Monthly MRR** — `$X,XXX` with month-over-month delta
2. **Portfolio margin** — `XX%` weighted average
3. **Human hours** — total human-only hours across all clients this month
4. **Agent efficiency** — `XXX tasks / $XX AI cost`

### `RetainerCard`

**File:** `resources/js/Components/Retainers/RetainerCard.vue`

**Props:** `retainer` object from `RetainerResource` (see API Resources section)

**Visual states by `health_status`:**

| Status | Left border | Badge |
|---|---|---|
| `healthy` | none | green "Healthy" |
| `warning` | `border-l-2` amber | amber badge |
| `critical` | `border-l-2` red | red "Needs attention" |
| `silent` | `border-l-2` amber | amber "Silent Xd" |

**Collapsed view:**
- Row 1: Avatar initials circle + Client name + MRR + badges
- Row 2: Hours progress bar (total equivalent / budget) — color shifts green → amber → red at 80% / 100%
- Row 3: Thin secondary bar showing human vs AI split
- Row 4: Cost to serve + margin pill

**Expanded view** (toggle on card click):
- Effort breakdown grid: Meetings hrs | Dev hrs | Agent tasks | Tickets closed
- Alert chips (from `alerts` array, color-coded by `type`)
- Action buttons (generated from `alerts[].action` where not null, plus always-present "Send report")

**Margin pill colors:**

| Margin | Tailwind classes |
|---|---|
| ≥ 60% | `bg-green-50 text-green-700` |
| 30–59% | `bg-blue-50 text-blue-700` |
| 10–29% | `bg-amber-50 text-amber-700` |
| < 10% | `bg-red-50 text-red-700` |

---

## Frontend: `Retainers/Show.vue`

Single retainer deep-dive. Seven sections stacked vertically:

1. **Header strip** — Client name, tier badge, period dates, MRR, health pill, "Send report" button
2. **This month snapshot** — same 4-cell effort grid as expanded card, plus margin + cost
3. **6-month margin trend** — ApexCharts line chart (`type: 'line'`) with margin % on Y axis, months on X. Threshold line at 20% and 0%. Color fill: green above 20%, amber 0–20%, red below 0%.
4. **6-month effort breakdown** — ApexCharts stacked bar: meetings / development / review / agent (equivalent hours)
5. **Recent agent runs** — Table: task description | effort_type | cost_usd | duration | status. Paginated, 20 per page.
6. **Meeting log** — List of calendar events with: event title | date | duration | attendees. Empty state: "No meetings recorded — check Google Calendar sync."
7. **Open tickets** — Tasks grouped by priority (urgent → high → medium → low). Shows title, age, status.
8. **Report history** — Table of past client_reports: period | status | sent_at | opens_count. "Generate report" button for current period.

---

## API Resources

**`app/Http/Resources/RetainerResource.php`**

Include: all `retainer_periods` fields + nested `client` (id, name, initials computed) + computed attributes (`agent_equivalent_hours`, `remaining_hours`, `usage_percent`, `is_over_budget`, `overage_hours`) + `alerts` array + `health_status`.

**`app/Http/Resources/AgentRunResource.php`** — already exists or create: id, task (truncated 80 chars), effort_type, cost_usd, duration_ms, status, started_at.

---

## Integration with `ClientReportService`

### Replace `aggregateRetainerData()`

Swap the existing implementation to delegate to `RetainerHealthService`:

```php
protected function aggregateRetainerData(Client $client, Carbon $start, Carbon $end): array
{
    $retainer = RetainerPeriod::where('client_id', $client->id)
        ->where('period_start', '<=', $end)
        ->where('period_end', '>=', $start)
        ->first();

    if (! $retainer) {
        return [
            'has_retainer'    => false,
            'included_hours'  => 0,
            'used_hours'      => 0,
            'remaining_hours' => 0,
            'usage_percent'   => 0,
            'is_over'         => false,
            'overage_hours'   => 0,
        ];
    }

    $snapshot = app(RetainerHealthService::class)
        ->computeAndPersistSnapshot($retainer, $start, $end, persist: false);

    return array_merge(['has_retainer' => true], $snapshot);
}
```

### Extend `generateReport()` to populate new columns

```php
// Add to the ClientReport::create([...]) call:
'human_hours'              => $retainerData['human_hours'] ?? 0,
'total_agent_cost_usd'     => $retainerData['agent_cost_usd'] ?? 0,
'agent_tasks_completed'    => $retainerData['agent_tasks_completed'] ?? 0,
'effective_margin_percent' => $retainerData['effective_margin_percent'] ?? null,
```

### Extend `buildAIPrompt()` with agentic context

Add to the DATA section of the prompt:

```
- Agent tasks completed: {agent_tasks_completed} (AI cost: ${agent_cost_usd})
- Human hours: {human_hours}hrs | Meeting hours: {meeting_hours}hrs | Dev hours: {dev_hours}hrs
- Effective margin this month: {effective_margin_percent}%
```

---

## Scheduled Jobs

Add to `routes/console.php`:

```php
use App\Jobs\MatchCalendarEventsToClients;
use App\Jobs\ComputeAllRetainerSnapshots;
use App\Jobs\ClassifyTimeEntryEffort;

Schedule::job(MatchCalendarEventsToClients::class)->hourly();
Schedule::job(ComputeAllRetainerSnapshots::class)->dailyAt('06:00');
Schedule::job(ClassifyTimeEntryEffort::class)->everyThirtyMinutes();
```

### `ComputeAllRetainerSnapshots`

**Location:** `app/Jobs/ComputeAllRetainerSnapshots.php`

Fetches all active `RetainerPeriod` records and dispatches one `ComputeRetainerSnapshot` job per retainer onto the `default` queue. Never does the work synchronously — always queued to avoid timeout risk as the portfolio grows.

### `ComputeRetainerSnapshot`

**Location:** `app/Jobs/ComputeRetainerSnapshot.php`

Single-retainer job. Calls `RetainerHealthService::computeAndPersistSnapshot()` with `persist: true`. If `health_status` resolves to `critical`, dispatches a `RetainerHealthAlert` notification to Justin via the existing notification system.

---

## Model Updates

### `RetainerPeriod`

```php
// Add to casts():
'monthly_amount'            => 'decimal:2',
'internal_hourly_rate'      => 'decimal:2',
'ai_equivalent_hourly_rate' => 'decimal:2',
'agent_cost_usd'            => 'decimal:4',
'effective_margin_percent'  => 'decimal:2',
'included_services'         => 'array',
'last_client_activity_at'   => 'datetime',

// New accessor:
public function getAgentEquivalentHoursAttribute(): float
{
    $rate = (float) $this->ai_equivalent_hourly_rate;
    if ($rate === 0.0) {
        return 0.0;
    }
    return round((float) $this->agent_cost_usd / $rate, 2);
}

// New scope — used by RetainerController::index():
public function scopeWithHealthSummary(Builder $query): Builder
{
    return $query
        ->with(['client', 'client.contacts'])
        ->active()
        ->orderByRaw("FIELD(health_status, 'critical', 'warning', 'silent', 'healthy')");
}
```

### `AgentRun`

```php
// Add relationship:
public function client(): BelongsTo
{
    return $this->belongsTo(Client::class);
}
```

### `CalendarEvent`

```php
// Add accessor:
public function getDurationHoursAttribute(): float
{
    return round($this->start_at->diffInMinutes($this->end_at) / 60, 2);
}
```

---

## Tests

### `tests/Feature/Retainers/RetainerDashboardTest.php`

```php
it('renders retainer index for authenticated user')
it('shows portfolio metrics correctly aggregated across all retainers')
it('filters retainers by health status via query param')
it('orders retainers critical first then warning then silent then healthy')
it('computes and persists snapshot via POST snapshot endpoint')
it('returns 403 for unauthenticated users on index')
it('returns 403 for unauthenticated users on show')
```

### `tests/Feature/Retainers/RetainerHealthServiceTest.php`

```php
it('computes human hours from time entries in period correctly')
it('computes agent cost from agent_runs filtered by client_id and period')
it('converts agent cost to equivalent hours using ai_equivalent_hourly_rate')
it('computes effective margin correctly when monthly_amount is set')
it('returns null margin when monthly_amount is zero')
it('sets health_status to critical when margin is negative')
it('sets health_status to warning when usage_percent exceeds 110')
it('sets health_status to warning when margin is below 20')
it('sets health_status to silent when last_client_activity_at is null')
it('sets health_status to silent when last_client_activity_at exceeds 45 days')
it('silent overrides critical in health status priority')
it('generates over_budget danger alert with correct hours in message')
it('generates negative margin danger alert with dollar amount')
it('generates no_meetings info alert when meeting_count is zero')
it('generates upsell info alert when same ticket type submitted 3 or more times')
it('generates pre_season info alert in October')
it('persists snapshot fields to retainer_periods when persist is true')
it('does not update retainer_periods when persist is false')
```

### `tests/Feature/Jobs/MatchCalendarEventsToClientsTest.php`

```php
it('matches calendar event to client by attendee email address')
it('sets is_client_meeting to true on matched event')
it('sets client_id on matched calendar event')
it('creates time_entry for matched meeting with correct duration and effort_type meeting')
it('does not create duplicate time_entry when external_reference already contains google_event_id')
it('does not match events with no attendees in client_contacts')
it('updates last_client_activity_at on retainer period after match')
it('skips future events that have not ended yet')
```

### `tests/Feature/Jobs/ClassifyTimeEntryEffortTest.php`

```php
it('classifies meeting from harvest task category name containing meeting keyword')
it('classifies meeting from notes containing call keyword')
it('classifies meeting from notes containing sync keyword')
it('classifies review from notes containing review keyword')
it('classifies deployment from notes containing deploy keyword')
it('classifies planning from notes containing scope keyword')
it('classifies communication from notes containing email keyword')
it('classifies development as default when no other keyword matches')
it('does not reclassify entries that already have effort_type set')
```

---

## Implementation Order

Build in this sequence to avoid blocked work:

1. **All 4 migrations** — `php artisan migrate`, verify with `php artisan tinker` spot checks
2. **Backfill `agent_runs.client_id`** — one-time tinker command (see Backfill note above)
3. **Model updates** — casts, scopes, accessors on `RetainerPeriod`, `AgentRun`, `CalendarEvent`
4. **`RetainerHealthService`** — write Pest tests first, then implement; run tests to confirm
5. **`MatchCalendarEventsToClients` job** — write tests first; confirm duplicate guard works
6. **`ClassifyTimeEntryEffort` job + `TimeEntry` observer** — write tests first
7. **Extend `ClientReportService`** — swap `aggregateRetainerData()`, add new `generateReport()` fields; run existing report tests to confirm no regression
8. **`ComputeRetainerSnapshot` + `ComputeAllRetainerSnapshots` jobs**
9. **`RetainerController`** — index, show, computeSnapshot; register routes
10. **`RetainerResource`** — Eloquent API resource with all required fields
11. **`PortfolioMetricsStrip` Vue component**
12. **`RetainerCard` Vue component** — collapsed + expanded states, alert chips, action buttons
13. **`Retainers/Index.vue` page** — wire props, filter bar, card list, "Send all reports" button
14. **`Retainers/Show.vue` page** — all 7 sections, ApexCharts sparklines
15. **Schedule registration** in `routes/console.php`
16. **End-to-end smoke test** — seed a test retainer client, trigger snapshot via artisan, verify dashboard renders with correct data

---

## Open Questions / Decisions Needed Before Build

1. **Navigation placement** — Where does "Retainers" live in the sidebar? Suggested: under the Clients section as a sub-item, or as its own top-level item if the ski vertical becomes a primary revenue stream warranting a dedicated space.

2. **Hour budget blending** — Should AI equivalent hours count against the `hours_budget`, or should budget be human-hours-only with AI tracked as a separate parallel metric? Recommendation: track separately. Displaying "8 human hrs + 12 AI equivalent hrs vs. 20 hr budget" is more transparent than blending them and avoids the appearance of penalizing efficient automation.

3. **`ai_equivalent_hourly_rate` calibration** — Currently defaulting to `$50/hr` as the normalization rate. Adjust this to reflect your actual blended AI cost model. The right number is: "what would a human have charged to do the equivalent work?"

4. **Client-facing margin visibility** — Should clients ever see effective margin or cost-to-serve data in their reports? Recommendation: no. Client reports show hours invested and value delivered. Margin stays internal-only and is never included in the `ClientReportService` AI prompt's output section.

5. **`last_client_activity_at` update triggers** — The spec proposes updating this field when a client: (a) submits a ticket, (b) attends a meeting, (c) opens a report, (d) replies to comms. Confirm these are the right signals. Consider also: client logs into the client portal (if one exists), or a client contact emails in directly (detected via Gmail sync).
