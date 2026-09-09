# Zao Dash User Guide

Guide to using Zao Dash for business strategy, lead generation, and client management.

---

## Getting Started

### Dashboard Overview

The dashboard provides at-a-glance visibility into:

- **Revenue Goal Progress** - Current vs target with on-track indicator
- **This Week's Plan** - Active weekly plan with task completion
- **KPI Metrics** - Key performance indicators
- **Quick Actions** - Command palette access (⌘K)

---

## Strategic Goals

Navigate to: **Goals** (`/goals`)

### Creating a Goal

1. Click "Create Goal" button
2. Enter goal details:
   - **Name**: e.g., "FY 2026 Revenue Target"
   - **Fiscal Year**: e.g., 2026
   - **Revenue Target**: e.g., $1,500,000
   - **Margin Target**: e.g., 90%
3. Click "Create"

The system automatically generates:
- Quarterly periods with pro-rated targets
- Monthly breakdowns
- Weekly targets

### Understanding Progress

| Indicator | Meaning |
|-----------|---------|
| 🟢 On Track | Actual ≥ Expected for time elapsed |
| 🟡 Behind | Actual < Expected but recoverable |
| 🔴 Critical | Significantly behind target |

### Levers Panel

When behind target, the system suggests actions:

- **Increase Lead Volume** - Need X more leads/week
- **Improve Close Rate** - Target Y% vs current Z%
- **Raise Deal Size** - Target $Xk avg vs current $Yk
- **Shorten Cycle** - Reduce days from X to Y

---

## Weekly Plans

Navigate to: **Weekly Plans** (`/weekly-plans`)

### How Plans Are Created

The **Business Strategist Agent** runs Monday at 6am and:

1. Analyzes goal progress vs targets
2. Reviews funnel metrics and pipeline
3. Creates a weekly plan with action items
4. Assigns tasks to specialized agents

### Plan Status Flow

```
Draft → Approved → Active → Completed
```

### Reviewing & Approving Plans

1. New plans appear with "Draft" status
2. Click into the plan to review:
   - Focus areas for the week
   - Human action items
   - Agent tasks (delegated work)
3. Click "Approve" to activate

### Plan Items

| Type | Description |
|------|-------------|
| Human | Tasks for you to complete manually |
| Agent | Automated work delegated to AI agents |

Item statuses:
- **Pending** - Not started
- **In Progress** - Currently being worked on
- **Completed** - Finished successfully
- **Skipped** - Intentionally skipped with reason

---

## Prospects & Lead Generation

Navigate to: **Prospects** (`/prospects`)

### Understanding the Pipeline

```
Prospects → Qualified Leads → Opportunities → Clients
   │              │                │
   └── ICP Score  └── Pipeline     └── Revenue
```

### Prospect Sources

- **Lead Generation Agent** - Automated research (Monday 8am)
- **Manual Entry** - Add prospects directly
- **Imports** - Bulk upload from CSV

### ICP Scoring

Each prospect is scored against Ideal Customer Profiles:

| Score Range | Rating | Action |
|-------------|--------|--------|
| 80-100 | Excellent | Prioritize outreach |
| 60-79 | Good | Standard outreach |
| 40-59 | Fair | Nurture sequence |
| 0-39 | Poor | Do not pursue |

Score factors:
- Industry match (30%)
- Company size (25%)
- Tech stack (25%)
- Buying signals (20%)

### Converting Prospects

When a prospect qualifies:

1. Click "Convert to Lead"
2. Confirm contact information
3. Set initial pipeline stage
4. Prospect becomes a Lead in your CRM

---

## Outreach Campaigns

Navigate to: **Campaigns** (`/campaigns`)

### Campaign Types

| Type | Purpose |
|------|---------|
| Cold Outreach | New prospect engagement |
| Nurture | Warm lead development |
| Re-engagement | Revive stale contacts |

### Creating a Campaign

1. Click "Create Campaign"
2. Configure targeting:
   - ICP to target
   - Minimum ICP score
   - Industries filter
   - Title filter
3. Set channels (Email, LinkedIn)
4. Save as Draft

### Building Sequences

Each campaign has multi-step sequences. Use the Sequence Builder UI:

```
Step 1: Initial Email (Day 0)
    ↓
Step 2: LinkedIn Connect (Day 3, if no reply)
    ↓
Step 3: Follow-up Email (Day 5, if no reply)
    ↓
Step 4: Break-up Email (Day 10, if no reply)
```

**Sequence Builder Features**:
- **Drag to reorder** steps in the sequence
- **Add Step** button creates new sequence steps
- **Edit** each step's content and settings
- **Delete** removes a step from the sequence

Sequence step options:
- **Channel**: Email, LinkedIn, Phone, Manual
- **Delay**: Days after previous step
- **Condition**: Always, No Reply, Opened, Not Opened
- **Requires Approval**: Toggle for review before sending
- **Subject Template**: For emails, the subject line
- **Body Template**: Message content with variable placeholders

### Campaign Metrics

| Metric | Calculation |
|--------|-------------|
| Enrolled | Total prospects in campaign |
| Sent | Messages delivered |
| Open Rate | Opened / Sent |
| Reply Rate | Replied / Sent |
| Conversion | Became Lead / Enrolled |

### Activating Campaigns

1. Review all sequences
2. Click "Activate"
3. **Outreach Campaign Agent** begins daily processing
4. Messages requiring approval appear in queue

---

## Approvals

Actions requiring approval appear in your approval queue.

### What Needs Approval

- Outreach messages (emails, LinkedIn)
- Weekly plans
- Campaign activations
- Agent task assignments

### Reviewing Approvals

1. Notification appears (bell icon)
2. Click to view pending approval
3. Review the content/action
4. Click "Approve" or "Reject"
5. Optionally add a note

### Expiration

Approvals expire after 24 hours (configurable). Expired items need re-generation.

---

## Notifications

Real-time notifications appear via:

- Bell icon (top right)
- Toast messages (bottom right)
- Email (for high priority)

### Notification Types

| Type | Examples |
|------|----------|
| Agent Completed | "Lead Gen Agent found 12 prospects" |
| Approval Needed | "Weekly plan ready for review" |
| Alert | "Client health alert: Acme Corp" |
| System | "Integration disconnected" |

---

## Command Palette

Press **⌘K** (Mac) or **Ctrl+K** (Windows) to open.

### Quick Actions

- Search clients, projects, leads
- Navigate to any page
- Run quick commands
- Access AI tools

### AI Tools

Available through command palette:
- "Analyze client health"
- "Draft proposal for..."
- "Summarize project status"

---

## Integrations

Navigate to: **Settings → Integrations** (`/settings/integrations`)

### QuickBooks

Syncs invoices, revenue data for goal tracking.

Setup:
1. Click "Connect QuickBooks"
2. Authorize access
3. Select company
4. Data syncs automatically

### Harvest

Syncs time entries, projects for capacity planning.

Setup:
1. Enter Account ID
2. Enter Personal Access Token
3. Click "Connect"

### Slack

Receive notifications, trigger agents from Slack.

Setup:
1. Click "Add to Slack"
2. Select workspace
3. Choose notification channel

### GitHub

Track development progress, deploy status.

Setup:
1. Install GitHub App
2. Select repositories
3. Configure webhooks

### Google (Gmail, Calendar, Drive)

Full GSuite integration for emails, calendar, and documents.

Setup:
1. Click "Connect Google"
2. Authorize access (gmail.readonly, calendar, drive)
3. Select calendars to sync

**What Gets Synced:**
- Gmail: Last 7 days of inbox messages
- Calendar: Next 30 days of events
- Drive: Documents for context matching

**Auto-Matching:**
- Emails matched to clients by sender domain
- Calendar events matched by attendee emails
- Documents matched by content and folder structure

### LinkedIn

Social media integration for the Marketing Agent.

Setup:
1. Go to Settings → Integrations
2. Click "Connect LinkedIn"
3. Authorize with LinkedIn
4. Optionally select a Company Page

**Required LinkedIn Products:**
- Sign In with LinkedIn using OpenID Connect
- Share on LinkedIn (request in LinkedIn Developer Portal)

**Capabilities:**
- Post as yourself (personal brand)
- Post as company page (if authorized)
- Share articles with commentary

### X (Twitter)

Social media integration for the Marketing Agent.

Setup:
1. Go to Settings → Integrations
2. Click "Connect X"
3. Authorize with X

**Requirements:**
- X Developer account
- Basic tier ($100/mo) required for posting
- Read and Write permissions

**Capabilities:**
- Single tweets (280 chars)
- Auto-threaded long content
- Reply to tweets

### Notion

Sync docs, databases for context.

Setup:
1. Click "Connect Notion"
2. Select pages to share
3. Configure sync frequency

---

## Tips & Best Practices

### Weekly Workflow

**Monday Morning**
1. Check dashboard for new weekly plan
2. Review and approve plan
3. Review any pending approvals

**Daily**
1. Check notifications
2. Update task statuses
3. Review agent outputs

**Friday**
1. Update completed items
2. Add notes for next week
3. Review goal progress

### Maximizing Agent Value

1. **Keep ICPs Updated** - Better targeting = better prospects
2. **Review Agent Outputs** - Feedback improves quality
3. **Approve Promptly** - Stale approvals lose relevance
4. **Trust the System** - Let agents handle repetitive work

### Common Questions

**Q: Why did the agent suggest this action?**
A: Check the agent run details for reasoning context.

**Q: Can I modify what agents do?**
A: Yes, SKILL.md files in storage/app/skills/ control behavior.

**Q: How do I stop an agent?**
A: Disable in Settings → Agents, or set circuit breaker.

**Q: Why is my goal showing "Behind"?**
A: Revenue actual < expected for time elapsed. Check Levers panel.

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| ⌘K | Open command palette |
| Esc | Close modal/palette |
| ⌘Enter | Submit form |
| ⌘S | Save (where applicable) |

---

## Client Portal

Clients can access a dedicated portal at `/portal` to view their projects and invoices.

### For Admins: Creating Client Users

1. Create a user with `role: 'client'`
2. Set `client_id` to link them to a client record
3. Share login credentials with the client

```php
User::create([
    'name' => 'John at Acme',
    'email' => 'john@acme.com',
    'password' => Hash::make('secure-password'),
    'role' => 'client',
    'client_id' => $acmeClientId,
]);
```

### Client Portal Features

| Route | Feature |
|-------|---------|
| `/portal` | Dashboard with project summary and invoices |
| `/portal/projects` | All projects with milestones and updates |
| `/portal/projects/{id}` | Single project detail with deliverables |
| `/portal/invoices` | Invoice history with payment status |

Client users can ONLY see data for their associated client. Internal dashboard, agents, and admin features are completely inaccessible.

---

## Vault (Secret Management)

Navigate to: **Vault** (`/vault`)

### Storing API Keys

1. Click "Add Secret"
2. Enter:
   - **Service**: e.g., Stripe, SendGrid
   - **Key**: The secret value
   - **Scope**: Which agents can access it
3. Click "Save"

Secrets are encrypted at rest using Laravel's encryption.

### Access Logging

Every time an agent or user retrieves a secret, it's logged:
- Who accessed it
- When
- For what purpose

Review access logs via the Vault page.

### Rotating Secrets

1. Click "Rotate" on a secret
2. Enter the new value
3. Old value is discarded, new value encrypted

---

## Emergency Controls

### Kill Switch

Located in: **Settings → Emergency**

Use when you need to immediately stop agent activity:

| Control | Effect |
|---------|--------|
| Global Kill Switch | Stops ALL agent runs immediately |
| Agent Kill Switch | Stops a specific agent |
| Spend Limit | Halts runs when daily budget exceeded |

### When to Use

- **API cost runaway**: Agent making too many calls
- **Data issue**: Agent operating on incorrect data
- **Investigation**: Need to pause while debugging
- **Security concern**: Suspicious activity detected

### Reactivating

1. Navigate to Emergency settings
2. Toggle the kill switch off
3. Confirm the action
4. Agents resume on next scheduled trigger

---

## Proactive Growth Engine

The dashboard includes an AI-powered Growth Engine that surfaces opportunities:

### How It Works

The system continuously monitors:
- **Slack messages** for budget/expansion signals
- **Client health scores** for at-risk accounts
- **Lead pipeline** for hot/stale opportunities
- **Completed projects** for case study potential
- **Work patterns** for vertical focus opportunities

### Insight Types

| Type | Signal | Suggested Action |
|------|--------|------------------|
| Slack Opportunity | Client mentions budget, expansion | Draft upsell proposal |
| Slack Risk | Client mentions frustration, delay | Review conversation |
| Vertical Pattern | 3+ clients in same industry | Generate landing page |
| Case Study | Recently completed project | Draft case study |
| Hot Lead | High-value, recently active | Send follow-up |
| Stale Lead | High-value, no recent contact | Draft outreach |
| At-Risk Client | Health score < 50 | Review client |

### One-Click Actions

Each insight card has an action button that:
1. Opens the relevant agent
2. Pre-fills context from the insight
3. Generates content/analysis for approval

### Dashboard Location

Insights appear in the **Growth Engine** panel on the main dashboard, sorted by priority and recency.

---

## Content & Marketing Tools

### Quarterly Pattern Analysis

The system analyzes your work patterns each quarter to suggest:

- **Case Studies**: Based on successful client work
- **Blog Posts**: Based on services delivered
- **Landing Pages**: For industries with track record
- **Outreach Targets**: ICPs based on success patterns

Access via: **Dashboard → Insights** or the quarterly analysis agent.

### Marketing Agent

**Schedule:** Monday 8am
**Budget:** $5.00

Generates content plans for LinkedIn and X (Twitter):

- Analyzes real-time X trends via Grok AI
- Reviews recent work and wins
- Drafts post ideas aligned with current trends
- Suggests optimal posting times
- Creates engagement templates

#### Grok Trend Intelligence

The agent uses xAI's Grok to understand what's working on X RIGHT NOW:

| Analysis Type | What It Does |
|---------------|--------------|
| Trends | Current conversations and hot topics |
| Hashtags | Trending tags with engagement levels |
| Content Formats | What post types are performing (threads, media, etc.) |
| Sentiment | Tone and controversy avoidance |
| Suggestions | AI-generated post ideas based on trends |
| Optimize | Score and improve draft posts |

**Workflow:**
1. Agent checks current X trends for WordPress/Laravel/web development
2. Identifies hashtags that are performing well
3. Matches trending topics to our recent work
4. Drafts content that authentically joins conversations
5. Optimizes drafts using Grok's scoring

**Setup:** Add `GROK_API_KEY` to your environment (get from [xAI Console](https://console.x.ai))

#### LinkedIn Posting

The agent can post directly to LinkedIn:
- Text posts (max 3000 characters)
- Article shares with commentary
- Company page posts (if connected)

#### X (Twitter) Posting

The agent can post to X:
- Single tweets (max 280 characters)
- Auto-threaded long-form content
- Reply chains for engagement

#### Approval Flow

1. Agent drafts content during scheduled run
2. Posts queue for approval in `/approvals`
3. Review content and hashtags
4. Click "Approve" to publish immediately
5. Or "Reject" with feedback for revision

All posts require approval before publishing.

### WordPress Publishing

The WordPress Agent can:

- Draft blog posts from outlines
- Format case studies
- Schedule publication
- Manage categories/tags

All content goes through approval workflow.

---

## Financial Tools

### Bookkeeping Agent

**Schedule:** Weekdays 7am
**Budget:** $5.00

Automatically categorizes QuickBooks expenses for tax optimization.

#### What It Does

1. Fetches uncategorized expenses from last 30 days
2. Analyzes vendor name and description
3. Suggests appropriate expense category
4. Prioritizes tax-deductible categories
5. Submits categorizations for approval

#### Tax Categories

The agent knows IRS expense categories:

| Category | Tax Deductible | Examples |
|----------|----------------|----------|
| Office Expense | Yes | Supplies, equipment |
| Software & Subscriptions | Yes | SaaS tools, hosting |
| Professional Services | Yes | Contractors, legal |
| Travel & Meals | Partial | Client meals (50%), travel |
| Advertising | Yes | Marketing, ads |

#### Vendor Pattern Matching

The agent learns vendor patterns:
- `Amazon` → Office Expense
- `Adobe` → Software & Subscriptions
- `GitHub` → Software & Subscriptions
- `OpenAI` → Software & Subscriptions

#### Approval Flow

1. Agent suggests category with reasoning
2. Review in `/approvals`
3. Approve to update QuickBooks
4. Or reject to keep uncategorized

**Note:** Categorizations require approval because they affect tax reporting.

---

## Slack Integration

### Action Item Detection

The system monitors Slack for potential tasks:

- Messages with "can you", "please", "need to"
- Mentions of deadlines ("by EOD", "ASAP")
- Direct requests with @mentions

Detected items appear in your task suggestions with:
- Original message context
- Suggested task title
- Urgency level
- One-click task creation

### Configuration

1. Connect Slack in Settings → Integrations
2. Select channels to monitor
3. Action items are processed automatically

---

## Google Drive Sync

Documents from Google Drive are indexed for context:

- MSAs and contracts
- SOWs and proposals
- Meeting notes

The system attempts to auto-link documents to clients based on content.

### Manual Sync

Trigger a sync via Settings → Integrations → Google → "Sync Drive"

---

## Getting Help

- **In-app**: Command palette → "Help"
- **Documentation**: `/docs` folder in codebase
- **Issues**: Report bugs via GitHub Issues

---

## Site Builder (Autonomous Website Creation)

Navigate to: **Site Builder** (`/site-builder`) or AI Tools → Site Builder

### What It Does

Build professional WordPress websites from domain names and business briefs using AI agents:

| Input | Process | Output |
|-------|---------|--------|
| `acme.com` + "Local bakery" | Research → Build → Deploy | Live WordPress site in 30 minutes |

### Quick Start

1. Click "Site Builder" in AI Tools menu
2. Enter domain name and business brief  
3. Choose hosting type (WordPress.com, self-hosted, existing site)
4. Click "Build Website"
5. AI agents handle: research, WordPress setup, content creation, deployment

### Supported Company Types

- **Active Companies**: Research current website, migrate content
- **Defunct Companies**: Archive.org research, reconstruct brand identity  
- **Startups**: Generate brand from industry best practices
- **Enterprise**: Complex requirements with comprehensive content

### Content Pipeline

All Zao Dash agents can push content directly to client WordPress sites:

```
Agent Output → ContentSyncAgent → WordPress MCP → Client Site
     ↓              ↓                     ↓              ↓
Blog Post     Draft Creation       createPost()     Live Content
Case Study    Media Upload        updatePost()     Client Edit  
Landing Page  Category Sync       REST API         Immediate Update
```

### Client Benefits

- **Full Control**: Clients edit sites directly in WordPress
- **Professional Design**: Ollie theme with custom branding
- **SEO Optimized**: Built-in performance and search optimization
- **Mobile Responsive**: Modern, accessible design

### Business Model

- **$500 Website Service**: Now autonomous (15-30 minute delivery)
- **Recurring Revenue**: Content updates and site maintenance  
- **Scalable**: One system serves unlimited client sites

### Technical Features

- **WordPress MCP Integration**: Existing WordPress sites become content hubs
- **Archive.org Integration**: Research defunct company information
- **Brand Analysis**: Extract colors, logos, voice from websites
- **Ollie Theme**: Professional design system with 60+ patterns

### Cost Structure

- **Build Cost**: $10-20 per site (vs $500 manual development)
- **Client Hosting**: $3-30/month (WordPress.com or self-hosted)
- **Content Management**: $50-200/month recurring revenue
- **ROI**: 25-50x return on investment

The Site Builder transforms your $500 website service into an autonomous, scalable business with recurring revenue streams.
