<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class ZaoDashServer extends Server
{
    protected string $name = 'Zao Dashboard';

    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Zao Dashboard MCP Server

        Core agency management: clients, projects, tasks, leads, invoices, and AI agents.

        Other capabilities are split into separate MCP servers:
        - **zao-finance** - Client profitability and invoice velocity (OSS subset)
        - **zao-web** — Website builder, WordPress, SEO content, Gravity Forms, Meta ads
        - **zao-comms** — Email, Slack, Google Drive, RFP pipeline

        ## Client Management
        - `list-clients` - List all clients with filtering
        - `get-client` - Get detailed client information, including billing and recurring invoice settings
        - `create-client` - Create a new client
        - `update-client` - Update client details, including billing email, payment terms, and recurring invoice settings (omitted fields are left unchanged)
        - `create-client-note` - Log a note about a client
        - `create-client-contact` - Add a contact person to a client

        ## Project Management
        - `list-projects` - List projects with filtering
        - `get-project` - Get project details with tasks
        - `create-project` - Create a new project
        - `update-project` - Update project status/details
        - `import-sow` - Turn approved SOW/proposal documents into a provisioned client/project setup

        ## GitHub
        - `list-github-repos` - List repositories known to Zao
        - `get-github-repo` - Get repository details, linked context, and recent synced activity
        - `sync-github-repo` - Queue GitHub syncs through Zao's GitHub App
        - `list-github-issues` - List synced GitHub issues
        - `create-github-issue` - Create a GitHub issue through Zao and optionally sync it to a task
        - `list-github-pull-requests` - List synced GitHub PRs
        - `get-github-pull-request` - Get a synced GitHub PR
        - `approve-github-pull-request` - Approve a PR in Zao; merging requires an explicit flag
        - `reject-github-pull-request` - Reject a PR in Zao, optionally with a GitHub comment
        - `link-github-repo-to-project` - Link a repo to a Zao project/client context

        ## Task Management
        - `list-tasks` - List tasks with filtering (use unassigned=true for unassigned tasks)
        - `get-task` - Get task details
        - `create-task` - Create a new task
        - `update-task` - Update task status/details
        - `bulk-update-tasks` - Update multiple tasks at once (for bulk assignment, etc.)
        - `delete-task` - Delete a task (requires confirmation)

        ## Sales Pipeline
        - `list-leads` - List leads in pipeline
        - `create-lead` - Create a new lead
        - `update-lead-stage` - Move lead through pipeline

        ## AI Agents
        - `list-agents` - List all configured agents
        - `get-agent` - Get agent details and stats
        - `trigger-agent` - Trigger an agent to run
        - `get-agent-run` - Get agent run status/output

        ## Compound Engineering (Interactive Workflows)
        - `trigger-compound-engineering` - Start an interactive Compound Engineering workflow
        - `list-interaction-requests` - List pending interaction requests from agents
        - `respond-to-interaction` - Submit a response to a pending agent interaction

        ## Utilities
        - `search` - Search across all entities
        - `list-invoices` - List invoices
        - `create-invoice` - Create a new invoice with line items
        - `update-invoice` - Update an existing invoice
        - `list-approval-requests` - Review the approval queue
        - `decide-approval-request` - Approve or reject a pending approval request
        - `get-integration-status` - Check integration connections
        - `get-focus` - Get current human-priority work, recommendations, and focus briefing
        - `build-retainer-narrative-from-text` - Build a retainer period's narrative + hour estimates from a pasted transcript (for work that never synced into Slack/email/commits)
        - `adjust-retainer-time-entry` - List/correct/remove a retainer period's time entries; corrections are promoted to manual so they survive narrative regeneration
        - `database-query` - Execute read-only SQL queries (SELECT, SHOW, DESCRIBE, EXPLAIN)

        ## WordPress
        - `wp-create-post` - Create a post through the Dash WordPress integration (draft by default; dry_run handshakes REST auth)
        - `wp-update-post` - Update an existing post via WordPressMcpService::updatePost (PUT /wp/v2/posts/{id})
        - `wordpress-media-upload` - Upload media through Dash-stored WordPress credentials (dry_run handshakes REST auth)

        ## Resources
        - `dashboard://kpis` - Current business metrics
        - `user://me` - Current authenticated user

        ## Prompts
        - `business-briefing` - Generate business status briefing
    MARKDOWN;

    protected array $tools = [
        \App\Mcp\Tools\ListClientsTool::class,
        \App\Mcp\Tools\GetClientTool::class,
        \App\Mcp\Tools\CreateClientTool::class,
        \App\Mcp\Tools\UpdateClientTool::class,
        \App\Mcp\Tools\CreateClientNoteTool::class,
        \App\Mcp\Tools\CreateClientContactTool::class,
        \App\Mcp\Tools\ListProjectsTool::class,
        \App\Mcp\Tools\GetProjectTool::class,
        \App\Mcp\Tools\CreateProjectTool::class,
        \App\Mcp\Tools\UpdateProjectTool::class,
        \App\Mcp\Tools\ImportSowTool::class,
        \App\Mcp\Tools\ListGitHubReposTool::class,
        \App\Mcp\Tools\GetGitHubRepoTool::class,
        \App\Mcp\Tools\SyncGitHubRepoTool::class,
        \App\Mcp\Tools\ListGitHubIssuesTool::class,
        \App\Mcp\Tools\CreateGitHubIssueTool::class,
        \App\Mcp\Tools\ListGitHubPullRequestsTool::class,
        \App\Mcp\Tools\GetGitHubPullRequestTool::class,
        \App\Mcp\Tools\ApproveGitHubPullRequestTool::class,
        \App\Mcp\Tools\RejectGitHubPullRequestTool::class,
        \App\Mcp\Tools\LinkGitHubRepoToProjectTool::class,
        \App\Mcp\Tools\ListTasksTool::class,
        \App\Mcp\Tools\GetTaskTool::class,
        \App\Mcp\Tools\CreateTaskTool::class,
        \App\Mcp\Tools\UpdateTaskTool::class,
        \App\Mcp\Tools\BulkUpdateTasksTool::class,
        \App\Mcp\Tools\DeleteTaskTool::class,
        \App\Mcp\Tools\ListLeadsTool::class,
        \App\Mcp\Tools\CreateLeadTool::class,
        \App\Mcp\Tools\UpdateLeadStageTool::class,
        \App\Mcp\Tools\ListAgentsTool::class,
        \App\Mcp\Tools\GetAgentTool::class,
        \App\Mcp\Tools\TriggerAgentTool::class,
        \App\Mcp\Tools\GetAgentRunTool::class,
        \App\Mcp\Tools\TriggerCompoundEngineeringTool::class,
        \App\Mcp\Tools\ListInteractionRequestsTool::class,
        \App\Mcp\Tools\RespondToInteractionTool::class,
        \App\Mcp\Tools\SearchTool::class,
        \App\Mcp\Tools\ListInvoicesTool::class,
        \App\Mcp\Tools\CreateInvoiceTool::class,
        \App\Mcp\Tools\UpdateInvoiceTool::class,
        \App\Mcp\Tools\ListApprovalRequestsTool::class,
        \App\Mcp\Tools\DecideApprovalRequestTool::class,
        \App\Mcp\Tools\GetIntegrationStatusTool::class,
        \App\Mcp\Tools\GetFocusTool::class,
        \App\Mcp\Tools\BuildRetainerNarrativeFromTextTool::class,
        \App\Mcp\Tools\AdjustRetainerTimeEntryTool::class,
        \App\Mcp\Tools\DatabaseQueryTool::class,
        \App\Mcp\Tools\WpCreatePostMcpTool::class,
        \App\Mcp\Tools\WpUpdatePostMcpTool::class,
        \App\Mcp\Tools\WordpressMediaUploadMcpTool::class,
    ];

    protected array $resources = [
        \App\Mcp\Resources\DashboardResource::class,
        \App\Mcp\Resources\CurrentUserResource::class,
    ];

    protected array $prompts = [
        \App\Mcp\Prompts\BusinessBriefingPrompt::class,
    ];
}
