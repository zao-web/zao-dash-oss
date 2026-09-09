<?php

namespace App\Http\Controllers;

use App\Jobs\ParseMeetingJob;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives meeting webhooks from:
 * - Google Calendar (event completion)
 * - Otter.ai, Fireflies.ai (transcription webhooks)
 * - Manual upload (meeting notes)
 */
class MeetingParserWebhookController extends Controller
{
    /**
     * Handle Google Calendar webhook.
     */
    public function googleCalendar(Request $request)
    {
        // Verify webhook signature if configured
        if (! $this->verifyGoogleSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $resourceId = $payload['resourceId'] ?? null;

        Log::info('Google Calendar webhook received', ['resource_id' => $resourceId]);

        // Find the calendar event
        $event = CalendarEvent::where('google_event_id', $resourceId)->first();
        if (! $event) {
            return response()->json(['status' => 'ignored', 'reason' => 'Event not found']);
        }

        // Queue parsing if event has ended and has notes/description
        if ($event->end_at->isPast() && ($event->description || $event->notes)) {
            ParseMeetingJob::dispatch($event);
        }

        return response()->json(['status' => 'queued']);
    }

    /**
     * Handle transcription service webhooks (Otter.ai, Fireflies.ai, etc.)
     */
    public function transcription(Request $request)
    {
        $validated = $request->validate([
            'service' => 'required|in:otter,fireflies,fathom,manual',
            'transcript' => 'required|string',
            'meeting_id' => 'nullable|string',
            'title' => 'nullable|string',
            'participants' => 'nullable|array',
            'duration_minutes' => 'nullable|integer',
            'recorded_at' => 'nullable|date',
        ]);

        Log::info('Transcription webhook received', [
            'service' => $validated['service'],
            'meeting_id' => $validated['meeting_id'] ?? 'manual',
        ]);

        // Try to match to existing calendar event
        $event = null;
        if (! empty($validated['meeting_id'])) {
            $event = CalendarEvent::where('google_event_id', $validated['meeting_id'])
                ->orWhere('external_id', $validated['meeting_id'])
                ->first();
        }

        // Create new event record if not found
        if (! $event) {
            $event = CalendarEvent::create([
                'title' => $validated['title'] ?? 'Meeting',
                'description' => null,
                'notes' => $validated['transcript'],
                'start_at' => $validated['recorded_at'] ?? now(),
                'end_at' => isset($validated['recorded_at'], $validated['duration_minutes'])
                    ? \Carbon\Carbon::parse($validated['recorded_at'])->addMinutes($validated['duration_minutes'])
                    : now(),
                'attendees' => $validated['participants'] ?? [],
                'external_id' => $validated['meeting_id'] ?? null,
                'source' => $validated['service'],
            ]);
        } else {
            // Update existing event with transcript
            $event->update([
                'notes' => $validated['transcript'],
                'source' => $validated['service'],
            ]);
        }

        // Queue parsing
        ParseMeetingJob::dispatch($event);

        return response()->json([
            'status' => 'queued',
            'event_id' => $event->id,
        ]);
    }

    /**
     * Manual meeting notes upload endpoint.
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'notes' => 'required|string',
            'client_id' => 'nullable|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'meeting_date' => 'nullable|date',
            'attendees' => 'nullable|string',
        ]);

        $event = CalendarEvent::create([
            'title' => $validated['title'],
            'notes' => $validated['notes'],
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'start_at' => $validated['meeting_date'] ?? now(),
            'end_at' => $validated['meeting_date'] ?? now(),
            'attendees' => $validated['attendees']
                ? array_map('trim', explode(',', $validated['attendees']))
                : [],
            'source' => 'manual',
        ]);

        ParseMeetingJob::dispatch($event);

        return response()->json([
            'status' => 'queued',
            'event_id' => $event->id,
            'message' => 'Meeting notes queued for parsing',
        ]);
    }

    protected function verifyGoogleSignature(Request $request): bool
    {
        // In production, verify X-Goog-Channel-Token matches configured token
        $token = $request->header('X-Goog-Channel-Token');
        $expectedToken = config('services.google.webhook_token');

        if (! $expectedToken) {
            // Skip verification if not configured
            return true;
        }

        return $token === $expectedToken;
    }
}
