<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the client-scoped MCP server (/mcp/zao-client).
 *
 * The Sanctum token must be tokenable by a Client — i.e. it was minted for a
 * specific client site with `php artisan zao:client-token {slug}`. The token IS
 * the client: scoped tools read the client from $request->user() and never
 * accept a client identifier, so one client site can never reach another
 * client's data. Internal User tokens are rejected here (they belong on the
 * internal servers behind EnsureInternalUser).
 */
class EnsureClientToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenable = $request->user();

        if (! $tokenable instanceof Client) {
            abort(403, 'Access denied. This endpoint requires a client-scoped token.');
        }

        if ($tokenable->isArchived()) {
            abort(403, 'This client is archived.');
        }

        // Make the bound client unambiguous to downstream tools.
        $request->attributes->set('client_scope', $tokenable);

        return $next($request);
    }
}
