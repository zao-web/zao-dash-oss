# Agent System Documentation

Guide to creating, configuring, and operating AI agents in Zao Dash.

---

## Architecture Overview

### High-Level System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          ZAO DASH AGENT SYSTEM                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌────────────────────┐        ┌─────────────────────────────────┐     │
│  │   TRIGGER LAYER    │        │      ORCHESTRATION LAYER        │     │
│  ├────────────────────┤        ├─────────────────────────────────┤     │
│  │ • Schedule (Cron)  │───────▶│  Business Strategist (Opus)     │     │
│  │ • Manual (UI/API)  │        │         ↓                       │     │
│  │ • Webhook (Events) │        │  Assigns tasks to specialized   │     │
│  │ • Chained (Agent)  │        │  agents via assign_agent_task   │     │
│  └────────────────────┘        └────────────┬────────────────────┘     │
│                                             │                          │
│                                             ▼                          │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │                    SPECIALIZED AGENTS                            │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │  Lead Gen  │  Outreach  │  Health  │  Sentiment  │  SEO  │  QBO  │  │
│  │  (Sonnet)  │  (Sonnet)  │ Monitor  │  Analysis   │ Agent │ Agent │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                  │                                     │
│                                  ▼                                     │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │                       TOOL REGISTRY                              │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │  Data      │  Analysis  │  SEO       │  Actions   │  QuickBooks  │  │
│  │  Retrieval │  & Intel   │  Tools     │  (Approval)│  Integration │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                  │                                     │
│                                  ▼                                     │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │                    APPROVAL & SAFETY LAYER                       │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │  HasApprovalGates   │   HasSandbox   │   Circuit Breaker        │  │
│  │  (Human review)     │   (Isolation)  │   (3 fails → pause)      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                  │                                     │
│                                  ▼                                     │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │                    INTEGRATIONS & SIDE EFFECTS                   │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │  WordPress  │  QuickBooks  │  Slack  │  Email  │  LinkedIn/X    │  │
│  │  Publishing │  Accounting  │  Alerts │  Outreach │  Social      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Agent Lifecycle Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                      AGENT EXECUTION FLOW                        │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. TRIGGER                                                      │
│     ┌─────────────┐                                              │
│     │  Scheduler  │  (Cron: 0 6 * * 1-5)                        │
│     │  /Manual/   │  (UI button or API call)                    │
│     │  /Webhook/  │  (External event)                           │
│     └──────┬──────┘                                              │
│            │                                                     │
│  2. VALIDATION & SETUP                                           │
│     ┌──────▼──────┐                                              │
│     │ Circuit     │  ✓ Agent not broken                         │
│     │ Check       │  ✓ Budget available                         │
│     │             │  ✓ Secrets configured                       │
│     └──────┬──────┘                                              │
│            │                                                     │
│  3. SANDBOX CREATION (if HasSandbox)                            │
│     ┌──────▼──────┐                                              │
│     │ Isolated    │  storage/app/agent-workspaces/{run-id}/     │
│     │ Workspace   │  ├── workspace/ (read-only)                 │
│     │             │  ├── output/ (agent writes)                 │
│     └──────┬──────┘  └── temp/                                  │
│            │                                                     │
│  4. EXECUTION                                                    │
│     ┌──────▼──────┐                                              │
│     │ Claude API  │  System prompt + Tools → Anthropic          │
│     │ Call        │  Model: opus/sonnet/haiku                   │
│     │             │  Budget tracking                            │
│     └──────┬──────┘                                              │
│            │                                                     │
│  5. TOOL EXECUTION                                               │
│     ┌──────▼──────┐                                              │
│     │ Tool        │  For each tool_use:                         │
│     │ Registry    │  • Validate params                          │
│     │ Dispatch    │  • Check approval required                  │
│     └──────┬──────┘  • Execute or queue for approval            │
│            │                                                     │
│  6. APPROVAL GATE (if requiresApproval)                         │
│     ┌──────▼──────┐                                              │
│     │ Create      │  ApprovalRequest created                    │
│     │ Approval    │  → Notification sent                        │
│     │ Request     │  → Human reviews in UI                      │
│     └──────┬──────┘  → Approve/Reject                           │
│            │                                                     │
│  7. OUTPUT PROCESSING                                            │
│     ┌──────▼──────┐                                              │
│     │ Process     │  processOutput() hook                       │
│     │ Output      │  Extract structured data                    │
│     └──────┬──────┘  Execute side effects (if approved)         │
│            │                                                     │
│  8. LOGGING & CLEANUP                                            │
│     ┌──────▼──────┐                                              │
│     │ AgentRun    │  • Save to database                         │
│     │ Record      │  • Log tokens/cost                          │
│     │             │  • Cleanup sandbox (preserve output)        │
│     │             │  • Update circuit breaker                   │
│     └─────────────┘  • Chain to next agent (if configured)      │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## Creating an Agent

### 1. Generate Scaffold

```bash
php artisan make:agent ClientOnboardingAgent
```

This creates:
- `app/Agents/Definitions/ClientOnboardingAgent.php`
- `storage/app/skills/client-onboarding/SKILL.md`

### 2. Configure the Agent Definition

```php
<?php

namespace App\Agents\Definitions;

class ClientOnboardingAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Client Onboarding';
    }

    protected function getDescription(): string
    {
        return 'Guides new client setup and creates onboarding checklist';
    }

    // --- OPTIONAL OVERRIDES ---

    // Change from default 'sonnet' to 'opus' for complex reasoning
    protected function getModel(): string
    {
        return 'opus';  // or 'sonnet', 'haiku'
    }

    // Set budget per run (default: $5.00)
    protected function getMaxBudget(): float
    {
        return 10.00;
    }

    // Trigger type: 'manual', 'scheduled', 'webhook', 'chained'
    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    // Cron expression for scheduled agents
    protected function getSchedule(): ?string
    {
        return '0 9 * * 1';  // Monday 9am
    }

    // For chained agents, specify the upstream agent
    protected function getChainFrom(): ?string
    {
        return 'lead-qualification';  // Runs after this agent
    }

    // Require human approval before side effects (default: true)
    protected function requiresApproval(): bool
    {
        return true;
    }

    // Assign tools to this agent
    protected function getTools(): array
    {
        return [
            'create_task',
            'send_email',
            'web_search',
        ];
    }

    // Required environment variables
    public function requiredSecrets(): array
    {
        return ['SENDGRID_API_KEY'];
    }

    // Validate input context
    public function configSchema(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
        ];
    }

    // Post-process agent output
    public function processOutput(array $output): array
    {
        // Add any transformations here
        return $output;
    }
}
```

### 3. Write the SKILL.md Prompt

```markdown
# Client Onboarding Agent

You are the Client Onboarding Agent for Zao, a digital agency.

## Role
Guide new clients through onboarding by creating personalized checklists
and sending welcome communications.

## Context
- Client information is provided in the context
- You have access to the task creation and email tools
- All emails require approval before sending

## Instructions
1. Review the client's project scope and requirements
2. Create an onboarding checklist with relevant tasks
3. Draft a welcome email for the main contact
4. Flag any missing information that needs follow-up

## Output Format
Provide a structured response with:
- Checklist items created
- Email draft (pending approval)
- Follow-up items if any

## Tone
Professional, welcoming, organized.
```

### 4. Sync to Database

```bash
php artisan agents:sync
```

---

## Agent Configuration Options

### Trigger Types

| Type | Description | Configuration |
|------|-------------|---------------|
| `manual` | Triggered via UI or API | Default, no extra config |
| `scheduled` | Runs on cron schedule | Set `getSchedule()` |
| `webhook` | Triggered by external event | Set webhook endpoint |
| `chained` | Runs after another agent | Set `getChainFrom()` |

### Model Selection

| Model | Use Case | Cost | Speed |
|-------|----------|------|-------|
| `haiku` | Simple tasks, classification | $ | Fast |
| `sonnet` | General purpose, most agents | $$ | Medium |
| `opus` | Complex reasoning, planning | $$$ | Slower |

### Schedule Format (Cron)

```
┌───────────── minute (0 - 59)
│ ┌───────────── hour (0 - 23)
│ │ ┌───────────── day of month (1 - 31)
│ │ │ ┌───────────── month (1 - 12)
│ │ │ │ ┌───────────── day of week (0 - 6) (Sunday = 0)
│ │ │ │ │
* * * * *
```

Examples:
- `0 6 * * 1-5` - Weekdays at 6am
- `0 9 * * 1` - Monday at 9am
- `0 */4 * * *` - Every 4 hours
- `30 8 1 * *` - 1st of month at 8:30am

---

## Creating Tools

### Tool Structure

```php
<?php

namespace App\Agents\Tools;

class CreateTaskTool extends BaseTool
{
    public function id(): string
    {
        return 'create_task';
    }

    public function name(): string
    {
        return 'Create Task';
    }

    public function description(): string
    {
        return 'Creates a task in the project management system';
    }

    // Define parameters for the tool
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Task title',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Task description',
                ],
                'assignee_id' => [
                    'type' => 'integer',
                    'description' => 'User ID to assign task to',
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'Due date in YYYY-MM-DD format',
                ],
            ],
            'required' => ['title'],
        ];
    }

    // Whether tool needs human approval
    public function requiresApproval(): bool
    {
        return false;  // Task creation is safe
    }

    // Execute the tool
    public function execute(array $params): array
    {
        $task = Task::create([
            'title' => $params['title'],
            'description' => $params['description'] ?? null,
            'assignee_id' => $params['assignee_id'] ?? null,
            'due_date' => $params['due_date'] ?? null,
        ]);

        return [
            'task_id' => $task->id,
            'message' => "Created task: {$task->title}",
        ];
    }
}
```

### Tool Registration & Auto-Discovery

Tools are auto-discovered from `app/Agents/Tools/` by the `ClaudeAgentSdk`. The SDK:

1. Scans all PHP files in `app/Agents/Tools/`
2. Instantiates classes implementing the `Tool` interface
3. Registers them by their `id()` (kebab-case)
4. Makes them available to agents via `allowedTools()`

**Tool ID Convention:**
- Tool class: `SearchClientsTool.php`
- Tool ID: `search-clients` (auto-generated via `Str::kebab()`)
- Agent reference: `'search-clients'` in `allowedTools()`

**Underscore Normalization:**
The SDK normalizes underscores to kebab-case. If an agent references `match_icp`, it maps to `match-icp`.

To check discovered tools:

```bash
php artisan tinker

# Via SDK reflection (shows all 60+ tools)
$sdk = new \App\Services\Agents\ClaudeAgentSdk();
$reflection = new ReflectionClass($sdk);
$method = $reflection->getMethod('discoverTools');
$method->setAccessible(true);
$method->invoke($sdk);
$prop = $reflection->getProperty('toolRegistry');
$prop->setAccessible(true);
array_keys($prop->getValue($sdk));
```

### Tool Execution Flow

```
Agent calls tool (e.g., "search-clients")
    ↓
ClaudeAgentSdk::executeToolCall()
    ↓
getToolInstance("search-clients")
    ↓ normalizes underscores
toolRegistry["search-clients"]
    ↓ found?
    ├── YES: tool->validate() → tool->execute()
    └── NO: Fall back to legacy hardcoded definitions
```

**Legacy Fallback:**
Some tools (like `web_search`, `create_task`) have legacy hardcoded implementations in `getLegacyToolDefinitions()` for backwards compatibility. New tools should always use the class-based approach.

---

## Complete Agent Catalog

### Business Strategy & Orchestration

| Agent | Trigger | Model | Budget | Approval | Purpose |
|-------|---------|-------|--------|----------|---------|
| **Business Strategist** | 6am weekdays | opus | $15 | Yes | Strategic planning and agent orchestration for revenue goals |
| **Lead Generation** | Monday 8am | sonnet | $5 | No | Proactive prospect research and ICP qualification |
| **Outreach Campaign** | 9am weekdays | sonnet | $3 | Yes | Personalized multi-channel outreach and campaign management |
| **Opportunity Scout** | Friday 10am | sonnet | $5 | Yes | Identifies upsell, cross-sell, referral, and market opportunities |

**Tools:** analyze_goal_progress, get_funnel_metrics, forecast_revenue, create_weekly_plan, assign_agent_task, search_prospects, search_leads, create_prospect, match_icp, draft_outreach_message, schedule_follow_up, web_search

### Client Management & Health

| Agent | Trigger | Model | Budget | Approval | Purpose |
|-------|---------|-------|--------|----------|---------|
| **Client Health Monitor** | 7am weekdays | sonnet | $2 | No | Monitor client health metrics and flag at-risk accounts |
| **Client Sentiment** | 8am weekdays | sonnet | $3 | No | Analyzes communication sentiment, detects escalation risks |
| **Lead Nurture** | 9am weekdays | sonnet | $3 | Yes | Automated lead follow-up and nurturing sequences |
| **Case Study Writer** | Manual | sonnet | $5 | Yes | Creates compelling case studies from completed projects |
| **Upsell Proposal** | Manual | sonnet | $3 | Yes | Drafts personalized upsell proposals based on signals |

**Tools:** search_clients, search_projects, search_tasks, search_emails, search_slack_messages, get_recent_communications, update_client_sentiment, create_alert, create_task, web_search

### Financial & Accounting

| Agent | Trigger | Model | Budget | Approval | Purpose |
|-------|---------|-------|--------|----------|---------|
| **Invoice Analyzer** | Monday 9am | sonnet | $3 | Yes | Analyzes Harvest time entries, generates invoice drafts |
| **Bookkeeping** | 7am weekdays | sonnet | $5 | Yes | Reviews and categorizes QuickBooks expenses for tax optimization |

**Tools:** get_time_entries, get_client_rates, create_invoice_draft, get_project_budget, qbo_get_expenses, qbo_get_categories, qbo_suggest_category, qbo_categorize_expense

### Content & Publishing

| Agent | Trigger | Model | Budget | Approval | Purpose |
|-------|---------|-------|--------|----------|---------|
| **Content Scheduler** | Monday 8am | sonnet | $3 | Yes | Manages and schedules WordPress content publishing |
| **WordPress Publisher** | Chained | sonnet | $3 | Yes | Publishes blog posts, case studies, and landing pages |
| **Marketing** | Monday 8am | sonnet | $5 | Yes | Creates LinkedIn and X content plans, drafts social posts |

**Tools:** search_content, wordpress_create_post, wordpress_upload_media, wordpress_get_categories, wordpress_get_tags, generate_featured_image, optimize_seo_meta, post_to_linked_in, post_to_x, get_x_trends, get_stats

### SEO & Growth

| Agent | Trigger | Model | Budget | Approval | Purpose |
|-------|---------|-------|--------|----------|---------|
| **Programmatic SEO** | Monday 7am | sonnet | $8 | Yes | Generates SEO-optimized landing pages and content at scale |
| **Landing Page Generator** | Manual | sonnet | $5 | Yes | Generates landing page copy targeting specific industries/verticals |

**Tools:** seo_keyword_research, seo_analyze_serp, seo_competitor_gaps, seo_search_volume, seo_generate_landing, seo_generate_blog, seo_optimize_content, get_quarterly_patterns, seo_get_rankings, seo_track_page, seo_get_pseo_performance, seo_get_conversions, wp_create_page, wp_create_post

---

## Agent Details

### Programmatic SEO Agent

Generates SEO-optimized landing pages and blog posts at scale based on work patterns.

**Schedule:** Monday 7am (before Marketing Agent)
**Model:** Sonnet
**Budget:** $8.00
**Approval:** Required for all content

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `seo-keyword-research` | Find keywords for a topic | No |
| `seo-analyze-serp` | Analyze top-ranking content | No |
| `seo-competitor-gaps` | Find competitor keyword gaps | No |
| `seo-search-volume` | Get volume/difficulty data | No |
| `seo-generate-landing` | Create SEO landing page | No |
| `seo-generate-blog` | Create SEO blog post | No |
| `seo-optimize-content` | Optimize existing content | No |
| `get-quarterly-patterns` | Our work patterns for targeting | No |
| `get-x-trends` | Real-time X/Twitter trends | No |
| `web-search` | Industry research | No |
| `seo-get-rankings` | Keyword positions from Search Console | No |
| `seo-track-page` | Page KPIs with period comparison | No |
| `seo-get-pseo-performance` | PSEO summary across all pages | No |
| `seo-get-conversions` | Conversion tracking from Analytics | No |
| `wp-create-page` | Publish page to WordPress | **Yes** |
| `wp-create-post` | Publish post to WordPress | **Yes** |

**Workflow:**
```
1. Review last week's performance (seo-get-pseo-performance)
   └── Track rankings, traffic, conversions
2. Analyze quarterly patterns (get-quarterly-patterns)
   └── Identify industries/services we've focused on
3. Keyword research (seo-keyword-research)
   └── Find keywords for each pattern
4. Prioritize opportunities (seo-search-volume)
   └── Score by volume × intent × expertise
5. Analyze competition (seo-analyze-serp)
   └── Understand what ranks, find gaps
6. Generate content (seo-generate-landing/blog)
   └── Create SEO-optimized pages
7. Submit for approval
   └── Human reviews in /approvals
```

**Content Types:**
- Service + Industry pages ("WordPress development for healthcare")
- Service + Location pages ("WordPress agency in Denver")
- Problem-Solution blog posts ("How to migrate from Shopify")
- Comparison pages ("Laravel vs Django for enterprise")

**Skill file:** `storage/app/skills/programmatic-seo/SKILL.md`

---

### Lead Generation Agent

Researches and qualifies prospects based on Ideal Customer Profile (ICP) criteria.

**Schedule:** Monday 8am
**Model:** Sonnet
**Budget:** $5.00
**Approval:** Not required (research only)

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `search-prospects` | Web/LinkedIn research | No |
| `create-prospect` | Save prospect with ICP score | No |
| `match-icp` | Score prospect against ICP | No |
| `web-search` | Company research | No |

**Workflow:**
```
1. Load active ICP definitions
2. Search for prospects matching criteria
3. Research each prospect (company, tech stack, signals)
4. Score against ICP (0-100)
5. Create prospects for scores ≥60
6. Log research notes for follow-up
```

**ICP Scoring Weights:**
- Industry match: 30%
- Company size: 25%
- Tech stack: 25%
- Buying signals: 20%

**Skill file:** `storage/app/skills/lead-generation/SKILL.md`

---

### Outreach Campaign Agent

Creates personalized outreach messages for qualified prospects.

**Schedule:** Daily 9am weekdays
**Model:** Sonnet
**Budget:** $3.00
**Approval:** Required for all messages

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `draft-outreach-message` | Create personalized message | No |
| `schedule-follow-up` | Schedule outreach | **Yes** |
| `web-search` | Prospect research | No |

**Workflow:**
```
1. Get prospects enrolled in active campaigns
2. Check sequence step for each prospect
3. Research recent news/triggers
4. Draft personalized message
5. Submit for approval
6. Approved messages send automatically
```

**Skill file:** `storage/app/skills/outreach-campaign/SKILL.md`

---

### Opportunity Scout Agent

Identifies revenue opportunities from completed work and client relationships.

**Schedule:** Friday 10am
**Model:** Sonnet
**Budget:** $5.00
**Approval:** Required for outreach suggestions

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `search-projects` | Find completed projects | No |
| `search-clients` | Get client information | No |
| `search-communications` | Review conversations | No |
| `get-quarterly-patterns` | Work pattern analysis | No |
| `get-client-health` | Satisfaction metrics | No |
| `web-search` | External research | No |
| `create-opportunity` | Log opportunity | No |
| `create-task` | Create follow-up | No |

**Opportunity Types:**
- **Upsell** - Additional services for existing clients
- **Cross-sell** - Related services (WordPress → WooCommerce)
- **Referral** - Satisfied clients who can refer others
- **Market** - New verticals based on work patterns

**Workflow:**
```
1. Review projects completed in last 30 days
2. Analyze client health scores and communications
3. Identify expansion signals and patterns
4. Research client markets externally
5. Create prioritized opportunity list
6. Submit for human review
```

**Skill file:** `storage/app/skills/opportunity-scout/SKILL.md`

---

### Client Sentiment Agent

Analyzes communications to detect sentiment shifts and escalation risks.

**Schedule:** Weekdays 8am
**Model:** Sonnet
**Budget:** $3.00
**Approval:** Not required (analysis only)

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `search-emails` | Get client emails | No |
| `search-slack-messages` | Get Slack messages | No |
| `get-recent-communications` | Get all recent comms | No |
| `search-clients` | Get client details | No |
| `update-client-sentiment` | Store sentiment score | No |
| `create-alert` | Create escalation alert | No |

**Sentiment vs Health Monitor:**
| Sentiment Agent | Health Monitor |
|-----------------|----------------|
| Communication tone | Operational metrics |
| Emotional signals | Project status |
| Escalation risk | Payment status |

**Sentiment Score:**
- **80-100**: Delighted (ask for referral)
- **50-79**: Satisfied (maintain)
- **20-49**: Neutral (check in)
- **0-19**: Concerned (schedule call)
- **<0**: At Risk (immediate attention)

**Alert Triggers:**
- Score drops >20 points
- Critical signal detected
- No communication 14+ days
- CC escalation to executives

**Skill file:** `storage/app/skills/client-sentiment/SKILL.md`

---

### Marketing Agent

Drives proactive growth through LinkedIn and X content.

**Schedule:** Monday 8am
**Model:** Sonnet
**Budget:** $5.00
**Approval:** Required for all posts

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `get-x-trends` | Real-time X/Twitter trend analysis via Grok | No |
| `search-projects` | Find completed work for content | No |
| `search-clients` | Understand verticals served | No |
| `get-stats` | Dashboard statistics | No |
| `web-search` | Industry trend research | No |
| `search-content` | Find existing content | No |
| `post-to-linked-in` | Publish to LinkedIn | **Yes** |
| `post-to-x` | Publish to X/Twitter | **Yes** |

**Workflow:**
```
1. Analyze current X trends via Grok (hashtags, formats, sentiment)
2. Review recent completed projects
3. Match trending topics to our work
4. Draft posts aligned with trends and tone guidelines
5. Optimize drafts using Grok scoring
6. Submit posts via tools (queued for approval)
7. Human reviews and approves/rejects
8. Approved posts publish automatically
```

**Skill file:** `storage/app/skills/marketing/SKILL.md`

---

### Bookkeeping Agent

Reviews and categorizes QuickBooks expenses for tax optimization.

**Schedule:** Weekdays 7am
**Model:** Sonnet
**Budget:** $5.00
**Approval:** Required for categorization changes

**Tools:**
| Tool | Purpose | Approval |
|------|---------|----------|
| `qbo-get-expenses` | Fetch uncategorized expenses | No |
| `qbo-get-categories` | List expense account options | No |
| `qbo-suggest-category` | AI suggestion for vendor | No |
| `qbo-categorize-expense` | Update expense category | **Yes** |

**Workflow:**
```
1. Fetch uncategorized expenses (last 30 days)
2. For each expense, determine appropriate category
3. Prioritize tax-deductible categories
4. Submit categorizations for approval
5. Human reviews and approves
6. Categories updated in QuickBooks
```

**Key Features:**
- Vendor pattern matching (Adobe → Office Expense)
- IRS tax category mappings
- Batch categorization for efficiency

**Skill file:** `storage/app/skills/bookkeeping/SKILL.md`

---

## Agent Runs

Every agent execution creates an `AgentRun` record:

```php
AgentRun::create([
    'agent_id' => $agent->id,
    'status' => 'running',  // running, completed, failed, requires_approval
    'context' => [...],     // Input context
    'output' => [...],      // Agent output
    'tokens_used' => 1500,
    'cost_usd' => 0.045,
    'started_at' => now(),
    'completed_at' => null,
    'error_message' => null,
]);
```

Query recent runs:

```bash
php artisan tinker
>>> AgentRun::with('agent')->latest()->take(10)->get(['id', 'agent_id', 'status', 'cost_usd']);
```

---

## Circuit Breaker

Agents have automatic circuit breaker protection:

1. **Trigger**: 3 consecutive failures
2. **Effect**: Agent stops executing
3. **Reset**: Automatic after 1 hour, or manual

Check circuit status:

```php
$agent = Agent::where('slug', 'business-strategist')->first();
$agent->circuit_broken_at;  // null = healthy, timestamp = broken
```

Manual reset:

```php
$agent->update(['circuit_broken_at' => null]);
```

---

## Approval Workflow

When `requiresApproval()` returns `true`:

1. Agent outputs are stored but not executed
2. `ApprovalRequest` record created
3. Notification sent to reviewers
4. Human approves/rejects in UI
5. If approved, side effects execute

```php
// Pending approvals
ApprovalRequest::where('status', 'pending')->get();

// Approve programmatically
$approval->approve($userId, 'Looks good');

// Reject
$approval->reject($userId, 'Needs revision');
```

---

## Best Practices

### 1. Single Responsibility
Each agent should do ONE thing well. Split complex workflows into chained agents.

### 2. External Prompts
Always use SKILL.md files, never embed prompts in code.

### 3. Deterministic Post-Processing
Side effects (emails, API calls) should happen in `processOutput()` or tools, not in the LLM response.

### 4. Budget Limits
Set appropriate `getMaxBudget()` to prevent runaway costs.

### 5. Approval for Side Effects
Any tool that modifies external systems should require approval.

### 6. Logging
All tool executions are logged automatically. Add custom logging for debugging.

---

## Troubleshooting

### Agent Not Triggering

```bash
# Check agent is synced
php artisan agents:sync

# Verify schedule
php artisan tinker
>>> Agent::where('slug', 'your-agent')->first()->schedule;

# Check circuit breaker
>>> Agent::where('slug', 'your-agent')->first()->circuit_broken_at;
```

### Tool Not Found

```bash
# List all discovered tools
php artisan tinker
>>> array_keys(app(\App\Agents\ToolRegistry::class)->all());
```

### High Costs

```bash
# Review recent runs
php artisan tinker
>>> AgentRun::where('cost_usd', '>', 1)->latest()->get(['agent_id', 'cost_usd', 'tokens_used']);
```

### Debug Execution

```bash
php artisan agents:test-pipeline --agent=your-agent
```

---

## Complete Tools Reference

### Data Retrieval Tools

| Tool | Parameters | Return Type | Approval | Description |
|------|-----------|-------------|----------|-------------|
| **search_clients** | query, status, industry, sort_by, limit | Array of clients | No | Search clients by name, industry, or criteria |
| **search_projects** | query, client_id, status, tech_stack, limit | Array of projects | No | Search projects with filters |
| **search_tasks** | query, project_id, assignee_id, status, limit | Array of tasks | No | Search tasks by criteria |
| **search_leads** | query, stage, stages, min_deal_value, days_since_contact, limit | Array of leads | No | Search pipeline leads |
| **search_prospects** | query, icp_score_min, status, limit | Array of prospects | No | Find prospects for outreach |
| **search_content** | query, status, type, site_id, scheduled_after, limit | Array of content | No | Search content suggestions and posts |
| **get_stats** | metric, period, client_id | Statistics object | No | Get dashboard statistics |
| **get_focus** | type, limit, priority_filter | Focus recommendations | No | Get human-required items and priorities |
| **get_approvals** | status, category, agent_id, limit | Array of approvals | No | Get pending approval requests |

### Analysis & Intelligence Tools

| Tool | Parameters | Return Type | Approval | Description |
|------|-----------|-------------|----------|-------------|
| **analyze_goal_progress** | goal_id, include_levers | Progress analysis | No | Analyze progress toward strategic goals |
| **get_funnel_metrics** | period | Funnel metrics | No | Get conversion rates and cycle times |
| **forecast_revenue** | horizon_days, scenario | Revenue forecast | No | Project 30/60/90 day revenue |
| **get_quarterly_patterns** | quarter | Pattern analysis | No | Analyze work patterns by industry/tech/service |
| **match_icp** | prospect_data | ICP score (0-100) | No | Score prospect against ICP criteria |
| **get_x_trends** | analysis_type, topic, draft | Trend analysis | No | Analyze X/Twitter trends via Grok |
| **web_search** | query, type, limit | Search results | No | Search web for research |

### SEO & Content Tools

| Tool | Parameters | Return Type | Approval | Description |
|------|-----------|-------------|----------|-------------|
| **seo_keyword_research** | topic, vertical, intent | Keyword list | No | Find keywords for topic/vertical |
| **seo_analyze_serp** | keyword | SERP analysis | No | Analyze top-ranking content |
| **seo_competitor_gaps** | competitors, our_domain | Keyword gaps | No | Find competitor keyword gaps |
| **seo_search_volume** | keywords | Volume/difficulty data | No | Get search volume metrics |
| **seo_generate_landing** | keyword, industry, services | Landing page HTML | No | Generate SEO landing page |
| **seo_generate_blog** | topic, keywords, outline | Blog post content | No | Generate SEO blog post |
| **seo_optimize_content** | content, target_keyword | Optimized content | No | Optimize existing content |
| **seo_get_rankings** | site, keywords, period | Ranking positions | No | Get Search Console rankings |
| **seo_track_page** | page_url, period | Page KPIs | No | Track page performance metrics |
| **seo_get_pseo_performance** | - | PSEO summary | No | Get programmatic SEO performance |
| **seo_get_conversions** | page_url, period | Conversion data | No | Get Analytics conversion tracking |

### Action Tools (Require Approval)

| Tool | Parameters | Return Type | Approval | Risk | Description |
|------|-----------|-------------|----------|------|-------------|
| **create_task** | title, description, assignee_id, due_date | Task object | No | Low | Create task in system |
| **update_task** | task_id, updates | Updated task | No | Low | Update task fields |
| **create_client** | name, industry, contact_info | Client object | Yes | Medium | Create new client record |
| **create_project** | client_id, name, description, budget | Project object | Yes | Medium | Create new project |
| **create_prospect** | company_name, contact, icp_score, notes | Prospect object | No | Low | Save qualified prospect |
| **create_weekly_plan** | focus_areas, targets, items | Plan object | Yes | Low | Create strategic weekly plan |
| **assign_agent_task** | agent_id, task_description, context | Assignment object | Yes | Medium | Delegate task to agent |
| **draft_outreach_message** | prospect_id, channel, personalization | Message draft | No | Low | Create personalized outreach |
| **schedule_follow_up** | prospect_id, message, send_date | Scheduled message | Yes | Medium | Schedule follow-up outreach |
| **post_to_linked_in** | content, media | Post object | Yes | High | Publish LinkedIn post |
| **post_to_x** | content, media | Tweet object | Yes | High | Publish X/Twitter post |
| **wp_create_page** | title, content, seo_meta | Page object | Yes | High | Create WordPress page |
| **wp_create_post** | title, content, category, tags | Post object | Yes | High | Create WordPress post |
| **qbo_categorize_expense** | expense_id, category_id | Updated expense | Yes | High | Categorize QuickBooks expense |

### QuickBooks Tools

| Tool | Parameters | Return Type | Approval | Description |
|------|-----------|-------------|----------|-------------|
| **qbo_get_expenses** | status, date_from, date_to, limit | Array of expenses | No | Fetch expenses from QuickBooks |
| **qbo_get_categories** | - | Array of categories | No | Get expense account categories |
| **qbo_suggest_category** | vendor, amount, description | Suggested category | No | AI suggestion for expense category |
| **qbo_categorize_expense** | expense_id, category_id | Updated expense | Yes | Update expense category |

### Tool Discovery

All tools are auto-discovered from `app/Agents/Tools/`. Check available tools:

```bash
php artisan tinker
>>> app(\App\Agents\ToolRegistry::class)->all();
```

---

## Agent Orchestration Patterns

### Pattern 1: Sequential Chaining

Agents can trigger other agents in sequence:

```
Business Strategist (Monday 6am)
    ↓ assigns tasks
Lead Generation (Monday 8am)
    ↓ creates prospects
Outreach Campaign (Monday 9am)
    ↓ drafts messages
[Human Approval]
    ↓ approved messages sent
```

**Implementation:**
```php
protected function getChainFrom(): ?string
{
    return 'content-creator'; // Runs after this agent
}
```

### Pattern 2: Parallel Execution

Multiple agents run independently on same schedule:

```
7am Weekdays:
├─ Client Health Monitor (monitors metrics)
├─ Bookkeeping (categorizes expenses)
└─ Programmatic SEO (checks performance)
```

### Pattern 3: Human-in-the-Loop

Agents with `requiresApproval() = true`:

```
Agent executes → Creates ApprovalRequest → Human reviews → Action executed
                                              ↓
                                         Rejected → Agent notified
```

### Pattern 4: Event-Driven

Agents respond to external events:

```
Slack mention detected
    ↓
Slack Webhook Controller
    ↓
Trigger relevant agent with context
    ↓
Agent analyzes and responds
```

---

## Using Approval Gates (HasApprovalGates Trait)

The `HasApprovalGates` trait provides approval workflow functionality.

### Basic Usage

```php
use App\Agents\Concerns\HasApprovalGates;

class MyAgent extends BaseAgentDefinition
{
    use HasApprovalGates;

    protected array $approvalCategories = [
        'communication.client_email',
        'financial.payment',
        'content.publish',
    ];
}
```

### Approval Categories

| Category | Risk Level | Auto-Approve Conditions |
|----------|-----------|------------------------|
| `deploy.production` | Critical | Never |
| `deploy.staging` | Medium | Tests pass |
| `financial.invoice` | High | Amount < $500 |
| `financial.payment` | Critical | Never |
| `database.migration` | Critical | Never |
| `communication.client_email` | Medium | Pre-approved template |
| `communication.cold_outreach` | Medium | Existing client |
| `content.publish` | High | Pre-approved template |

### Creating Approval Requests

```php
$approval = $this->createApprovalRequest(
    run: $agentRun,
    category: 'communication.client_email',
    title: 'Send onboarding email to Acme Corp',
    payload: [
        'to' => 'contact@acmecorp.com',
        'subject' => 'Welcome to Zao',
        'body' => $emailBody,
    ],
    description: 'Automated onboarding email'
);
```

### Execute with Approval

```php
$result = $this->executeWithApproval(
    run: $agentRun,
    category: 'content.publish',
    title: 'Publish blog post: Laravel Best Practices',
    payload: ['post_id' => 123],
    action: function($payload) {
        return WordPress::publish($payload['post_id']);
    }
);
```

### Auto-Approve Conditions

```php
// In config/approval_policies.php
'communication.client_email' => [
    'risk_level' => 'medium',
    'requires_approval' => true,
    'auto_approve_conditions' => [
        'existing_client' => true,
        'pre_approved_template' => [1, 2, 3], // Template IDs
    ],
],
```

---

## Using Sandbox (HasSandbox Trait)

The `HasSandbox` trait provides filesystem and network isolation.

### Basic Usage

```php
use App\Agents\Concerns\HasSandbox;

class MyAgent extends BaseAgentDefinition
{
    use HasSandbox;

    protected array $allowedDomains = [
        'api.anthropic.com',
        'api.openai.com',
        'github.com',
    ];
}
```

### Creating Sandbox

```php
public function run(AgentRun $agentRun): array
{
    // Create isolated workspace
    $sandboxPath = $this->createSandbox($agentRun->id);

    // Copy files into sandbox
    $this->copyToSandbox('/path/to/source/files');

    // Execute agent logic
    $result = $this->executeInSandbox();

    // Get output files
    $outputs = $this->getOutputFiles();

    // Cleanup (preserve output directory)
    $this->cleanupSandbox(preserveOutput: true);

    return $result;
}
```

### Filesystem Isolation

```php
// Sandbox structure
storage/app/agent-workspaces/{run-id}/
├── workspace/     # Read-only input files
├── output/        # Agent writes here
├── temp/          # Temporary files
└── .sandbox-manifest.json

// Check path access
if ($this->isPathAllowed($path)) {
    File::write($path, $content);
}

// Validate file operations
if ($this->validateFileOperation('write', $path)) {
    // Safe to write
}
```

### Network Isolation

```php
// Check URL access
if ($this->isUrlAllowed('https://api.github.com/repos/zao/project')) {
    Http::get($url);
}

// Add allowed domains dynamically
$this->addAllowedDomains([
    'api.stripe.com',
    'hooks.slack.com',
]);

// Get environment for sandboxed execution
$env = $this->getSandboxEnvironment();
// Returns:
// [
//     'SANDBOX_PATH' => '/path/to/sandbox',
//     'SANDBOX_WORKSPACE' => '/path/to/sandbox/workspace',
//     'SANDBOX_OUTPUT' => '/path/to/sandbox/output',
//     'SANDBOX_ALLOWED_DOMAINS' => 'api.anthropic.com,github.com',
// ]
```

### Blocked Patterns

The sandbox automatically blocks access to sensitive files:

```php
protected array $blockedPatterns = [
    '*.env*',
    '*credentials*',
    '*secret*',
    '*.pem',
    '*.key',
    '*password*',
    '.git/config',
];
```

---

## Agent Chaining Examples

### Example 1: Content Pipeline

```php
// 1. Marketing Agent (Monday 8am)
class MarketingAgent extends BaseAgentDefinition
{
    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 8 * * 1'; // Monday 8am
    }
}

// 2. Content Creator Agent (chained)
class ContentCreatorAgent extends BaseAgentDefinition
{
    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'marketing'; // Runs after Marketing Agent
    }
}

// 3. WordPress Publisher (chained)
class WordPressAgent extends BaseAgentDefinition
{
    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'content-creator'; // Runs after Content Creator
    }
}
```

**Flow:**
```
Marketing Agent creates content plan
    ↓ passes context to
Content Creator generates drafts
    ↓ passes drafts to
WordPress Publisher schedules posts
    ↓ requires approval
Human approves
    ↓
Posts published
```

### Example 2: Lead Nurture Pipeline

```php
// Business Strategist assigns lead gen task
assign_agent_task(
    agent_id: 'lead-generation',
    task: 'Find 20 SaaS prospects',
    context: ['industries' => ['SaaS', 'FinTech']]
)

// Lead Generation creates prospects
create_prospect(...)

// Outreach Campaign auto-triggers
// (scheduled daily, finds new prospects)
```

### Example 3: Client Health Alert Chain

```php
// Client Sentiment detects issue
if ($sentimentScore < 20) {
    create_alert([
        'type' => 'client_at_risk',
        'client_id' => $clientId,
        'severity' => 'high',
    ]);

    // Trigger Upsell/Retention agent
    trigger_agent(
        agent: 'client-retention',
        context: ['client_id' => $clientId]
    );
}
```

---

## See Also

- [Infrastructure](./INFRASTRUCTURE.md) - Traits, config, services
- [Programmatic SEO](./specs/PROGRAMMATIC_SEO.md) - SEO agent system
- [Agentic Workflows](./AGENTIC_WORKFLOWS.md) - Design principles
- [Commands Reference](./COMMANDS.md) - CLI tools
- [System Architecture](./SYSTEM.md) - Overall system design
