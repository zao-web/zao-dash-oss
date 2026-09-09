<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalUser
{
    /**
     * Handle an incoming request.
     *
     * Ensures only internal team members (owner, admin, staff) can access
     * the protected resource. Client portal users are denied access.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Must be an internal User. A client-scoped token (tokenable = Client) is
        // NOT a User, so it can never reach the internal MCP servers — the
        // defensive half of per-client isolation (the other half is
        // EnsureClientToken on /mcp/zao-client).
        if (! $user instanceof \App\Models\User || ! $user->isInternalUser()) {
            abort(403, 'Access denied. This resource is restricted to internal team members.');
        }

        return $next($request);
    }
}
