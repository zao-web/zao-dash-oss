# Harvest Integration Technical Spec

## Overview

Integrate Harvest for time tracking, invoicing, and profitability analysis. Enable one-click timer starts from tasks, AI-driven invoice creation, and automated monthly client reports.

---

## Real-Time Philosophy

**Webhooks + periodic sync.**

| Event | Method | Latency Target |
|-------|--------|----------------|
| Time entry created/updated | Webhook | < 1 minute |
| Invoice created/sent | Webhook | < 1 minute |
| Project/task changes | Poll every 15 min | - |
| Full sync (reconciliation) | Daily at 2am | - |

Harvest webhooks are reliable for time/invoice events. Project structure changes are less frequent, so polling suffices.

---

## 1. Data Model Mapping

### Harvest → Zao Dash

| Harvest | Zao Dash | Notes |
|---------|----------|-------|
| Client | Client | Match by name or create link |
| Project | Project | Link to client |
| Task (Harvest) | - | Used for time categorization only |
| Time Entry | TimeEntry | Core tracking unit |
| Invoice | Invoice | For billing/reporting |
| User | TeamMember | Match by email |

### Key Insight
Harvest "Tasks" are category labels (Development, Meetings, Design), not actionable tasks. Zao Dash Tasks are work items. Don't conflate them.

---

## 2. Time Tracking Integration

### One-Click Timer Start

**The Feature**: Click button on Zao Dash task → starts Harvest timer with task context.

```
User clicks "Start Timer" on Task #123
    ↓
Zao Dash API call
    ├── Find/create Harvest project for client
    ├── Select appropriate Harvest task category
    └── POST to Harvest API: /time_entries
        {
          "project_id": 123,
          "task_id": 456,  // "Development" category
          "spent_date": "2024-01-15",
          "notes": "Task #123: Implement user authentication",
          "is_running": true  // Starts timer
        }
    ↓
Timer starts in Harvest (desktop app syncs)
    ↓
Task in Zao Dash shows "Timer running" indicator
```

### Timer Sync
- Poll running timers every 30 seconds for active users
- When timer stops in Harvest → update Zao Dash task
- Show elapsed time on task card while running

### Database: `time_entries`
```
id
harvest_time_entry_id (unique)
user_id
client_id
project_id
task_id (nullable - Zao Dash task)
harvest_project_id
harvest_task_id (category)
hours
notes
spent_date
is_running
started_at
ended_at
is_billable
hourly_rate
synced_at
```

---

## 3. Project Budget Tracking

### Budget Configuration
When linking Harvest project to Zao Dash:
- Import budget hours from Harvest
- Set project timeline (start/end dates)
- Define billing type (hourly, fixed, retainer)

### Health Impact Rules

| Condition | Health Impact |
|-----------|---------------|
| Project > 80% budget, < 50% complete | Warning |
| Project > 100% budget | -1.0 health score |
| Project 2+ weeks past deadline | -0.5 health score |
| No time logged in 30 days (active project) | Warning |
| Consistent on-track delivery | +0.5 health score |

### Database: `project_budgets`
```
id
project_id
harvest_project_id
budget_hours
budget_amount
billing_type (hourly, fixed, retainer)
start_date
end_date
hours_logged
amount_billed
last_activity_at
status (on_track, at_risk, over_budget)
```

---

## 4. Profitability Analytics

### Data Points
- **Hours logged** per client/project
- **Billable vs non-billable** breakdown
- **Effective hourly rate** = revenue / hours
- **Budget variance** = budgeted - actual
- **Utilization** = billable hours / available hours

### Queries to Support
```
"How profitable is Client X?"
→ Total revenue - (hours × internal cost rate)

"How accurate are our estimates?"
→ Average(actual hours / estimated hours) per project type

"Which project types are most profitable?"
→ Profit margin by project category

"Team utilization this month?"
→ Billable hours / (working days × 8) per team member
```

### Database: `profitability_snapshots`
```
id
period_type (daily, weekly, monthly)
period_start
period_end
client_id (nullable)
project_id (nullable)
user_id (nullable)
hours_logged
hours_billable
revenue
cost (hours × internal rate)
profit
margin_percent
created_at
```

### Scheduled Job
Daily snapshot generation:
- Aggregate time entries by client/project/user
- Calculate revenue from invoices
- Compute profit margins
- Store for historical trending

---

## 5. AI-Driven Invoice Creation

### The Feature
"Create invoice for Project X" → AI generates complete invoice.

### Workflow

```
User: "Create invoice for Acme Corp October work"
    ↓
InvoiceCreatorAgent
    ├── Fetch uninvoiced time entries for client/period
    ├── Group by project/task category
    ├── Generate line item descriptions
    │   - Summarize work done (from notes)
    │   - Calculate hours and amounts
    ├── Apply client billing rules
    │   - Hourly rate
    │   - Retainer credits
    │   - Fixed fee items
    └── Create draft invoice via Harvest API
    ↓
POST /invoices
{
  "client_id": 123,
  "subject": "October 2024 Services",
  "line_items": [
    {
      "kind": "Service",
      "description": "Development: User authentication, API integration",
      "quantity": 24.5,
      "unit_price": 150.00
    },
    ...
  ]
}
    ↓
Return invoice link for review
    ↓
User reviews in Harvest → sends to client
```

### Agent: InvoiceCreatorAgent
- **Trigger**: User command or scheduled (monthly retainers)
- **Approval Required**: Yes (review before sending)
- **Output**: Draft invoice in Harvest
- **Skills**:
  - Summarize time entry notes into professional descriptions
  - Handle multiple projects in single invoice
  - Apply discounts/credits as configured
  - Detect anomalies (unusually high hours, missing entries)

---

## 6. Automated Monthly Client Reports

### The Feature
Beautiful, branded PDF reports sent to clients monthly showing work completed.

### Data Sources (Cross-Integration)

| Source | Data Pulled |
|--------|-------------|
| Harvest | Time entries, hours by category, team members |
| GitHub | PRs merged, issues closed, commits |
| Slack | Key decisions made (from threads) |
| Google Calendar | Meetings held |
| Zao Dash | Tasks completed, milestones reached |

### Report Structure

```
┌─────────────────────────────────────────────┐
│  [Client Logo]          Monthly Report      │
│                         October 2024        │
├─────────────────────────────────────────────┤
│  Executive Summary                          │
│  ─────────────────                          │
│  "This month we focused on launching the    │
│  new checkout flow. Key achievements        │
│  include..."                                │
│  [AI-generated 2-3 sentence summary]        │
├─────────────────────────────────────────────┤
│  Work Completed                             │
│  ─────────────────                          │
│  ✓ Implemented new checkout flow            │
│  ✓ Fixed 12 bug reports                     │
│  ✓ Deployed 3 releases to production        │
│  [Generated from tasks/GitHub/Harvest]      │
├─────────────────────────────────────────────┤
│  Time Breakdown          [Donut Chart]      │
│  ─────────────────                          │
│  Development: 45h                           │
│  Meetings: 8h                               │
│  QA/Testing: 12h                            │
│  Planning: 5h                               │
├─────────────────────────────────────────────┤
│  Key Metrics                                │
│  ─────────────────                          │
│  │ 70h │ 15 │ 3 │ 98% │                    │
│  │Total│Tasks│PRs│Uptime│                   │
├─────────────────────────────────────────────┤
│  Upcoming                                   │
│  ─────────────────                          │
│  • Phase 2 kickoff scheduled Nov 5          │
│  • Mobile app beta target: Nov 15           │
│  [From calendar + open tasks]               │
└─────────────────────────────────────────────┘
```

### Technical Implementation

```
Monthly cron (1st of month, 9am)
    ↓
For each client with reporting enabled:
    ↓
ClientReportAgent
    ├── Gather data from all sources (prev month)
    │   ├── Harvest: time entries
    │   ├── GitHub: merged PRs, closed issues
    │   ├── Slack: key thread summaries
    │   ├── Calendar: meetings held
    │   └── Zao Dash: completed tasks
    ├── Generate executive summary (AI)
    ├── Create visualizations
    │   ├── Time breakdown donut chart
    │   ├── Activity timeline
    │   └── Key metrics cards
    ├── Render PDF from template
    └── Email to client contacts
    ↓
Store report in documents table
    ↓
Dashboard shows: "October reports sent to 12 clients"
```

### Database: `client_reports`
```
id
client_id
period_start
period_end
report_type (monthly, quarterly, custom)
data_snapshot (json - all gathered data)
executive_summary
pdf_path
sent_at
sent_to (json - email addresses)
opened_at (nullable - email tracking)
created_at
```

### Configuration: `client_report_settings`
```
client_id
is_enabled
frequency (monthly, quarterly)
send_day (1-28)
recipients (json - email addresses)
include_time_breakdown (boolean)
include_github_activity (boolean)
include_financials (boolean)
custom_branding (json - logo, colors)
template_id
```

---

## 7. Retainer Management

### Retainer Tracking
For clients on monthly retainers:
- Track hours included in retainer
- Show usage: "32/40 hours used this month"
- Alert when approaching limit
- Rollover rules (if configured)

### Dashboard Widget
```
┌─────────────────────────────────┐
│ Retainer Status: Acme Corp     │
│ ══════════════════════════════ │
│ [████████████░░░░░░] 32/40h    │
│ 8 hours remaining • Resets Dec 1│
│ [View Details]                  │
└─────────────────────────────────┘
```

### Database: `retainer_periods`
```
id
client_id
hours_included
hours_used
period_start
period_end
rollover_hours (from previous)
overage_rate
status (active, completed, invoiced)
```

---

## 8. Harvest API Integration

### Authentication
Harvest uses OAuth 2.0:
1. User authorizes via Harvest OAuth
2. Store access token + refresh token
3. Tokens refresh automatically (no expiry if active)

### Key Endpoints

| Action | Endpoint |
|--------|----------|
| List time entries | GET /time_entries |
| Create time entry | POST /time_entries |
| Start timer | POST /time_entries (is_running: true) |
| Stop timer | PATCH /time_entries/{id} (is_running: false) |
| Create invoice | POST /invoices |
| List projects | GET /projects |
| Webhooks | POST /webhooks |

### Webhook Events
```
Invoices: invoice.created, invoice.sent, invoice.paid
Time: timeEntries.created, timeEntries.updated, timeEntries.deleted
```

### Rate Limits
- 100 requests per 15 seconds
- Queue large syncs with delays

---

## 9. Dashboard Integration

### New Components

#### Time Tracking Panel
- Today's logged hours (personal)
- "Start Timer" quick action
- Running timer indicator
- Recent time entries

#### Profitability Dashboard
- Revenue vs cost trend chart
- Client profitability ranking
- Project budget status
- Utilization metrics

#### Retainer Widget
- Per-client retainer usage
- Hours remaining visualization
- Overage alerts

---

## 10. Dev Agent Automatic Time Logging

### The Feature
When Dev Agent completes a task, it estimates how long a senior engineer would have taken and logs that time to Harvest automatically.

### Why This Matters
- Captures value delivered, not just AI execution time
- Ensures clients see fair billing for work completed
- Maintains accurate project budget tracking
- Provides data for estimate accuracy analysis

### Workflow

```
Dev Agent completes task
    ↓
Estimate senior engineer time
    ├── Simple bug fix: 0.5-1h
    ├── Feature implementation: 2-4h
    ├── Complex refactor: 4-8h
    └── Use task complexity + lines changed + test coverage
    ↓
Find linked Harvest project
    ├── Task → Project → HarvestProject mapping
    └── Select "Development" task category
    ↓
HarvestService::logAgentTaskTime()
    {
      "project_id": 123,
      "task_id": 456,  // "Development"
      "hours": 2.5,
      "notes": "Implement user auth flow [Task #789] [Agent Run #456] [AI-estimated time]",
      "spent_date": "2024-12-18"
    }
    ↓
Time entry visible in Harvest
Client sees work logged
```

### Time Estimation Heuristics

| Task Type | Base Hours | Modifiers |
|-----------|------------|-----------|
| Bug fix (simple) | 0.5h | +0.5h if tests added |
| Bug fix (complex) | 1.5h | +1h if DB changes |
| New endpoint | 2h | +1h per complex validation |
| UI component | 1.5h | +1h if state management |
| Refactor | 2h | +0.5h per 100 lines |
| Database migration | 1h | +1h if data backfill |

### Agent Estimation Prompt
```
Given the completed work:
- Files changed: {files}
- Lines added/removed: {diff_stats}
- Tests written: {test_count}
- Task description: {task_name}

Estimate how long a senior engineer would take to:
1. Understand the requirements
2. Research the codebase
3. Implement the solution
4. Write tests
5. Self-review and polish

Return a decimal hours value (e.g., 2.5).
Consider this is HUMAN time, not AI execution time.
```

### Configuration

```php
// config/agents.php
'dev_agent' => [
    'auto_log_time' => env('DEV_AGENT_AUTO_LOG_TIME', true),
    'default_task_category' => 'Development', // Harvest task name
    'minimum_hours' => 0.25,
    'maximum_hours' => 8.0,
    'require_linked_project' => true, // Only log if task has Harvest project
],
```

---

## 11. New Agents

| Agent | Trigger | Approval | Purpose |
|-------|---------|----------|---------|
| InvoiceCreatorAgent | User command / monthly schedule | Yes (review draft) | Generate invoices from time entries |
| ClientReportAgent | Monthly cron (1st of month) | No | Generate and send monthly reports |
| BudgetAlertAgent | Daily check | No | Monitor project budgets, create alerts |

---

## 12. "Spotify Wrapped" Monthly Reports - Design Vision

### The Goal
Monthly client reports should feel like a **gift**, not an obligation. Think Spotify Wrapped: beautiful, personalized, shareable, delightful.

### Design Principles

1. **Narrative Over Numbers**
   - Lead with a story: "This month we shipped your new checkout flow"
   - Numbers support the narrative, don't dominate
   - AI-generated executive summary that reads like a human wrote it

2. **Visual Hierarchy**
   - Hero stat at top (biggest achievement)
   - Clean data viz (donut charts, activity timelines)
   - White space is a feature, not wasted space
   - Mobile-first PDF design

3. **Personalization**
   - Client's logo and brand colors
   - Mention specific people ("Sarah approved the design...")
   - Reference shared context ("Following our strategy call on the 5th...")

4. **Delightful Details**
   - Subtle animations in email preview
   - Shareable social card image
   - "Your Year in Review" annual compilation
   - Achievement badges ("🚀 Shipped 5 features this month")

### Report Sections (Refined)

```
┌─────────────────────────────────────────────────────────────┐
│  [Client Logo]                                              │
│                                                             │
│  ══════════════════════════════════════════════════════════ │
│       DECEMBER 2024 · MONTHLY PARTNERSHIP REPORT           │
│  ══════════════════════════════════════════════════════════ │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  🚀  YOUR MONTH AT A GLANCE                         │   │
│  │                                                     │   │
│  │  "We launched your new checkout experience,        │   │
│  │   reducing cart abandonment by 23%."               │   │
│  │                                 — AI Summary        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  HERO METRICS                                               │
│  ───────────────────────────────────────────────────────── │
│                                                             │
│  ┌───────┐  ┌───────┐  ┌───────┐  ┌───────┐               │
│  │  72h  │  │  18   │  │  4    │  │ 99.9% │               │
│  │ hours │  │ tasks │  │ PRs   │  │uptime │               │
│  │logged │  │done   │  │merged │  │       │               │
│  └───────┘  └───────┘  └───────┘  └───────┘               │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  WORK COMPLETED                                             │
│  ───────────────────────────────────────────────────────── │
│                                                             │
│  ✅ Launched new checkout flow (12 tasks, 28h)             │
│     → Cart abandonment ↓23%, mobile conversion ↑15%        │
│                                                             │
│  ✅ Fixed 7 critical bugs reported by users                │
│     → Support tickets ↓40% week-over-week                  │
│                                                             │
│  ✅ Implemented customer feedback portal                   │
│     → 47 submissions received in first week                │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  TIME INVESTMENT                  ┌──────────────────────┐ │
│  ─────────────────────           │    [Donut Chart]     │ │
│                                   │                      │ │
│  Development      45h  (63%)      │   Dev ████████████   │ │
│  Meetings          8h  (11%)      │   Mtg ███            │ │
│  QA & Testing     12h  (17%)      │   QA  ████           │ │
│  Planning          7h   (9%)      │   Plan ██            │ │
│                                   │                      │ │
│                                   └──────────────────────┘ │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  ACTIVITY TIMELINE                                          │
│  ───────────────────────────────────────────────────────── │
│                                                             │
│  Dec 1  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  Dec 31 │
│         ▪▪▪    ▪▪▪▪▪▪    ▪▪▪▪      ▪▪▪▪▪▪▪▪    ▪▪▪        │
│              ↑                    ↑                         │
│         Checkout v1          Final launch                   │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  LOOKING AHEAD (January)                                    │
│  ───────────────────────────────────────────────────────── │
│                                                             │
│  📅 Phase 2 kickoff: January 8th                           │
│  🎯 Mobile app beta target: January 22nd                   │
│  💡 Recommendation: Consider A/B testing payment options   │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Questions? Reply to this email or book time:              │
│  calendly.com/zao/client-check-in                          │
│                                                             │
│  ───────────────────────────────────────────────────────── │
│  Zao · WordPress & Laravel Agency                          │
│  example.com · @zaborowski                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Technical Implementation

**PDF Generation**: Use `barryvdh/laravel-dompdf` or `spatie/browsershot` (Puppeteer) for high-quality rendering.

**Template System**:
- Blade templates with Tailwind CSS
- Client-specific variables (logo, colors, contacts)
- Conditional sections based on data availability

**Email Delivery**:
- HTML email with inline styles
- Attached PDF for archival
- Tracking pixel for open tracking
- One-click feedback ("Was this report useful?")

### Quality Checklist

Before sending any report:
- [ ] Client logo renders correctly
- [ ] All numbers are accurate (cross-check with Harvest)
- [ ] AI summary reads naturally, no awkward phrasing
- [ ] Charts have sufficient data (hide if <3 data points)
- [ ] No empty sections
- [ ] PDF renders correctly on mobile
- [ ] Links work (calendly, support email)

---

## 13. Implementation Order

1. **OAuth flow** - Connect Harvest account
2. **Data sync** - Import clients, projects, time entries
3. **Timer integration** - Start/stop from Zao Dash tasks
4. **Webhook receiver** - Real-time time entry updates
5. **Budget tracking** - Project hours/timeline monitoring
6. **Profitability snapshots** - Daily aggregation job
7. **Retainer tracking** - Usage monitoring
8. **InvoiceCreatorAgent** - AI-driven invoice generation
9. **ClientReportAgent** - Monthly report automation
10. **Profitability dashboard** - Analytics UI

---

## 14. Bidirectional Sync (Harvest ↔ Zao Dash)

### The Feature
Harvest serves as the source of truth for clients and projects. Changes in either system automatically sync to the other.

### Harvest → Zao Dash (Auto-Import)

After each Harvest sync, the `HarvestSyncService` reconciles data:

1. **Clients**: Creates `Client` records for any Harvest clients not yet in Zao Dash
2. **Projects**: Creates `Project` records linked to the appropriate `Client`
3. **Retainers**: Creates `RetainerPeriod` for projects with monthly budgets
4. **Time Entries**: Backfills `client_id` and `project_id` based on links
5. **Invoices**: Links to `Client` records

### Zao Dash → Harvest

Changes in Zao Dash push to Harvest via the `ProjectObserver`:

| Zao Dash Action | Harvest Result |
|-----------------|----------------|
| Archive Project (soft delete) | Project marked inactive in Harvest |
| Restore Project | Project reactivated in Harvest |

### Key Database Fields

**On `clients` table:**
- `harvest_client_id` - Links to Harvest client

**On `projects` table:**
- `harvest_project_id` - Links to Harvest project

**On `retainer_periods` table:**
- `harvest_project_id` - Links to source budget

### Sync Command

```bash
# Full sync with reconciliation
php artisan sync:harvest

# Manual reconciliation only (no API calls)
php artisan tinker
>>> app(HarvestSyncService::class)->reconcile()
```

### Reconciliation Results

```php
[
    'clients' => ['created' => 5, 'updated' => 2, 'archived' => 0],
    'projects' => ['created' => 12, 'updated' => 3, 'archived' => 1, 'restored' => 0],
    'retainers' => ['created' => 3, 'updated' => 8],
    'time_entries_backfilled' => 156,
    'invoices_backfilled' => 23,
    'retainer_hours_updated' => 8,
]
```

### Retainer Budget Display

Projects synced from Harvest with monthly budgets show:
- `$project->total_hours` - Total hours logged
- `$project->monthly_hours` - Hours this month
- `$project->budget_remaining` - Hours left in budget
- `$project->budget_usage_percent` - % of budget used

---

## 15. Security Considerations

- OAuth tokens encrypted at rest
- No storage of financial amounts in logs
- Audit log for all invoice operations
- Rate limiting on timer operations
- Client reports stored encrypted (contain financials)
