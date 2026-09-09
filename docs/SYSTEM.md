# Zao Dash System Architecture

> A revenue-goal-driven business intelligence platform with AI agent orchestration

## System Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         ZAO DASH ARCHITECTURE                           │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                     PRESENTATION LAYER                          │   │
│  │  Vue 3 + Inertia.js │ Command Palette │ Dashboard Widgets       │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                    │
│  ┌─────────────────────────────────┴───────────────────────────────┐   │
│  │                      APPLICATION LAYER                          │   │
│  │  Laravel Controllers │ Services │ Jobs │ Events                 │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                    │
│  ┌────────────────┬────────────────┴────────────────┬──────────────┐   │
│  │  AGENT SYSTEM  │     BUSINESS INTELLIGENCE       │ INTEGRATIONS │   │
│  │                │                                 │              │   │
│  │  Strategist    │  Goals & Periods               │  QuickBooks  │   │
│  │  Lead Gen      │  Funnel Metrics                │  Harvest     │   │
│  │  Outreach      │  Revenue Forecasting           │  Slack       │   │
│  │  Health Mon    │  Capacity Planning             │  GitHub      │   │
│  │  Case Study    │                                │  Google      │   │
│  │  Upsell        │                                │  Notion      │   │
│  └────────────────┴────────────────────────────────┴──────────────┘   │
│                                    │                                    │
│  ┌─────────────────────────────────┴───────────────────────────────┐   │
│  │                        DATA LAYER                               │   │
│  │  MySQL │ Redis (Queues/Cache) │ File Storage (Skills)          │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Core Concepts

### 1. Strategic Goals

The system is built around **revenue goals** that cascade down to actionable weekly plans.

```
Strategic Goal ($1.5M/year)
    └── Goal Periods (quarterly/monthly/weekly targets)
        └── Weekly Plans (specific actions)
            └── Plan Items (human tasks + agent tasks)
```

### 2. Agent Orchestration

The **Business Strategist Agent** acts as the orchestrator, analyzing progress and delegating to specialized agents:

```
                    ┌─────────────────────┐
                    │ Business Strategist │  (Opus, $15 budget)
                    │   Monday 6am        │
                    └─────────┬───────────┘
                              │ delegates to
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│  Lead Gen     │    │   Outreach    │    │ Client Health │
│  Agent        │    │   Agent       │    │   Monitor     │
└───────────────┘    └───────────────┘    └───────────────┘
```

### 3. Proactive Growth Engine

The dashboard surfaces AI-detected opportunities from multiple data sources:

```
┌──────────────────────────────────────────────────────────────┐
│                    PROACTIVE INSIGHTS                         │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  Slack Messages ──┐                                           │
│  Client Health ───┼──▶ ProactiveInsightsService ──▶ Dashboard │
│  Lead Pipeline ───┤         │                                 │
│  Project Status ──┤         ▼                                 │
│  Quarterly ───────┘  QuarterlyPatternAnalysisService         │
│                              │                                │
│                              ▼                                │
│                   Content/Landing/Outreach Suggestions        │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

Each insight includes a one-click action that triggers the appropriate agent.

### 4. Approval Workflow

Actions with side effects require human approval:

| Action Type | Requires Approval |
|-------------|-------------------|
| Research/Analysis | No |
| Draft content | No |
| Create campaigns | Yes |
| Schedule outreach | Yes |
| Assign agent tasks | Yes |
| Modify client data | Yes |
| Social media posts | Yes |
| Expense categorization | Yes |

---

## Directory Structure

```
app/
├── Agents/
│   ├── Definitions/           # Agent configurations
│   │   ├── BaseAgentDefinition.php
│   │   ├── BusinessStrategistAgent.php
│   │   ├── LeadGenerationAgent.php
│   │   └── ...
│   ├── Tools/                 # Agent tool implementations
│   │   ├── BaseTool.php
│   │   ├── AnalyzeGoalProgressTool.php
│   │   └── ...
│   ├── ToolRegistry.php       # Tool registration
│   └── ClaudeCliRunner.php    # Claude CLI integration
├── Http/Controllers/
├── Models/
├── Services/
│   ├── BusinessIntelligenceService.php
│   ├── ProactiveInsightsService.php
│   ├── QuarterlyPatternAnalysisService.php
│   ├── GitHub/
│   ├── Google/
│   ├── Harvest/
│   ├── LinkedIn/
│   ├── QuickBooks/
│   ├── Slack/
│   ├── WordPress/
│   ├── X/
│   └── ...
└── Jobs/

storage/app/skills/           # External prompt storage
├── business-strategist/
│   └── SKILL.md
├── lead-generation/
│   └── SKILL.md
└── ...

resources/js/
├── Pages/
│   ├── Dashboard.vue
│   ├── Goals/
│   ├── WeeklyPlan/
│   ├── Prospects/
│   ├── Campaigns/
│   └── ...
├── Components/
└── Layouts/
```

---

## Data Flow

### Revenue Tracking Flow
```
QuickBooks Invoices ─┐
                     ├──▶ BusinessIntelligenceService ──▶ Goal Progress
Harvest Invoices ────┘
```

### Agent Execution Flow
```
Scheduler (cron)
    │
    ▼
php artisan agents:run-scheduled
    │
    ▼
Agent Definition → Load SKILL.md → Build Prompt
    │
    ▼
Claude CLI Runner → Execute with Tools
    │
    ▼
Tool Results → Store AgentRun → Process Outputs
    │
    ▼
ProcessAgentTasksJob (if delegated tasks)
```

### Weekly Planning Flow
```
Monday 6am: Business Strategist runs
    │
    ├── Analyzes goal progress
    ├── Reviews funnel metrics
    ├── Creates weekly plan (requires approval)
    └── Assigns agent tasks (requires approval)
          │
          ▼
Human reviews in UI → Approves plan
          │
          ▼
ProcessAgentTasksJob picks up approved tasks
          │
          ▼
Specialized agents execute assigned work
```

---

## Key Services

### BusinessIntelligenceService

Central service for all revenue/funnel calculations:

```php
// Calculate funnel conversion rates
$metrics = $biService->calculateFunnelMetrics($startDate, $endDate);

// Work backwards from goal to required weekly leads
$requirements = $biService->calculateRequiredLeadsPerWeek($goal);

// Identify what levers to pull when behind
$levers = $biService->identifyLevers($goal);

// 30/60/90 day revenue forecast
$forecast = $biService->forecastRevenue($goal);
```

### VaultService

Encrypted secret storage with scoped access:

```php
$vault = app(VaultService::class);

// Store a secret (encrypted at rest)
$vault->store('stripe', 'STRIPE_SECRET_KEY', $key, $agentScope);

// Retrieve (decrypted, logged)
$secret = $vault->get('STRIPE_SECRET_KEY', $agentSlug);

// Rotate keys
$vault->rotate($secretId, $newValue);
```

Access is logged to `vault_access_logs` for audit.

### KillSwitchService

Emergency controls for agent operations:

```php
$killSwitch = app(KillSwitchService::class);

// Global kill switch - stops ALL agents
$killSwitch->activateGlobal('Emergency: API costs');
$killSwitch->deactivateGlobal();

// Per-agent kill switch
$killSwitch->activateForAgent($agentId, 'Investigation');
$killSwitch->deactivateForAgent($agentId);

// Spend limits
$killSwitch->setDailySpendLimit(100.00);
$killSwitch->isSpendLimitExceeded(); // true/false

// Health check
$killSwitch->healthCheck(); // ['global_active' => false, ...]
```

### ProactiveInsightsService

Powers the dashboard's Growth Engine with AI-detected opportunities:

```php
$insights = app(ProactiveInsightsService::class)->getInsights(limit: 6);
// Returns prioritized insights from multiple sources:
// - slack_opportunity: Budget/expansion signals from Slack
// - slack_risk: Client dissatisfaction signals
// - pattern_vertical: Industry concentration patterns
// - project_case_study: Recently completed work
// - lead_hot: High-value active leads
// - lead_stale: Re-engagement opportunities
// - client_at_risk: Low health score alerts
// - quarterly_landing: Vertical page suggestions
// - quarterly_content: Content ideas from patterns
```

Insights include actionable metadata:
- `agent_slug`: Which agent handles the action
- `action_label`: Button text for one-click execution
- `priority`: Sort weight (higher = more urgent)
- `recency_score`: Freshness boost (0-30)

### QuarterlyPatternAnalysisService

Analyzes work patterns to generate content suggestions:

```php
$analysis = app(QuarterlyPatternAnalysisService::class)->analyze();
// Returns:
// - industry_patterns: Which industries we served
// - service_patterns: Service hours breakdown
// - technology_patterns: Tech stack usage
// - content_suggestions: Blog/case study ideas
// - landing_page_suggestions: Vertical pages to create
// - outreach_suggestions: ICP targeting ideas
```

Integrates with ProactiveInsightsService to surface suggestions on dashboard.

### SlackActionItemService

Extracts action items from Slack messages:

```php
$service = app(SlackActionItemService::class);

// Get suggested tasks from recent Slack messages
$suggestions = $service->getSuggestions(10);

// Create a task from an action
$task = $service->createTaskFromAction($action, $projectId);
```

### Agent System

Agents are defined as PHP classes extending `BaseAgentDefinition`:

```php
class BusinessStrategistAgent extends BaseAgentDefinition
{
    protected function getSlug(): string { return 'business-strategist'; }
    protected function getModel(): string { return 'opus'; }
    protected function getMaxBudget(): float { return 15.00; }
    protected function getSchedule(): ?string { return '0 6 * * 1-5'; }
    protected function getTools(): array { return [...]; }
}
```

---

## Database Schema Overview

### Strategic Planning
- `strategic_goals` - Annual revenue targets
- `goal_periods` - Quarterly/monthly/weekly breakdowns
- `funnel_metrics` - Historical conversion snapshots
- `weekly_plans` - Action plans per week
- `weekly_plan_items` - Individual actions
- `agent_tasks` - Delegated agent work

### Lead Generation
- `ideal_customer_profiles` - ICP definitions with scoring weights
- `prospects` - Pre-qualified targets
- `leads` - Qualified opportunities (existing)
- `outreach_campaigns` - Multi-channel campaigns
- `outreach_sequences` - Campaign steps
- `outreach_messages` - Individual messages

### Clients & Projects
- `clients` - Client records
- `projects` - Active projects
- `time_entries` - Harvest time data
- `invoices` - QuickBooks/Harvest invoices

### Integrations
- `quickbooks_connections` - QBO OAuth tokens
- `harvest_credentials` - Harvest API tokens
- `slack_workspaces` - Slack team connections
- `github_installations` - GitHub App installations
- `google_credentials` - Google OAuth tokens (Gmail, Calendar, Drive)
- `notion_connections` - Notion integrations
- `linked_in_credentials` - LinkedIn OAuth tokens
- `x_credentials` - X (Twitter) OAuth tokens
- `wordpress_sites` - WordPress site connections

### Security & Operations
- `vault_secrets` - Encrypted API keys with scoped access
- `vault_access_logs` - Audit trail for secret access

---

## Client Portal

Isolated, client-facing interface at `/portal`.

### Architecture

```
┌─────────────────────────────────────────────────┐
│                 CLIENT PORTAL                    │
├─────────────────────────────────────────────────┤
│  Route: /portal/*                                │
│  Middleware: ClientPortalMiddleware              │
│  User Role: 'client'                             │
├─────────────────────────────────────────────────┤
│                                                  │
│  /portal           → Dashboard (stats, summary)  │
│  /portal/projects  → Project list               │
│  /portal/projects/{id} → Project detail         │
│  /portal/invoices  → Invoice history            │
│                                                  │
└─────────────────────────────────────────────────┘
```

### Data Isolation

- Users with `role='client'` can only access portal routes
- Portal controller filters all queries by `client_id`
- Internal routes (dashboard, agents, etc.) are blocked
- Middleware enforces tenant isolation at request level

---

## Environment Requirements

```env
# Core
APP_URL=https://your-domain.com
DB_CONNECTION=mysql

# Claude AI
ANTHROPIC_API_KEY=sk-ant-...

# Grok (X/Twitter trend intelligence)
GROK_API_KEY=xai-...

# Integrations (all optional)
QUICKBOOKS_CLIENT_ID=
QUICKBOOKS_CLIENT_SECRET=
HARVEST_ACCOUNT_ID=
HARVEST_ACCESS_TOKEN=
SLACK_BOT_TOKEN=
GITHUB_APP_ID=
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
NOTION_CLIENT_ID=
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
X_CLIENT_ID=
X_CLIENT_SECRET=
```

---

## Related Documentation

- [Artisan Commands](./COMMANDS.md) - CLI reference
- [Agent System](./AGENTS.md) - Agent development guide
- [User Guide](./USER_GUIDE.md) - Feature documentation
- [API Reference](./API.md) - REST endpoints
- [Agentic Workflows](./AGENTIC_WORKFLOWS.md) - Design principles

### Integration Specs

- [GSuite Sync](./specs/GSUITE_SYNC.md) - Gmail & Calendar integration
- [LinkedIn & X](./specs/LINKEDIN_X_INTEGRATION.md) - Social media posting
- [Grok Integration](./specs/GROK_INTEGRATION.md) - X/Twitter trend intelligence
