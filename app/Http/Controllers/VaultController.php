<?php

namespace App\Http\Controllers;

use App\Models\VaultSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class VaultController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:255|unique:vault_secrets,key',
            'value' => 'required|string',
            'type' => 'required|in:api_key,oauth_token,password,certificate,other',
            'service' => 'required|string|max:255',
            'description' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'auto_rotate' => 'boolean',
            'rotate_interval_days' => 'nullable|integer|min:1|max:365',
        ]);

        $secret = VaultSecret::create([
            'name' => $validated['name'],
            'key' => $validated['key'],
            'encrypted_value' => Crypt::encryptString($validated['value']),
            'type' => $validated['type'],
            'service' => $validated['service'],
            'description' => $validated['description'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Secret added successfully');
    }

    public function update(Request $request, VaultSecret $vaultSecret)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:api_key,oauth_token,password,certificate,other',
            'service' => 'required|string|max:255',
            'description' => 'nullable|string',
            'expires_at' => 'nullable|date',
        ]);

        $vaultSecret->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'service' => $validated['service'],
            'description' => $validated['description'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Secret updated successfully');
    }

    public function destroy(VaultSecret $vaultSecret)
    {
        $vaultSecret->delete();

        return redirect()->back()->with('success', 'Secret deleted successfully');
    }

    public function rotate(Request $request, VaultSecret $vaultSecret)
    {
        $validated = $request->validate([
            'new_value' => 'nullable|string',
            'auto_generate' => 'boolean',
            'notify_agents' => 'boolean',
        ]);

        // Generate new value if auto_generate is true
        if ($validated['auto_generate']) {
            $newValue = $this->generateSecureValue();
        } else {
            $newValue = $validated['new_value'];
        }

        if (! $newValue) {
            return redirect()->back()->withErrors(['new_value' => 'New value is required']);
        }

        // Update the secret with new value and rotation timestamp
        $vaultSecret->update([
            'encrypted_value' => Crypt::encryptString($newValue),
            'last_rotated' => now(),
        ]);

        // TODO: Implement agent notification if notify_agents is true

        return redirect()->back()->with('success', 'Secret rotated successfully');
    }

    private function generateSecureValue(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        $length = 32;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }
}
