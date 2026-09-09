<?php

use App\Http\Middleware\EnsureClientToken;
use App\Http\Middleware\EnsureInternalUser;
use App\Mcp\Servers\ZaoClientServer;
use App\Mcp\Servers\ZaoCommsServer;
use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Servers\ZaoFinanceServer;
use App\Mcp\Servers\ZaoWebServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/zao-dash', ZaoDashServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);

Mcp::web('/mcp/zao-finance', ZaoFinanceServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);

Mcp::web('/mcp/zao-web', ZaoWebServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);

Mcp::web('/mcp/zao-comms', ZaoCommsServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);

// Per-client scoped surface for deployed client sites (Zao Assistant widget).
// The token's tokenable is a Client (see EnsureClientToken); every tool is bound
// to that one client, so a site can never read or write another client's data.
Mcp::web('/mcp/zao-client', ZaoClientServer::class)
    ->middleware(['auth:sanctum', EnsureClientToken::class]);

Mcp::local('zao-dash', ZaoDashServer::class);
