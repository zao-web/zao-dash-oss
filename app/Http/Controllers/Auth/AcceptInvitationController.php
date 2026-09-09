<?php

namespace App\Http\Controllers\Auth;

use App\Events\ClientInvitationAccepted;
use App\Http\Controllers\Controller;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class AcceptInvitationController extends Controller
{
    public function show(string $token)
    {
        $invitation = ClientInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            return Inertia::render('Portal/InvitationExpired', [
                'message' => 'This invitation has already been accepted.',
            ]);
        }

        if ($invitation->isExpired()) {
            return Inertia::render('Portal/InvitationExpired', [
                'message' => 'This invitation has expired. Please contact the team for a new invitation.',
            ]);
        }

        return Inertia::render('Portal/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'clientName' => $invitation->client->name,
            'contactName' => $invitation->contact?->name,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = ClientInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            return redirect()->route('portal.dashboard')->withErrors(['token' => 'This invitation has already been accepted.']);
        }

        if ($invitation->isExpired()) {
            return redirect()->route('login')->withErrors(['token' => 'This invitation has expired.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Check if user already exists
        $user = User::where('email', $invitation->email)->first();

        if ($user) {
            // Update existing user to link to this client
            if ($user->client_id && $user->client_id !== $invitation->client_id) {
                return redirect()->back()->withErrors(['email' => 'This email is already associated with another client.']);
            }
            $user->update([
                'client_id' => $invitation->client_id,
                'role' => 'client',
            ]);
        } else {
            // Create new user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'role' => 'client',
                'client_id' => $invitation->client_id,
            ]);
        }

        // Mark invitation as accepted
        $invitation->update(['accepted_at' => now()]);

        // Broadcast event for real-time notification
        event(new ClientInvitationAccepted($invitation->fresh(), $user));

        // Log the user in
        Auth::login($user);

        return redirect()->route('portal.dashboard')->with('success', 'Welcome to the portal!');
    }
}
