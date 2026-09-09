<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ClientPortalMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Ensures users can only access their own client data,
     * or allows internal team members to impersonate clients.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Check for admin/owner impersonation
        $impersonatingClientId = session('impersonating_client_id');
        if ($impersonatingClientId && $user->isInternalUser()) {
            $client = Client::find($impersonatingClientId);
            if (! $client) {
                session()->forget(['impersonating_client_id', 'impersonating_client_name', 'original_user_id']);

                return redirect()->route('clients.index');
            }

            Inertia::share('impersonating', [
                'active' => true,
                'clientId' => $client->id,
                'clientName' => $client->name,
            ]);
            Inertia::share('portalClient', $client);

            // Make the resolved client available to controllers
            $request->attributes->set('portal_client', $client);

            return $next($request);
        }

        // Check if user is a client user
        if (! $user->isClientUser()) {
            abort(403, 'Access denied. This area is for client users only.');
        }

        if (! $user->client_id) {
            abort(403, 'No client account associated with your user.');
        }

        Inertia::share('portalClient', $user->client);
        Inertia::share('impersonating', ['active' => false]);

        // Make the resolved client available to controllers
        $request->attributes->set('portal_client', $user->client);

        return $next($request);
    }
}
