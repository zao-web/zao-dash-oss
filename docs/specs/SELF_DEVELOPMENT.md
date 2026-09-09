# Self-Development System

Zao Dashboard can develop itself via the "Request Feature" tool in the command palette.

## Overview

When you open the command palette (Cmd+K) and ask the AI to "add a feature" or "fix a bug", the `RequestFeatureTool` creates a task in the Zao Dashboard project and optionally triggers the Dev Agent to implement it.

## Flow

```
User → Command Palette → "Add dark mode toggle"
                ↓
        RequestFeatureTool
                ↓
    Creates Task in self-project
                ↓
    (optional) ImplementFeatureJob
                ↓
        Dev Agent executes
                ↓
    Creates PR with implementation
```

## Configuration

Add to `.env`:

```env
# Enable self-development (default: true)
SELF_DEVELOPMENT_ENABLED=true

# Project where feature requests are created
SELF_PROJECT_SLUG=zao-dash
# Fallback if slug not found
SELF_PROJECT_ID=1

# Automatically trigger Dev Agent (default: false - requires approval)
SELF_AUTO_TRIGGER_AGENT=false

# Default priority for new feature requests
SELF_FEATURE_PRIORITY=medium

# GitHub repo for Dev Agent context
SELF_GITHUB_REPO=example/zao-dash
```

## Request Types

| Type | Prefix | Use Case |
|------|--------|----------|
| `feature` | [Feature] | New functionality |
| `bug` | [Bug] | Something broken |
| `enhancement` | [Enhancement] | Improve existing feature |
| `refactor` | [Refactor] | Code quality improvement |

## Usage Examples

### Via Command Palette Chat

```
User: "Add a button to export tasks as CSV"

AI: I'll create a feature request for that.
[Uses RequestFeatureTool with title="Export tasks as CSV"]

Result: Created [Feature] Export tasks as CSV in Zao Dashboard project
```

### With Dev Agent Trigger

```
User: "Build a dark mode toggle and have the Dev Agent implement it"

AI: Creating the feature and triggering implementation...
[Uses RequestFeatureTool with trigger_dev_agent=true]

Result:
- Task created: [Feature] Dark mode toggle
- Dev Agent queued for implementation
```

### Scoped Request

```
User: "Add form validation to the client creation form - affects app/Http/Controllers/ClientController.php and resources/js/Pages/Clients/Create.vue"

AI: [Uses RequestFeatureTool with file_scope array]

Result: Task created with file scope for Dev Agent context
```

## Components

### RequestFeatureTool

Location: `app/Agents/Tools/RequestFeatureTool.php`

Auto-discovered by ToolRegistry, available in command palette AI tools.

Input schema:
- `title` (required): Short feature title
- `description` (required): Detailed description (min 20 chars)
- `type`: feature|bug|enhancement|refactor
- `priority`: low|medium|high|urgent
- `trigger_dev_agent`: boolean
- `file_scope`: array of affected files/directories

### ImplementFeatureJob

Location: `app/Jobs/ImplementFeatureJob.php`

Queued job that:
1. Updates task status to `in_progress`
2. Builds rich prompt with task context
3. Executes Dev Agent via AgentExecutor
4. Stores agent run reference in task metadata

### Config

Location: `config/services.php` → `self_development`

## Task Metadata

Tasks created via self-development have:

```php
'metadata' => [
    'type' => 'feature', // or bug/enhancement/refactor
    'file_scope' => ['app/...', 'resources/...'],
    'requested_via' => 'command_palette',
    'requested_at' => '2025-12-18T...',
    'agent_run_id' => 123, // if agent triggered
    'agent_status' => 'pending_approval',
]
```

## Security

- `RequestFeatureTool.requiresApproval()` returns `true`
- User must approve task creation before execution
- Dev Agent runs also require approval (unless globally disabled)
- Agent execution logged with `job:implement-feature` source

## Best Practices

1. **Be specific**: Include acceptance criteria and expected behavior
2. **Scope files**: Help Dev Agent by specifying affected files
3. **Review first**: Keep `SELF_AUTO_TRIGGER_AGENT=false` and review tasks before agent implementation
4. **Test locally**: Run `php artisan test` before merging agent-generated PRs
