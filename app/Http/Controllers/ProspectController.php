<?php

namespace App\Http\Controllers;

use App\Models\IdealCustomerProfile;
use App\Models\Prospect;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProspectController extends Controller
{
    public function index(Request $request)
    {
        $query = Prospect::with('icp')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->icp_id, fn ($q, $id) => $q->where('icp_id', $id))
            ->when($request->min_score, fn ($q, $s) => $q->where('icp_score', '>=', $s))
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('company_name', 'like', "%{$s}%")
                    ->orWhere('contact_name', 'like', "%{$s}%")
                    ->orWhere('contact_email', 'like', "%{$s}%");
            }));

        $prospects = $query->orderByDesc('icp_score')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn ($p) => [
                'id' => $p->id,
                'company_name' => $p->company_name,
                'company_website' => $p->company_website,
                'contact_name' => $p->contact_name,
                'contact_title' => $p->contact_title,
                'contact_email' => $p->contact_email,
                'industry' => $p->industry,
                'company_size' => $p->company_size,
                'icp_score' => $p->icp_score,
                'icp_name' => $p->icp?->name,
                'status' => $p->status,
                'source' => $p->source,
                'signals' => $p->signals ?? [],
                'created_at' => $p->created_at->toDateString(),
            ]);

        $stats = [
            'total' => Prospect::count(),
            'qualified' => Prospect::where('status', 'qualified')->count(),
            'new' => Prospect::where('status', 'new')->count(),
            'converted' => Prospect::where('status', 'converted')->count(),
            'avg_score' => round(Prospect::avg('icp_score') ?? 0),
        ];

        return Inertia::render('Prospects/Index', [
            'prospects' => $prospects,
            'stats' => $stats,
            'icps' => IdealCustomerProfile::where('is_active', true)->get(['id', 'name', 'slug']),
            'filters' => $request->only(['status', 'icp_id', 'min_score', 'search']),
        ]);
    }

    public function show(Prospect $prospect)
    {
        $prospect->load(['icp', 'messages', 'convertedLead']);

        return Inertia::render('Prospects/Show', [
            'prospect' => [
                'id' => $prospect->id,
                'company_name' => $prospect->company_name,
                'company_website' => $prospect->company_website,
                'company_linkedin' => $prospect->company_linkedin,
                'industry' => $prospect->industry,
                'company_size' => $prospect->company_size,
                'location' => $prospect->location,
                'estimated_revenue' => $prospect->estimated_revenue,
                'contact_name' => $prospect->contact_name,
                'contact_title' => $prospect->contact_title,
                'contact_email' => $prospect->contact_email,
                'contact_phone' => $prospect->contact_phone,
                'contact_linkedin' => $prospect->contact_linkedin,
                'tech_stack' => $prospect->tech_stack ?? [],
                'signals' => $prospect->signals ?? [],
                'research_notes' => $prospect->research_notes,
                'icp_score' => $prospect->icp_score,
                'score_breakdown' => $prospect->score_breakdown ?? [],
                'status' => $prospect->status,
                'source' => $prospect->source,
                'source_url' => $prospect->source_url,
                'icp' => $prospect->icp ? [
                    'id' => $prospect->icp->id,
                    'name' => $prospect->icp->name,
                ] : null,
                'converted_to_lead_id' => $prospect->converted_to_lead_id,
                'converted_at' => $prospect->converted_at?->toDateTimeString(),
                'created_at' => $prospect->created_at->toDateTimeString(),
            ],
            'messages' => $prospect->messages->map(fn ($m) => [
                'id' => $m->id,
                'channel' => $m->channel,
                'subject' => $m->subject,
                'status' => $m->status,
                'sent_at' => $m->sent_at?->toDateTimeString(),
                'opened_at' => $m->opened_at?->toDateTimeString(),
                'replied_at' => $m->replied_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_website' => 'nullable|url|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_title' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|string|max:50',
            'icp_id' => 'nullable|exists:ideal_customer_profiles,id',
            'research_notes' => 'nullable|string',
        ]);

        $validated['status'] = 'new';
        $validated['source'] = 'manual';

        $prospect = Prospect::create($validated);

        // Auto-score if ICP provided
        if ($prospect->icp) {
            $prospect->calculateIcpScore();
        }

        return redirect()->route('prospects.show', $prospect)
            ->with('success', 'Prospect created.');
    }

    public function update(Prospect $prospect, Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'company_website' => 'nullable|url|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_title' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|string|max:50',
            'status' => 'sometimes|in:new,researching,qualified,unqualified,converted',
            'research_notes' => 'nullable|string',
        ]);

        $prospect->update($validated);

        return back()->with('success', 'Prospect updated.');
    }

    public function destroy(Prospect $prospect)
    {
        $prospect->delete();

        return redirect()->route('prospects.index')
            ->with('success', 'Prospect deleted.');
    }

    public function convertToLead(Prospect $prospect, Request $request)
    {
        if ($prospect->status === 'converted') {
            return back()->with('error', 'Prospect already converted.');
        }

        $validated = $request->validate([
            'deal_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $lead = $prospect->convertToLead($validated['deal_value'] ?? null);

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Prospect converted to lead.');
    }
}
