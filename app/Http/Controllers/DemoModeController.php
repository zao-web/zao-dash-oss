<?php

namespace App\Http\Controllers;

use App\Http\Middleware\MaskDemoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DemoModeController extends Controller
{
    /**
     * Toggle owner-only demo mode for the current session.
     *
     * When enabled, every Inertia response is anonymized by MaskDemoData. A
     * fresh seed is generated on each enable so consecutive demos use a
     * different (but internally consistent) set of fake values.
     */
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === 'owner', 403);

        $enabled = ! $request->session()->get(MaskDemoData::SESSION_KEY, false);

        $request->session()->put(MaskDemoData::SESSION_KEY, $enabled);

        if ($enabled) {
            $request->session()->put(MaskDemoData::SEED_KEY, bin2hex(random_bytes(8)));
        } else {
            $request->session()->forget(MaskDemoData::SEED_KEY);
        }

        return back()->with('success', $enabled ? 'Demo mode on — data anonymized.' : 'Demo mode off.');
    }
}
