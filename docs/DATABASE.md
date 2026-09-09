# Database Schema Documentation

Complete database schema for Zao Dashboard - an AI-powered agency management system with autonomous agents.

---

## Table of Contents

1. [Entity Relationship Diagram](#entity-relationship-diagram)
2. [Domain Groupings](#domain-groupings)
3. [Core Models](#core-models)
4. [Agent System](#agent-system)
5. [Client Management](#client-management)
6. [Project & Task Management](#project--task-management)
7. [Integrations](#integrations)
8. [Strategic Planning & Goals](#strategic-planning--goals)
9. [Lead Generation & Outreach](#lead-generation--outreach)
10. [Financial & Time Tracking](#financial--time-tracking)
11. [Content & Communication](#content--communication)
12. [System & Infrastructure](#system--infrastructure)

---

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           CORE USER & CLIENT                                 │
└─────────────────────────────────────────────────────────────────────────────┘

users ──┬─── (has many) tasks
        ├─── (has one) google_credential
        ├─── (has one) harvest_credential
        ├─── (has one) linkedin_credential
        ├─── (has one) x_credential
        ├─── (has many) time_entries
        └─── (belongs to) client [for client users]

clients ──┬─── (has many) client_contacts
          ├─── (has many) projects
          ├─── (has many) harvest_projects
          ├─── (has many) retainer_periods
          ├─── (has many) client_reports
          ├─── (has many) harvest_invoices
          ├─── (has many) health_alerts
          ├─── (has many) slack_channels
          ├─── (has many) emails
          ├─── (has many) documents
          ├─── (has many) github_repos
          ├─── (has many) qbo_customers
          └─── (has many) wordpress_sites

┌─────────────────────────────────────────────────────────────────────────────┐
│                            AGENT ECOSYSTEM                                   │
└─────────────────────────────────────────────────────────────────────────────┘

agents ──┬─── (has many) agent_runs
         ├─── (has many) agent_activity_logs
         ├─── (has many) agent_tasks
         └─── (has many) prompt_templates

agent_runs ──┬─── (has one) approval_request
             ├─── (belongs to) agent_chain_run
             └─── (belongs to) prompt_version

agent_chains ──┬─── (has many) agent_chain_runs
               └─── (steps → agents via slug)

agent_templates ──┬─── (creates) agents
                  └─── (belongs to) user [creator]

┌─────────────────────────────────────────────────────────────────────────────┐
│                        PROJECT & TASK MANAGEMENT                             │
└─────────────────────────────────────────────────────────────────────────────┘

projects ──┬─── (belongs to) client
           ├─── (has many) milestones
           ├─── (has many) tasks
           └─── (has many) github_repos

tasks ──┬─── (belongs to) project
        ├─── (belongs to) milestone
        ├─── (belongs to) user [assignee]
        └─── (has many) time_entries

milestones ──┬─── (belongs to) project
             └─── (has many) tasks

leads ──┬─── (belongs to) user [assignee]
        ├─── (belongs to) client [converted]
        └─── (has many) outreach_messages

┌─────────────────────────────────────────────────────────────────────────────┐
│                      STRATEGIC PLANNING & GOALS                              │
└─────────────────────────────────────────────────────────────────────────────┘

strategic_goals ──┬─── (has many) goal_periods [yearly/quarterly/monthly/weekly]
                  ├─── (has many) weekly_plans
                  └─── (belongs to) user

goal_periods ──┬─── (belongs to) strategic_goal
               └─── (tracks actuals vs targets)

weekly_plans ──┬─── (belongs to) strategic_goal
               ├─── (has many) weekly_plan_items
               └─── (approved by) user

weekly_plan_items ──┬─── (belongs to) weekly_plan
                    ├─── (belongs to) agent_run [if executed]
                    └─── (creates) agent_task

business_goals ──┬─── (belongs to) user
                 └─── (simple KPI tracking)

funnel_metrics ──┬─── (period snapshots)
                 └─── (conversion tracking)

┌─────────────────────────────────────────────────────────────────────────────┐
│                    LEAD GENERATION & OUTREACH                                │
└─────────────────────────────────────────────────────────────────────────────┘

ideal_customer_profiles ──┬─── (has many) prospects
                          └─── (has many) outreach_campaigns

prospects ──┬─── (belongs to) ideal_customer_profile
            ├─── (has many) outreach_messages
            ├─── (belongs to) lead [converted]
            └─── (discovered by) agent_run

outreach_campaigns ──┬─── (belongs to) ideal_customer_profile
                     ├─── (has many) outreach_sequences
                     └─── (has many through) outreach_messages

outreach_sequences ──┬─── (belongs to) outreach_campaign
                     └─── (has many) outreach_messages

outreach_messages ──┬─── (belongs to) outreach_sequence
                    ├─── (belongs to) prospect
                    ├─── (belongs to) lead
                    ├─── (created by) agent_run
                    └─── (approved by) user

┌─────────────────────────────────────────────────────────────────────────────┐
│                        INTEGRATION SYSTEMS                                   │
└─────────────────────────────────────────────────────────────────────────────┘

# GOOGLE
google_credentials ──┬─── (belongs to) user
                     ├─── (stores tokens)
                     └─── (watch channels)

emails ──┬─── (belongs to) client
         └─── (sentiment analysis)

calendar_events ──┬─── (belongs to) client
                  ├─── (belongs to) project
                  └─── (meeting intelligence)

documents ──┬─── (belongs to) client
            └─── (belongs to) project

# SLACK
slack_workspaces ──┬─── (has many) slack_channels
                   └─── (has many) slack_messages

slack_channels ──┬─── (belongs to) workspace
                 ├─── (belongs to) client
                 ├─── (has many) slack_messages
                 └─── (has many) slack_threads

slack_messages ──┬─── (belongs to) slack_channel
                 ├─── (belongs to) client
                 └─── (action item detection)

slack_threads ──┬─── (belongs to) slack_channel
                └─── (has many) slack_messages

slack_request_patterns ──┬─── (belongs to) client
                         └─── (repeated request tracking)

# GITHUB
github_installations ──┬─── (has many) github_repos
                       └─── (OAuth tokens)

github_repos ──┬─── (belongs to) github_installation
               ├─── (belongs to) client
               ├─── (belongs to) project
               ├─── (has many) github_issues
               ├─── (has many) github_pull_requests
               └─── (has one) deployment_config

github_issues ──┬─── (belongs to) github_repo
                ├─── (belongs to) task
                └─── (belongs to) agent_run

github_pull_requests ──┬─── (belongs to) github_repo
                       ├─── (belongs to) approval_request
                       └─── (belongs to) agent_run [QA agent]

deployment_configs ──┬─── (belongs to) github_repo
                     ├─── (belongs to) client
                     └─── (belongs to) agent_run [onboarding]

# HARVEST (Time Tracking)
harvest_credentials ──┬─── (belongs to) user
                      └─── (OAuth tokens)

harvest_projects ──┬─── (belongs to) client
                   ├─── (belongs to) project
                   └─── (has many) time_entries

harvest_task_categories ──┬─── (has many) time_entries
                          └─── (task types)

harvest_invoices ──┬─── (belongs to) client
                   └─── (billing data)

time_entries ──┬─── (belongs to) user
               ├─── (belongs to) client
               ├─── (belongs to) project
               └─── (belongs to) task

# NOTION
notion_connections ──┬─── (belongs to) user
                     └─── (has many) notion_pages

notion_pages ──┬─── (belongs to) notion_connection
               ├─── (belongs to) client
               ├─── (has one) notion_page_content
               ├─── (has many) notion_database_items [if database]
               └─── (parent/child hierarchy)

notion_database_items ──┬─── (belongs to) notion_page [database]
                        └─── (task/item tracking)

# WORDPRESS
wordpress_sites ──┬─── (belongs to) client
                  ├─── (has many) wordpress_posts
                  └─── (has many) content_suggestions

wordpress_posts ──┬─── (belongs to) wordpress_site
                  └─── (synced content)

content_suggestions ──┬─── (belongs to) wordpress_site
                      └─── (AI-generated ideas)

# QUICKBOOKS
quickbooks_connections ──┬─── (belongs to) user
                         ├─── (has many) qbo_accounts
                         ├─── (has many) qbo_transactions
                         ├─── (has many) qbo_invoices
                         ├─── (has many) qbo_customers
                         └─── (has many) financial_snapshots

qbo_accounts ──┬─── (belongs to) qbo_connection
               └─── (has many) qbo_transactions

qbo_customers ──┬─── (belongs to) qbo_connection
                └─── (belongs to) client [matched]

qbo_invoices ──┬─── (belongs to) qbo_connection
               ├─── (belongs to) client
               └─── (belongs to) harvest_invoice

qbo_transactions ──┬─── (belongs to) qbo_connection
                   ├─── (belongs to) qbo_account
                   ├─── (belongs to) client
                   └─── (belongs to) project

financial_snapshots ──┬─── (belongs to) qbo_connection
                      └─── (period summaries)

┌─────────────────────────────────────────────────────────────────────────────┐
│                     VAULT & SECURITY                                         │
└─────────────────────────────────────────────────────────────────────────────┘

vault_secrets ──┬─── (belongs to) project
                ├─── (belongs to) client
                ├─── (created by) user
                ├─── (updated by) user
                └─── (has many) vault_access_logs

vault_access_logs ──┬─── (belongs to) vault_secret
                    └─── (audit trail)

┌─────────────────────────────────────────────────────────────────────────────┐
│                   HEALTH & MONITORING                                        │
└─────────────────────────────────────────────────────────────────────────────┘

health_alerts ──┬─── (belongs to) client
                ├─── (acknowledged by) user
                ├─── (resolved by) user
                └─── (escalation tracking)

escalation_targets ──┬─── (belongs to) user
                     └─── (escalation routing)

┌─────────────────────────────────────────────────────────────────────────────┐
│                      SYSTEM TABLES                                           │
└─────────────────────────────────────────────────────────────────────────────┘

notifications ──┬─── (belongs to) user [or global]
                └─── (real-time alerts)

command_history ──┬─── (belongs to) user
                  └─── (command palette usage)

audit_logs ──┬─── (system audit)
             └─── (compliance)

approval_requests ──┬─── (belongs to) agent_run
                    ├─── (decided by) user
                    └─── (risk assessment)

profitability_snapshots ──┬─── (belongs to) client
                          ├─── (belongs to) project
                          ├─── (belongs to) user
                          └─── (margin tracking)

client_reports ──┬─── (belongs to) client
                 └─── (scheduled reporting)

project_budgets ──┬─── (belongs to) project
                  └─── (budget vs actual)

retainer_periods ──┬─── (belongs to) client
                   └─── (hour bank tracking)

prompt_templates ──┬─── (belongs to) agent
                   ├─── (created by) user
                   └─── (has many) prompt_versions

prompt_versions ──┬─── (belongs to) prompt_template
                  ├─── (created by) user
                  └─── (A/B testing)

sessions, password_reset_tokens, cache, jobs, job_batches, failed_jobs
```

---

## Domain Groupings

### 1. Core Authentication & Users
- **users** - System users (owner, staff, client portal users)
- **sessions** - User sessions
- **password_reset_tokens** - Password reset mechanism

### 2. Agent System
- **agents** - AI agent definitions
- **agent_runs** - Execution history
- **agent_activity_logs** - Audit trail
- **agent_templates** - Reusable templates
- **agent_chains** - Multi-step workflows
- **agent_chain_runs** - Chain execution tracking
- **agent_tasks** - Agent task queue
- **prompt_templates** - Versioned prompts
- **prompt_versions** - A/B testing support

### 3. Client & Project Management
- **clients** - Client companies
- **client_contacts** - Contact persons
- **projects** - Client projects
- **milestones** - Project milestones
- **tasks** - Work items
- **leads** - Sales pipeline
- **project_budgets** - Budget tracking
- **retainer_periods** - Retainer hour tracking
- **client_reports** - Auto-generated reports

### 4. Strategic Planning
- **strategic_goals** - Annual revenue goals
- **goal_periods** - Quarterly/monthly/weekly targets
- **funnel_metrics** - Sales funnel snapshots
- **business_goals** - Simple KPI tracking
- **weekly_plans** - Weekly execution plans
- **weekly_plan_items** - Plan action items

### 5. Lead Generation & Outreach
- **ideal_customer_profiles** - ICP definitions
- **prospects** - Pre-lead prospects
- **outreach_campaigns** - Multi-channel campaigns
- **outreach_sequences** - Message sequences
- **outreach_messages** - Individual outreach

### 6. Integrations - Google
- **google_credentials** - OAuth tokens
- **emails** - Email monitoring
- **calendar_events** - Meeting intelligence
- **documents** - Google Drive docs

### 7. Integrations - Slack
- **slack_workspaces** - Workspace connections
- **slack_channels** - Channel monitoring
- **slack_messages** - Message history
- **slack_threads** - Thread tracking
- **slack_request_patterns** - Repeated requests

### 8. Integrations - GitHub
- **github_installations** - GitHub App installations
- **github_repos** - Repository connections
- **github_issues** - Issue tracking
- **github_pull_requests** - PR monitoring
- **deployment_configs** - Deployment automation

### 9. Integrations - Harvest (Time Tracking)
- **harvest_credentials** - OAuth tokens
- **harvest_projects** - Project mappings
- **harvest_task_categories** - Task types
- **harvest_invoices** - Invoice sync
- **time_entries** - Time tracking

### 10. Integrations - Notion
- **notion_connections** - Workspace connections
- **notion_pages** - Page/database sync
- **notion_page_content** - Content blocks
- **notion_database_items** - Database rows

### 11. Integrations - WordPress
- **wordpress_sites** - Site connections
- **wordpress_posts** - Post sync
- **content_suggestions** - AI content ideas

### 12. Integrations - QuickBooks
- **quickbooks_connections** - OAuth tokens
- **qbo_accounts** - Chart of accounts
- **qbo_customers** - Customer sync
- **qbo_invoices** - Invoice tracking
- **qbo_transactions** - Transaction history
- **financial_snapshots** - Financial summaries

### 13. Integrations - Social Media
- **linkedin_credentials** - LinkedIn OAuth
- **x_credentials** - X/Twitter OAuth

### 14. Security & Vault
- **vault_secrets** - Encrypted secrets
- **vault_access_logs** - Secret access audit

### 15. Health & Monitoring
- **health_alerts** - Client health alerts
- **escalation_targets** - Alert routing
- **profitability_snapshots** - Margin tracking

### 16. System Infrastructure
- **notifications** - Real-time notifications
- **approval_requests** - Human-in-loop approvals
- **command_history** - Command palette usage
- **audit_logs** - System audit trail
- **cache** - Cache storage
- **jobs** - Queue jobs
- **job_batches** - Batch jobs
- **failed_jobs** - Failed job tracking

---

## Core Models

### User
**Table:** `users`

**Relationships:**
- hasMany: tasks (as assignee)
- hasOne: googleCredential
- hasOne: harvestCredential
- hasOne: linkedInCredential
- hasOne: xCredential
- hasMany: timeEntries
- belongsTo: client (for client portal users)

**Casts:**
- `email_verified_at` → datetime
- `password` → hashed
- `permissions` → array

**Key Fields:**
- `role` → enum: owner, admin, staff, client
- `client_id` → FK for client portal users

**Scopes/Methods:**
- `isClientUser()` → role === 'client'
- `isInternalUser()` → role in [owner, admin, staff]

**Indexes:**
- email (unique)

---

### Client
**Table:** `clients`

**Relationships:**
- hasMany: contacts (ClientContact)
- hasMany: projects
- hasMany: harvestProjects
- hasMany: retainerPeriods
- hasMany: clientReports
- hasMany: invoices (HarvestInvoice)
- hasMany: healthAlerts
- hasMany: unresolvedHealthAlerts (scoped)

**Casts:**
- `health_score` → decimal(3,1)

**Soft Deletes:** Yes

**Key Fields:**
- `slug` → unique identifier
- `status` → enum: active, inactive, prospect
- `health_score` → 0-10 scale

**Methods:**
- `activeRetainer()` → current RetainerPeriod

**Indexes:**
- slug (unique)

---

## Agent System

### Agent
**Table:** `agents`

**Relationships:**
- hasMany: runs (AgentRun)
- hasMany: activityLogs (AgentActivityLog)
- hasMany: tasks (AgentTask)

**Casts:**
- `requires_approval` → boolean
- `use_consortium` → boolean
- `consortium_config` → array
- `is_dynamic` → boolean
- `max_budget_usd` → decimal(8,2)
- `allowed_tools` → array
- `trigger_config` → array
- `circuit_broken_at` → datetime
- `definition_synced_at` → datetime
- `webhook_enabled` → boolean
- `webhook_allowed_ips` → array

**Key Fields:**
- `slug` → unique identifier
- `status` → enum: active, paused, disabled, circuit_broken
- `model` → enum: opus, sonnet, haiku, opus-4-5
- `execution_mode` → enum: tool_use, sequential, parallel

**Events:**
- created → logs creation
- updating → captures original values
- updated → logs changes & status transitions

**Methods:**
- `getDefinition()` → PHP definition (if registered)
- `isRegistered()` → !is_dynamic
- `isOutOfSync()` → definition changed
- `getExecutionConfig()` → merged config from definition + DB

**Indexes:**
- slug (unique)
- status

---

### AgentRun
**Table:** `agent_runs`

**Relationships:**
- belongsTo: agent
- hasOne: approvalRequest
- belongsTo: chainRun (AgentChainRun)

**Casts:**
- `context` → array
- `output` → array
- `trigger_metadata` → array
- `cost_usd` → decimal(4)
- `started_at` → datetime
- `completed_at` → datetime

**Constants:**
- SOURCE_MANUAL, SOURCE_WEBHOOK, SOURCE_SCHEDULED, SOURCE_COMMAND_PALETTE, SOURCE_API, SOURCE_CHAINED

**Scopes:**
- `fromSource($source)` → filter by invocation source

**Methods:**
- `isPartOfChain()` → chain_run_id !== null
- `isAutomated()` → webhook/scheduled/chained

**Indexes:**
- agent_id
- status
- invocation_source

---

### AgentActivityLog
**Table:** `agent_activity_logs`

**Relationships:**
- belongsTo: agent
- belongsTo: user

**Casts:**
- `changes` → array (before/after diff)
- `metadata` → array

**Constants:**
- ACTION_CREATED, ACTION_UPDATED, ACTION_DELETED, ACTION_STATUS_CHANGED, ACTION_TRIGGERED, ACTION_CLONED, ACTION_CLONED_FROM

**Static Methods:**
- `log(agent, action, changes, metadata)` → create entry
- `logCreated(agent)` → log agent creation
- `logUpdated(agent, original, changed)` → log updates with diff
- `logStatusChanged(agent, from, to)` → log status transitions
- `logTriggered(agent, source, runId)` → log manual/scheduled execution
- `logCloned(original, clone)` → log cloning on both agents

**Indexes:**
- agent_id
- action
- created_at

---

### AgentTemplate
**Table:** `agent_templates`

**Relationships:**
- belongsTo: createdBy (User)

**Casts:**
- `default_tools` → array
- `config_schema` → array
- `default_requires_approval` → boolean
- `is_public` → boolean
- `default_budget_usd` → decimal(8,2)

**Key Fields:**
- `system_prompt_template` → template with {{placeholders}}
- `config_schema` → JSON schema for validation
- `usage_count` → tracks template usage

**Scopes:**
- `public()` → is_public = true
- `category($cat)` → filter by category

**Methods:**
- `createAgent(config)` → instantiate agent from template
- `validateConfig(config)` → validate against schema

**Indexes:**
- category
- is_public

---

### AgentChain
**Table:** `agent_chains`

**Relationships:**
- hasMany: runs (AgentChainRun)

**Casts:**
- `is_active` → boolean
- `steps` → array of step definitions
- `metadata` → array

**Step Structure:**
```json
[
  {
    "agent_slug": "case-study-writer",
    "condition": null,
    "transform": null
  },
  {
    "agent_slug": "landing-page-generator",
    "condition": "output_contains:success",
    "transform": "extract_key_points"
  }
]
```

**Methods:**
- `getStepAgent(index)` → Agent for step
- `getStepCount()` → total steps
- `shouldExecuteStep(index, prevOutput, prevSuccess)` → evaluate condition
- `transformOutput(index, output)` → apply transform
- `createFromTemplate(key)` → predefined templates

**Conditions:**
- `previous_success` / `previous_failure`
- `output_contains:needle`
- `output_not_contains:needle`
- `output_length_gt:500`

**Transforms:**
- `extract_summary` → first 500 chars
- `extract_key_points` → bullet points
- `first_paragraph` / `last_paragraph`

**Indexes:**
- slug (unique)
- is_active

---

### AgentChainRun
**Table:** `agent_chain_runs`

**Relationships:**
- belongsTo: chain (AgentChain)
- hasMany: agentRuns

**Casts:**
- `step_results` → array
- `started_at` → datetime
- `completed_at` → datetime

**Constants:**
- STATUS_PENDING, STATUS_RUNNING, STATUS_COMPLETED, STATUS_FAILED, STATUS_CANCELLED

**Methods:**
- `getCurrentStep()` → current step index
- `getStepResult(index)` → result from step
- `getPreviousOutput()` → output from previous step
- `wasPreviousStepSuccessful()` → prev status === 'completed'
- `recordStepResult(index, run)` → save step completion
- `markCompleted()` / `markFailed(reason)`
- `shouldContinue()` → check if next step should execute
- `getTransformedInput()` → input for current step
- `getSummary()` → execution summary

**Indexes:**
- agent_chain_id
- status
- started_at

---

### AgentTask
**Table:** `agent_tasks`

**Relationships:**
- belongsTo: agent
- belongsTo: assignedBy (User)
- belongsTo: weeklyPlanItem
- belongsTo: agentRun

**Casts:**
- `context` → array
- `result` → array
- `scheduled_for` → datetime
- `started_at` → datetime
- `completed_at` → datetime

**Constants:**
- STATUS: pending, scheduled, running, completed, failed
- PRIORITY: low, normal, high, urgent

**Scopes:**
- `ready()` → pending & due
- `scheduled()` → scheduled for future
- `byPriority()` → ordered by priority

**Methods:**
- `scheduleFor(datetime)` → schedule task
- `start()` → mark running
- `complete(result, runId)` → mark done + update plan item
- `fail(error, runId)` → mark failed
- `getExecutionContext()` → context + plan + goal data
- `createFromStrategist(...)` → static factory

**Indexes:**
- agent_id
- status
- priority
- scheduled_for

---

### PromptTemplate
**Table:** `prompt_templates`

**Relationships:**
- belongsTo: agent (optional - can be global)
- belongsTo: createdBy (User)
- hasMany: versions (PromptVersion)
- hasMany: runs (AgentRun)

**Casts:**
- `variables` → array
- `tags` → array
- `metadata` → array
- `is_active` → boolean
- `is_public` → boolean

**Categories:**
- system, task, analysis, content, communication, code

**Scopes:**
- `category($cat)` → filter by category
- `forAgent($id)` → agent-specific or global
- `public()` → is_public = true

**Methods:**
- `currentVersion()` → active PromptVersion
- `render(variables)` → substitute {{placeholders}}
- `extractVariables()` → parse placeholders from content
- `createVersion(content, desc)` → version bump
- `getMetrics()` → success rate, cost, runs

**Indexes:**
- agent_id
- category
- is_public

---

### PromptVersion
**Table:** `prompt_versions`

**Relationships:**
- belongsTo: template (PromptTemplate)
- belongsTo: createdBy (User)
- hasMany: runs (AgentRun)

**Casts:**
- `variables` → array
- `is_active` → boolean
- `ab_test_weight` → integer (for A/B testing)

**Methods:**
- `render(variables)` → personalized output
- `getMetrics()` → performance stats
- `activate()` → set as active version
- `compareWith(other)` → A/B comparison

**Indexes:**
- prompt_template_id
- version_number
- is_active

---

### ApprovalRequest
**Table:** `approval_requests`

**Relationships:**
- belongsTo: agentRun
- belongsTo: decidedBy (User)

**Casts:**
- `payload` → array
- `decided_at` → datetime
- `expires_at` → datetime

**Key Fields:**
- `action_type` → what needs approval
- `risk_level` → low, medium, high
- `decision` → approved, denied, null
- `reason` → justification text

**Indexes:**
- agent_run_id
- status
- expires_at

---

## Client Management

### ClientContact
**Table:** `client_contacts`

**Relationships:**
- belongsTo: client

**Casts:**
- `is_primary` → boolean

**Key Fields:**
- `name`, `email`, `phone`, `role`, `notes`

**Indexes:**
- client_id
- email

---

### HealthAlert
**Table:** `health_alerts`

**Relationships:**
- belongsTo: client
- belongsTo: acknowledgedBy (User)
- belongsTo: resolvedBy (User)

**Casts:**
- `health_score` → decimal(1)
- `previous_score` → decimal(1)
- `factors` → array
- `escalation_history` → array
- `acknowledged_at` → datetime
- `resolved_at` → datetime
- `next_escalation_at` → datetime

**Constants:**
- STATUS: open, acknowledged, escalated, resolved
- SEVERITY: low, medium, high, critical
- TYPE: health_critical, health_warning, sentiment_negative, churn_risk, declining_trend

**Scopes:**
- `open()` → status = open
- `unresolved()` → open/acknowledged/escalated
- `needsEscalation()` → due for escalation
- `critical()` → severity = critical
- `forClient($id)` → filter by client

**Methods:**
- `acknowledge(userId)` → mark acknowledged
- `resolve(userId, note)` → mark resolved
- `escalate()` → bump escalation level
- `calculateNextEscalationTime()` → based on severity
- `createFromHealthDrop(client, old, new)` → static factory

**Accessors:**
- `severity_color` → UI color
- `escalation_level_name` → Initial/Manager/Director/Executive

**Indexes:**
- client_id
- status
- severity
- next_escalation_at

---

### EscalationTarget
**Table:** `escalation_targets`

**Relationships:**
- belongsTo: user

**Casts:**
- `is_active` → boolean

**Constants:**
- LEVEL_ACCOUNT_MANAGER = 0
- LEVEL_MANAGER = 1
- LEVEL_DIRECTOR = 2
- LEVEL_EXECUTIVE = 3

**Scopes:**
- `active()` → is_active = true
- `forLevel($level)` → level filter

**Static Methods:**
- `getTargetsForLevel($level)` → ordered list
- `getLevelName($level)` → human label

**Indexes:**
- user_id
- level
- is_active

---

### ClientReport
**Table:** `client_reports`

**Relationships:**
- belongsTo: client

**Casts:**
- `period_start` → date
- `period_end` → date
- `data_snapshot` → array
- `sent_at` → datetime
- `sent_to` → array
- `opened_at` → datetime

**Key Fields:**
- `report_type` → weekly, monthly, quarterly
- `pdf_path` → generated PDF

**Methods:**
- `isSent()` → sent_at !== null
- `isOpened()` → opened_at !== null
- `markAsSent(recipients)` → set sent metadata
- `markAsOpened()` → track open

**Accessors:**
- `period_label` → "Q1 2025" or "January 2025"

**Indexes:**
- client_id
- report_type
- period_start

---

## Project & Task Management

### Project
**Table:** `projects`

**Relationships:**
- belongsTo: client
- hasMany: milestones
- hasMany: tasks

**Casts:**
- `budget` → decimal(2)

**Soft Deletes:** Yes

**Key Fields:**
- `slug` → route key
- `status` → enum: active, on_hold, completed

**Route Key:** slug

**Indexes:**
- client_id
- slug (unique)
- status

---

### Milestone
**Table:** `milestones`

**Relationships:**
- belongsTo: project

**Casts:**
- `due_date` → date
- `completed_at` → datetime

**Indexes:**
- project_id
- due_date

---

### Task
**Table:** `tasks`

**Relationships:**
- belongsTo: project
- belongsTo: assignee (User, foreign key: assigned_to)
- belongsTo: milestone

**Casts:**
- `due_date` → date

**Soft Deletes:** Yes

**Key Fields:**
- `position` → drag-drop ordering
- `status` → enum: todo, in_progress, done

**Indexes:**
- project_id
- assigned_to
- milestone_id
- status
- position

---

### Lead
**Table:** `leads`

**Relationships:**
- belongsTo: assignee (User, foreign key: assigned_to)
- belongsTo: convertedClient (Client, foreign key: converted_client_id)

**Casts:**
- `deal_value` → decimal(2)
- `expected_close_date` → date
- `last_contacted_at` → datetime
- `converted_at` → datetime
- `tags` → array

**Soft Deletes:** Yes

**Key Fields:**
- `stage` → enum: new, contacted, qualified, proposal, negotiation, won, lost
- `source` → where lead came from
- `position` → kanban ordering

**Indexes:**
- assigned_to
- converted_client_id
- stage
- position

---

## Strategic Planning & Goals

### StrategicGoal
**Table:** `strategic_goals`

**Relationships:**
- belongsTo: user
- hasMany: periods (GoalPeriod)
- hasOne: yearlyPeriod
- hasMany: quarters, months, weeks (scoped GoalPeriod)

**Casts:**
- `revenue_target` → decimal(14,2)
- `margin_target_pct` → decimal(5,2)
- `profit_target` → decimal(14,2)
- `assumptions` → array

**Constants:**
- STATUS: active, achieved, missed, archived

**Unique:** (user_id, fiscal_year)

**Methods:**
- `currentQuarter/Month/Week()` → active period
- `generatePeriods(assumptions)` → create all sub-periods
- `getAssumption(key, default)` → read assumption value

**Accessors:**
- `calculated_profit` → revenue × margin %
- `progress_percent` → actual / target × 100
- `time_elapsed_percent` → % of year passed
- `is_on_track` → progress >= time elapsed

**Indexes:**
- user_id
- fiscal_year
- unique(user_id, fiscal_year)

---

### GoalPeriod
**Table:** `goal_periods`

**Relationships:**
- belongsTo: strategicGoal

**Casts:**
- `period_start/end` → date
- `revenue_target/actual` → decimal(14,2)
- `pipeline_target/actual` → decimal(14,2)
- `variance_pct` → decimal(8,2)
- `notes` → array

**Constants:**
- STATUS: pending, on_track, ahead, behind, critical, completed
- TYPE: yearly, quarterly, monthly, weekly

**Scopes:**
- `current()` → active period
- `past()` / `future()`
- `ofType($type)` → filter by period type

**Methods:**
- `updateStatus()` → calculate on_track/ahead/behind
- `calculateVariance()` → (actual - target) / target × 100

**Accessors:**
- `is_current/is_past/is_future` → date checks
- `elapsed_percent` → % of period completed
- `revenue_progress` → actual / target × 100
- `leads/deals/pipeline_progress` → % complete

**Indexes:**
- strategic_goal_id
- period_type
- period_start, period_end

---

### FunnelMetrics
**Table:** `funnel_metrics`

**Casts:**
- `period_start/end` → date
- All rates → decimal(5,2)
- Counts → integer
- Revenue → decimal(14,2)

**Constants:**
- TYPE: daily, weekly, monthly

**Unique:** (period_type, period_start)

**Scopes:**
- `latest($type)` → most recent snapshot
- `forRange($start, $end)` → date range
- `ofType($type)` → period type

**Static Methods:**
- `averageRates($type, $periods)` → rolling averages
- `trend($metric, $type, $periods)` → improving/declining/stable

**Accessors:**
- `overall_conversion` → product of all stage rates
- `win_loss_ratio` → won / lost
- `avg_revenue_per_deal` → revenue / deals

**Indexes:**
- unique(period_type, period_start)
- period_end

---

### BusinessGoal
**Table:** `business_goals`

**Relationships:**
- belongsTo: user (optional)

**Casts:**
- `period_start/end` → date
- `target` → decimal(14,2)
- `current_value` → decimal(14,2)

**Constants:**
- TYPE: revenue, pipeline, clients, win_rate, leads, deals
- PERIOD: mtd, qtd, ytd, weekly, custom

**Static Methods:**
- `setGoal($type, $period, $target, $userId)` → upsert

**Accessors:**
- `progress` → current / target × 100
- `remaining` → target - current
- `is_achieved` → current >= target
- `display_label` → "Revenue this month"

**Methods:**
- `getDateRange()` → [start, end] for period

**Scopes:**
- `active()` → status = active
- `ofType($type)` → type filter

**Indexes:**
- type, period
- user_id

---

### WeeklyPlan
**Table:** `weekly_plans`

**Relationships:**
- belongsTo: strategicGoal
- belongsTo: createdBy (User)
- belongsTo: approvedBy (User)
- hasMany: items (WeeklyPlanItem)
- hasMany: pendingItems, completedItems, agentItems, humanItems (scoped)

**Casts:**
- `week_starting` → date (Monday)
- `focus_areas` → array
- `targets` → array
- `week_results` → array
- `approved_at` → datetime

**Constants:**
- STATUS: draft, approved, active, completed

**Static Methods:**
- `forWeek($date, $goalId)` → find or create
- `current()` → this week's plan

**Methods:**
- `approve(user)` → mark approved
- `activate()` → mark active (after approval)
- `complete(results)` → mark completed with results
- `addItem/addAgentItem/addHumanItem(...)` → create items

**Accessors:**
- `is_current_week` → week_starting = this Monday
- `week_ending` → Sunday
- `week_label` → "Dec 9 - Dec 15, 2025"
- `progress_percent` → completed / total items × 100

**Indexes:**
- week_starting (unique)
- strategic_goal_id
- status

---

### WeeklyPlanItem
**Table:** `weekly_plan_items`

**Relationships:**
- belongsTo: weeklyPlan
- belongsTo: agentRun (if executed)

**Casts:**
- `expected_outcome` → array
- `result` → array

**Constants:**
- STATUS: pending, in_progress, completed, skipped
- OWNER: human, agent
- PRIORITY: low, medium, high, critical

**Methods:**
- `start()` → mark in_progress
- `complete(result, runId)` → mark done
- `skip(reason)` → mark skipped
- `createAgentTask(context)` → generate AgentTask

**Accessors:**
- `agent` → Agent model (if agent task)
- `is_human_task` / `is_agent_task` → owner type
- `due_date` → calculated from due_day + week_starting
- `is_overdue` → past due date

**Indexes:**
- weekly_plan_id
- status
- owner_type

---

## Lead Generation & Outreach

### IdealCustomerProfile
**Table:** `ideal_customer_profiles`

**Relationships:**
- hasMany: prospects
- hasMany: campaigns (OutreachCampaign)

**Casts:**
- `industries/company_sizes/locations` → array
- `tech_stack/tools_used` → array
- `buying_signals/pain_points` → array
- `avg_deal_value` → decimal(14,2)
- `is_active` → boolean

**Key Fields:**
- `weight_industry/size/tech/signals` → scoring weights (sum to 100)
- `target_monthly_leads` → goal

**Scopes:**
- `active()` → is_active = true

**Methods:**
- `scoreProspect(data)` → weighted scoring algorithm
- `getProspectStatsAttribute` → counts by status

**Boot:** Auto-generate slug from name

**Indexes:**
- slug (unique)
- is_active

---

### Prospect
**Table:** `prospects`

**Relationships:**
- belongsTo: icp (IdealCustomerProfile)
- belongsTo: convertedLead (Lead)
- belongsTo: discoveredByRun (AgentRun)
- hasMany: outreachMessages

**Casts:**
- `tech_stack/signals/research_notes` → array
- `score_breakdown` → array
- `estimated_revenue` → decimal(14,2)
- `converted_at` → datetime

**Constants:**
- STATUS: new, researching, qualified, unqualified, converted

**Soft Deletes:** Yes

**Scopes:**
- `new()` → status = new
- `qualified($minScore)` → score >= threshold & not converted
- `notConverted()` → converted_to_lead_id is null
- `forIcp($id)` → icp filter

**Methods:**
- `calculateScore()` → score against ICP + save
- `convertToLead()` → create Lead record

**Accessors:**
- `qualification_status` → highly_qualified/qualified/potential/low_fit (based on score)
- `has_been_contacted` → any sent messages exist

**Indexes:**
- status, icp_score
- company_name
- icp_id
- converted_to_lead_id

---

### OutreachCampaign
**Table:** `outreach_campaigns`

**Relationships:**
- belongsTo: icp (IdealCustomerProfile)
- hasMany: sequences (OutreachSequence)
- hasManyThrough: messages (via sequences)

**Casts:**
- `target_industries/titles` → array
- `use_email/linkedin/phone` → boolean

**Constants:**
- STATUS: draft, active, paused, completed
- TYPE: cold_outreach, nurture, reengagement

**Key Fields:**
- `min_icp_score` → qualification threshold
- Metrics: enrolled/sent/opened/replied/converted counts

**Scopes:**
- `active()` → status = active

**Methods:**
- `getEligibleProspects()` → query matching prospects
- `enrollProspect(prospect)` → add to campaign
- `refreshMetrics()` → recalculate counts

**Accessors:**
- `conversion_rate` → converted / enrolled × 100
- `reply_rate` → replied / sent × 100
- `open_rate` → opened / sent × 100

**Boot:** Auto-generate slug + random suffix

**Indexes:**
- slug (unique)
- icp_id
- status

---

### OutreachSequence
**Table:** `outreach_sequences`

**Relationships:**
- belongsTo: campaign (OutreachCampaign)
- hasMany: messages (OutreachMessage)

**Casts:**
- `send_days` → array (e.g., ["monday", "wednesday"])
- `requires_approval` → boolean
- `is_active` → boolean

**Constants:**
- CHANNEL: email, linkedin, phone, manual
- CONDITION: always, no_reply, opened, not_opened

**Unique:** (campaign_id, step_number)

**Methods:**
- `getNextStep()` → next sequence in campaign
- `shouldTriggerFor(prevMessage)` → evaluate condition
- `getNextSendTime(after)` → calculate send time (respects delay + preferred time + allowed days)
- `personalizeFor(prospect, extraContext)` → replace {{placeholders}}

**Indexes:**
- campaign_id
- unique(campaign_id, step_number)

---

### OutreachMessage
**Table:** `outreach_messages`

**Relationships:**
- belongsTo: sequence (OutreachSequence)
- belongsTo: prospect
- belongsTo: lead
- belongsTo: createdByRun (AgentRun)
- belongsTo: approvedBy (User)

**Casts:**
- `personalization_context` → array
- `scheduled_for/sent_at/opened_at/replied_at/approved_at` → datetime

**Constants:**
- STATUS: draft, approved, scheduled, sent, opened, replied, bounced
- SENTIMENT: positive, neutral, negative

**Scopes:**
- `readyToSend()` → approved/scheduled & due
- `pendingApproval()` → status = draft
- `ofChannel($channel)` → filter by channel

**Methods:**
- `approve(user)` → mark approved
- `markSent()` → sent_at + increment campaign counter
- `markOpened()` → opened_at + increment counter
- `recordReply(text, sentiment)` → replied_at + increment counter
- `createNextStepMessage()` → trigger next sequence step if conditions met

**Accessors:**
- `recipient` → email/linkedin/phone based on channel

**Indexes:**
- status, scheduled_for
- prospect_id, status
- sequence_id

---

## Integrations

### Google Credentials
**Table:** `google_credentials`

**Relationships:**
- belongsTo: user

**Casts:**
- `scopes` → array
- `expires_at` → datetime
- `watch_expiration` → datetime (Gmail push)
- `calendar_watch_expiration` → datetime

**Hidden:** access_token, refresh_token

**Accessors:**
- `access_token` / `refresh_token` → encrypted (via Crypt)

**Methods:**
- `isExpired()` → expires_at isPast
- `needsWatchRenewal()` → watch expires within 1 day
- `needsCalendarWatchRenewal()` → calendar watch expires within 1 day

**Indexes:**
- user_id (unique)

---

### Email
**Table:** `emails`

**Relationships:**
- belongsTo: client

**Casts:**
- `to_addresses` → array
- `received_at` → datetime
- `processed_at` → datetime
- `sentiment_score` → decimal(2)
- `is_transcript` → boolean (Gemini meeting transcripts)
- `is_processed` → boolean

**Methods:**
- `isFromClient()` → client_id !== null
- `isGeminiTranscript()` → detect meeting transcripts

**Accessors:**
- `sentiment_badge` → UI badge color

**Indexes:**
- client_id
- received_at
- is_processed

---

### CalendarEvent
**Table:** `calendar_events`

**Relationships:**
- belongsTo: client
- belongsTo: project

**Casts:**
- `start_at/end_at` → datetime
- `attendees/key_decisions` → array
- `is_all_day/is_client_meeting` → boolean
- `pre_brief_sent/post_followup_sent` → boolean
- `parsed_at` → datetime

**Methods:**
- `isUpcoming/isInProgress/isPast()` → time checks
- `needsPreBrief()` → 30min before & not sent
- `needsPostFollowup()` → past & not sent

**Accessors:**
- `external_attendees` → non-internal domains

**Indexes:**
- client_id
- start_at
- is_client_meeting

---

### Document
**Table:** `documents`

**Relationships:**
- belongsTo: client
- belongsTo: project

**Casts:**
- `effective_date/expiration_date` → date
- `contract_value` → decimal(2)
- `google_modified_at/indexed_at` → datetime

**Methods:**
- `isExpiringSoon()` → within 30 days
- `isExpired()` → past expiration
- `isLinked()` → has client_id
- `getDocumentTypeLabel()` → human label

**Indexes:**
- client_id
- project_id
- document_type
- expiration_date

---

### Slack Tables

**SlackWorkspace**
**Table:** `slack_workspaces`

**Relationships:**
- hasMany: channels
- hasMany: messages
- hasMany: monitoredChannels (scoped)
- hasMany: clientChannels (scoped)

**Casts:**
- `is_primary` → boolean

**Hidden:** access_token

**Accessors:**
- `access_token` → encrypted

**Indexes:**
- team_id (unique)

---

**SlackChannel**
**Table:** `slack_channels`

**Relationships:**
- belongsTo: workspace
- belongsTo: client
- hasMany: messages
- hasMany: threads

**Casts:**
- `is_private/is_shared/monitoring_enabled` → boolean
- `last_synced_at` → datetime

**Methods:**
- `isClientChannel()` → classification or client_id
- `classifyAutomatically()` → internal/client/general/project

**Indexes:**
- workspace_id, channel_id (unique)
- client_id

---

**SlackMessage**
**Table:** `slack_messages`

**Relationships:**
- belongsTo: workspace
- belongsTo: channel
- belongsTo: client

**Casts:**
- `attachments` → array
- `user_is_external` → boolean
- `has_action_item/is_repeated_request` → boolean
- `action_item_confidence` → decimal(2)
- `processed_at` → datetime

**Methods:**
- `isThreadReply()` → thread_ts !== message_ts
- `isFromExternalUser()` → user_is_external
- `needsProcessing()` → processed_at is null

**Accessors:**
- `permalink` → Slack URL

**Indexes:**
- channel_id, message_ts (unique)
- thread_ts
- processed_at

---

**SlackThread**
**Table:** `slack_threads`

**Relationships:**
- belongsTo: channel
- hasMany: messages (via channel where thread_ts matches)

**Casts:**
- `participants` → array
- `action_items_extracted` → array
- `has_external_participant` → boolean
- `last_reply_at` → datetime

**Methods:**
- `needsSummarization()` → >=10 messages & no summary
- `hasClientParticipant()` → has_external_participant

**Indexes:**
- channel_id, thread_ts (unique)

---

**SlackRequestPattern**
**Table:** `slack_request_patterns`

**Relationships:**
- belongsTo: client

**Casts:**
- `topic_embedding` → array (vector)
- `messages` → array (message IDs)
- `first_asked_at/last_asked_at/resolved_at` → datetime
- `is_resolved` → boolean

**Methods:**
- `getHealthImpact()` → 0-2 based on ask_count
- `getSeverityLevel()` → normal/warning/high/critical
- `markResolved()` → set resolved flag
- `addMessage(messageTs)` → append to array + increment ask_count

**Indexes:**
- client_id
- is_resolved

---

### GitHub Tables

**GitHubInstallation**
**Table:** `github_installations`

**Relationships:**
- hasMany: repos

**Casts:**
- `permissions` → array
- `token_expires_at/connected_at` → datetime

**Hidden:** access_token

**Accessors:**
- `access_token` → encrypted

**Methods:**
- `tokenIsExpired()` → expires_at isPast
- `isOrgInstallation()` → account_type = Organization

**Indexes:**
- installation_id (unique)

---

**GitHubRepo**
**Table:** `github_repos`

**Relationships:**
- belongsTo: installation
- belongsTo: client
- belongsTo: project
- hasMany: issues
- hasMany: pullRequests
- hasOne: deploymentConfig
- hasMany: openIssues/openPullRequests (scoped)

**Casts:**
- `is_private/monitoring_enabled` → boolean
- `deployment_config` → array

**Accessors:**
- `url` → https://github.com/{full_name}

**Indexes:**
- installation_id
- full_name (unique)
- client_id

---

**GitHubIssue**
**Table:** `github_issues`

**Relationships:**
- belongsTo: repo
- belongsTo: task
- belongsTo: agentRun

**Casts:**
- `labels/assignees` → array
- `closed_at` → datetime

**Methods:**
- `isOpen()` → state = open
- `hasLabel(label)` → case-insensitive check
- `isAgentTask()` → has 'agent' or 'agent-task' label

**Accessors:**
- `url` → GitHub issue URL

**Indexes:**
- repo_id, issue_number (unique)
- task_id
- agent_run_id

---

**GitHubPullRequest**
**Table:** `github_pull_requests`

**Relationships:**
- belongsTo: repo
- belongsTo: approvalRequest
- belongsTo: qaAgentRun (AgentRun)

**Casts:**
- `reviewers` → array
- `checks_passed` → boolean
- `merged_at` → datetime

**Methods:**
- `isOpen()` → state = open
- `isMerged()` → state = merged || merged_at
- `targetsMain()` → base in [main, master]
- `targetsDevelop()` → base in [develop, dev, development]
- `needsApproval()` → targets main & pending

**Accessors:**
- `url` → GitHub PR URL

**Indexes:**
- repo_id, pr_number (unique)
- approval_request_id
- state

---

**DeploymentConfig**
**Table:** `deployment_configs`

**Relationships:**
- belongsTo: client
- belongsTo: repo (GitHubRepo)
- belongsTo: onboardingAgentRun (AgentRun)

**Casts:**
- `secrets` → array
- `onboarding_completed` → boolean

**Methods:**
- `isSftp/isVercel/isNetlify()` → deployment_type checks
- `needsOnboarding()` → !onboarding_completed
- `getWorkflowTemplatePath()` → template file based on type

**Indexes:**
- client_id
- repo_id (unique)

---

### Harvest (Time Tracking)

**HarvestCredential**
**Table:** `harvest_credentials`

**Relationships:**
- belongsTo: user

**Casts:**
- `expires_at` → datetime

**Hidden:** access_token, refresh_token

**Accessors:**
- Encrypted tokens via Crypt

**Methods:**
- `isExpired()` → expires_at isPast

**Indexes:**
- user_id (unique)

---

**HarvestProject**
**Table:** `harvest_projects`

**Relationships:**
- belongsTo: client
- belongsTo: project
- hasMany: timeEntries (via harvest_id)

**Casts:**
- `is_active/is_billable/budget_is_monthly` → boolean
- `hourly_rate/budget` → decimal(2)

**Accessors:**
- `total_hours` → sum(time_entries.hours)
- `billable_hours` → sum(billable hours)
- `budget_used_percent` → hours / budget × 100

**Indexes:**
- harvest_id (unique)
- client_id
- project_id

---

**HarvestTaskCategory**
**Table:** `harvest_task_categories`

**Relationships:**
- hasMany: timeEntries

**Casts:**
- `is_default/is_active` → boolean
- `default_hourly_rate` → decimal(2)

**Accessors:**
- `total_hours` → sum(time_entries.hours)

**Indexes:**
- harvest_id (unique)

---

**HarvestInvoice**
**Table:** `harvest_invoices`

**Relationships:**
- belongsTo: client

**Casts:**
- `amount/due_amount` → decimal(2)
- `issue_date/due_date/sent_at/paid_at` → date

**Methods:**
- `isPaid()` → state = paid
- `isOverdue()` → state = open && due_date past

**Accessors:**
- `days_overdue` → due_date.diffInDays(now)

**Indexes:**
- harvest_id (unique)
- client_id
- state

---

**TimeEntry**
**Table:** `time_entries`

**Relationships:**
- belongsTo: user
- belongsTo: client
- belongsTo: project
- belongsTo: task

**Casts:**
- `hours/hourly_rate/cost_rate` → decimal(2)
- `spent_date` → date
- `is_running/is_billable/is_billed` → boolean
- `timer_started_at` → datetime

**Accessors:**
- `billable_amount` → hours × hourly_rate (if billable)
- `cost` → hours × cost_rate

**Methods:**
- `isRunning()` → is_running

**Indexes:**
- harvest_id (unique)
- user_id
- client_id
- project_id
- spent_date

---

### Notion

**NotionConnection**
**Table:** `notion_connections`

**Relationships:**
- belongsTo: user
- hasMany: pages
- hasMany: databases (pages where is_database = true)

**Casts:**
- `connected_at` → datetime

**Hidden:** access_token

**Accessors:**
- `access_token` → encrypted

**Indexes:**
- user_id
- workspace_id (unique)

---

**NotionPage**
**Table:** `notion_pages`

**Relationships:**
- belongsTo: connection (NotionConnection)
- belongsTo: client
- hasOne: content (NotionPageContent)
- hasMany: items (NotionDatabaseItem, if is_database)
- belongsTo: parent (self, via page_id)
- hasMany: children (self, via parent_id)

**Casts:**
- `properties_schema` → array
- `is_database/archived` → boolean
- `last_synced_at` → datetime

**Scopes:**
- `databases()` → is_database = true
- `pages()` → is_database = false
- `active()` → archived = false

**Indexes:**
- notion_connection_id
- page_id (unique)
- client_id
- parent_id

---

**NotionPageContent**
**Table:** `notion_page_content`

**Relationships:**
- belongsTo: page (NotionPage)

**Casts:**
- `content_blocks` → array
- `synced_at` → datetime

**Indexes:**
- notion_page_id (unique)

---

**NotionDatabaseItem**
**Table:** `notion_database_items`

**Relationships:**
- belongsTo: database (NotionPage, via notion_page_id)

**Casts:**
- `properties` → array
- `due_date` → date
- `synced_at` → datetime

**Scopes:**
- `withStatus($status)` → filter by status
- `overdue()` → past due & not done
- `upcoming($days)` → due within N days

**Indexes:**
- notion_page_id
- item_id (unique)
- status

---

### WordPress

**WordPressSite**
**Table:** `wordpress_sites`

**Relationships:**
- belongsTo: client
- hasMany: posts (WordPressPost)
- hasMany: contentSuggestions

**Casts:**
- `mcp_enabled/is_primary` → boolean
- `capabilities` → array
- `last_connected_at` → datetime

**Hidden:** application_password

**Accessors:**
- `application_password` → encrypted
- `mcp_endpoint` → rest_url or /wp-json/mcp/...
- `auth_header` → Basic auth string

**Scopes:**
- `primary()` → is_primary = true

**Indexes:**
- client_id
- url (unique)

---

**WordPressPost**
**Table:** `wordpress_posts`

**Relationships:**
- belongsTo: site (WordPressSite)

**Casts:**
- `categories/tags` → array
- `published_at/modified_at/synced_at` → datetime

**Scopes:**
- `published()` → status = publish
- `drafts()` → status = draft
- `ofType($type)` → type filter

**Indexes:**
- wordpress_site_id
- wp_post_id (unique per site)

---

**ContentSuggestion**
**Table:** `content_suggestions`

**Relationships:**
- belongsTo: site (WordPressSite)

**Casts:**
- `source_data` → array

**Scopes:**
- `pending/approved/published()` → status filters

**Methods:**
- `approve()` → status = approved
- `dismiss()` → status = dismissed
- `markPublished(wpPostId)` → status = published + link

**Indexes:**
- wordpress_site_id
- status

---

### QuickBooks

**QuickBooksConnection**
**Table:** `quickbooks_connections`

**Relationships:**
- belongsTo: user
- hasMany: accounts (QboAccount)
- hasMany: transactions (QboTransaction)
- hasMany: invoices (QboInvoice)
- hasMany: customers (QboCustomer)
- hasMany: snapshots (FinancialSnapshot)

**Casts:**
- `access_token_expires_at/refresh_token_expires_at/last_synced_at` → datetime
- `sync_enabled` → boolean

**Hidden:** access_token, refresh_token

**Accessors:**
- Encrypted tokens

**Methods:**
- `isAccessTokenExpired()` → expires_at isPast
- `isRefreshTokenExpiring()` → expires within 7 days
- `needsTokenRefresh()` → expires within 10 minutes

**Indexes:**
- user_id
- realm_id (unique)

---

**QboAccount**
**Table:** `qbo_accounts`

**Relationships:**
- belongsTo: connection (QuickBooksConnection)
- hasMany: transactions

**Casts:**
- `current_balance` → decimal(2)
- `active` → boolean
- `synced_at` → datetime

**Scopes:**
- `active()` → active = true
- `ofType($type)` → account_type filter
- `bank/income/expense()` → type shortcuts

**Indexes:**
- qbo_connection_id
- qbo_id (unique)

---

**QboCustomer**
**Table:** `qbo_customers`

**Relationships:**
- belongsTo: connection (QuickBooksConnection)
- belongsTo: client (matched)

**Casts:**
- `balance` → decimal(2)
- `active` → boolean
- `synced_at` → datetime

**Scopes:**
- `active()` → active = true
- `withBalance()` → balance > 0
- `unmatched()` → client_id is null

**Methods:**
- `matchToClient(clientId)` → link to client

**Indexes:**
- qbo_connection_id
- qbo_id (unique)
- client_id

---

**QboInvoice**
**Table:** `qbo_invoices`

**Relationships:**
- belongsTo: connection (QuickBooksConnection)
- belongsTo: client
- belongsTo: harvestInvoice

**Casts:**
- `txn_date/due_date` → date
- `total_amount/balance` → decimal(2)
- `line_items` → array
- `synced_at` → datetime

**Scopes:**
- `open()` → status = Open & balance > 0
- `paid()` → status = Paid
- `overdue()` → open + past due

**Methods:**
- `isPaid()` → status = Paid || balance = 0
- `isOverdue()` → Open + past due + balance > 0

**Accessors:**
- `days_overdue` → due_date.diffInDays(now)

**Indexes:**
- qbo_connection_id
- qbo_id (unique)
- client_id
- status

---

**QboTransaction**
**Table:** `qbo_transactions`

**Relationships:**
- belongsTo: connection (QuickBooksConnection)
- belongsTo: account (QboAccount)
- belongsTo: client
- belongsTo: project

**Casts:**
- `txn_date` → date
- `amount` → decimal(2)
- `is_reconciled` → boolean
- `synced_at` → datetime

**Scopes:**
- `ofType($type)` → txn_type filter
- `income()` → Invoice/Payment/SalesReceipt/Deposit
- `expenses()` → Expense/Bill/BillPayment/Purchase
- `inDateRange($start, $end)` → date filter

**Indexes:**
- qbo_connection_id
- qbo_id (unique)
- account_id
- txn_date

---

**FinancialSnapshot**
**Table:** `financial_snapshots`

**Relationships:**
- belongsTo: connection (QuickBooksConnection)

**Casts:**
- `period_start/end` → date
- All financial amounts → decimal(2)
- `top_expense_categories/top_income_sources/insights` → array

**Scopes:**
- `daily/weekly/monthly()` → period_type filter
- `forPeriod($start, $end)` → date range

**Static Methods:**
- `latest($periodType)` → most recent snapshot

**Accessors:**
- `profit_margin` → net_profit / total_income × 100
- `expense_ratio` → total_expenses / total_income × 100

**Indexes:**
- qbo_connection_id
- period_type
- period_end

---

### Social Media

**LinkedInCredential**
**Table:** `linkedin_credentials`

**Relationships:**
- belongsTo: user

**Casts:**
- `token_expires_at/last_synced_at` → datetime
- `scopes` → array
- `is_active` → boolean

**Hidden:** access_token, refresh_token

**Methods:**
- `isTokenExpired()` → token_expires_at isPast
- `hasOrganizationAccess()` → organization_id not empty

**Indexes:**
- user_id (unique)
- linkedin_id

---

**XCredential**
**Table:** `x_credentials`

**Relationships:**
- belongsTo: user

**Casts:**
- `token_expires_at/last_synced_at` → datetime
- `scopes` → array
- `is_active/verified` → boolean
- Follower counts → integer

**Hidden:** access_token, refresh_token

**Methods:**
- `isTokenExpired()` → token_expires_at isPast

**Indexes:**
- user_id (unique)
- x_user_id

---

## Security & Vault

### VaultSecret
**Table:** `vault_secrets`

**Relationships:**
- belongsTo: project
- belongsTo: client
- belongsTo: createdBy (User)
- belongsTo: updatedBy (User)
- hasMany: accessLogs (VaultAccessLog)

**Casts:**
- `allowed_agents/allowed_users` → array
- `is_sensitive/is_active` → boolean
- `last_accessed_at/expires_at` → datetime
- `access_count` → integer

**Hidden:** encrypted_value

**Constants:**
- CATEGORY: api_key, oauth, credential, certificate, other

**Scopes:**
- `active()` → is_active = true
- `notExpired()` → expires_at > now || null
- `forProject($id)` → project_id filter
- `forClient($id)` → client_id filter
- `global()` → no project/client
- `category($cat)` → category filter

**Accessors:**
- `is_expired` → expires_at isPast
- `scope_label` → "Project: X" or "Client: Y" or "Global"

**Methods:**
- `canBeAccessedBy(user, agentSlug)` → access control check
- `setValueAttribute($value)` → encrypt on write
- `getDecryptedValue()` → decrypt on read

**Indexes:**
- project_id
- client_id
- category
- is_active

---

### VaultAccessLog
**Table:** `vault_access_logs`

**Relationships:**
- belongsTo: secret (VaultSecret)

**Casts:**
- `context` → array
- `was_successful` → boolean
- `created_at` → datetime

**No timestamps** (only created_at)

**Constants:**
- TYPE: user, agent, system
- ACTION: read, write, delete, rotate

**Static Methods:**
- `log(secret, action, accessorType, ...)` → create log entry

**Indexes:**
- vault_secret_id
- accessor_type
- action
- created_at

---

## System & Infrastructure

### Notification
**Table:** `notifications`

**Relationships:**
- belongsTo: user (nullable for global notifications)

**Casts:**
- `metadata` → array
- `read_at/dismissed_at` → datetime

**Scopes:**
- `unread()` → read_at is null
- `undismissed()` → dismissed_at is null
- `forUser($userId)` → user filter + global

**Methods:**
- `markAsRead()` → set read_at
- `dismiss()` → set dismissed_at
- `isUnread()` → read_at === null

**Static Factory Methods:**
- `approvalNeeded(ApprovalRequest)` → approval notification
- `agentCompleted(AgentRun)` → run result notification
- `syncComplete(service, count, userId)` → integration sync
- `system(title, message, severity)` → system message

**Indexes:**
- user_id
- read_at
- dismissed_at
- created_at

---

### CommandHistory
**Table:** `command_history`

**Relationships:**
- belongsTo: user

**Casts:**
- `metadata` → array
- `executed_at` → datetime

**Static Methods:**
- `log(commandId, commandType, query, metadata)` → create entry
- `recent($limit)` → last N commands (unique by command_id)
- `frequent($limit)` → most used commands

**Indexes:**
- user_id
- executed_at
- command_id

---

### ProfitabilitySnapshot
**Table:** `profitability_snapshots`

**Relationships:**
- belongsTo: client
- belongsTo: project
- belongsTo: user

**Casts:**
- `period_start/end` → date
- All hour/revenue/cost fields → decimal(2)

**Static Methods:**
- `createForPeriod($type, $start, $end, $data)` → factory

**Accessors:**
- `billable_ratio` → billable / logged × 100
- `effective_rate` → revenue / billable_hours

**Indexes:**
- client_id
- project_id
- user_id
- period_type
- period_start

---

### ProjectBudget
**Table:** `project_budgets`

**Relationships:**
- belongsTo: project

**Casts:**
- `budget_hours/budget_amount` → decimal(2)
- `hours_logged/amount_billed` → decimal(2)
- `start_date/end_date` → date
- `last_activity_at` → datetime

**Methods:**
- `isOverBudget()` → hours_logged > budget_hours
- `isAtRisk()` → >= 80% used
- `updateStatus()` → calculate on_track/at_risk/over_budget

**Accessors:**
- `budget_used_percent` → logged / budget × 100
- `remaining_hours` → budget - logged

**Indexes:**
- project_id
- status

---

### RetainerPeriod
**Table:** `retainer_periods`

**Relationships:**
- belongsTo: client

**Casts:**
- `hours_included/hours_used/rollover_hours/overage_rate` → decimal(2)
- `period_start/end` → date

**Static Methods:**
- `currentForClient($clientId)` → active period for client

**Methods:**
- `isActive()` → status = active & current date in range
- `isOverage()` → hours_used > total_hours

**Accessors:**
- `total_hours` → hours_included + rollover_hours
- `remaining_hours` → max(0, total - used)
- `usage_percent` → used / total × 100
- `overage_hours` → used - total (if overage)

**Indexes:**
- client_id
- status
- period_start, period_end

---

### AuditLog
**Table:** `audit_logs`

Basic audit logging (minimal implementation in current codebase)

**Indexes:**
- created_at

---

## Key Indexes & Foreign Keys

### Primary Indexes
All tables have auto-incrementing `id` primary key except:
- `password_reset_tokens` → email (primary)
- `sessions` → id (string primary)

### Unique Constraints
- users.email
- clients.slug
- agents.slug
- projects.slug
- strategic_goals(user_id, fiscal_year)
- ideal_customer_profiles.slug
- outreach_campaigns.slug
- github_repos.full_name
- slack_channels(workspace_id, channel_id)
- and many more for external IDs (harvest_id, qbo_id, etc.)

### Critical Foreign Keys
All foreign keys use `constrained()` with appropriate cascade/nullOnDelete:
- Most agent/client relationships → `cascadeOnDelete()`
- Integration relationships → `nullOnDelete()` (preserve data if integration disconnected)
- User relationships → `constrained()` (prevent orphaned records)

### Performance Indexes
- Timestamp columns: created_at, updated_at (for sorting)
- Status columns (for filtering active/pending items)
- Date ranges: period_start, period_end (for goal/metric queries)
- Foreign keys: All FK columns auto-indexed
- Composite indexes on frequently joined columns

---

## Migration History

**Initial Setup:**
- 0001_01_01_000000 → users, sessions, password_reset_tokens
- 0001_01_01_000001 → cache
- 0001_01_01_000002 → jobs, job_batches, failed_jobs

**Core Models (2025-12-07):**
- create_clients_table
- create_client_contacts_table
- create_projects_table
- create_milestones_table
- create_tasks_table
- create_agents_table
- create_agent_runs_table
- create_approval_requests_table
- create_audit_logs_table
- create_vault_secrets_table
- create_leads_table

**Agent Infrastructure (2025-12-12/13):**
- add_risk_level_to_approval_requests
- add_team_fields_to_users
- update_role_enum_in_users
- add_position_to_leads_and_tasks (drag-drop)
- add_agent_infrastructure_columns
- create_agent_templates_table
- add_webhook_fields_to_agents
- add_consortium_to_agents
- update_agent_status_enum
- create_agent_activity_logs_table
- create_command_history_table

**Integrations (2025-12-13/14):**
- create_google_credentials_table
- create_google_data_tables (emails, calendar_events, documents)
- create_slack_tables (workspaces, channels, messages, threads, patterns)
- create_github_tables (installations, repos, issues, PRs, deployment)
- create_harvest_tables (credentials, projects, categories, invoices, time_entries, budgets, retainers, snapshots, reports)
- create_notion_tables (connections, pages, content, database_items)
- create_wordpress_tables (sites, posts, content_suggestions)
- create_quickbooks_tables (connections, accounts, customers, invoices, transactions, snapshots)
- create_notifications_table

**Agent Chains & Prompts (2025-12-14):**
- create_agent_chains_tables (chains, chain_runs)
- add_execution_mode_to_agents
- create_prompt_library_tables (templates, versions)

**Health & Goals (2025-12-14):**
- create_health_alerts_table
- create_strategic_goals_tables (goals, periods, funnel_metrics, business_goals)

**Lead Generation (2025-12-14):**
- create_lead_generation_tables (ICPs, prospects, campaigns, sequences, messages)

**Weekly Planning (2025-12-14):**
- create_weekly_plans_tables (plans, plan_items, agent_tasks)

**Security & Social (2025-12-14):**
- create_vault_secrets_table (replaces old vault table)
- add_client_id_to_users_table (client portal users)
- create_social_credentials_tables (LinkedIn, X/Twitter)

---

## Summary Stats

**Total Tables:** 85+

**Domain Breakdown:**
- Core (Users, Clients, Projects): 8 tables
- Agent System: 10 tables
- Strategic Planning & Goals: 7 tables
- Lead Generation & Outreach: 5 tables
- Google Integration: 4 tables
- Slack Integration: 5 tables
- GitHub Integration: 5 tables
- Harvest Integration: 8 tables
- Notion Integration: 4 tables
- WordPress Integration: 3 tables
- QuickBooks Integration: 6 tables
- Social Media: 2 tables
- Vault & Security: 2 tables
- Health & Monitoring: 4 tables
- System Infrastructure: 12 tables (cache, jobs, sessions, etc.)

**Key Features:**
- Soft Deletes: 6 tables (clients, projects, tasks, leads, prospects)
- Encrypted Fields: 15+ credential tables (OAuth tokens, secrets)
- JSON Casts: 60+ array/object fields
- Decimal Precision: Financial (14,2), Rates (5,2), Scores (3,1)
- Timestamp Tracking: created_at, updated_at on all models
- Composite Unique Constraints: 20+ (workspace+channel, user+fiscal_year, etc.)

---

## Best Practices Observed

1. **Consistent naming:** snake_case tables, singular model names
2. **Soft deletes:** On user-facing entities (clients, projects, tasks)
3. **Encryption:** All OAuth tokens, secrets, passwords encrypted at rest
4. **Relationships:** Explicit foreign keys with cascade rules
5. **Indexing:** FK auto-indexed, additional indexes on frequent queries
6. **Casts:** Type safety via model casts (dates, decimals, arrays, booleans)
7. **Scopes:** Common queries encapsulated (active, unread, pending)
8. **Accessors:** Computed properties (progress_percent, is_expired)
9. **Events:** Model observers for audit logging (Agent system)
10. **Versioning:** Prompt templates support A/B testing via versions
11. **Audit trails:** Activity logs, access logs, command history
12. **Separation of concerns:** Integration tables isolated per service
