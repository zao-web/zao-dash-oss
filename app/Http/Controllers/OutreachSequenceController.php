<?php

namespace App\Http\Controllers;

use App\Models\OutreachCampaign;
use App\Models\OutreachSequence;
use Illuminate\Http\Request;

class OutreachSequenceController extends Controller
{
    /**
     * Store a new sequence step.
     */
    public function store(Request $request, OutreachCampaign $campaign)
    {
        $validated = $request->validate([
            'channel' => 'required|in:email,linkedin,phone,manual',
            'subject_template' => 'nullable|string|max:255',
            'body_template' => 'required|string',
            'delay_days' => 'required|integer|min:0|max:90',
            'condition' => 'required|in:always,no_reply,opened,not_opened',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // Get next step number
        $maxStep = $campaign->sequences()->max('step_number') ?? 0;

        $campaign->sequences()->create([
            ...$validated,
            'step_number' => $maxStep + 1,
            'requires_approval' => $validated['requires_approval'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Sequence step added.');
    }

    /**
     * Update a sequence step.
     */
    public function update(Request $request, OutreachCampaign $campaign, OutreachSequence $sequence)
    {
        $validated = $request->validate([
            'channel' => 'sometimes|in:email,linkedin,phone,manual',
            'subject_template' => 'nullable|string|max:255',
            'body_template' => 'sometimes|string',
            'delay_days' => 'sometimes|integer|min:0|max:90',
            'condition' => 'sometimes|in:always,no_reply,opened,not_opened',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $sequence->update($validated);

        return back()->with('success', 'Sequence step updated.');
    }

    /**
     * Delete a sequence step.
     */
    public function destroy(OutreachCampaign $campaign, OutreachSequence $sequence)
    {
        $deletedStep = $sequence->step_number;
        $sequence->delete();

        // Reorder remaining steps
        $campaign->sequences()
            ->where('step_number', '>', $deletedStep)
            ->decrement('step_number');

        return back()->with('success', 'Sequence step deleted.');
    }

    /**
     * Reorder a sequence step.
     */
    public function reorder(Request $request, OutreachCampaign $campaign, OutreachSequence $sequence)
    {
        $validated = $request->validate([
            'position' => 'required|integer|min:1',
        ]);

        $newPosition = $validated['position'];
        $oldPosition = $sequence->step_number;

        if ($newPosition === $oldPosition) {
            return back();
        }

        // Shift other sequences
        if ($newPosition < $oldPosition) {
            // Moving up: increment steps between new and old
            $campaign->sequences()
                ->where('step_number', '>=', $newPosition)
                ->where('step_number', '<', $oldPosition)
                ->increment('step_number');
        } else {
            // Moving down: decrement steps between old and new
            $campaign->sequences()
                ->where('step_number', '>', $oldPosition)
                ->where('step_number', '<=', $newPosition)
                ->decrement('step_number');
        }

        $sequence->update(['step_number' => $newPosition]);

        return back()->with('success', 'Sequence reordered.');
    }
}
