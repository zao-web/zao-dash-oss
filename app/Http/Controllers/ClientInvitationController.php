<?php

namespace App\Http\Controllers;

use App\Mail\ClientPortalInvitation;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ClientInvitationController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'contact_id' => 'nullable|exists:client_contacts,id',
        ]);

        // Check if user already has portal access
        if (User::where('email', $validated['email'])->where('client_id', $client->id)->exists()) {
            return redirect()->back()->withErrors(['email' => 'This email already has portal access.']);
        }

        // Check for pending invitation
        $existing = ClientInvitation::where('client_id', $client->id)
            ->where('email', $validated['email'])
            ->pending()
            ->first();

        if ($existing) {
            return redirect()->back()->withErrors(['email' => 'A pending invitation already exists for this email.']);
        }

        $invitation = ClientInvitation::create([
            'client_id' => $client->id,
            'client_contact_id' => $validated['contact_id'] ?? null,
            'email' => $validated['email'],
            'token' => ClientInvitation::generateToken(),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($validated['email'])->send(new ClientPortalInvitation($invitation));

        return redirect()->back()->with('success', 'Invitation sent to '.$validated['email']);
    }

    public function resend(ClientInvitation $invitation)
    {
        if ($invitation->isAccepted()) {
            return redirect()->back()->withErrors(['invitation' => 'This invitation has already been accepted.']);
        }

        // Generate new token and extend expiration
        $invitation->update([
            'token' => ClientInvitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new ClientPortalInvitation($invitation));

        return redirect()->back()->with('success', 'Invitation resent to '.$invitation->email);
    }

    public function destroy(ClientInvitation $invitation)
    {
        $email = $invitation->email;
        $invitation->delete();

        return redirect()->back()->with('success', 'Invitation for '.$email.' cancelled.');
    }
}
