<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'stage' => 'nullable|in:new,qualified,proposal,negotiation,won,lost',
            'source' => 'nullable|in:referral,website,linkedin,cold_outreach,conference,other',
            'deal_value' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'last_contacted_at' => 'nullable|date',
        ]);

        Lead::create($validated);

        return redirect()->back()->with('success', 'Lead created successfully.');
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'stage' => 'nullable|in:new,qualified,proposal,negotiation,won,lost',
            'source' => 'nullable|in:referral,website,linkedin,cold_outreach,conference,other',
            'deal_value' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'last_contacted_at' => 'nullable|date',
        ]);

        $lead->update($validated);

        return redirect()->back()->with('success', 'Lead updated successfully.');
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return redirect()->back()->with('success', 'Lead deleted successfully.');
    }

    public function updateStage(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'stage' => 'required|in:new,qualified,proposal,negotiation,won,lost',
            'position' => 'nullable|integer|min:0',
        ]);

        $lead->update($validated);

        return redirect()->back()->with('success', 'Lead stage updated successfully.');
    }

    public function closeDeal(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'stage' => 'required|in:won,lost',
            'deal_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'lost_reason' => 'nullable|string|max:255',
        ]);

        $updateData = [
            'stage' => $validated['stage'],
            'converted_at' => now(),
        ];

        if (isset($validated['deal_value'])) {
            $updateData['deal_value'] = $validated['deal_value'];
        }

        if (isset($validated['notes'])) {
            $updateData['notes'] = $validated['notes'];
        }

        if ($validated['stage'] === 'lost' && isset($validated['lost_reason'])) {
            $updateData['lost_reason'] = $validated['lost_reason'];
        }

        $lead->update($updateData);

        $message = $validated['stage'] === 'won' ? 'Deal marked as won!' : 'Lead marked as lost.';

        return redirect()->back()->with('success', $message);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'leads' => 'required|array',
            'leads.*.id' => 'required|exists:leads,id',
            'leads.*.position' => 'required|integer|min:0',
            'leads.*.stage' => 'required|in:new,qualified,proposal,negotiation,won,lost',
        ]);

        foreach ($validated['leads'] as $leadData) {
            Lead::where('id', $leadData['id'])->update([
                'position' => $leadData['position'],
                'stage' => $leadData['stage'],
            ]);
        }

        return redirect()->back()->with('success', 'Leads reordered successfully.');
    }

    public function assign(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $lead->update($validated);

        return redirect()->back()->with('success', 'Lead assigned successfully.');
    }
}
