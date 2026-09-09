# Zao Dash: AI-Powered Agency Command Center

## Vision
An autonomous agent army that runs Zao (WordPress/Laravel agency) with human-in-the-loop safety rails. "Only a blessing, never a curse."

## Tech Stack
- **Backend**: Laravel 12 + Inertia.js + Vue 3 + TypeScript (monolith)
- **Agents**: Laravel queue jobs executing Claude CLI with process isolation
- **Real-time**: Laravel Reverb (WebSockets)
- **Queue**: Redis + Horizon (dedicated agent supervisor)
- **Hosting**: Laravel Cloud (AWS/Lambda behind the scenes)
- **UI**: Linear/Vercel style + ClickUp/Notion inspiration - magical KPIs, sophisticated dark mode

## Anthropic Best Practices (Internalized)

### Context Engineering
- **Tiered loading**: Pre-load essential guidance, just-in-time retrieval for data
- **Compaction**: Summarize conversation history at context limits
- **Sub-agents**: Distribute work across specialized agents with isolated contexts
- **Progressive disclosure**: Agents discover context through exploration

### Agent Skills Pattern
- Each agent capability = `SKILL.md` file with YAML frontmatter
- Skills stored in `storage/app/skills/` directory
- Agents load skill metadata at startup, full instructions when relevant
- Unbounded context via file references (agents can read without loading)

### Sandboxing (Two-Layer)
- **Filesystem isolation**: Agents only access designated directories
- **Network isolation**: Allowlisted domains only via proxy
- **Credential separation**: API keys never in agent execution context
- **Scoped tokens**: Agents get minimal-permission tokens per task

### Tool Design
- Dynamic discovery over static loading (saves 98%+ tokens)
- Parallel tool execution where independent
- Filter results in code before returning to context
- Provide usage examples, not just schemas

### Verification Loop
1. Rules-based feedback (linting, validation)
2. Visual feedback (screenshots via Playwright MCP)
   - **Smart distinction**: Use structural analysis (DOM diff) to separate content changes (OK) from layout breakage (NOT OK)
   - Compare bounding boxes, flex/grid structure, z-index layers vs text/image content
3. LLM-as-judge for subjective quality

---

## Phase 1: Foundation (Core Laravel App)

### 1.1 Project Initialization
- [ ] `laravel new zao-dash` with Inertia + Vue + TypeScript preset
- [ ] Configure Laravel Cloud deployment
- [ ] Setup Redis, Horizon, Reverb
- [ ] Initialize Tailwind with dark mode theme

### 1.2 Database Models
```
Users (owner/staff/client roles)
├── Clients
│   ├── ClientContacts
│   ├── Communications (email/slack/call history)
│   └── Projects
│       ├── Milestones
│       ├── TimeEntries (synced from Harvest)
│       └── Secrets (encrypted vault per project)
├── Agents
│   └── AgentRuns (execution history)
├── ApprovalRequests (human-in-the-loop queue)
├── Integrations (GSuite, Slack, Notion, GitHub, Harvest, Loom)
└── AuditLogs (immutable action history)
```

### 1.3 Core Services
- `app/Services/Vault/VaultService.php` - Encrypted secrets with scoped access
- `app/Services/Agents/AgentOrchestrator.php` - Dispatch agents, track runs
- `app/Services/Approval/ApprovalService.php` - Human approval queue
- `app/Services/Analytics/KpiCalculator.php` - Dashboard metrics

### 1.4 Authentication & Authorization
- Multi-role: Owner (full) → Staff (role-based) → Client (read-only portal)
- Laravel Policies for all models
- Client portal on separate route group with global scopes
- Agents get scoped API tokens (principle of least privilege)

---

## Phase 2: Integrations Hub

### 2.1 OAuth Connections
- [ ] Google OAuth (Gmail, Calendar, Meet, **Drive**)
- [ ] Slack OAuth (workspace access)
- [ ] Notion OAuth (database access)
- [ ] GitHub OAuth (repo access)
- [ ] Harvest API (time tracking)
- [ ] **WordPress MCP** (agency website via WordPress 7.0 AI SDK)
- [ ] **QuickBooks OAuth** (bookkeeping, invoicing, backoffice)

### 2.2 Sync Jobs
- `SyncGSuiteJob` - Pull emails, calendar events, meeting transcripts
- `SyncGoogleDriveJob` - **MSAs, SOWs, contracts → sync to client records**
- `SyncSlackJob` - Pull channel messages, DMs (opted-in)
- `SyncNotionJob` - Pull project databases
- `SyncGitHubJob` - Pull repos, issues, PRs
- `SyncHarvestJob` - Pull time entries, projects
- `SyncWordPressJob` - **Pull/push blog posts, case studies, landing pages**
- `SyncQuickBooksJob` - **Pull invoices, payments, financial data**

### 2.3 Webhook Receivers
- Gmail push notifications
- Google Drive file changes
- Slack events
- GitHub webhooks
- Harvest webhooks
- **WordPress webhooks** (post publish, comment, etc.)
- **QuickBooks webhooks** (payment received, invoice created)

---

## Phase 3: Agent Orchestration Layer (Laravel Monolith)

### 3.1 Directory Structure
```
app/
├── Agents/
│   ├── AgentRunner.php                    # Core execution service
│   ├── AgentResult.php                    # Result DTO
│   ├── Concerns/
│   │   ├── HasApprovalGates.php           # Approval trait
│   │   └── HasSandbox.php                 # Isolation trait
│   └── Definitions/
│       ├── MeetingParserAgent.php         # Transcript → action items
│       ├── InvoiceAnalyzerAgent.php       # Harvest → invoices
│       ├── ClientSentimentAgent.php       # Comms → health score
│       ├── OpportunityScoutAgent.php      # Work → upsell opportunities
│       ├── ContentCreatorAgent.php        # Projects → case studies
│       ├── DevAgent.php                   # Tasks → code changes
│       ├── QAAgent.php                    # Changes → validation
│       ├── CommunicationAgent.php         # Drafts → client updates
│       ├── MarketingAgent.php             # Proactive growth campaigns
│       ├── WordPressAgent.php             # Blog posts, case studies, pages
│       └── BookkeepingAgent.php           # QuickBooks integration
├── Jobs/
│   └── Agents/
│       ├── ExecuteAgentJob.php            # Queue job for agent runs
│       └── ProcessAgentResultJob.php      # Handle completion
└── Services/
    ├── AgentOrchestrator.php              # Dispatch and chain agents
    ├── AgentSandbox.php                   # Process isolation
    └── ClaudeCliRunner.php                # Shell exec to Claude CLI

storage/app/
├── skills/                                # Agent skill files
│   ├── meeting-parser/
│   │   └── SKILL.md
│   ├── dev-agent/
│   │   └── SKILL.md
│   └── qa-agent/
│       └── SKILL.md
└── agent-workspaces/                      # Isolated per-run directories
    └── {run-uuid}/
```

### 3.2 Agent Execution via Claude CLI
```php
// app/Services/ClaudeCliRunner.php
class ClaudeCliRunner
{
    public function execute(Agent $agent, string $task, array $context): AgentResult
    {
        $workspace = $this->createSandboxedWorkspace($agent);

        $process = Process::timeout(300)
            ->path($workspace)
            ->env([
                'ANTHROPIC_API_KEY' => config('services.anthropic.key'),
                // Scoped, minimal credentials only
            ])
            ->run([
                'claude',
                '--print', 'all',
                '--output-format', 'json',
                '--max-turns', '50',
                '--allowedTools', $this->getAllowedTools($agent),
                '-p', $this->buildPrompt($agent, $task, $context),
            ]);

        return AgentResult::fromProcess($process, $agent);
    }
}
```

### 3.3 Agent Capabilities Matrix
| Agent | Triggers | Output | Requires Approval |
|-------|----------|--------|-------------------|
| Meeting Parser | New transcript | Action items/tasks | No |
| Invoice Analyzer | Weekly schedule | Invoice drafts | Yes (send) |
| Client Sentiment | New comms | Health score update | No |
| Opportunity Scout | Project completion | Upsell suggestions | Yes (outreach) |
| Content Creator | Project milestone | Case study draft | Yes (publish) |
| Dev Agent | Task assignment | Code changes | No (→ QA) |
| QA Agent | Dev completion | Test results | Yes (merge) |
| Communication | Scheduled/triggered | Email/Slack drafts | Yes (send) |
| **Marketing** | Weekly/quarterly | LinkedIn plans, landing pages | Yes (publish) |
| **WordPress** | Content Creator output | Blog posts with images | Yes (publish) |
| **Bookkeeping** | Invoice/payment events | QuickBooks sync, reports | Yes (financial) |

### 3.4 Proactive Growth Engine
The system should **actively drive business growth**, not just track it:

**Weekly Proactive Actions:**
- Analyze Slack conversations → convert to actionable tasks
- Review completed work → suggest case studies
- Identify client patterns → suggest landing pages targeting that vertical
- Scan client domains → suggest LinkedIn outreach strategies

**Quarterly Analysis:**
- "You worked with 3 fintech clients this quarter → here's a fintech landing page draft"
- "Client X mentioned budget expansion → here's an upsell proposal"
- "Team logged 200 hours on WooCommerce → here's a WooCommerce expertise blog post"

**Content Pipeline:**
```
Work Completed → Content Creator → WordPress Agent → Blog/Case Study
                                 → Marketing Agent → LinkedIn campaign
                                 → Landing page suggestion
```

### 3.5 Agent Chaining
```
Meeting → MeetingParser → Tasks created
                        ↓ (if dev task)
                    DevAgent → QAAgent → ApprovalQueue → Merge
                                                      ↓
                                            CommunicationAgent → Client notified

Project Milestone → ContentCreator → WordPressAgent → Blog published
                                  → MarketingAgent → LinkedIn campaign queued
```

---

## Phase 4: Security & Safety Rails

### 4.1 Vault System
- AES-256-GCM encryption with per-tenant DEKs
- Key hierarchy: KEK (external KMS) → DEKs → Data
- Scoped access: Agents only see secrets they're assigned
- Full access audit trail

### 4.2 Approval Queue Categories
| Category | Risk Level | Auto-Approve Conditions |
|----------|------------|------------------------|
| `deploy.production` | Critical | Never |
| `deploy.staging` | Medium | Tests pass, no breaking changes |
| `financial.invoice` | High | Amount < $500, existing client |
| `database.migration` | Critical | Never |
| `communication.client_email` | Medium | Pre-approved template |
| `communication.cold_outreach` | High | Never |

### 4.3 Emergency Controls
- **Global Kill Switch**: Disables all agents, cancels pending approvals
- **Agent Kill Switch**: Disable individual agent + revoke tokens
- **Circuit Breaker**: Auto-disable after 5 consecutive failures
- **Spend Governor**: Daily cost limit per agent (default $100)
- **Rate Limiter**: Per-minute action limits

### 4.4 Client Portal Isolation
- Separate route group with tenant scoping
- Global scopes filter all queries to client's data
- Blocked from: vault, agents, audit, approvals, admin
- Read-only with comment capability

---

## Phase 5: Dashboard & UI

### 5.1 Main Dashboard (Magical KPIs)
- **KPI cards**: Active projects, Revenue MTD, Hours tracked, Client health
- **Agent status panel**: Running agents, Queue depth, Recent completions
- **Activity feed**: Recent actions, approvals, alerts
- **Quick actions**: Trigger agent, View approvals, Jump to project
- **Proactive Insights Panel** (growth driver, not just CRUD):
  - "Slack: Client X mentioned budget expansion" → Action button
  - "Weekly work summary: 3 WooCommerce projects" → Case study suggestion
  - "Pattern detected: 3 fintech clients this quarter" → Landing page draft
  - "LinkedIn opportunity: 5 prospects match your client profile" → Outreach plan

### 5.2 Core Pages
- `/dashboard` - KPIs + activity
- `/projects` - Project list + detail + milestones
- `/clients` - CRM + sentiment + communications
- `/team` - Staff + one-on-ones + time tracking
- `/agents` - Agent status + runs + history
- `/vault` - Secrets management (owner/admin only)
- `/approvals` - Pending approval queue
- `/integrations` - Connected services status
- `/settings` - Users, permissions, API configs

### 5.3 Client Portal
- `/portal/dashboard` - Project overview
- `/portal/projects/:id` - Progress, milestones, updates
- `/portal/invoices` - Billing history

---

## Phase 6: First Agent Pipeline (Meeting → Action Items)

### 6.1 Flow
1. Google Meet ends → transcript available
2. Webhook triggers `ProcessMeetingTranscript` job
3. Job sends transcript to Meeting Parser Agent
4. Agent extracts: action items, owners, deadlines, categories
5. Agent calls Laravel API to create tasks
6. Tasks appear in dashboard
7. Dev tasks auto-chain to Dev Agent (if enabled)

### 6.2 Implementation Steps
- [ ] Google Calendar webhook for meeting end
- [ ] Google Meet transcript fetch service
- [ ] Meeting Parser agent definition
- [ ] Task creation API endpoint
- [ ] Dashboard task list component
- [ ] Real-time task creation notification

---

## Critical Files

### Core Services
- `app/Services/ClaudeCliRunner.php` - Execute Claude CLI with sandboxing
- `app/Services/AgentOrchestrator.php` - Dispatch, chain, track agents
- `app/Services/AgentSandbox.php` - Filesystem/network isolation
- `app/Services/Vault/VaultService.php` - Encrypted secrets with scoped access
- `app/Services/Approval/ApprovalService.php` - Human-in-the-loop queue
- `app/Services/Emergency/KillSwitchService.php` - Emergency controls

### Agent Definitions
- `app/Agents/Definitions/MeetingParserAgent.php` - Transcript → tasks
- `app/Agents/Definitions/DevAgent.php` - Task → code changes
- `app/Agents/Definitions/QAAgent.php` - Validate + test changes
- `app/Agents/Definitions/MarketingAgent.php` - Proactive growth campaigns
- `app/Agents/Definitions/WordPressAgent.php` - Blog/case study publishing
- `app/Agents/Definitions/BookkeepingAgent.php` - QuickBooks sync

### Agent Skills
- `storage/app/skills/meeting-parser/SKILL.md`
- `storage/app/skills/dev-agent/SKILL.md`
- `storage/app/skills/qa-agent/SKILL.md`

### Queue Jobs
- `app/Jobs/Agents/ExecuteAgentJob.php` - Queue-based agent execution
- `app/Jobs/Agents/ProcessAgentResultJob.php` - Handle completion/chaining

### Config
- `config/agents.php` - Agent definitions, tool allowlists
- `config/approval_policies.php` - Approval rules by category
- `config/horizon.php` - Agent queue supervisor

### Database
- `database/migrations/create_vault_secrets_table.php`
- `database/migrations/create_approval_requests_table.php`
- `database/migrations/create_agent_runs_table.php`
- `database/migrations/create_audit_logs_table.php`

---

## Implementation Order

### Sprint 1: Foundation
1. `laravel new zao-dash` with Inertia + Vue + TS
2. Database models, migrations, seeders
3. Auth with roles (owner/staff/client)
4. Basic CRUD for Projects, Clients, Tasks
5. Linear-style dark mode UI shell

### Sprint 2: Agent Infrastructure
6. Vault system (encrypted secrets)
7. Approval queue (human-in-the-loop)
8. AgentRunner + ClaudeCliRunner services
9. Agent sandbox isolation
10. Horizon queue for agents

### Sprint 3: Meeting → Tasks Pipeline
11. Google OAuth integration
12. Google Meet transcript fetch
13. Meeting Parser agent + SKILL.md
14. Task creation from parsed output
15. Real-time dashboard updates

### Sprint 4: Dev/QA Agents (Second Priority)
16. GitHub OAuth integration
17. Dev Agent + SKILL.md (code changes)
18. QA Agent + SKILL.md (test/validate)
19. Agent chaining (Dev → QA → Approval)
20. Merge workflow with human approval

### Sprint 5: Remaining Integrations
21. Slack OAuth + sync
22. Notion OAuth + sync
23. Harvest API integration
24. Loom transcript integration
25. **Google Drive sync** (MSAs, SOWs, contracts)
26. **WordPress MCP integration** (agency website)
27. **QuickBooks OAuth** (bookkeeping)

### Sprint 6: Remaining Agents
28. Invoice Analyzer agent
29. Client Sentiment agent
30. Communication agent
31. Opportunity Scout agent
32. Content Creator agent
33. **Marketing Agent** (LinkedIn plans, growth campaigns)
34. **WordPress Agent** (blog/case study publishing with images)
35. **Bookkeeping Agent** (QuickBooks sync, financial reports)

### Sprint 7: Proactive Growth Engine
36. Weekly Slack → Action Items automation
37. Quarterly client pattern analysis
38. Auto-generated landing page suggestions
39. LinkedIn outreach plan generation
40. Proactive Insights Panel in dashboard

### Sprint 8: Polish
41. Client portal (isolated)
42. KPI dashboard refinement (magical feel)
43. Mobile responsiveness
44. Notifications system
45. Emergency controls UI

---

## Non-Negotiable Safety Rules

1. **No production deploy without human approval**
2. **No database DROP/TRUNCATE ever (even with approval)**
3. **No external communication without human approval** (except pre-approved templates)
4. **No vault write access for agents**
5. **All actions logged to immutable audit trail**
6. **Kill switch accessible from separate service (Redis)**
7. **Circuit breakers prevent runaway agents**
8. **Client data completely isolated from portal users**
