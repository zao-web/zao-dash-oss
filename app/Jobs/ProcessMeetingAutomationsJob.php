<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Notification;
use App\Services\AI\AnthropicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeetingAutomationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AnthropicService $ai): void
    {
        Log::info('Processing meeting automations');

        $stats = [
            'pre_briefs_sent' => 0,
            'post_followups_sent' => 0,
        ];

        $this->processPreBriefs($ai, $stats);
        $this->processPostFollowups($stats);

        Log::info('Meeting automations complete', $stats);
    }

    protected function processPreBriefs(AnthropicService $ai, array &$stats): void
    {
        $meetings = CalendarEvent::where('is_client_meeting', true)
            ->where('pre_brief_sent', false)
            ->where('start_at', '>', now())
            ->where('start_at', '<=', now()->addMinutes(35))
            ->with('client')
            ->get();

        foreach ($meetings as $meeting) {
            $this->sendPreBrief($meeting, $ai);
            $stats['pre_briefs_sent']++;
        }
    }

    protected function processPostFollowups(array &$stats): void
    {
        $meetings = CalendarEvent::where('is_client_meeting', true)
            ->where('post_followup_sent', false)
            ->where('end_at', '<', now())
            ->where('end_at', '>', now()->subHours(24))
            ->with('client')
            ->get();

        foreach ($meetings as $meeting) {
            $this->sendPostFollowup($meeting);
            $stats['post_followups_sent']++;
        }
    }

    protected function sendPreBrief(CalendarEvent $meeting, AnthropicService $ai): void
    {
        $client = $meeting->client;
        $briefing = $this->generatePreBrief($meeting, $client, $ai);

        $notification = Notification::create([
            'type' => 'meeting_pre_brief',
            'title' => "Meeting in 30 min: {$meeting->title}",
            'message' => $briefing,
            'icon' => 'calendar',
            'severity' => 'info',
            'action_url' => $client ? "/clients/{$client->slug}" : null,
            'action_label' => $client ? 'View Client' : null,
            'metadata' => [
                'event_id' => $meeting->id,
                'client_id' => $client?->id,
                'start_at' => $meeting->start_at->toIso8601String(),
            ],
        ]);

        event(new NotificationCreated($notification));

        $meeting->update(['pre_brief_sent' => true]);

        Log::info('Pre-brief sent', [
            'event_id' => $meeting->id,
            'client_id' => $client?->id,
        ]);
    }

    protected function sendPostFollowup(CalendarEvent $meeting): void
    {
        $client = $meeting->client;

        if ($meeting->notes || $meeting->description) {
            ParseMeetingJob::dispatch($meeting);
        }

        $followup = $this->generatePostFollowup($meeting, $client);

        $notification = Notification::create([
            'type' => 'meeting_post_followup',
            'title' => "Meeting ended: {$meeting->title}",
            'message' => $followup,
            'icon' => 'check-circle',
            'severity' => 'info',
            'action_url' => "/tasks/create?client_id={$client?->id}&source=meeting&meeting_id={$meeting->id}",
            'action_label' => 'Create Follow-up Task',
            'metadata' => [
                'event_id' => $meeting->id,
                'client_id' => $client?->id,
            ],
        ]);

        event(new NotificationCreated($notification));

        $meeting->update(['post_followup_sent' => true]);

        Log::info('Post-followup sent', [
            'event_id' => $meeting->id,
            'client_id' => $client?->id,
        ]);
    }

    protected function generatePreBrief(CalendarEvent $meeting, ?Client $client, AnthropicService $ai): string
    {
        if (! $client) {
            return 'Meeting with external attendees. Review calendar for details.';
        }

        $context = $this->buildClientContext($client);

        $prompt = <<<PROMPT
Generate a brief pre-meeting summary (2-3 sentences) for a client meeting.

Client: {$client->name}
Meeting: {$meeting->title}
Time: {$meeting->start_at->format('g:i A')}

Context:
{$context}

Focus on:
- Recent project status
- Any outstanding issues or action items
- Health score concerns if applicable

Keep it concise and actionable.
PROMPT;

        try {
            $response = $ai->message($prompt, 'You are a helpful assistant preparing meeting briefings for an agency.', [], 'claude-3-5-haiku-20241022', [], 200);

            return $this->extractTextContent($response);
        } catch (\Exception $e) {
            Log::warning('Failed to generate pre-brief with AI', ['error' => $e->getMessage()]);

            return "Upcoming meeting with {$client->name}. Health score: {$client->health_score}/100.";
        }
    }

    protected function generatePostFollowup(CalendarEvent $meeting, ?Client $client): string
    {
        if (! $client) {
            return 'Meeting completed. Consider adding notes and follow-up tasks.';
        }

        $duration = $meeting->start_at->diffInMinutes($meeting->end_at);

        return "Meeting with {$client->name} completed ({$duration} min). Add meeting notes to extract action items automatically.";
    }

    protected function buildClientContext(Client $client): string
    {
        $lines = [];

        $lines[] = "Health Score: {$client->health_score}/100";
        $lines[] = "Status: {$client->status}";

        $activeProjects = $client->projects()->where('status', 'active')->count();
        $lines[] = "Active Projects: {$activeProjects}";

        $pendingTasks = $client->tasks()->where('status', 'pending')->count();
        if ($pendingTasks > 0) {
            $lines[] = "Pending Tasks: {$pendingTasks}";
        }

        $lastEmail = $client->emails()->latest('received_at')->first();
        if ($lastEmail) {
            $lines[] = "Last email: {$lastEmail->received_at->diffForHumans()} - {$lastEmail->subject}";
        }

        if ($client->notes) {
            $recentNote = substr($client->notes, -200);
            $lines[] = "Recent notes: ...{$recentNote}";
        }

        return implode("\n", $lines);
    }

    protected function extractTextContent(array $response): string
    {
        $content = $response['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'] ?? '';
            }
        }

        return '';
    }
}
