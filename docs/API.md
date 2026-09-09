# Zao Dash API Documentation

Complete reference for all routes, controllers, webhooks, scheduled tasks.

---

## Table of Contents

- [Authentication](#authentication)
- [Dashboard & Stats](#dashboard--stats)
- [Agents](#agents)
- [Approvals](#approvals)
- [Projects](#projects)
- [Clients](#clients)
- [Tasks](#tasks)
- [Leads](#leads)
- [Team](#team)
- [Vault](#vault)
- [Command Palette](#command-palette)
- [Integrations](#integrations)
- [Webhooks](#webhooks)
- [Notifications](#notifications)
- [Health Alerts](#health-alerts)
- [Insights & Capabilities](#insights--capabilities)
- [Strategic Goals & Weekly Plans](#strategic-goals--weekly-plans)
- [Prospects & Campaigns](#prospects--campaigns)
- [Prompt Library](#prompt-library)
- [Agent Analytics](#agent-analytics)
- [Cost Tracking](#cost-tracking)
- [Agent Templates](#agent-templates)
- [Client Portal](#client-portal)
- [Scheduled Tasks](#scheduled-tasks)
- [Console Commands](#console-commands)
- [Broadcasting Channels](#broadcasting-channels)

---

## Authentication

**Middleware:** `guest` for login routes, `auth` for protected routes

### Show Login
```
GET /login
Controller: AuthController@showLogin
Response: Inertia 'Auth/Login'
```

### Login
```
POST /login
Controller: AuthController@login
Middleware: guest

Request:
{
  "email": "string (required, email)",
  "password": "string (required)",
  "remember": "boolean (optional)"
}

Response: Redirect to dashboard or validation errors
```

### Logout
```
POST /logout
Controller: AuthController@logout
Middleware: auth
Response: Redirect to /login
```

---

## Dashboard & Stats

### Dashboard
```
GET /
Middleware: auth
Response: Inertia 'Dashboard'

Data:
{
  "kpis": {
    "revenue_mtd": number,
    "revenue_change_pct": number,
    "active_projects": number,
    "client_health_avg": number,
    "hours_tracked_mtd": number,
    "pending_approvals": number,
    "running_agents": number,
    "total_agents": number
  },
  "recentAgentRuns": [...],
  "pendingApprovals": [...],
  "clients": [...],
  "activeGoal": {...} | null,
  "currentPlan": {...} | null
}
```

### KPI Dashboard Data
```
GET /api/kpis
Controller: KpiController@index
Middleware: auth

Response:
{
  "pipeline": {
    "total_pipeline": number,
    "weighted_pipeline": number,
    "win_rate": number,
    "won_count": number,
    "lost_count": number,
    "won_value_mtd": number,
    "funnel": [...],
    "by_stage": {...}
  },
  "clients": {
    "total_active": number,
    "avg_health": number,
    "health_distribution": {
      "healthy": number,
      "at_risk": number,
      "critical": number
    },
    "at_risk_clients": [...],
    "by_status": {...}
  },
  "operations": {
    "task_completion_rate": number,
    "completed_tasks_month": number,
    "total_tasks_month": number,
    "tasks_by_status": {...},
    "overdue_tasks": number,
    "agent_success_rate": number,
    "total_agent_runs": number,
    "agent_costs_30d": number,
    "active_projects": number,
    "projects_by_status": {...}
  },
  "financial": {
    "revenue_mtd": number,
    "revenue_change_pct": number,
    "outstanding_ar": number,
    "overdue_amount": number,
    "overdue_count": number,
    "avg_days_to_pay": number,
    "invoices_paid_mtd": number
  },
  "time_tracking": {
    "hours_mtd": number,
    "billable_hours": number,
    "non_billable_hours": number,
    "utilization_rate": number,
    "billable_amount_mtd": number,
    "hours_this_week": number,
    "unbilled_hours": number,
    "top_clients": [...]
  },
  "github": {
    "open_issues": number,
    "issues_closed_week": number,
    "agent_tasks": number,
    "prs_merged_week": number,
    "open_prs": number,
    "avg_pr_cycle_days": number,
    "issues_by_label": {...}
  },
  "trends": {
    "pipeline": [...],
    "tasks": [...],
    "agent_costs": [...],
    "overdue": [...],
    "revenue": [...],
    "hours": [...]
  }
}
```

---

## Agents

All agent routes require `auth` middleware.

### List Agents (UI)
```
GET /agents
Response: Inertia 'Agents/Index' with agents, stats
```

### Show Agent (UI)
```
GET /agents/{slug}
Response: Inertia 'Agents/Show' with agent details, runs, cost history
```

### Show Agent Run
```
GET /agents/{slug}/runs/{runId}
Response: Inertia 'Agents/Runs/Show'
```

### Create Agent
```
POST /agents
Controller: AgentController@store

Request:
{
  "name": "string (required, max:255)",
  "slug": "string (required, max:255, unique)",
  "description": "string (optional)",
  "status": "enum (optional): active|paused|disabled",
  "model": "enum (required): opus|sonnet|haiku",
  "requires_approval": "boolean (optional, default: true)",
  "use_consortium": "boolean (optional)",
  "max_budget_usd": "number (optional, default: 10)",
  "system_prompt": "string (optional)",
  "tools": "array (optional)",
  "schedule": "string (optional, cron expression)"
}
```

### Update Agent
```
PUT /agents/{agent}
Controller: AgentController@update
Request: Same as create (all optional except name/model)
```

### Delete Agent
```
DELETE /agents/{agent}
Controller: AgentController@destroy
```

### Update Agent Status
```
POST /agents/{agent}/status
Controller: AgentController@updateStatus

Request: { "status": "active|paused|disabled" }
```

### Trigger Agent
```
POST /agents/{agent}/trigger
Controller: AgentController@trigger

Request:
{
  "prompt": "string (optional)",
  "context": "object (optional)",
  "async": "boolean (optional)"
}

Response:
{
  "status": "string",
  "run_id": number,
  "requires_approval": boolean,
  "output": {...}
}
```

### Launch from Insight
```
POST /agents/launch
Controller: AgentController@launchFromInsight

Request:
{
  "type": "enum: upsell|landing|case_study|followup|review",
  "client": "string (optional, client slug)",
  "tone": "enum (optional): professional|friendly|formal|casual|technical",
  "focus": "string (optional, max:500)",
  "additionalContext": "string (optional, max:2000)",
  "notifyOnComplete": "boolean (optional)"
}
```

### Clone Agent
```
POST /agents/{agent}/clone
Controller: AgentController@clone

Request: { "name": "string (optional)" }
```

### Dry Run
```
POST /agents/{agent}/dry-run
Controller: AgentController@dryRun

Request:
{
  "prompt": "string (optional)",
  "context": "object (optional)"
}
```

### Agent Run Status
```
GET /agents/{agent}/runs/{runId}/status
Controller: AgentController@status

Response:
{
  "status": "string",
  "output": {...},
  "cost_usd": number,
  "started_at": "datetime",
  "completed_at": "datetime"
}
```

### Webhook Config
```
GET /agents/{agent}/webhook/config
Controller: WebhookController@config

Response:
{
  "agent": "string",
  "webhook_enabled": boolean,
  "webhook_url": "string",
  "has_token": boolean,
  "allowed_ips": [...],
  "has_ip_restriction": boolean
}
```

### Regenerate Webhook Token
```
POST /agents/{agent}/webhook/regenerate
Controller: WebhookController@regenerateToken

Response:
{
  "token": "string (store securely)",
  "webhook_url": "string",
  "message": "string"
}
```

### Disable Webhook
```
POST /agents/{agent}/webhook/disable
Controller: WebhookController@disable
```

### Update Webhook IP Allowlist
```
PUT /agents/{agent}/webhook/allowlist
Controller: WebhookController@updateAllowlist

Request:
{
  "allowed_ips": ["string (IP or CIDR)"]
}
```

---

## Agents REST API

### List Agents
```
GET /api/agents
Controller: AgentController@list
Response: Array of active agents
```

### Get Agent
```
GET /api/agents/{agent}
Controller: AgentController@apiShow

Response:
{
  "agent": {...},
  "stats": {
    "total_runs": number,
    "completed_runs": number,
    "total_cost": number
  }
}
```

### Create Agent (API)
```
POST /api/agents
Controller: AgentController@apiStore
Status: 201
```

### Update Agent (API)
```
PUT /api/agents/{agent}
Controller: AgentController@apiUpdate
```

### Delete Agent (API)
```
DELETE /api/agents/{agent}
Controller: AgentController@apiDestroy
```

### Run Agent (API)
```
POST /api/agents/{agent}/run
Controller: AgentController@trigger
Same as POST /agents/{agent}/trigger
```

### List Agent Runs
```
GET /api/agents/{agent}/runs
Controller: AgentController@runs

Query params:
- status: filter
- from: date (created_at >=)
- to: date (created_at <=)
- per_page: pagination (default: 20)

Response: Paginated runs
```

---

## Approvals

### List Approvals
```
GET /approvals
Controller: ApprovalController@index

Query params:
- status: pending|approved|rejected|expired
- risk_level: low|medium|high
- agent_id: filter by agent
- action_type: filter by action type

Response: Inertia 'Approvals/Index' with approvals, stats, filters
```

### Show Approval
```
GET /approvals/{approval}
Controller: ApprovalController@show
Response: Inertia 'Approvals/Show' with details, similar approvals
```

### Approve
```
POST /approvals/{approval}/approve
Controller: ApprovalController@approve

Request: { "comment": "string (optional)" }
```

### Reject
```
POST /approvals/{approval}/reject
Controller: ApprovalController@reject

Request: { "reason": "string (required, min:3)" }
```

### Bulk Approve
```
POST /approvals/batch-approve
Controller: ApprovalController@bulkApprove

Request:
{
  "ids": "array (required, min:1)",
  "comment": "string (optional)"
}
```

### Bulk Reject
```
POST /approvals/batch-reject
Controller: ApprovalController@bulkReject

Request:
{
  "ids": "array (required, min:1)",
  "reason": "string (required, min:3)"
}
```

---

## Projects

### List Projects
```
GET /projects
Response: Inertia 'Projects/Index'
```

### Show Project
```
GET /projects/{slug}
Response: Inertia 'Projects/Show' with tasks, milestones, team, stats
```

### Create Project
```
POST /projects
Controller: ProjectController@store

Request:
{
  "client_id": "number (required, exists:clients)",
  "name": "string (required, max:255)",
  "description": "string (optional)",
  "status": "enum (optional): active|on_hold|completed|archived",
  "type": "enum (optional): retainer|project|support",
  "budget": "number (optional, min:0)",
  "github_repo": "string (optional, max:255)",
  "notion_page_id": "string (optional, max:255)"
}
```

### Update Project
```
PUT /projects/{project}
Controller: ProjectController@update
```

### Delete Project
```
DELETE /projects/{project}
Controller: ProjectController@destroy
```

### Update Project Status
```
POST /projects/{project}/status
Controller: ProjectController@updateStatus

Request: { "status": "active|on_hold|completed|archived" }
```

---

## Clients

### List Clients
```
GET /clients
Response: Inertia 'Clients/Index'
```

### Show Client
```
GET /clients/{slug}
Response: Inertia 'Clients/Show' with contacts, projects, stats, activity
```

### Create Client
```
POST /clients
Controller: ClientController@store

Request:
{
  "name": "string (required, max:255)",
  "description": "string (optional)",
  "health_score": "number (optional, 0-10)",
  "status": "enum (optional): active|inactive|prospect",
  "website": "url (optional, max:255)",
  "slack_channel": "string (optional, max:255)"
}
```

### Update Client
```
PUT /clients/{client}
Controller: ClientController@update
```

### Delete Client
```
DELETE /clients/{client}
Controller: ClientController@destroy
```

### Add Contact
```
POST /clients/{client}/contacts
Controller: ClientController@addContact

Request:
{
  "name": "string (required, max:255)",
  "email": "email (required, max:255)",
  "role": "string (optional, max:255)",
  "phone": "string (optional, max:255)",
  "is_primary": "boolean (optional)"
}
```

### Update Contact
```
PUT /clients/{client}/contacts/{contact}
Controller: ClientController@updateContact
```

### Delete Contact
```
DELETE /clients/{client}/contacts/{contact}
Controller: ClientController@removeContact
```

---

## Tasks

### List Tasks
```
GET /tasks
Response: Inertia 'Tasks/Index' with tasks, stats, team
```

### Create Task
```
POST /tasks
Controller: TaskController@store

Request:
{
  "title": "string (required, max:255)",
  "description": "string (optional)",
  "status": "enum (optional): pending|in_progress|review|completed",
  "priority": "enum (optional): low|medium|high|urgent",
  "project_id": "number (optional, exists:projects)",
  "assigned_to": "number (optional, exists:users)",
  "due_date": "date (optional)",
  "source": "enum (optional): manual|meeting-parser|agent",
  "source_session_id": "string (optional, max:255)",
  "milestone_id": "number (optional, exists:milestones)"
}
```

### Update Task
```
PUT /tasks/{task}
Controller: TaskController@update
```

### Delete Task
```
DELETE /tasks/{task}
Controller: TaskController@destroy
```

### Update Task Status
```
POST /tasks/{task}/status
Controller: TaskController@updateStatus

Request:
{
  "status": "enum (required): pending|in_progress|review|completed",
  "position": "number (optional, min:0)"
}
```

### Assign Task
```
POST /tasks/{task}/assign
Controller: TaskController@assign

Request: { "assigned_to": "number (optional, exists:users)" }
```

### Reorder Tasks
```
POST /tasks/reorder
Controller: TaskController@reorder

Request:
{
  "tasks": [
    {
      "id": "number (required, exists:tasks)",
      "position": "number (required, min:0)",
      "status": "enum (required): pending|in_progress|review|completed"
    }
  ]
}
```

---

## Leads

### List Leads
```
GET /leads
Response: Inertia 'Leads/Index' with leads, stats, team
```

### Create Lead
```
POST /leads
Controller: LeadController@store

Request:
{
  "company_name": "string (required, max:255)",
  "contact_name": "string (required, max:255)",
  "contact_email": "email (required, max:255)",
  "contact_phone": "string (optional, max:255)",
  "website": "url (optional, max:255)",
  "description": "string (optional)",
  "stage": "enum (optional): new|qualified|proposal|negotiation|won|lost",
  "source": "enum (optional): referral|website|linkedin|cold_outreach|conference|other",
  "deal_value": "number (optional, min:0)",
  "probability": "number (optional, 0-100)",
  "expected_close_date": "date (optional)",
  "assigned_to": "number (optional, exists:users)",
  "notes": "string (optional)",
  "tags": "array (optional)",
  "last_contacted_at": "date (optional)"
}
```

### Update Lead
```
PUT /leads/{lead}
Controller: LeadController@update
```

### Delete Lead
```
DELETE /leads/{lead}
Controller: LeadController@destroy
```

### Update Lead Stage
```
POST /leads/{lead}/stage
Controller: LeadController@updateStage

Request:
{
  "stage": "enum (required): new|qualified|proposal|negotiation|won|lost",
  "position": "number (optional, min:0)"
}
```

### Assign Lead
```
POST /leads/{lead}/assign
Controller: LeadController@assign

Request: { "assigned_to": "number (optional, exists:users)" }
```

### Reorder Leads
```
POST /leads/reorder
Controller: LeadController@reorder
```

---

## Team

### List Team
```
GET /team
Response: Inertia 'Team/Index'
```

### Invite Team Member
```
POST /team OR POST /team/invite
Controller: TeamController@store

Request:
{
  "name": "string (required, max:255)",
  "email": "email (required, unique:users)",
  "role": "enum (required): owner|admin|staff",
  "title": "string (optional, max:255)",
  "department": "string (optional, max:255)"
}
```

### Update Team Member
```
PUT /team/{user}
Controller: TeamController@update

Request:
{
  "name": "string (required, max:255)",
  "email": "email (required)",
  "phone": "string (optional, max:255)",
  "role": "enum (required): owner|admin|staff",
  "title": "string (optional, max:255)",
  "department": "string (optional, max:255)",
  "permissions": {
    "manage_clients": "boolean (optional)",
    "manage_projects": "boolean (optional)",
    "manage_team": "boolean (optional)",
    "manage_billing": "boolean (optional)",
    "view_vault": "boolean (optional)",
    "approve_work": "boolean (optional)"
  }
}
```

### Delete Team Member
```
DELETE /team/{user}
Controller: TeamController@destroy
Note: Cannot delete yourself, unassigns tasks before deletion
```

---

## Vault

### List Secrets
```
GET /vault
Response: Inertia 'Vault/Index'
```

### Create Secret
```
POST /vault
Controller: VaultController@store

Request:
{
  "name": "string (required, max:255)",
  "key": "string (required, max:255, unique)",
  "value": "string (required, encrypted)",
  "type": "enum (required): api_key|oauth_token|password|certificate|other",
  "service": "string (required, max:255)",
  "description": "string (optional)",
  "expires_at": "date (optional)",
  "auto_rotate": "boolean (optional)",
  "rotate_interval_days": "number (optional, 1-365)"
}
```

### Update Secret
```
PUT /vault/{vaultSecret}
Controller: VaultController@update
Note: Does not update value, use rotate
```

### Delete Secret
```
DELETE /vault/{vaultSecret}
Controller: VaultController@destroy
```

### Rotate Secret
```
POST /vault/{vaultSecret}/rotate
Controller: VaultController@rotate

Request:
{
  "new_value": "string (optional)",
  "auto_generate": "boolean (optional)",
  "notify_agents": "boolean (optional, TODO)"
}
```

---

## Command Palette

### AI Chat (SSE)
```
POST /api/command-palette/chat
Controller: CommandPaletteController@chat

Request:
{
  "message": "string (required, max:2000)",
  "context": "array (optional)",
  "use_tools": "boolean (optional, default: true)",
  "page_context": "object (optional)"
}

Response: Server-Sent Events
Events:
- content: {"text": "string"}
- tools_used: {"iterations": number}
- done: {"usage": {...}}
- error: {"message": "string"}
```

### Search
```
GET /api/command-palette/search
Controller: CommandPaletteController@search

Query:
- query: string (required, min:2, max:100)
- types: array (optional, default: projects,tasks,clients)

Response:
{
  "projects": [...],
  "tasks": [...],
  "clients": [...]
}
```

### Stats
```
GET /api/command-palette/stats
Controller: CommandPaletteController@stats

Response:
{
  "projects": {"total": number, "active": number},
  "tasks": {"total": number, "pending": number, "in_progress": number},
  "clients": {"total": number, "active": number},
  "approvals": {"pending": number},
  "agents": {"total": number, "active": number}
}
```

### Recent Commands
```
GET /api/command-palette/recent
Controller: CommandPaletteController@recentCommands

Response:
{
  "recent": [...],
  "frequent": [...]
}
```

### Log Command
```
POST /api/command-palette/log
Controller: CommandPaletteController@logCommand

Request:
{
  "command_id": "string (required, max:100)",
  "command_type": "enum (required): navigation|action|agent|ai",
  "query": "string (optional, max:500)",
  "metadata": "object (optional)"
}
```

### List Tools
```
GET /api/command-palette/tools
Controller: CommandPaletteController@tools
```

---

## Integrations

### Settings Page
```
GET /settings/integrations
Controller: SettingsController@integrations
Response: Inertia 'Settings/Integrations'
```

### All Status
```
GET /api/settings/integrations/status
Controller: SettingsController@allIntegrationStatus

Response:
{
  "google": {"connected": boolean},
  "slack": {"connected": boolean},
  "github": {"connected": boolean},
  "harvest": {"connected": boolean},
  "notion": {"connected": boolean},
  "wordpress": {"connected": boolean},
  "quickbooks": {"connected": boolean}
}
```

### Google
```
GET /auth/google - OAuth redirect
GET /auth/google/callback - OAuth callback
GET /api/integrations/google/status
POST /api/integrations/google/disconnect
POST /api/integrations/google/sync/emails
POST /api/integrations/google/sync/calendar
POST /api/integrations/google/discover/documents
GET /api/integrations/google/drive/search
GET /api/integrations/google/meetings/upcoming
POST /api/integrations/google/watches/renew
```

### Slack
```
GET /auth/slack - OAuth redirect
GET /auth/slack/callback - OAuth callback
GET /api/integrations/slack/status
DELETE /api/integrations/slack/workspaces/{workspace}
POST /api/integrations/slack/workspaces/{workspace}/sync-channels
GET /api/integrations/slack/workspaces/{workspace}/channels
PUT /api/integrations/slack/channels/{channel}
POST /api/integrations/slack/channels/{channel}/sync
GET /api/integrations/slack/channels/{channel}/messages
```

### GitHub
```
GET /auth/github/callback
GET /api/integrations/github/status
GET /api/integrations/github/install-url
POST /api/integrations/github/sync
GET /api/integrations/github/installations/{installation}/repos
PUT /api/integrations/github/repos/{repo}
POST /api/integrations/github/repos/{repo}/sync-issues
POST /api/integrations/github/repos/{repo}/sync-prs
GET /api/integrations/github/pull-requests
POST /api/integrations/github/pull-requests/{pr}/approve
POST /api/integrations/github/pull-requests/{pr}/reject
```

### Harvest
```
GET /auth/harvest - OAuth redirect
GET /auth/harvest/callback - OAuth callback
GET /api/integrations/harvest/status
POST /api/integrations/harvest/disconnect
POST /api/integrations/harvest/sync/all
POST /api/integrations/harvest/sync/projects
POST /api/integrations/harvest/sync/task-categories
POST /api/integrations/harvest/sync/time-entries
POST /api/integrations/harvest/sync/invoices
GET /api/integrations/harvest/timers/running
POST /api/integrations/harvest/timers/start
POST /api/integrations/harvest/timers/{id}/stop
POST /api/integrations/harvest/timers/{id}/restart
GET /api/integrations/harvest/projects
POST /api/integrations/harvest/projects/link
GET /api/integrations/harvest/reports/profitability
GET /api/integrations/harvest/reports/project-time
GET /api/integrations/harvest/reports/team-time
```

### Notion
```
GET /auth/notion - OAuth redirect
GET /auth/notion/callback - OAuth callback
DELETE /api/integrations/notion/connections/{connection}
POST /api/integrations/notion/connections/{connection}/sync
GET /api/integrations/notion/connections/{connection}/pages
POST /api/integrations/notion/databases/{database}/sync
```

### WordPress
```
POST /api/integrations/wordpress/sites
DELETE /api/integrations/wordpress/sites/{site}
POST /api/integrations/wordpress/sites/{site}/sync
POST /api/integrations/wordpress/sites/{site}/discover-capabilities
GET /api/integrations/wordpress/sites/{site}/posts
GET /api/integrations/wordpress/sites/{site}/suggestions
POST /api/integrations/wordpress/suggestions/{suggestion}/approve
POST /api/integrations/wordpress/suggestions/{suggestion}/reject
POST /api/integrations/wordpress/suggestions/{suggestion}/publish
```

### QuickBooks
```
GET /auth/quickbooks - OAuth redirect
GET /auth/quickbooks/callback - OAuth callback
DELETE /api/integrations/quickbooks/connections/{connection}
POST /api/integrations/quickbooks/connections/{connection}/sync
POST /api/integrations/quickbooks/connections/{connection}/snapshot
GET /api/integrations/quickbooks/connections/{connection}/reports
GET /api/integrations/quickbooks/connections/{connection}/cash-flow
POST /api/integrations/quickbooks/connections/{connection}/invoices
POST /api/integrations/quickbooks/connections/{connection}/payments
POST /api/integrations/quickbooks/connections/{connection}/expenses
```

### LinkedIn
```
GET /auth/linkedin - OAuth redirect
GET /auth/linkedin/callback - OAuth callback
GET /api/integrations/linkedin/status
POST /api/integrations/linkedin/disconnect
POST /api/integrations/linkedin/post
GET /api/integrations/linkedin/organizations
POST /api/integrations/linkedin/organizations/set
```

### X (Twitter)
```
GET /auth/x - OAuth redirect
GET /auth/x/callback - OAuth callback
GET /api/integrations/x/status
POST /api/integrations/x/disconnect
POST /api/integrations/x/tweet
POST /api/integrations/x/thread
GET /api/integrations/x/timeline
POST /api/integrations/x/refresh-stats
```

---

## Webhooks

No auth middleware - uses token validation

### Trigger Agent
```
POST /webhooks/agents/{agent:slug}
Controller: WebhookController@trigger

Headers:
- X-Webhook-Token: string (required)
- X-Webhook-Source: string (optional)

Request:
{
  "prompt": "string (optional, max:10000)",
  "context": "object (optional)",
  "metadata": "object (optional)"
}

Response (202):
{
  "success": boolean,
  "run_id": number,
  "status": "string",
  "requires_approval": boolean,
  "status_url": "string"
}
```

### Get Run Status
```
GET /webhooks/status/{runId}
Controller: WebhookController@status

Response:
{
  "run_id": number,
  "agent": "string",
  "status": "string",
  "started_at": "datetime",
  "completed_at": "datetime",
  "duration_ms": number,
  "output": {...} | null,
  "error": "string" | null
}
```

### Integration Webhooks
```
POST /webhooks/google/gmail - GoogleWebhookController@gmail
POST /webhooks/google/calendar - GoogleWebhookController@calendar
POST /webhooks/slack/events - SlackWebhookController@events
POST /webhooks/slack/slash - SlackWebhookController@slashCommand
POST /webhooks/github - GitHubWebhookController@handle
POST /webhooks/harvest - HarvestWebhookController@handle
POST /webhooks/quickbooks - QuickBooksWebhookController@handle
POST /webhooks/wordpress - WordPressWebhookController@handle
POST /webhooks/meetings/google-calendar - MeetingParserWebhookController@googleCalendar
POST /webhooks/meetings/transcription - MeetingParserWebhookController@transcription
POST /api/meetings/upload - MeetingParserWebhookController@upload (auth)
```

---

## Notifications

### List
```
GET /api/notifications
Controller: NotificationController@index

Response:
{
  "notifications": [...last 50...],
  "unread_count": number
}
```

### Unread Count
```
GET /api/notifications/unread-count
Controller: NotificationController@unreadCount

Response: {"count": number}
```

### Mark as Read
```
POST /api/notifications/{notification}/read
Controller: NotificationController@markAsRead
```

### Mark All Read
```
POST /api/notifications/mark-all-read
Controller: NotificationController@markAllAsRead
```

### Dismiss
```
POST /api/notifications/{notification}/dismiss
Controller: NotificationController@dismiss
```

### Dismiss All
```
POST /api/notifications/dismiss-all
Controller: NotificationController@dismissAll
```

---

## Health Alerts

```
GET /health-alerts - List
GET /health-alerts/{healthAlert} - Show
GET /health-alerts/settings - Escalation settings
POST /health-alerts/{healthAlert}/acknowledge
POST /health-alerts/{healthAlert}/resolve
POST /health-alerts/escalation-targets
GET /api/health-alerts/summary
GET /api/clients/{client}/health-alerts
```

---

## Insights & Capabilities

### Insights
```
GET /api/insights
Controller: InsightsController@index

Query: limit (default: 6)

Response:
{
  "insights": [...],
  "count": number
}
```

### Capabilities
```
GET /api/capabilities/human-required
GET /api/capabilities/briefing
GET /api/capabilities/summary
GET /api/capabilities/gaps
GET /api/capabilities/focus
Controller: CapabilitySynthesisController
```

---

## Strategic Goals & Weekly Plans

### Goals
```
GET /goals - List
GET /goals/create - Create form
POST /goals - Store
GET /goals/{goal} - Show
PUT /goals/{goal} - Update
DELETE /goals/{goal} - Delete
GET /api/goals/{goal}/progress
GET /api/goals/{goal}/levers
GET /api/goals/{goal}/forecast
GET /api/funnel-metrics
```

### Weekly Plans
```
GET /weekly-plans - List
GET /weekly-plans/{weeklyPlan} - Show
POST /weekly-plans/{weeklyPlan}/approve
POST /weekly-plans/{weeklyPlan}/activate
PATCH /weekly-plans/items/{item}
```

---

## Prospects & Campaigns

### Prospects
```
GET /prospects - List
GET /prospects/{prospect} - Show
POST /prospects - Create
PUT /prospects/{prospect} - Update
DELETE /prospects/{prospect} - Delete
POST /prospects/{prospect}/convert - Convert to lead
```

### Campaigns
```
GET /campaigns - List
GET /campaigns/{campaign} - Show
POST /campaigns - Create
PUT /campaigns/{campaign} - Update
POST /campaigns/{campaign}/activate
POST /campaigns/{campaign}/pause
```

### Sequences
```
POST /campaigns/{campaign}/sequences - Create
PUT /campaigns/{campaign}/sequences/{sequence} - Update
DELETE /campaigns/{campaign}/sequences/{sequence} - Delete
POST /campaigns/{campaign}/sequences/{sequence}/reorder
```

---

## Prompt Library

```
GET /prompts - List
GET /prompts/{promptTemplate} - Show
POST /prompts - Create
PUT /prompts/{promptTemplate} - Update
DELETE /prompts/{promptTemplate} - Delete
POST /prompts/{promptTemplate}/versions - Create version
POST /prompts/{promptTemplate}/versions/{version}/activate
GET /prompts/{promptTemplate}/versions/{version}
POST /prompts/{promptTemplate}/compare - Compare versions
POST /prompts/{promptTemplate}/ab-test - Set A/B weights
POST /prompts/{promptTemplate}/preview
POST /prompts/{promptTemplate}/duplicate
```

---

## Agent Analytics

```
GET /agents/analytics - Dashboard
GET /agents/{agent}/analytics - Agent analytics
GET /api/agents/analytics - Data
GET /api/agents/{agent}/analytics - Agent data
GET /api/agents/analytics/export - Export
```

---

## Cost Tracking

```
GET /costs - Dashboard
GET /api/costs/summary - Summary API
```

---

## Agent Templates

```
GET /agents/templates - UI list
GET /api/agent-templates - List
GET /api/agent-templates/categories
GET /api/agent-templates/featured
GET /api/agent-templates/search
GET /api/agent-templates/{template} - Show
POST /api/agent-templates/{template}/preview
POST /api/agent-templates/{template}/create-agent
POST /api/agent-templates/{template}/duplicate
POST /api/agent-templates - Create
POST /api/agent-templates/from-agent/{agent}
PUT /api/agent-templates/{template} - Update
DELETE /api/agent-templates/{template} - Delete
```

---

## Client Portal

**Middleware:** `auth`, `ClientPortalMiddleware`

```
GET /portal - Dashboard
GET /portal/projects - Projects
GET /portal/projects/{project} - Project detail
GET /portal/invoices - Invoices
```

---

## Scheduled Tasks

From `/routes/console.php`:

### Agents
```
Schedule: Every minute
Command: agents:run-scheduled
Options: withoutOverlapping, onOneServer
Purpose: Execute agents with cron schedules
```

### Approvals
```
Schedule: Every 5 minutes
Callback: Expire pending approvals past expiration time
```

### Agent Health
```
Schedule: Hourly
Callback: Reset circuit breakers for agents broken >1 hour
```

### Health Escalation
```
Schedule: Every 15 minutes
Command: health:process-escalations
Options: withoutOverlapping
```

### Goal Progress
```
Schedule: Hourly
Job: UpdateGoalProgressJob
Options: withoutOverlapping
```

### Funnel Metrics
```
Schedule: Daily 23:55
Job: CalculateFunnelMetricsJob
Options: withoutOverlapping
```

### Agent Tasks
```
Schedule: Every 5 minutes
Job: ProcessAgentTasksJob
Options: withoutOverlapping
```

### Integrations Sync
```
GSuite: Every 4 hours (SyncGSuiteJob)
Slack: Every 30 minutes (SyncSlackJob) - backup
GitHub: Every 4 hours (SyncGitHubJob)
Harvest: Every 2 hours (SyncHarvestJob)
Notion: Every 6 hours (SyncNotionJob)
WordPress: Every 4 hours (SyncWordPressJob)
QuickBooks: Daily 06:00 (SyncQuickBooksJob)
Google Drive: Daily 05:00 (SyncGoogleDriveJob)
```

---

## Console Commands

### Run Scheduled Agents
```
Command: php artisan agents:run-scheduled
Options:
  --dry-run     Show what would run without executing
  --status      Show status of all scheduled agents

Implementation: App\Console\Commands\RunScheduledAgentsCommand
```

### Inspire
```
Command: php artisan inspire
Purpose: Display inspiring quote
```

---

## Broadcasting Channels

From `/routes/channels.php`:

### User Channel
```
Channel: App.Models.User.{id}
Auth: User ID match
Purpose: User-specific events
```

### Agent Channel
```
Channel: agents.{agentId}
Auth: Agent exists
Purpose: Agent run updates
```

### Agent Run Channel
```
Channel: agent-runs.{runId}
Auth: Run exists
Purpose: Detailed run updates
```

### Notifications Channel
```
Channel: notifications.{userId}
Auth: User ID match
Purpose: User notifications
```

---

## Response Formats

### Inertia
Vue components with props via Inertia middleware

### JSON Success
```json
{
  "message": "Success",
  "data": {...}
}
```

### JSON Error
```json
{
  "error": "Error message",
  "details": {...}
}
```

### Validation Error (422)
```json
{
  "message": "Validation failed",
  "errors": {
    "field": ["Error message"]
  }
}
```

---

## Security Notes

### Webhook Auth
- Token: SHA-256 in `X-Webhook-Token` header
- IP allowlist: CIDR/exact IP filtering
- Circuit breaker: Auto-disable failing agents

### Agent Invocation Sources
- `manual` - User via UI
- `api` - REST API
- `webhook` - External webhook
- `scheduled` - Cron scheduler
- `insight` - Proactive insight

### Integration Sync
- **Real-time**: Google push, Slack events, GitHub webhooks
- **Frequent**: Harvest 2h, Slack backup 30m
- **Regular**: GitHub 4h, Drive 4h, WordPress 4h, GSuite 4h, Notion 6h
- **Daily**: QuickBooks 06:00, Drive 05:00

All sync jobs: `withoutOverlapping()`

---

## AI Tools

See `/app/Agents/Tools/` for implementations. Auto-discovered via ToolRegistry.

Common tools:
- search-tasks, search-projects, search-clients
- create-task, create-project, create-client (require approval)
- update-task (requires approval)
- get-stats, get-approvals
- trigger-agent
- navigate
- get-focus

Add new tools by extending `BaseTool` in `/app/Agents/Tools/`.
