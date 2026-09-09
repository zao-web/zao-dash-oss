# Agent Task Workflow

Execute AI agents from Tasks with automatic time tracking and Harvest billing.

---

## Overview

The Agent Task Workflow connects the Task management system to the Agent execution system, enabling:

1. Assign an AI agent to work on a Task
2. Agent executes with project-scoped credentials from Vault
3. On completion, human-equivalent time is estimated
4. Time entry automatically created in Harvest

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      AGENT TASK WORKFLOW                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌────────────────┐      ┌─────────────────┐      ┌─────────────────┐  │
│  │     TASK       │      │   AGENT TASK    │      │   AGENT RUN     │  │
│  │  (Dashboard)   │─────▶│   (Bridge)      │─────▶│   (Execution)   │  │
│  └────────────────┘      └─────────────────┘      └─────────────────┘  │
│         │                        │                        │            │
│         │                        │                        │            │
│         ▼                        ▼                        ▼            │
│  ┌────────────────┐      ┌─────────────────┐      ┌─────────────────┐  │
│  │  TASK ACTIVITY │      │  VAULT SECRETS  │      │  HARVEST TIME   │  │
│  │  (Audit Log)   │      │  (Credentials)  │      │  (Billing)      │  │
│  └────────────────┘      └─────────────────┘      └─────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Key Components

### Models

| Model | Purpose |
|-------|---------|
| `Task` | The work item from project management |
| `AgentTask` | Links Task to Agent, tracks execution state |
| `AgentRun` | Actual agent execution record |
| `TaskActivity` | Audit log of all task events |

### Services

| Service | Purpose |
|---------|---------|
| `TaskAgentService` | Orchestrates the workflow |
| `TimeEstimationService` | Calculates human-equivalent hours |
| `VaultService` | Loads project-scoped credentials |
| `HarvestApiService` | Creates time entries |

---

## Workflow Steps

### 1. Assign Agent to Task

From the Task UI, select an agent to work on the task:

```php
$taskAgentService->assignAgentToTask($task, $agent, $user);
```

This creates an `AgentTask` record with:
- Task context (title, description, project info)
- Priority mapping (task priority → agent priority)
- Project and client IDs for credential scoping

### 2. Execute Agent

Trigger execution from the API or UI:

```php
$run = $taskAgentService->executeAgentTask($agentTask);
```

The service:
1. Loads credentials from Vault scoped to project/client
2. Builds execution context with task and project info
3. Executes the agent via `AgentExecutor`
4. Logs activity to `TaskActivity`

### 3. Agent Completion

When the `AgentRun` status changes to completed/failed, the `AgentRunObserver` triggers:

```php
$taskAgentService->handleAgentCompletion($run, $agentTask);
```

On success:
- Estimates human-equivalent hours
- Creates Harvest time entry
- Detects PR creation (if present in output)
- Logs all activities

On failure:
- Logs the error
- Updates AgentTask status

---

## Time Estimation

The `TimeEstimationService` calculates human-equivalent billing time.

### Estimation Formula

```
human_hours = base_hours × complexity_multiplier × priority_multiplier
```

### Factors

| Factor | Weight | Values |
|--------|--------|--------|
| Task Type | Base | bug_fix: 1.5h, feature: 3h, refactor: 2h, review: 1h |
| Priority | 1.0-1.5x | urgent: 1.5x, high: 1.25x, normal: 1.0x, low: 0.75x |
| Complexity | 0.8-2.0x | Based on output tokens and execution time |
| Files Changed | +0.5h | Per 5 files (if detected in output) |

### Example

```php
$breakdown = $timeEstimator->getEstimationBreakdown($run, $task);

// Returns:
[
    'base_hours' => 1.5,        // bug_fix
    'task_type' => 'bug_fix',
    'complexity_multiplier' => 1.2,
    'priority_multiplier' => 1.25, // high priority
    'final_hours' => 2.25,
]
```

---

## Harvest Integration

When an agent completes a task:

1. Check if project has `harvest_project_id`
2. Find user with Harvest credentials
3. Create time entry with human-equivalent hours
4. Store `harvest_time_entry_id` on AgentTask

### Time Entry Notes Format

```
Task: Fix authentication bug
Completed by AI Agent (Dev Agent)
Agent execution: 45s
Human-equivalent estimate: 2.25h
```

---

## API Endpoints

### Assign Agent

```
POST /tasks/{task}/agent
{
    "agent_id": 1
}
```

### Execute Agent

```
POST /tasks/{task}/agent/execute
```

### Get Agent Status

```
GET /tasks/{task}/agent/status
```

Returns:
```json
{
    "has_active_task": true,
    "latest_task": {
        "id": 123,
        "status": "running",
        "agent_id": 1,
        "started_at": "2025-12-27T12:00:00Z"
    }
}
```

### Get Task Activities

```
GET /tasks/{task}/activities
```

Returns chronological activity log.

---

## Activity Types

| Type | Description |
|------|-------------|
| `agent_assigned` | Agent assigned to task |
| `agent_started` | Agent execution began |
| `credentials_loaded` | Vault secrets loaded |
| `agent_completed` | Agent finished successfully |
| `agent_failed` | Agent execution failed |
| `time_entry` | Harvest time entry created |
| `pr_created` | Pull request created by agent |

---

## Vault Credential Loading

Credentials are loaded with scoping:

```php
$credentials = $vault->getAgentSecrets(
    $agent->slug,    // e.g., 'dev-agent'
    $projectId,      // project scope
    $clientId        // client scope
);
```

Priority order:
1. Project-specific secrets (`project:{id}:*`)
2. Client-specific secrets (`client:{id}:*`)
3. Agent-global secrets (`agent:{slug}:*`)

---

## Database Schema

### task_activities

```sql
CREATE TABLE task_activities (
    id BIGINT PRIMARY KEY,
    task_id BIGINT NOT NULL,
    activity_type VARCHAR(50) NOT NULL,
    description TEXT,
    metadata JSON,
    performed_by BIGINT,
    created_at TIMESTAMP
);
```

### agent_tasks additions

```sql
ALTER TABLE agent_tasks ADD COLUMN task_id BIGINT;
ALTER TABLE agent_tasks ADD COLUMN estimated_human_hours DECIMAL(8,2);
ALTER TABLE agent_tasks ADD COLUMN actual_agent_seconds DECIMAL(12,2);
ALTER TABLE agent_tasks ADD COLUMN harvest_time_entry_id BIGINT;
```

---

## Usage Examples

### Assign and Execute

```php
use App\Services\TaskAgentService;

$service = app(TaskAgentService::class);

// Assign dev agent to bug fix task
$agentTask = $service->assignAgentToTask($task, $devAgent, auth()->user());

// Execute immediately
$run = $service->executeAgentTask($agentTask);

// Completion handled automatically via observer
```

### Check Status

```php
// Via Task model
$task->hasActiveAgentTask(); // bool

// Latest agent task
$latestTask = $task->latestAgentTask;
$latestTask->status; // pending, running, completed, failed

// Activity log
$activities = $task->activities;
```

### Manual Completion Handling

```php
// Usually not needed - observer handles this
// But available for testing or manual intervention
$service->handleAgentCompletion($run, $agentTask);
```

---

## Configuration

### Project Sync to Harvest

Projects can be synced to Harvest on creation:

```php
// ProjectController.store()
// Pass sync_to_harvest: true in request
```

Requires:
- Client must have `harvest_client_id`
- User with Harvest credentials available

---

## Best Practices

### 1. Set Harvest Project ID

For time tracking, link projects to Harvest:

```php
$project->update(['harvest_project_id' => $harvestId]);
```

### 2. Configure Vault Secrets

Store project-specific credentials:

```bash
php artisan vault:store project:123:github_token ghp_xxx
```

### 3. Use Appropriate Agents

| Task Type | Recommended Agent |
|-----------|-------------------|
| Bug fixes | dev-agent |
| Features | dev-agent |
| Code review | code-review-agent |
| Documentation | doc-writer-agent |

### 4. Monitor Activity Log

Review `TaskActivity` for audit trail and debugging.

---

## Troubleshooting

### Agent Not Executing

```bash
# Check agent is active
php artisan tinker
>>> Agent::where('id', 1)->first()->status;

# Check circuit breaker
>>> Agent::where('id', 1)->first()->circuit_broken_at;
```

### Time Entry Not Created

1. Verify project has `harvest_project_id`
2. Check user has Harvest credentials
3. Review logs for API errors

### Credentials Not Loading

1. Verify Vault secrets exist with correct scope
2. Check agent slug matches secret key pattern
3. Review Vault access logs

---

## See Also

- [Agents Documentation](./AGENTS.md)
- [Vault Service](./SERVICES.md#vault)
- [Harvest Integration](./SERVICES.md#harvest)
- [API Reference](./API.md)
