<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, Client $client)
    {
        if (! $request->user()->isInternalUser()) {
            abort(403, 'Only internal team members can view client portals.');
        }

        // Store impersonation in session
        session([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $request->user()->id,
        ]);

        return redirect()->route('portal.dashboard');
    }

    public function stop(Request $request)
    {
        $clientId = session('impersonating_client_id');

        // Clear impersonation session
        session()->forget([
            'impersonating_client_id',
            'impersonating_client_name',
            'original_user_id',
        ]);

        // Redirect back to client page if we know which client
        if ($clientId) {
            $client = Client::find($clientId);
            if ($client) {
                return redirect()->route('clients.show', $client->slug);
            }
        }

        return redirect()->route('clients.index');
    }
}
