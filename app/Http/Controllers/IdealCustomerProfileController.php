<?php

namespace App\Http\Controllers;

use App\Models\IdealCustomerProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdealCustomerProfileController extends Controller
{
    public function index(): Response
    {
        $icps = IdealCustomerProfile::query()
            ->withCount('prospects')
            ->orderBy('name')
            ->get()
            ->map(fn ($icp) => [
                'id' => $icp->id,
                'name' => $icp->name,
                'slug' => $icp->slug,
                'description' => $icp->description,
                'industries' => $icp->industries ?? [],
                'company_sizes' => $icp->company_sizes ?? [],
                'tech_stack' => $icp->tech_stack ?? [],
                'avg_deal_value' => $icp->avg_deal_value,
                'is_active' => $icp->is_active,
                'prospects_count' => $icp->prospects_count,
                'stats' => $icp->prospect_stats,
            ]);

        return Inertia::render('Icps/Index', [
            'icps' => $icps,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'industries' => 'required|array|min:1',
            'industries.*' => 'string',
            'company_sizes' => 'nullable|array',
            'company_sizes.*' => 'string',
            'locations' => 'nullable|array',
            'locations.*' => 'string',
            'tech_stack' => 'nullable|array',
            'tech_stack.*' => 'string',
            'tools_used' => 'nullable|array',
            'tools_used.*' => 'string',
            'buying_signals' => 'nullable|array',
            'buying_signals.*' => 'string',
            'pain_points' => 'nullable|array',
            'pain_points.*' => 'string',
            'avg_deal_value' => 'nullable|numeric|min:0',
            'weight_industry' => 'nullable|integer|min:0|max:100',
            'weight_size' => 'nullable|integer|min:0|max:100',
            'weight_tech' => 'nullable|integer|min:0|max:100',
            'weight_signals' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['weight_industry'] ??= 25;
        $validated['weight_size'] ??= 20;
        $validated['weight_tech'] ??= 30;
        $validated['weight_signals'] ??= 25;
        $validated['is_active'] ??= true;

        IdealCustomerProfile::create($validated);

        return redirect()->back()->with('success', 'ICP created successfully.');
    }

    public function update(Request $request, IdealCustomerProfile $icp)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'industries' => 'required|array|min:1',
            'industries.*' => 'string',
            'company_sizes' => 'nullable|array',
            'company_sizes.*' => 'string',
            'locations' => 'nullable|array',
            'locations.*' => 'string',
            'tech_stack' => 'nullable|array',
            'tech_stack.*' => 'string',
            'tools_used' => 'nullable|array',
            'tools_used.*' => 'string',
            'buying_signals' => 'nullable|array',
            'buying_signals.*' => 'string',
            'pain_points' => 'nullable|array',
            'pain_points.*' => 'string',
            'avg_deal_value' => 'nullable|numeric|min:0',
            'weight_industry' => 'nullable|integer|min:0|max:100',
            'weight_size' => 'nullable|integer|min:0|max:100',
            'weight_tech' => 'nullable|integer|min:0|max:100',
            'weight_signals' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $icp->update($validated);

        return redirect()->back()->with('success', 'ICP updated successfully.');
    }

    public function destroy(IdealCustomerProfile $icp)
    {
        $icp->delete();

        return redirect()->back()->with('success', 'ICP deleted successfully.');
    }

    public function toggle(IdealCustomerProfile $icp)
    {
        $icp->update(['is_active' => ! $icp->is_active]);

        $status = $icp->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "ICP {$status} successfully.");
    }

    public function score(Request $request, IdealCustomerProfile $icp)
    {
        $validated = $request->validate([
            'industry' => 'nullable|string',
            'company_size' => 'nullable|string',
            'tech_stack' => 'nullable|array',
            'signals' => 'nullable|array',
        ]);

        $result = $icp->scoreProspect($validated);

        return response()->json($result);
    }
}
