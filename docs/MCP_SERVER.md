# Zao Dashboard MCP Server

The Zao Dashboard exposes a Model Context Protocol (MCP) server that allows LLMs to interface with every part of the dashboard programmatically.

First-run client setup for Cursor, Claude, and other local MCP clients is in [Connect a local assistant](getting-started.md).

## Quick Start

### Using with Cursor, Claude Desktop, or Claude Code

The checked-in client config is `.mcp.json`. It calls the HTTP server through `mcp-remote`. Start the app first, then mint a token with `php artisan mcp:token`.

```json
{
  "mcpServers": {
    "zao-dash": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "http://localhost:8000/mcp/zao-dash",
        "--header",
        "Authorization: Bearer ${ZAO_DASH_MCP_TOKEN}"
      ]
    }
  }
}
```

`php artisan mcp:start zao-dash` is a stdio server. It waits on stdin. Use it only when the client speaks stdio, not as a stand-in for the HTTP URL above.

### Testing with MCP Inspector

```bash
php artisan mcp:inspect zao-dash
```

This opens a web-based inspector to test tools interactively.

## Available Tools

### Client Management

| Tool | Description |
|------|-------------|
| `list-clients` | List clients with optional status/search filtering |
| `get-client` | Get detailed client info by ID or slug, including billing and recurring invoice settings |
| `create-client` | Create a new client |
| `update-client` | Update client details, including billing and recurring invoice (retainer) settings. Omitted fields are left unchanged. |

### Project Management

| Tool | Description |
|------|-------------|
| `list-projects` | List projects with status/client filtering |
| `get-project` | Get project details with tasks and milestones |
| `create-project` | Create a new project for a client |
| `update-project` | Update project status or details |

### GitHub

| Tool | Description |
|------|-------------|
| `list-github-repos` | List repositories known to Zao with client/project filters |
| `get-github-repo` | Get repository details, linked context, and recent synced activity |
| `sync-github-repo` | Queue GitHub syncs through Zao's GitHub App |
| `list-github-issues` | List synced GitHub issues by repo, client, or project |
| `create-github-issue` | Create a GitHub issue through Zao and optionally sync it to a task |
| `list-github-pull-requests` | List synced GitHub pull requests by repo, client, project, or approval status |
| `get-github-pull-request` | Get one synced GitHub pull request |
| `approve-github-pull-request` | Approve a pull request in Zao; merging requires `merge_if_eligible: true` |
| `reject-github-pull-request` | Reject a pull request in Zao, optionally with a GitHub comment |
| `link-github-repo-to-project` | Link a GitHub repository to a Zao project/client context |

### Task Management

| Tool | Description |
|------|-------------|
| `list-tasks` | List tasks with status/project/assignee filtering |
| `get-task` | Get task details with comments and activity |
| `create-task` | Create a new task in a project |
| `update-task` | Update task status, priority, or details |

### Sales Pipeline

| Tool | Description |
|------|-------------|
| `list-leads` | List leads with pipeline stage filtering |
| `create-lead` | Create a new sales lead |
| `update-lead-stage` | Move lead through pipeline stages |

### AI Agents

| Tool | Description |
|------|-------------|
| `list-agents` | List all configured AI agents |
| `get-agent` | Get agent details, stats, and recent runs |
| `trigger-agent` | Trigger an agent to run asynchronously |
| `get-agent-run` | Get agent run status and output |

### WordPress

| Tool | Description |
|------|-------------|
| `wp-create-post` | Create a WordPress post through the Dash integration (draft by default; `dry_run` handshakes REST auth and persists `rest_url` without title or content) |
| `wp-update-post` | Update an existing WordPress post via `WordPressMcpService::updatePost` (PUT `/wp/v2/posts/{id}`). Requires `post_id` and at least one field to change. |
| `wordpress-media-upload` | Upload media through Dash-stored credentials (`file_path` under Dash media dirs, public `file_url`, or `file_base64`). Uploads require approval; `dry_run` handshakes only. |

### Utilities

| Tool | Description |
|------|-------------|
| `search` | Search across clients, projects, tasks, leads, agents |
| `list-invoices` | List invoices with status/client filtering |
| `get-integration-status` | Check connection status of all integrations |

## Resources

| URI | Description |
|-----|-------------|
| `dashboard://kpis` | Current business metrics and KPIs |

## Prompts

| Prompt | Description |
|--------|-------------|
| `business-briefing` | Generate a business status briefing (general, sales, projects, or financial focus) |

## Example Usage

### Get Dashboard KPIs

```
Use the dashboard resource to fetch current metrics.
```

### List Active Projects for a Client

```
Use list-projects with client_id parameter.
```

### Create a New Task

```json
{
  "tool": "create-task",
  "arguments": {
    "title": "Review design mockups",
    "project_id": 5,
    "priority": "high",
    "due_date": "2026-01-15"
  }
}
```

### Trigger an Agent

```json
{
  "tool": "trigger-agent",
  "arguments": {
    "slug": "business-strategist",
    "task": "Analyze current client health and suggest improvements"
  }
}
```

### Update client recurring invoice / billing settings

Omitted fields stay as they are. Passing `false` for `recurring_invoice_enabled` disables generation and leaves the amount in place.

`recurring_invoice_description` is capped at 255 characters, matching the `clients` column. JSON `null` clears `billing_email`, `billing_cc_emails`, `recurring_invoice_description`, or `recurring_invoice_project_id`. Other omitted keys are unchanged.

```json
{
  "tool": "update-client",
  "arguments": {
    "id": 9,
    "recurring_invoice_amount": 2000
  }
}
```

```json
{
  "tool": "update-client",
  "arguments": {
    "id": 11,
    "recurring_invoice_enabled": true,
    "recurring_invoice_amount": 1000,
    "recurring_invoice_day": 15
  }
}
```

Accepted billing / retainer fields: `recurring_invoice_enabled`, `recurring_invoice_amount`, `recurring_invoice_day` (1-28), `recurring_invoice_auto_send`, `recurring_invoice_description` (max 255), `recurring_invoice_project_id` (must belong to the client unless clearing with null), `recurring_invoice_in_advance`, `payment_terms` (`Due on Receipt`, `Net 15`, `Net 30`, `Net 45`, `Net 60`), `billing_email`, `billing_cc_emails`.

### Update an existing WordPress post

```json
{
  "tool": "wp-update-post",
  "arguments": {
    "post_id": 53642,
    "title": "Updated title",
    "content": "<p>Updated HTML.</p>",
    "status": "draft"
  }
}
```

This is the zao-dash MCP `tools/call` for `wp-update-post`. It runs `WordPressMcpService::updatePost`, which PUTs `/wp/v2/posts/{id}`.

## Adding New Tools

1. Create a new tool class in `app/Mcp/Tools/`:

```bash
php artisan make:mcp-tool MyNewTool
```

2. Implement the `handle()` and `schema()` methods
3. Register in `app/Mcp/Servers/ZaoDashServer.php`

## Architecture

```
app/Mcp/
├── Servers/
│   └── ZaoDashServer.php      # Main server class
├── Tools/
│   ├── ListClientsTool.php
│   ├── GetClientTool.php
│   └── ...                    # All tool implementations
├── Resources/
│   └── DashboardResource.php  # Read-only resources
└── Prompts/
    └── BusinessBriefingPrompt.php
```

## Configuration

The MCP server is registered in `AppServiceProvider`:

```php
Mcp::local('zao-dash', \App\Mcp\Servers\ZaoDashServer::class);
```

Published config available at `config/mcp.php`.
