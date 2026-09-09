# Task Assignment System

Tasks can be assigned to three types of assignees:

## Assignee Types

| Type | Value | Description |
|------|-------|-------------|
| Team Member | `user` | Internal users (staff, admin, owner) |
| Agent | `agent` | AI agents (Dev Agent, QA Agent, etc.) |
| Client Contact | `client_contact` | External client contacts |

## How It Works

### Database Schema
- `tasks.assigned_to` - The ID of the assignee (nullable integer, no FK constraint)
- `tasks.assignee_type` - The type of assignee: `user`, `agent`, or `client_contact` (nullable string)

### Assigning via API
```
POST /tasks/{task}/assign
{
    "assigned_to": 5,
    "assignee_type": "agent"
}
```

### What Happens on Assignment

1. **All types**: A `TaskComment` of type `assignment` is logged with the old and new assignee names/types.
2. **Agent assignments**: An `AgentTask` record is automatically created linking the task to the agent. The agent does not auto-execute — use the "Execute" action or the `/tasks/{task}/execute-agent` endpoint to trigger execution.
3. **Client contact assignments**: Purely internal tracking. No notifications are sent to the client.

### Frontend Behavior

The assignee dropdown in the Tasks page shows grouped options:
- **Team** — All internal users
- **Agents** — All active AI agents
- **Clients** — All contacts from active clients

The dropdown uses composite values (`type:id` format) internally to distinguish between types that might share the same numeric ID.

### Avatars

Assignee avatars are color-coded by type:
- **Users**: Unique hue based on user ID
- **Agents**: Purple (`var(--color-status-purple)`) with "AI" label
- **Client Contacts**: Orange (`var(--color-status-orange)`) with initials

## Agent Handoff Workflow

When an agent completes (or fails) a task, automatic handoff occurs:

### On Completion
1. Task status moves to `review`
2. Task is reassigned to the user who originally assigned the agent
3. A system comment is added with the agent's summary and PR link (if any)
4. If the agent created a PR with a branch name, a **preview environment** is created via Laravel Cloud

### On Failure
1. Task is reassigned back to the user who assigned the agent
2. A system comment is added with the error details

### Full Lifecycle Example
1. You assign task to **Dev Agent** -> agent works -> auto-moves to **Review** -> reassigns to you
2. You review, then assign to **QA Agent** -> agent works -> auto-moves to **Review** -> reassigns to you
3. You assign to **Client Contact** for their review

## Preview Environments (Laravel Cloud)

When an agent creates a PR with a branch name, a `CreatePreviewEnvironment` job is dispatched that:

1. Creates a preview environment on Laravel Cloud for the branch
2. Triggers a deployment
3. Stores the preview URL, environment ID, and branch in `tasks.metadata`
4. Adds a system comment with the preview URL, PR link, and testing instructions

### Configuration
Set these environment variables:
```
LARAVEL_CLOUD_API_TOKEN=your-token
LARAVEL_CLOUD_APP_ID=your-application-id
```

If not configured, preview creation is silently skipped.

### Task Metadata
Preview data is stored in the task's `metadata` JSON column:
```json
{
    "cloud_environment_id": "env-123",
    "preview_url": "https://preview-name.cloud.laravel.com",
    "preview_branch": "feature/task-name",
    "preview_status": "provisioning",
    "pr_url": "https://github.com/org/repo/pull/42",
    "deployment_id": "deploy-456"
}
```

### Frontend Display
When a task has preview metadata, a "Preview & Review" section appears in the task detail panel with:
- A link to the preview environment
- A link to the pull request
- The branch name

## Model API

```php
$task->assignee_info;       // ['id' => 5, 'name' => 'Dev Agent', 'type' => 'agent']
$task->resolved_assignee;   // Returns the actual Agent model instance
$task->isAssignedToAgent(); // true
```
