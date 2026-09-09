<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class ZaoFinanceServer extends Server
{
    protected string $name = 'Zao Finance';

    public int $defaultPaginationLength = 50;

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Zao Finance MCP Server

        Agency billing analytics only. Household ledger, CPA/tax, and personal-bank tools are not included in this distribution.

        - `get-client-profitability` - Client profitability analysis with revenue, cost, margin
        - `get-invoice-velocity` - Invoice payment velocity (DSO) per client
        MARKDOWN;

    protected array $tools = [
        \App\Mcp\Tools\GetClientProfitabilityTool::class,
        \App\Mcp\Tools\GetInvoiceVelocityTool::class,
    ];
}