<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class ZaoCommsServer extends Server
{
    protected string $name = 'Zao Communications & RFP';

    public int $defaultPaginationLength = 50;

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Zao Communications & RFP MCP Server

        Email, Slack, Google Drive, and RFP pipeline tools.

        ## Email & Search
        - `search-emails` - Search synced Gmail emails
        - `search-gmail-live` - Search Gmail directly via the API in real-time
        - `search-google-drive` - Search Google Drive for proposals, case studies, SOWs

        ## Slack
        - `get-channel-operations-context` - Get the full operating context for a Slack channel
        - `link-slack-context` - Link a Slack channel to a client and/or project
        - `list-slack-watchlist` - List Slack channels a user is privately tracking
        - `manage-slack-watchlist` - Track, untrack, list, or clear private Slack channel watchlists
        - `run-channel-integration-action` - Queue safe integration sync actions for a Slack channel

        ## RFP Pipeline — Discovery
        - `search-rfp-opportunities` - Search and filter RFP opportunities
        - `create-rfp-opportunity` - Create a new RFP opportunity
        - `update-rfp-opportunity` - Update an RFP opportunity
        - `fetch-rfp-listing` - Fetch and extract content from an RFP listing URL
        - `trigger-rfp-scan` - Scan emails for RFP opportunities
        - `trigger-rfp-evaluation` - Evaluate one or all discovered opportunities (scores, qualifies, declines, triggers proposals)

        ## RFP Pipeline — Proposals
        - `create-rfp-proposal` - Trigger AI generation of a proposal (multi-model debate)
        - `get-rfp-proposal` - Get full proposal content: sections, pricing, requirement responses
        - `update-rfp-proposal` - Revise specific sections, pricing, or add review notes

        ## RFP Pipeline — Outcomes & Learning
        - `record-rfp-outcome` - Record win/loss with feedback to feed the learning layer
        - `get-rfp-learning-insights` - Get active learning insights from past RFP outcomes
        - `create-rfp-learning-insight` - Create a new learning insight from outcome analysis

        ## RFP Pipeline — Sources
        - `list-rfp-sources` - List all discovery sources with status and stats
        - `add-rfp-source` - Add a new email sender, RSS feed, RFP board, or web scrape source
        - `trigger-source-discovery` - Trigger AI-powered search for new RFP sources
    MARKDOWN;

    protected array $tools = [
        \App\Mcp\Tools\SearchEmailsTool::class,
        \App\Mcp\Tools\SearchGmailLiveTool::class,
        \App\Mcp\Tools\SearchGoogleDriveTool::class,
        \App\Mcp\Tools\GetChannelOperationsContextTool::class,
        \App\Mcp\Tools\LinkSlackContextTool::class,
        \App\Mcp\Tools\ListSlackWatchlistTool::class,
        \App\Mcp\Tools\ManageSlackWatchlistTool::class,
        \App\Mcp\Tools\RunChannelIntegrationActionTool::class,
        \App\Mcp\Tools\SearchRfpOpportunitiesTool::class,
        \App\Mcp\Tools\CreateRfpOpportunityTool::class,
        \App\Mcp\Tools\UpdateRfpOpportunityTool::class,
        \App\Mcp\Tools\FetchRfpListingTool::class,
        \App\Mcp\Tools\TriggerRfpScanTool::class,
        \App\Mcp\Tools\TriggerRfpEvaluationTool::class,
        \App\Mcp\Tools\CreateRfpProposalTool::class,
        \App\Mcp\Tools\GetRfpProposalTool::class,
        \App\Mcp\Tools\UpdateRfpProposalTool::class,
        \App\Mcp\Tools\RecordRfpOutcomeTool::class,
        \App\Mcp\Tools\GetRfpLearningInsightsTool::class,
        \App\Mcp\Tools\CreateRfpLearningInsightTool::class,
        \App\Mcp\Tools\ListRfpSourcesTool::class,
        \App\Mcp\Tools\AddRfpSourceTool::class,
        \App\Mcp\Tools\TriggerSourceDiscoveryTool::class,
    ];
}
