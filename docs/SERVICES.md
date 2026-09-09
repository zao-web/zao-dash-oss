# Services Documentation

Complete documentation of all service classes in the Zao Dash application.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Core Services](#core-services)
- [Agent Services](#agent-services)
- [AI Services](#ai-services)
- [Integration Services](#integration-services)
- [Analytics Services](#analytics-services)
- [Security Services](#security-services)
- [Business Intelligence Services](#business-intelligence-services)
- [Service Dependencies](#service-dependencies)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Presentation Layer                        │
│                  (Controllers, API, Events)                      │
└────────────┬────────────────────────────────────┬────────────────┘
             │                                    │
             ▼                                    ▼
┌────────────────────────┐          ┌────────────────────────────┐
│   Core Services        │          │  Integration Services      │
│                        │          │                            │
│ - AgentExecutor        │◄─────────┤ - Google (OAuth, APIs)     │
│ - AgentSandbox         │          │ - Slack (OAuth, API)       │
│ - VaultService         │          │ - GitHub (App, API)        │
│ - ApprovalService      │          │ - Harvest (OAuth, API)     │
│ - KillSwitchService    │          │ - Notion (OAuth, API)      │
└───────────┬────────────┘          │ - WordPress (MCP)          │
            │                       │ - QuickBooks (OAuth, API)  │
            │                       └────────────┬───────────────┘
            │                                    │
            ▼                                    ▼
┌────────────────────────┐          ┌────────────────────────────┐
│   AI/Agent Services    │          │  Business Intelligence     │
│                        │          │                            │
│ - ClaudeAgentSdk       │          │ - KpiCalculator            │
│ - ClaudeCliRunner      │          │ - BusinessIntelligence     │
│ - MultiModelConsortium │          │ - CapabilitySynthesis      │
│ - ChainExecutor        │          │ - ProactiveInsights        │
│ - AgentAnalytics       │          │ - QuarterlyPattern         │
└────────────────────────┘          └────────────────────────────┘
```

---

## Core Services

### AgentSandbox
**Location:** `app/Services/AgentSandbox.php`

**Purpose:** Manages sandboxed execution environments for agents with filesystem and network isolation.

**Key Methods:**
- `create(AgentRun $run, ?Agent $agent): string` - Create isolated workspace
- `isPathAllowed(string $sandboxPath, string $targetPath): bool` - Validate file access
- `validateWrite(string $sandboxPath, string $targetPath, int $sizeBytes): bool` - Validate write operations
- `getAllowedDomains(?Agent $agent): array` - Get permitted network domains
- `isUrlAllowed(string $url, ?Agent $agent): bool` - Validate network requests
- `httpGet(string $url, ?Agent $agent): ?string` - Make sandboxed HTTP requests
- `getEnvironment(AgentRun $run, array $secrets): array` - Build isolated environment variables
- `copyIn(AgentRun $run, string $sourcePath, ?string $destName): string` - Copy files into sandbox
- `getOutputFiles(AgentRun $run): array` - Retrieve agent outputs
- `cleanup(AgentRun $run, bool $preserveOutput): void` - Clean up workspace
- `cleanupOld(): int` - Cleanup old sandboxes (scheduled)

**Configuration:**
```php
// config/agents.php
'sandbox' => [
    'base_path' => storage_path('app/agent-workspaces'),
    'max_file_size_mb' => 50,
    'cleanup_after_hours' => 24,
],
'domains' => [
    '_default' => ['api.anthropic.com'],
    'web-scraper' => ['*.example.com'],
],
```

**Security Features:**
- Filesystem isolation with blocked patterns (credentials, secrets, config)
- Network domain allowlisting
- File size limits
- Automatic cleanup
- Audit trail

---

### AgentOutputRouter
**Location:** `app/Services/AgentOutputRouter.php`

**Purpose:** Routes agent outputs to appropriate destinations (WordPress, Slack, Email).

**Key Methods:**
- `route(AgentRun $run): array` - Route output based on agent type
- `registerRule(string $agentSlug, array $destinations): void` - Add custom routing
- `getRulesFor(string $agentSlug): array` - Get agent routing rules

**Routing Rules:**
```php
'landing-page-generator' => ['wordpress_draft'],
'case-study-writer' => ['wordpress_draft'],
'content-scheduler' => ['wordpress_draft', 'slack_notify'],
'lead-nurture' => ['email_draft', 'slack_notify'],
'upsell-proposal' => ['email_draft', 'slack_notify'],
'client-health-monitor' => ['slack_alert', 'notification'],
```

**Supported Destinations:**
- `wordpress_draft` - Create draft post in WordPress
- `email_draft` - Store email draft in storage
- `slack_notify` - Send Slack message to workspace
- `slack_alert` - Send Slack alert to designated channel
- `notification` - Create in-app notification

---

## Agent Services

### AgentExecutor
**Location:** `app/Services/Agents/AgentExecutor.php`

**Purpose:** Orchestrates agent execution with validation, approval flow, and status management.

**Key Methods:**
- `execute(Agent $agent, array $config, string $source, ?string $invokedBy, array $metadata): AgentRun` - Execute agent
- `executeApproved(AgentRun $run, array $config): AgentRun` - Execute after approval
- `executeChained(Agent $agent, AgentRun $previousRun, array $config): AgentRun` - Chain execution
- `dryRun(Agent $agent, array $config): array` - Preview execution without running
- `cancel(AgentRun $run): AgentRun` - Cancel pending run
- `resetCircuitBreaker(Agent $agent): void` - Reset after failures

**Execution Flow:**
1. Validate agent state (active, not circuit broken)
2. Create run record
3. Check if approval required
4. Execute via SDK or CLI
5. Post-process output via definition
6. Handle chaining
7. Route output to destinations
8. Update status and broadcast events

**Dependencies:**
- `ClaudeCliRunner` - CLI execution
- `ClaudeAgentSdk` - SDK/API execution
- `MultiModelConsortium` - Multi-model consensus
- `AgentOutputRouter` - Output routing
- `ChainExecutor` - Chain orchestration

---

### ClaudeAgentSdk
**Location:** `app/Services/Agents/ClaudeAgentSdk.php`

**Purpose:** Direct Anthropic API integration for programmatic control with full tool use support.

**Key Methods:**
- `execute(Agent $agent, AgentRun $run, array $config): ExecutionResult` - Execute agent with agentic loop
- `callApi(string $model, string $systemPrompt, array $messages, array $tools, array $config): array` - Call Claude API
- `executeToolCall(Agent $agent, array $toolUse, AgentRun $run): mixed` - Execute tool

**Benefits over CLI:**
- Native streaming support
- Full tool use control
- Better cost/token tracking
- Session/conversation management
- Programmatic tool execution

**Agentic Loop:**
1. Send message with tools
2. Process response (text + tool_use blocks)
3. Execute tool calls
4. Send tool results back
5. Repeat until end_turn or max iterations (25)

**Built-in Tools:**
- `web_search` - Search the web
- `read_file` - Read workspace files
- `write_file` - Write workspace files
- `api_call` - HTTP API requests
- `create_task` - Create Zao Dash tasks
- `send_notification` - Send notifications
- `query_database` - Query Zao Dash data

**Token Costs (per million):**
```php
'claude-opus-4-20250514' => ['input' => 15.0, 'output' => 75.0],
'claude-sonnet-4-20250514' => ['input' => 3.0, 'output' => 15.0],
'claude-3-5-haiku-20241022' => ['input' => 0.80, 'output' => 4.0],
```

---

### ClaudeCliRunner
**Location:** `app/Services/Agents/ClaudeCliRunner.php`

**Purpose:** Executes agents via Claude CLI in sandboxed workspaces.

**Key Methods:**
- `execute(Agent $agent, AgentRun $run, array $config): ExecutionResult` - Execute via CLI
- `createWorkspace(AgentRun $run): string` - Create isolated workspace
- `cleanupWorkspace(string $workspace): void` - Remove workspace
- `buildCommand(Agent $agent, string $prompt, string $workspace): string` - Build CLI command

**Features:**
- Workspace isolation
- Tool allowlist enforcement
- Process timeouts (default 5min, max 30min)
- Cost and token tracking
- Output parsing

**Tool Mapping:**
```php
'web_search' => 'WebSearch',
'code_exec' => 'Bash',
'file_ops' => 'Read,Write,Edit',
'api_calls' => 'WebFetch',
```

---

### ChainExecutor
**Location:** `app/Services/Agents/ChainExecutor.php`

**Purpose:** Orchestrates execution of agent chains with step sequencing and condition evaluation.

**Key Methods:**
- `startChain(AgentChain $chain, string $initialInput, string $triggeredBy, array $metadata): AgentChainRun` - Start chain
- `startFromTemplate(string $templateKey, string $initialInput, string $triggeredBy): ?AgentChainRun` - Start from template
- `executeNextStep(AgentChainRun $chainRun): void` - Execute next step
- `handleStepCompletion(AgentRun $run): void` - Process completed step
- `cancelChain(AgentChainRun $chainRun): void` - Cancel running chain
- `retryChain(AgentChainRun $chainRun): void` - Retry from failed step
- `validateChain(AgentChain $chain): array` - Validate all agents exist

**Chain Templates:**
- Content creation workflows
- Lead nurture sequences
- Client onboarding flows
- Reporting pipelines

---

### AgentAnalyticsService
**Location:** `app/Services/Agents/AgentAnalyticsService.php`

**Purpose:** Tracks cost, usage, performance, and ROI metrics for agents.

**Key Methods:**
- `getDashboardAnalytics(int $days): array` - Comprehensive analytics
- `getSummaryMetrics(Carbon $startDate): array` - Summary stats
- `getCostTrend(Carbon $startDate): array` - Daily cost trend
- `getUsageTrend(Carbon $startDate): array` - Usage by status
- `getByAgentMetrics(Carbon $startDate): array` - Per-agent breakdown
- `getBySourceMetrics(Carbon $startDate): array` - By invocation source
- `getTopPerformers(Carbon $startDate, int $limit): array` - Best performing agents
- `getFailureAnalysis(Carbon $startDate): array` - Failure patterns
- `getTokenUsage(Carbon $startDate): array` - Token consumption
- `getAgentAnalytics(Agent $agent, int $days): array` - Single agent deep dive

**Metrics Tracked:**
- Total runs, success rate, failure rate
- Cost (total, average, by model)
- Token usage (input, output, total)
- Performance trends
- Error categorization
- Daily/weekly/monthly aggregates

---

### ExecutionResult
**Location:** `app/Services/Agents/ExecutionResult.php`

**Purpose:** Immutable value object representing agent execution results.

**Properties:**
```php
readonly class ExecutionResult {
    bool $success
    array $output
    string $rawOutput
    string $errorOutput
    int $exitCode
    float $durationSeconds
    int $tokensUsed
    float $costUsd
    ?string $workspace
    ?int $inputTokens
    ?int $outputTokens
    ?int $durationMs
}
```

**Methods:**
- `succeeded(): bool` - Check if successful
- `failed(): bool` - Check if failed
- `errorMessage(): ?string` - Get error message
- `toArray(): array` - Convert to array for storage

---

## AI Services

### AnthropicService
**Location:** `app/Services/AI/AnthropicService.php`

**Purpose:** Anthropic Claude API service with streaming support.

**Key Methods:**
- `message(string $prompt, ?string $systemPrompt, array $context, ?string $model, array $tools): array` - Send message
- `messageWithTools(string $prompt, ?string $systemPrompt, array $context, array $tools, callable $toolExecutor, ?string $model, int $maxIterations): array` - Message with automatic tool handling
- `streamMessage(string $prompt, ?string $systemPrompt, array $context, ?string $model): Generator` - Stream response

**Default Model:** `claude-sonnet-4-20250514`

**Use Cases:**
- Command palette AI assistant
- Single-model agent execution
- Tool-enabled conversations
- Reasoning agent in consortium

---

### OpenAIService
**Location:** `app/Services/AI/OpenAIService.php`

**Purpose:** OpenAI GPT API service for multi-model consortium.

**Key Methods:**
- `message(string $prompt, ?string $systemPrompt, array $context, ?string $model): array` - Send message

**Default Model:** `gpt-4o`

**Use Cases:**
- Multi-model consortium member
- Alternative AI provider
- Cross-validation

---

### GeminiService
**Location:** `app/Services/AI/GeminiService.php`

**Purpose:** Google Gemini API service for multi-model consortium.

**Key Methods:**
- `message(string $prompt, ?string $systemPrompt, array $context, ?string $model): array` - Send message

**Default Model:** `gemini-1.5-pro`

**Use Cases:**
- Multi-model consortium member
- Cost optimization (lower cost per token)

---

### MultiModelConsortium
**Location:** `app/Services/AI/MultiModelConsortium.php`

**Purpose:** Multi-model consensus for high-stakes outputs with hallucination reduction.

**Key Methods:**
- `generate(string $prompt, ?string $systemPrompt, array $context, array $options): ConsortiumResult` - Generate with consensus
- `getAvailableProviders(): array` - List enabled providers
- `isAvailable(): bool` - Check if 2+ providers configured

**How It Works:**
1. Query all enabled providers in parallel (Claude, GPT, Gemini)
2. Collect individual responses
3. Use Claude as reasoning agent to consolidate
4. Resolve conflicts through agreement analysis
5. Return consolidated output with confidence score

**Consolidation Process:**
- Identifies areas of agreement (factual alignment)
- Resolves conflicts between responses
- Removes hallucinations (claims only one model makes)
- Combines best elements from each response
- Assigns confidence score based on agreement level

**Options:**
```php
'min_agreement' => 2,          // Minimum providers needed
'use_reasoning' => true,        // Use reasoning consolidation
```

**Output:**
```php
class ConsortiumResult {
    string $content              // Consolidated output
    bool $consolidated           // Whether consolidation occurred
    array $providers            // ['claude', 'gpt', 'gemini']
    array $individualResponses  // Original responses
    float $confidence          // 0.0-1.0
    string $reasoning         // Consolidation reasoning
    array $conflicts         // Identified disagreements
}
```

---

## Integration Services

### Google Services

#### GoogleOAuthService
**Location:** `app/Services/Google/GoogleOAuthService.php`

**Purpose:** OAuth 2.0 authentication flow for Google services.

**Scopes:**
- Gmail (read, modify)
- Calendar (read)
- Drive (read)
- Search Console (read)
- Analytics (read)
- User info (email, profile)

**Key Methods:**
- `getAuthUrl(string $state): string` - Generate authorization URL
- `exchangeCodeForTokens(string $code): array` - Exchange code for tokens
- `refreshAccessToken(GoogleCredential $credential): GoogleCredential` - Refresh expired token
- `getUserInfo(string $accessToken): array` - Fetch user profile
- `storeCredentials(User $user, array $tokens): GoogleCredential` - Store credentials
- `getValidAccessToken(User $user): ?string` - Get valid token (auto-refresh)
- `revokeAccess(GoogleCredential $credential): bool` - Revoke access

#### GmailService
**Location:** `app/Services/Google/GmailService.php`

**Purpose:** Interact with Gmail API.

**Key Methods:**
- Fetch emails
- Parse email content
- Filter by date, sender, label
- Extract action items from emails

#### CalendarService
**Location:** `app/Services/Google/CalendarService.php`

**Purpose:** Interact with Google Calendar API.

**Key Methods:**
- Fetch calendar events
- Filter by date range
- Sync to local database

#### SearchConsoleService
**Location:** `app/Services/Google/SearchConsoleService.php`

**Purpose:** Fetch SEO data from Google Search Console.

**Key Methods:**
- Get search analytics
- Query performance data
- Track ranking changes

---

### Slack Services

#### SlackOAuthService
**Location:** `app/Services/Slack/SlackOAuthService.php`

**Purpose:** OAuth 2.0 authentication for Slack workspaces.

**Scopes:**
- Channel/group history and read
- IM/MPIM history and read
- Users read (email)
- Chat write
- Commands
- Reactions read

**Key Methods:**
- `getAuthUrl(string $state): string` - Generate auth URL
- `exchangeCodeForTokens(string $code): array` - Exchange code
- `storeWorkspace(array $tokenData): SlackWorkspace` - Store workspace
- `revokeAccess(SlackWorkspace $workspace): bool` - Revoke access
- `testConnection(SlackWorkspace $workspace): bool` - Test connection

#### SlackApiService
**Location:** `app/Services/Slack/SlackApiService.php`

**Purpose:** Interact with Slack API.

**Key Methods:**
- Fetch channels, messages, threads
- Post messages
- Parse action items from conversations
- Detect client signals (budget mentions, expansion)

#### SlackActionItemService
**Location:** `app/Services/Slack/SlackActionItemService.php`

**Purpose:** Extract action items and requests from Slack messages.

**Pattern Detection:**
- "can you", "could you", "please"
- Task-related keywords
- Deadline mentions
- Client requests

---

### GitHub Services

#### GitHubAppService
**Location:** `app/Services/GitHub/GitHubAppService.php`

**Purpose:** GitHub App installation and authentication.

**Key Methods:**
- Install GitHub App on repositories
- Generate installation tokens
- Manage webhooks

#### GitHubApiService
**Location:** `app/Services/GitHub/GitHubApiService.php`

**Purpose:** Interact with GitHub API.

**Key Methods:**
- Fetch repositories
- Fetch pull requests, issues
- Track code deployments
- Monitor repo activity

---

### Harvest Services

#### HarvestOAuthService
**Location:** `app/Services/Harvest/HarvestOAuthService.php`

**Purpose:** OAuth 2.0 authentication for Harvest time tracking.

**Key Methods:**
- OAuth flow
- Token management
- Credential storage

#### HarvestApiService
**Location:** `app/Services/Harvest/HarvestApiService.php`

**Purpose:** Sync time entries, projects, invoices from Harvest.

**Key Methods:**
- Fetch time entries
- Fetch projects
- Fetch invoices
- Calculate billable hours
- Track profitability

---

### Notion Services

#### NotionOAuthService
**Location:** `app/Services/Notion/NotionOAuthService.php`

**Purpose:** OAuth 2.0 authentication for Notion workspace.

#### NotionApiService
**Location:** `app/Services/Notion/NotionApiService.php`

**Purpose:** Interact with Notion API.

**Key Methods:**
- Fetch databases
- Fetch pages
- Query database items
- Parse page content

---

### WordPress Services

#### WordPressMcpService
**Location:** `app/Services/WordPress/WordPressMcpService.php`

**Purpose:** Interact with WordPress via MCP (Model Context Protocol).

**Key Methods:**
- Create draft posts
- Publish content
- Manage WordPress sites
- Agent-generated content routing

---

### QuickBooks Services

#### QuickBooksOAuthService
**Location:** `app/Services/QuickBooks/QuickBooksOAuthService.php`

**Purpose:** OAuth 2.0 authentication for QuickBooks Online.

#### QuickBooksApiService
**Location:** `app/Services/QuickBooks/QuickBooksApiService.php`

**Purpose:** Sync financial data from QuickBooks.

**Key Methods:**
- Fetch invoices
- Fetch customers
- Fetch accounts
- Fetch transactions
- Calculate revenue metrics

---

## Analytics Services

### KpiCalculator
**Location:** `app/Services/Analytics/KpiCalculator.php`

**Purpose:** Calculates KPIs for dashboard and reporting with caching support.

**Key Methods:**
- `getDashboardKpis(?Carbon $from, ?Carbon $to): array` - All dashboard KPIs
- `getRevenue(?Carbon $from, ?Carbon $to): array` - Revenue metrics
- `getProjectStats(?Carbon $from, ?Carbon $to): array` - Project KPIs
- `getClientStats(?Carbon $from, ?Carbon $to): array` - Client KPIs
- `getPipelineStats(?Carbon $from, ?Carbon $to): array` - Sales pipeline
- `getTeamStats(?Carbon $from, ?Carbon $to): array` - Team utilization
- `getAgentStats(?Carbon $from, ?Carbon $to): array` - Agent performance
- `getGoalProgress(): array` - Strategic goal tracking

**Revenue Metrics:**
- Invoiced MTD
- Paid MTD
- Outstanding
- Period-over-period change

**Project Metrics:**
- Active projects
- Completed this period
- At-risk projects
- Average health score

**Client Metrics:**
- Total active
- New clients
- Health distribution (healthy/moderate/at-risk)
- Average lifetime value

**Pipeline Metrics:**
- Pipeline value
- Leads created/won/lost
- Win rate
- Average deal size
- Average sales cycle days

**Team Metrics:**
- Total hours
- Billable hours
- Utilization rate
- Hours by project

**Agent Metrics:**
- Total runs
- Success rate
- Total cost
- Pending approvals
- Top agents by volume

**Caching:**
- TTL: 5 minutes
- Cache key pattern: `dashboard_kpis_{from}_{to}`
- Manual cache clear: `clearCache()`

---

## Security Services

### VaultService
**Location:** `app/Services/Vault/VaultService.php`

**Purpose:** Secure credential storage with access control and audit logging.

**Key Methods:**
- `get(string $key, ?User $user, ?string $agentSlug, array $context): ?string` - Get secret
- `getMany(array $keys, ?User $user, ?string $agentSlug): array` - Get multiple secrets
- `store(string $key, string $value, string $name, array $options): VaultSecret` - Store secret
- `update(VaultSecret $secret, string $newValue, ?User $user): bool` - Update secret
- `rotate(VaultSecret $secret, string $newValue, ?User $user): bool` - Rotate secret
- `delete(VaultSecret $secret, ?User $user): bool` - Delete secret
- `getAgentSecrets(string $agentSlug, ?int $projectId, ?int $clientId): array` - Get secrets for agent
- `exists(string $key, ?User $user, ?string $agentSlug): bool` - Check existence
- `list(?User $user, ?string $category): array` - List accessible secrets

**Secret Categories:**
```php
const CATEGORY_API_KEY = 'api_key';
const CATEGORY_DATABASE = 'database';
const CATEGORY_OAUTH = 'oauth';
const CATEGORY_WEBHOOK = 'webhook';
const CATEGORY_OTHER = 'other';
```

**Access Control:**
- User-based permissions
- Agent allowlists
- Project/client scoping
- Global vs scoped secrets

**Audit Trail:**
```php
VaultAccessLog::log(
    $secret,
    $action,        // read, write, rotate, delete
    $accessorType,  // user, agent, system
    $userId,
    $accessorName,
    $success,
    $failureReason,
    $context
);
```

**Security Features:**
- Encrypted storage (Laravel Crypt)
- Access logging
- Expiration support
- Rotation tracking
- Sensitive flag for extra logging

---

### ApprovalService
**Location:** `app/Services/Approval/ApprovalService.php`

**Purpose:** Manages human-in-the-loop approval queue for agent actions.

**Key Methods:**
- `createRequest(AgentRun $run, string $category, string $title, array $payload, ?string $description): ApprovalRequest` - Create approval request
- `approve(ApprovalRequest $approval, User $approver, ?string $notes): ApprovalRequest` - Approve request
- `reject(ApprovalRequest $approval, User $rejector, ?string $reason): ApprovalRequest` - Reject request
- `cancel(ApprovalRequest $approval, ?string $reason): ApprovalRequest` - Cancel request
- `getPending(?string $category, ?string $riskLevel, ?int $limit): Collection` - Get pending approvals
- `escalateStale(): int` - Escalate old approvals
- `expireOld(): int` - Expire timed-out approvals
- `canAutoApprove(string $category, array $context): bool` - Check auto-approval
- `getStats(): array` - Approval statistics

**Risk Levels:**
- `critical` - Requires 2FA, urgent notification
- `high` - Multi-channel notification
- `medium` - Standard notification
- `low` - Database only

**Auto-Approval Conditions:**
```php
'amount_under' => ($context['amount'] ?? PHP_INT_MAX) < $value,
'existing_client' => !empty($context['client_id']),
'tests_pass' => ($context['tests_passed'] ?? false) === true,
'no_breaking_changes' => ($context['breaking_changes'] ?? true) === false,
'pre_approved_template' => in_array($context['template_id'] ?? null, (array)$value),
```

**Policy Configuration:**
```php
// config/approval_policies.php
'categories' => [
    'email_send' => [
        'risk_level' => 'medium',
        'requires_approval' => true,
        'expires_hours' => 24,
        'notify_roles' => ['owner', 'admin'],
        'auto_approve_conditions' => [
            'amount_under' => 100,
            'existing_client' => true,
        ],
    ],
],
```

**Escalation:**
- Escalate after N hours (default: 12)
- Notify higher roles
- Track escalation timestamp
- Prevent duplicate escalations

---

### KillSwitchService
**Location:** `app/Services/Emergency/KillSwitchService.php`

**Purpose:** Emergency stop mechanism for agent activity with spend limits.

**Key Methods:**
- `activateGlobal(string $reason, ?int $userId): void` - Stop ALL agents
- `deactivateGlobal(?int $userId): void` - Resume agents
- `isGlobalKillActive(): bool` - Check global status
- `getGlobalStatus(): ?array` - Get global kill details
- `killAgent(Agent $agent, string $reason, ?int $userId): void` - Kill specific agent
- `reviveAgent(Agent $agent, ?int $userId): void` - Revive killed agent
- `canAgentRun(Agent $agent): array` - Check if agent can execute
- `recordSpend(Agent $agent, float $amount): bool` - Record spend and check limit
- `getAgentDailySpend(Agent $agent): float` - Get daily spend
- `getTotalDailySpend(): float` - System-wide spend
- `healthCheck(): array` - Auto-trigger circuit breakers

**Global Kill Switch:**
When activated:
- Cancel all pending approvals
- Cancel all running agent runs
- Clear agent queue
- Block new executions
- Log emergency action

**Agent-Level Kill:**
When activated:
- Set circuit breaker
- Disable agent
- Cancel running runs
- Cancel pending approvals

**Spend Limits:**
```php
const DEFAULT_DAILY_LIMIT = 100.00; // Per agent
```

**Health Check (Auto-Circuit Breaking):**
- 3+ consecutive failures → kill agent
- Total spend > $500 → warning
- Runs > 30min → kill stuck run

**Usage:**
```php
// Emergency stop
$killSwitch->activateGlobal('Runaway costs detected', auth()->id());

// Check before execution
$check = $killSwitch->canAgentRun($agent);
if (!$check['allowed']) {
    throw new Exception($check['reason']);
}

// Record spend
$killSwitch->recordSpend($agent, 0.15);
```

---

## Business Intelligence Services

### BusinessIntelligenceService
**Location:** `app/Services/BusinessIntelligenceService.php`

**Purpose:** Calculate funnel metrics, goal progress, and strategic insights.

**Key Methods:**
- `calculateFunnelMetrics(Carbon $startDate, Carbon $endDate): array` - Funnel conversion rates
- `storeFunnelSnapshot(string $periodType, Carbon $periodStart, Carbon $periodEnd): FunnelMetrics` - Store snapshot
- `calculateRequiredLeadsPerWeek(StrategicGoal $goal): array` - Backsolve to goal
- `calculateProgress(StrategicGoal $goal): array` - Goal progress vs target
- `identifyLevers(StrategicGoal $goal): array` - Actions to close gap
- `analyzeCapacity(StrategicGoal $goal): array` - Team capacity analysis
- `forecastRevenue(StrategicGoal $goal, int $days): array` - Revenue forecast
- `updateGoalActuals(StrategicGoal $goal): void` - Sync actuals from data

**Funnel Stages:**
```php
const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
```

**Stage Probability Weights:**
```php
'new' => 0.10,
'qualified' => 0.25,
'proposal' => 0.50,
'negotiation' => 0.75,
```

**Metrics Calculated:**
- Conversion rates at each stage
- Overall win rate
- Average deal size
- Average sales cycle days
- Pipeline value (total and weighted)

**Levers to Pull (when behind):**
1. Increase lead generation
2. Improve win rate
3. Increase average deal size
4. Accelerate sales cycle
5. Reactivate lost opportunities

**Capacity Analysis:**
- Lead generation capacity
- Pipeline management capacity
- Overload detection
- Recommendations

**Forecasting:**
- Velocity-based (current pace)
- Pipeline-based (expected closes)
- Blended forecast
- 30/60/90 day projections
- Year-end forecast

---

### CapabilitySynthesisService
**Location:** `app/Services/CapabilitySynthesisService.php`

**Purpose:** Synthesize what requires human attention vs what's automated.

**Key Methods:**
- `getHumanRequiredItems(?int $userId): array` - Items needing human action
- `getCapabilitySummary(): array` - What's automated vs manual
- `getMorningBriefing(): array` - Daily briefing for humans
- `getAutomationGaps(): array` - Areas for automation improvement

**Human-Required Items:**
1. Pending approvals (agent actions)
2. Content suggestions (AI-generated)
3. Pull requests needing review
4. Overdue invoices
5. Stale leads (no contact in 7+ days)
6. At-risk clients (health < 60)
7. Unassigned tasks

**Priority Levels:**
- `critical` - Immediate action required
- `high` - Important, time-sensitive
- `medium` - Standard priority
- `low` - Can wait

**Morning Briefing:**
```php
[
    'greeting' => 'Good morning',
    'summary' => [
        'total_items' => 12,
        'critical' => 2,
        'high' => 5,
        'medium' => 5,
    ],
    'by_type' => [...],
    'top_priorities' => [...],  // Top 5
    'recommendations' => [...],
]
```

**Automation Coverage:**
```php
'automated' => [
    'email_parsing' => true,
    'calendar_sync' => true,
    'time_tracking' => true,
    'invoice_sync' => true,
    'github_monitoring' => true,
    'slack_notifications' => true,
    'content_suggestions' => true,
],
'manual_required' => [
    'approvals' => 'Agent actions requiring authorization',
    'content_review' => 'AI content before publishing',
    'client_calls' => 'Direct client communication',
    'strategic_decisions' => 'Business strategy',
    'contract_signing' => 'Legal execution',
    'hiring' => 'Team decisions',
],
```

---

### ProactiveInsightsService
**Location:** `app/Services/ProactiveInsightsService.php`

**Purpose:** Generate actionable insights from patterns in data.

**Key Methods:**
- `getInsights(int $limit): array` - Get all insights (sorted by priority)

**Insight Sources:**
1. **Slack signals** - Budget mentions, expansion, risk keywords
2. **Client patterns** - Vertical concentration, repeat business
3. **Project opportunities** - Case studies from completed work
4. **Lead signals** - Hot leads, stale high-value leads
5. **Client health** - At-risk clients (health < 50)
6. **Quarterly patterns** - Industry trends, service patterns

**Insight Structure:**
```php
[
    'type' => 'slack_opportunity',
    'title' => 'Acme Corp mentioned "budget"',
    'subtitle' => 'Detected 2 hours ago in #acme-corp',
    'action' => 'upsell',
    'action_label' => 'Draft upsell proposal',
    'agent_slug' => 'upsell-proposal',
    'priority' => 80,               // 0-100
    'recency_score' => 25,          // 0-30
    'metadata' => [...],
]
```

**Priority Calculation:**
```
total_score = priority + recency_score
```

**Recency Scoring:**
- < 6 hours: 30 points
- < 24 hours: 25 points
- < 3 days: 20 points
- < 1 week: 15 points
- Older: 10 points

**Opportunity Keywords:**
- `budget`, `expansion`, `growth`, `new project`, `additional`, `more work`, `scale`

**Risk Keywords:**
- `unhappy`, `frustrated`, `delay`, `issue`, `problem`, `concerned`, `disappointed`

---

### QuarterlyPatternAnalysisService
**Location:** `app/Services/QuarterlyPatternAnalysisService.php`

**Purpose:** Analyze quarterly patterns to inform content and outreach strategies.

**Key Methods:**
- `analyze(?Carbon $quarterStart): array` - Full quarterly analysis
- `analyzeIndustryPatterns(Carbon $start, Carbon $end): array` - Which industries served
- `analyzeServicePatterns(Carbon $start, Carbon $end): array` - Service types delivered
- `analyzeTechnologyPatterns(Carbon $start, Carbon $end): array` - Technologies used
- `analyzeClientGrowthPatterns(Carbon $start, Carbon $end): array` - Growth metrics
- `generateContentSuggestions(Carbon $start, Carbon $end): array` - Content ideas
- `generateLandingPageSuggestions(Carbon $start, Carbon $end): array` - Landing pages to create
- `generateOutreachSuggestions(Carbon $start, Carbon $end): array` - Outreach targets
- `saveSuggestions(array $analysis): int` - Save to database

**Analysis Output:**
```php
[
    'period' => ['start' => '2025-01-01', 'end' => '2025-03-31', 'label' => 'Q1 2025'],
    'industry_patterns' => [...],
    'service_patterns' => [...],
    'technology_patterns' => [...],
    'client_growth_patterns' => [...],
    'content_suggestions' => [...],
    'landing_page_suggestions' => [...],
    'outreach_suggestions' => [...],
]
```

**Content Suggestions:**
Types:
- `case_study` - Industry success stories (2+ clients)
- `blog_post` - Service expertise deep dive (50+ hours)
- `tutorial` - Technology best practices (2+ projects)

**Landing Page Suggestions:**
Generated when:
- 3+ projects in same industry
- Strong revenue track record
- Multiple satisfied clients

Format: "WordPress for {Industry}"

**Outreach Suggestions:**
Generated when:
- 2+ clients in industry with good results
- Suggests ICP (Ideal Customer Profile)
- Vertical-specific campaign approach

---

### HealthAlertEscalationService
**Location:** `app/Services/HealthAlertEscalationService.php`

**Purpose:** Create and escalate health alerts for at-risk clients.

**Key Methods:**
- `createAlertFromHealthDrop(Client $client, float $oldScore, float $newScore): HealthAlert` - Create alert
- `processEscalations(): array` - Process all pending escalations
- `escalateAlert(HealthAlert $alert): void` - Escalate single alert
- `acknowledgeAlert(HealthAlert $alert, User $user): void` - Acknowledge alert
- `resolveAlert(HealthAlert $alert, User $user, ?string $note): void` - Resolve alert
- `getDashboardSummary(): array` - Alert statistics

**Severity Levels:**
```php
SEVERITY_CRITICAL    // < 40
SEVERITY_HIGH        // 40-59
SEVERITY_MEDIUM      // 60-79
SEVERITY_LOW         // 80+
```

**Escalation Levels:**
```php
LEVEL_TEAM_MEMBER    // 1
LEVEL_TEAM_LEAD      // 2
LEVEL_MANAGER        // 3
LEVEL_DIRECTOR       // 4
LEVEL_EXECUTIVE      // 5
```

**Escalation Flow:**
1. Alert created at health drop
2. Notify appropriate level
3. If no response in N hours, escalate
4. Notify new level + inform previous level
5. Continue until resolved or max level

**Dashboard Summary:**
```php
[
    'open_alerts' => 5,
    'critical_alerts' => 2,
    'pending_escalation' => 1,
    'escalated_today' => 3,
    'resolved_today' => 8,
    'by_severity' => [
        'critical' => 2,
        'high' => 3,
        'medium' => 0,
        'low' => 0,
    ],
    'at_risk_clients' => 2,  // Distinct clients with critical/high alerts
]
```

---

## Service Dependencies

### Dependency Injection Patterns

**Constructor Injection (Preferred):**
```php
class AgentExecutor
{
    public function __construct(
        protected ClaudeCliRunner $runner,
        protected ?ClaudeAgentSdk $sdk = null,
        protected ?MultiModelConsortium $consortium = null,
        protected ?AgentOutputRouter $outputRouter = null,
    ) {}
}
```

**Service Container Resolution:**
```php
// In service provider
$this->app->singleton(VaultService::class);
$this->app->singleton(KillSwitchService::class);

// Usage
$vault = app(VaultService::class);
$killSwitch = app(KillSwitchService::class);
```

**Facade Pattern:**
```php
// Not currently used, but could be implemented
use Facades\App\Services\VaultService as Vault;
Vault::get('api_key');
```

### Key Service Relationships

```
AgentExecutor
├── requires: ClaudeCliRunner
├── requires: ClaudeAgentSdk
├── optional: MultiModelConsortium
├── optional: AgentOutputRouter
└── optional: ChainExecutor (circular, set via setter)

ClaudeAgentSdk
└── uses: AnthropicService (API client)

MultiModelConsortium
├── requires: AnthropicService
├── requires: OpenAIService
└── requires: GeminiService

AgentOutputRouter
├── optional: WordPressMcpService
├── optional: SlackApiService
└── creates: Notification

ApprovalService
└── triggers: NotificationCreated events

KpiCalculator
├── queries: QboInvoice
├── queries: HarvestInvoice
├── queries: TimeEntry
├── queries: Lead
├── queries: Project
├── queries: Client
├── queries: AgentRun
└── queries: StrategicGoal

BusinessIntelligenceService
├── queries: Lead
├── queries: QboInvoice
├── queries: Project
├── queries: TimeEntry
└── uses: FunnelMetrics model

CapabilitySynthesisService
├── queries: ApprovalRequest
├── queries: ContentSuggestion
├── queries: GitHubPullRequest
├── queries: QboInvoice
├── queries: Lead
├── queries: Client
├── queries: Task
└── uses: QuarterlyPatternAnalysisService

ProactiveInsightsService
├── queries: SlackMessage
├── queries: Client
├── queries: Project
├── queries: Lead
└── uses: QuarterlyPatternAnalysisService

VaultService
├── uses: Crypt facade
└── creates: VaultAccessLog
```

### Service Layer Architecture

**Layer 1: Core Infrastructure**
- VaultService
- KillSwitchService
- AgentSandbox

**Layer 2: Execution**
- AgentExecutor
- ClaudeAgentSdk
- ClaudeCliRunner
- ChainExecutor

**Layer 3: AI/LLM**
- AnthropicService
- OpenAIService
- GeminiService
- MultiModelConsortium

**Layer 4: Integration**
- Google services
- Slack services
- GitHub services
- Harvest services
- Notion services
- WordPress services
- QuickBooks services

**Layer 5: Business Logic**
- ApprovalService
- AgentOutputRouter
- KpiCalculator
- BusinessIntelligenceService
- CapabilitySynthesisService
- ProactiveInsightsService
- QuarterlyPatternAnalysisService
- HealthAlertEscalationService
- AgentAnalyticsService

---

## Configuration

### Environment Variables

```env
# Anthropic
ANTHROPIC_API_KEY=sk-ant-...

# OpenAI
OPENAI_API_KEY=sk-...

# Google
GEMINI_API_KEY=...
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://app.example.com/auth/google/callback

# Slack
SLACK_CLIENT_ID=...
SLACK_CLIENT_SECRET=...
SLACK_REDIRECT_URI=https://app.example.com/auth/slack/callback

# GitHub
GITHUB_APP_ID=...
GITHUB_APP_PRIVATE_KEY=...
GITHUB_WEBHOOK_SECRET=...

# Harvest
HARVEST_CLIENT_ID=...
HARVEST_CLIENT_SECRET=...

# Notion
NOTION_CLIENT_ID=...
NOTION_CLIENT_SECRET=...

# QuickBooks
QUICKBOOKS_CLIENT_ID=...
QUICKBOOKS_CLIENT_SECRET=...
```

### Service Configuration Files

**config/services.php**
```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
],
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
],
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    'gemini_api_key' => env('GEMINI_API_KEY'),
],
'slack' => [
    'client_id' => env('SLACK_CLIENT_ID'),
    'client_secret' => env('SLACK_CLIENT_SECRET'),
    'redirect_uri' => env('SLACK_REDIRECT_URI'),
],
// ... other integrations
```

**config/agents.php**
```php
'sandbox' => [
    'base_path' => storage_path('app/agent-workspaces'),
    'max_file_size_mb' => 50,
    'cleanup_after_hours' => 24,
],
'domains' => [
    '_default' => ['api.anthropic.com'],
],
```

**config/approval_policies.php** (example)
```php
'categories' => [
    'email_send' => [
        'risk_level' => 'medium',
        'requires_approval' => true,
        'expires_hours' => 24,
        'notify_roles' => ['owner', 'admin'],
    ],
    'financial_transaction' => [
        'risk_level' => 'critical',
        'requires_approval' => true,
        'requires_2fa' => true,
        'expires_hours' => 48,
        'notify_roles' => ['owner'],
    ],
],
'escalation' => [
    'escalate_after_hours' => 12,
    'escalate_to' => ['owner', 'admin'],
],
```

---

## Testing

### Unit Testing Example

```php
use Tests\TestCase;
use App\Services\VaultService;
use App\Models\VaultSecret;

class VaultServiceTest extends TestCase
{
    public function test_can_store_and_retrieve_secret()
    {
        $vault = app(VaultService::class);

        $secret = $vault->store(
            'test_api_key',
            'secret_value_123',
            'Test API Key',
            ['category' => VaultSecret::CATEGORY_API_KEY]
        );

        $this->assertNotNull($secret);
        $this->assertEquals('test_api_key', $secret->key);

        $retrieved = $vault->get('test_api_key', auth()->user());
        $this->assertEquals('secret_value_123', $retrieved);
    }

    public function test_access_control_blocks_unauthorized()
    {
        $vault = app(VaultService::class);

        $secret = VaultSecret::factory()->create([
            'allowed_users' => [999], // Different user
        ]);

        $result = $vault->get($secret->key, auth()->user());
        $this->assertNull($result);
    }
}
```

### Integration Testing Example

```php
use Tests\TestCase;
use App\Services\Agents\AgentExecutor;
use App\Models\Agent;

class AgentExecutionTest extends TestCase
{
    public function test_agent_execution_flow()
    {
        $agent = Agent::factory()->create([
            'slug' => 'test-agent',
            'status' => 'active',
        ]);

        $executor = app(AgentExecutor::class);

        $run = $executor->execute(
            $agent,
            ['prompt' => 'Test task'],
            'manual',
            'user:1'
        );

        $this->assertNotNull($run);
        $this->assertEquals('completed', $run->status);
        $this->assertNotNull($run->output);
    }
}
```

---

## Performance Considerations

### Caching Strategies

**KPI Caching:**
```php
// 5-minute cache for dashboard KPIs
Cache::remember("dashboard_kpis_{$from}_{$to}", 300, function () {
    return $this->calculateAllKpis();
});
```

**Token/Credential Caching:**
```php
// Auto-refresh before expiry
if ($credential->isExpired()) {
    $credential = $this->refreshAccessToken($credential);
}
```

**Spend Tracking:**
```php
// Daily spend in cache (expires EOD)
$key = "spend:agent:{$agentId}:" . now()->format('Y-m-d');
Cache::put($key, $amount, now()->endOfDay());
```

### Database Optimization

**Indexes Required:**
```sql
-- Agent runs
INDEX idx_agent_runs_status_created (status, created_at)
INDEX idx_agent_runs_agent_status (agent_id, status)

-- Vault access logs
INDEX idx_vault_logs_secret_accessor (vault_secret_id, accessor_type, created_at)

-- Approval requests
INDEX idx_approvals_status_risk (status, risk_level, created_at)

-- Time entries
INDEX idx_time_entries_date_project (date, project_id)
```

### Query Optimization

**Use select() to limit columns:**
```php
AgentRun::select('id', 'status', 'cost_usd')
    ->whereBetween('created_at', [$start, $end])
    ->get();
```

**Eager load relationships:**
```php
Agent::with(['runs' => fn($q) => $q->latest()->limit(10)])
    ->get();
```

**Use chunk() for large datasets:**
```php
TimeEntry::whereBetween('date', [$start, $end])
    ->chunk(1000, function ($entries) {
        // Process batch
    });
```

---

## Security Best Practices

### Secrets Management

**Never log secrets:**
```php
Log::info('Vault access', [
    'secret_key' => $secret->key,  // OK
    'value' => '***REDACTED***',   // NEVER log actual value
]);
```

**Use Vault for all credentials:**
```php
// BAD
$apiKey = env('THIRD_PARTY_API_KEY');

// GOOD
$apiKey = app(VaultService::class)->get('third_party_api_key', null, 'my-agent');
```

### Input Validation

**Validate all external input:**
```php
// In service method
public function storeSecret(string $key, string $value, array $options = []): VaultSecret
{
    if (strlen($key) > 255) {
        throw new \InvalidArgumentException('Key too long');
    }

    if (preg_match('/[^a-z0-9_-]/i', $key)) {
        throw new \InvalidArgumentException('Invalid key format');
    }

    // Continue...
}
```

### Authorization

**Check permissions before actions:**
```php
if (!$secret->canBeAccessedBy($user, $agentSlug)) {
    $this->logAccess($secret, 'read', $user, $agentSlug, false, 'Access denied');
    return null;
}
```

**Require 2FA for critical actions:**
```php
if (($policy['requires_2fa'] ?? false) && !$approver->hasVerified2FA()) {
    throw new \Exception("2FA verification required");
}
```

---

## Troubleshooting

### Common Issues

**Agent execution fails with "Domain not allowed":**
```php
// Add to config/agents.php
'domains' => [
    'my-agent' => ['api.example.com', '*.example.com'],
],
```

**Vault access denied:**
```php
// Check secret configuration
$secret->allowed_agents;  // Should contain agent slug or be null
$secret->allowed_users;   // Should contain user ID or be null
```

**Token refresh fails:**
```php
// Check credential expiry
$credential->isExpired();  // Should trigger refresh

// Manual refresh
$oauthService->refreshAccessToken($credential);
```

**Agent hits spend limit:**
```php
// Check daily spend
$killSwitch->getAgentDailySpend($agent);

// Increase limit
$agent->update(['daily_spend_limit' => 200.00]);

// Or reset
$killSwitch->reviveAgent($agent);
```

### Debugging

**Enable service logging:**
```php
// In service method
Log::debug('Service operation', [
    'operation' => 'vault.get',
    'key' => $key,
    'user_id' => $user?->id,
    'agent' => $agentSlug,
]);
```

**Check queue status:**
```php
php artisan queue:failed    // Failed jobs
php artisan queue:work --once --verbose  // Process one job with output
```

**Test integration connections:**
```php
$slackService->testConnection($workspace);
$googleService->hasValidCredentials($user);
```

---

## Future Enhancements

### Planned Services

1. **NotificationService** - Centralized notification routing
2. **WebhookService** - Inbound webhook processing
3. **ReportingService** - Automated report generation
4. **BillingService** - Invoice generation and tracking
5. **AuditService** - System-wide audit trail

### Service Improvements

1. **Retry logic** - Exponential backoff for API calls
2. **Rate limiting** - Per-service rate limit tracking
3. **Circuit breakers** - Auto-disable failing integrations
4. **Metrics collection** - Prometheus/Grafana integration
5. **Event sourcing** - Event-driven architecture
6. **CQRS** - Separate read/write models

---

## Maintenance

### Regular Tasks

**Daily:**
- Monitor agent spend: `KillSwitchService::healthCheck()`
- Process escalations: `ApprovalService::escalateStale()`
- Expire old approvals: `ApprovalService::expireOld()`

**Weekly:**
- Cleanup old sandboxes: `AgentSandbox::cleanupOld()`
- Review vault access logs
- Analyze agent performance

**Monthly:**
- Rotate sensitive secrets: `VaultService::rotate()`
- Review integration quotas
- Archive old agent runs

### Scheduled Commands

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(fn() => app(ApprovalService::class)->escalateStale())
        ->hourly();

    $schedule->call(fn() => app(KillSwitchService::class)->healthCheck())
        ->everyFifteenMinutes();

    $schedule->call(fn() => app(AgentSandbox::class)->cleanupOld())
        ->daily();
}
```

---

## Appendix

### Service File Locations

```
app/Services/
├── AgentOutputRouter.php
├── AgentSandbox.php
├── BusinessIntelligenceService.php
├── CapabilitySynthesisService.php
├── HealthAlertEscalationService.php
├── ProactiveInsightsService.php
├── QuarterlyPatternAnalysisService.php
├── Agents/
│   ├── AgentAnalyticsService.php
│   ├── AgentExecutor.php
│   ├── ChainExecutor.php
│   ├── ClaudeAgentSdk.php
│   ├── ClaudeCliRunner.php
│   └── ExecutionResult.php
├── AI/
│   ├── AnthropicService.php
│   ├── ConsortiumResult.php
│   ├── GeminiService.php
│   ├── MultiModelConsortium.php
│   └── OpenAIService.php
├── Analytics/
│   └── KpiCalculator.php
├── Approval/
│   └── ApprovalService.php
├── Emergency/
│   └── KillSwitchService.php
├── GitHub/
│   ├── GitHubApiService.php
│   └── GitHubAppService.php
├── Google/
│   ├── CalendarService.php
│   ├── DriveService.php
│   ├── GmailService.php
│   ├── GoogleOAuthService.php
│   └── SearchConsoleService.php
├── Grok/
│   └── GrokService.php
├── Harvest/
│   ├── HarvestApiService.php
│   └── HarvestOAuthService.php
├── LinkedIn/
│   └── LinkedInService.php
├── Notion/
│   ├── NotionApiService.php
│   └── NotionOAuthService.php
├── QuickBooks/
│   ├── QuickBooksApiService.php
│   └── QuickBooksOAuthService.php
├── Seo/
│   └── SeoResearchService.php
├── Slack/
│   ├── SlackActionItemService.php
│   ├── SlackApiService.php
│   └── SlackOAuthService.php
├── Vault/
│   └── VaultService.php
├── WordPress/
│   └── WordPressMcpService.php
└── X/
    └── XService.php
```

### Related Documentation

- [Agent Definitions](./AGENTS.md)
- [API Documentation](./API.md)
- [Database Schema](./DATABASE.md)
- [Deployment Guide](./DEPLOYMENT.md)
