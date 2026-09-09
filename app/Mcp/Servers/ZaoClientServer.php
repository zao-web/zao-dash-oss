<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ClientSite\CreateGitHubIssueTool;
use App\Mcp\Tools\ClientSite\GetMyClientTool;
use App\Mcp\Tools\ClientSite\ListMyReposTool;
use App\Mcp\Tools\ClientSite\NotifyTeamTool;
use Laravel\Mcp\Server;

/**
 * Client-scoped MCP server (/mcp/zao-client).
 *
 * Mounted behind ['auth:sanctum', EnsureClientToken], so every request is bound
 * to exactly one Client via its token. The tools here never accept a client
 * identifier — they derive it from the token — which makes cross-client access
 * structurally impossible. This is the ONLY Zao MCP surface a deployed client
 * site (e.g. the Zao Assistant WordPress widget) is given a token for.
 */
class ZaoClientServer extends Server
{
    protected string $name = 'Zao Client';

    public int $defaultPaginationLength = 50;

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Zao Client MCP Server (per-client scoped)

        Narrow, client-scoped surface for a deployed client site. Every tool acts
        only on the client this token belongs to.

        - `get-my-client` — this client's profile (projects, connected repos)
        - `list-my-repos` — this client's GitHub repositories
        - `create-github-issue` — file an issue for one of this client's repos
          (syncs to a Zao task → retainer report)
        - `notify-team` — post a question/escalation to this client's Slack channel
          (uses the Zao bot token; the message lands in the retainer report's
          Slack synthesis automatically)
        MARKDOWN;

    protected array $tools = [
        GetMyClientTool::class,
        ListMyReposTool::class,
        CreateGitHubIssueTool::class,
        NotifyTeamTool::class,
    ];
}
