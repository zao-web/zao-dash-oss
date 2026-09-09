<?php

namespace App\Http\Controllers;

use App\Models\IdealCustomerProfile;
use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OutreachCampaignController extends Controller
{
    public function index()
    {
        $campaigns = OutreachCampaign::with(['icp', 'sequences'])
            ->withCount(['messages', 'messages as sent_count' => fn ($q) => $q->where('status', 'sent')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'status' => $c->status,
                'type' => $c->type,
                'icp_name' => $c->icp?->name,
                'sequences_count' => $c->sequences->count(),
                'messages_count' => $c->messages_count,
                'sent_count' => $c->sent_count,
                'metrics' => $c->metrics ?? [],
                'created_at' => $c->created_at->toDateString(),
            ]);

        $stats = [
            'total' => OutreachCampaign::count(),
            'active' => OutreachCampaign::where('status', 'active')->count(),
            'messages_sent' => OutreachMessage::where('status', 'sent')->count(),
            'replies' => OutreachMessage::whereNotNull('replied_at')->count(),
            'reply_rate' => OutreachMessage::where('status', 'sent')->count() > 0
                ? round(OutreachMessage::whereNotNull('replied_at')->count() / OutreachMessage::where('status', 'sent')->count() * 100, 1)
                : 0,
        ];

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'icps' => IdealCustomerProfile::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function show(OutreachCampaign $campaign)
    {
        $campaign->load(['icp', 'sequences' => fn ($q) => $q->orderBy('step_number')]);

        $messages = OutreachMessage::where('sequence_id', $campaign->sequences->pluck('id'))
            ->with('prospect')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'prospect_name' => $m->prospect?->company_name,
                'contact_name' => $m->prospect?->contact_name,
                'channel' => $m->channel,
                'subject' => $m->subject,
                'status' => $m->status,
                'scheduled_for' => $m->scheduled_for?->toDateTimeString(),
                'sent_at' => $m->sent_at?->toDateTimeString(),
                'opened_at' => $m->opened_at?->toDateTimeString(),
                'replied_at' => $m->replied_at?->toDateTimeString(),
            ]);

        // Calculate metrics
        $metrics = [
            'enrolled' => $campaign->metrics['enrolled'] ?? 0,
            'sent' => OutreachMessage::whereIn('sequence_id', $campaign->sequences->pluck('id'))
                ->where('status', 'sent')->count(),
            'opened' => OutreachMessage::whereIn('sequence_id', $campaign->sequences->pluck('id'))
                ->whereNotNull('opened_at')->count(),
            'replied' => OutreachMessage::whereIn('sequence_id', $campaign->sequences->pluck('id'))
                ->whereNotNull('replied_at')->count(),
            'converted' => $campaign->metrics['converted'] ?? 0,
        ];

        return Inertia::render('Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
                'status' => $campaign->status,
                'type' => $campaign->type,
                'min_icp_score' => $campaign->min_icp_score,
                'target_industries' => $campaign->target_industries ?? [],
                'target_titles' => $campaign->target_titles ?? [],
                'use_email' => $campaign->use_email,
                'use_linkedin' => $campaign->use_linkedin,
                'use_phone' => $campaign->use_phone,
                'icp' => $campaign->icp ? ['id' => $campaign->icp->id, 'name' => $campaign->icp->name] : null,
                'created_at' => $campaign->created_at->toDateTimeString(),
            ],
            'sequences' => $campaign->sequences->map(fn ($s) => [
                'id' => $s->id,
                'step_number' => $s->step_number,
                'channel' => $s->channel,
                'subject_template' => $s->subject_template,
                'body_template' => substr($s->body_template ?? '', 0, 200),
                'delay_days' => $s->delay_days,
                'condition' => $s->condition,
                'requires_approval' => $s->requires_approval,
                'is_active' => $s->is_active,
            ]),
            'messages' => $messages,
            'metrics' => $metrics,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:cold_outreach,nurture,reengagement',
            'icp_id' => 'nullable|exists:ideal_customer_profiles,id',
            'min_icp_score' => 'nullable|integer|min:0|max:100',
            'target_industries' => 'nullable|array',
            'target_titles' => 'nullable|array',
            'use_email' => 'boolean',
            'use_linkedin' => 'boolean',
        ]);

        $validated['status'] = 'draft';
        $validated['metrics'] = ['enrolled' => 0, 'converted' => 0];

        $campaign = OutreachCampaign::create($validated);

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Campaign created.');
    }

    public function update(OutreachCampaign $campaign, Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'min_icp_score' => 'nullable|integer|min:0|max:100',
            'target_industries' => 'nullable|array',
            'target_titles' => 'nullable|array',
        ]);

        $campaign->update($validated);

        return back()->with('success', 'Campaign updated.');
    }

    public function activate(OutreachCampaign $campaign)
    {
        if ($campaign->sequences()->count() === 0) {
            return back()->with('error', 'Add at least one sequence before activating.');
        }

        $campaign->update(['status' => 'active']);

        return back()->with('success', 'Campaign activated.');
    }

    public function pause(OutreachCampaign $campaign)
    {
        $campaign->update(['status' => 'paused']);

        return back()->with('success', 'Campaign paused.');
    }
}
