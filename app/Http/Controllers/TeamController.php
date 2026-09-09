<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:owner,admin,staff',
            'title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
        ]);

        // Generate temporary password for new user
        $validated['password'] = Hash::make(bin2hex(random_bytes(16)));

        $user = User::create($validated);

        // Generate password reset token and send invitation email
        $token = Password::broker()->createToken($user);
        $resetUrl = url(route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ], false));

        Mail::to($user->email)->send(new \App\Mail\TeamMemberInvitation($user, $resetUrl));

        return redirect()->back()->with('success', 'Team member invited successfully.');
    }

    public function update(Request $request, User $user)
    {
        // Check if it's just a role update (quick role change)
        if ($request->has('role') && count($request->all()) === 1) {
            $validated = $request->validate([
                'role' => 'required|in:owner,admin,staff',
            ]);

            $user->update($validated);

            return redirect()->back()->with('success', 'Role updated successfully.');
        }

        // Full profile update
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:255',
            'role' => 'required|in:owner,admin,staff',
            'title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.manage_clients' => 'nullable|boolean',
            'permissions.manage_projects' => 'nullable|boolean',
            'permissions.manage_team' => 'nullable|boolean',
            'permissions.manage_billing' => 'nullable|boolean',
            'permissions.view_vault' => 'nullable|boolean',
            'permissions.approve_work' => 'nullable|boolean',
        ]);

        $user->update($validated);

        return redirect()->back()->with('success', 'Team member updated successfully.');
    }

    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return redirect()->back()->withErrors(['error' => 'You cannot remove yourself from the team.']);
        }

        // Unassign all tasks before deletion
        $user->tasks()->update(['assigned_to' => null]);

        $user->delete();

        return redirect()->back()->with('success', 'Team member removed successfully.');
    }
}
