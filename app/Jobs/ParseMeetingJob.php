<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Task;
use App\Services\AI\ClaudeCliService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Parses meeting notes/transcripts to extract:
 * - Action items → Tasks
 * - Client mentions → Update client records
 * - Follow-up needs → Lead stage updates
 * - Key decisions → Meeting summary
 */
class ParseMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public CalendarEvent $event
    ) {}

    public function handle(): void
    {
        $content = $this->event->notes ?? $this->event->description;
        if (empty($content)) {
            Log::info('No meeting content to parse', ['event_id' => $this->event->id]);

            return;
        }

        Log::info('Parsing meeting', ['event_id' => $this->event->id, 'title' => $this->event->title]);

        // Use Claude to parse the meeting
        $parsed = $this->parseWithClaude($content);

        if (! $parsed) {
            Log::warning('Failed to parse meeting content', ['event_id' => $this->event->id]);

            return;
        }

        // Process extracted data
        $this->createTasks($parsed['action_items'] ?? []);
        $this->updateClients($parsed['client_mentions'] ?? []);
        $this->updateLeads($parsed['follow_ups'] ?? []);
        $this->storeSummary($parsed['summary'] ?? null, $parsed['key_decisions'] ?? []);

        // Create notification about parsed meeting
        $this->notifyParsed($parsed);
    }

    protected function parseWithClaude(string $content): ?array
    {
        $cli = new ClaudeCliService;

        if (! $cli->isConfigured()) {
            Log::warning('Claude CLI not configured');

            return $this->parseWithRegex($content);
        }

        $systemPrompt = 'You are a meeting notes parser. Extract structured data from meeting transcripts.';

        $prompt = <<<PROMPT
Analyze this meeting transcript/notes and extract structured data.

Meeting: {$this->event->title}
Date: {$this->event->start_at?->format('M j, Y')}
Attendees: {$this->formatAttendees()}

Content:
{$content}

Extract and return as JSON:
{
  "summary": "2-3 sentence meeting summary",
  "action_items": [
    {"task": "description", "assignee": "person name or null", "due": "date string or null", "priority": "high|medium|low"}
  ],
  "client_mentions": [
    {"name": "client/company name", "context": "what was discussed about them", "sentiment": "positive|neutral|negative"}
  ],
  "follow_ups": [
    {"contact": "person/company", "reason": "why follow up needed", "urgency": "high|medium|low"}
  ],
  "key_decisions": ["decision 1", "decision 2"]
}
PROMPT;

        try {
            $parsed = $cli->messageJson($prompt, $systemPrompt, 'sonnet', 120);

            if (! $parsed) {
                Log::warning('Failed to parse meeting JSON from Claude CLI');

                return $this->parseWithRegex($content);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Claude CLI parsing failed', ['error' => $e->getMessage()]);

            return $this->parseWithRegex($content);
        }
    }

    /**
     * Fallback regex-based parsing.
     */
    protected function parseWithRegex(string $content): array
    {
        $actionItems = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Look for action item patterns
            if (preg_match('/^[\-\*\[\]]+\s*(TODO|ACTION|TASK|@\w+)[:.\s]+(.+)/i', $line, $matches)) {
                $actionItems[] = [
                    'task' => trim($matches[2]),
                    'assignee' => null,
                    'due' => null,
                    'priority' => 'medium',
                ];
            }

            // Look for "will do" patterns
            if (preg_match('/(\w+)\s+will\s+(.+?)(?:\.|$)/i', $line, $matches)) {
                $actionItems[] = [
                    'task' => trim($matches[2]),
                    'assignee' => $matches[1],
                    'due' => null,
                    'priority' => 'medium',
                ];
            }
        }

        return [
            'summary' => 'Meeting notes parsed',
            'action_items' => $actionItems,
            'client_mentions' => [],
            'follow_ups' => [],
            'key_decisions' => [],
        ];
    }

    protected function createTasks(array $actionItems): void
    {
        foreach ($actionItems as $item) {
            Task::create([
                'title' => $item['task'],
                'description' => "From meeting: {$this->event->title}",
                'status' => 'pending',
                'priority' => $item['priority'] ?? 'medium',
                'client_id' => $this->event->client_id,
                'project_id' => $this->event->project_id,
                'due_date' => $this->parseDueDate($item['due'] ?? null),
                'source' => 'meeting_parser',
                'source_id' => $this->event->id,
            ]);
        }

        Log::info('Created tasks from meeting', [
            'event_id' => $this->event->id,
            'count' => count($actionItems),
        ]);
    }

    protected function updateClients(array $mentions): void
    {
        foreach ($mentions as $mention) {
            $client = Client::where('name', 'like', "%{$mention['name']}%")->first();
            if (! $client) {
                continue;
            }

            // Add meeting note to client
            $existingNotes = $client->notes ?? '';
            $newNote = "\n\n[{$this->event->start_at?->format('M j')}] {$mention['context']}";

            $client->update([
                'notes' => $existingNotes.$newNote,
                'last_meeting_at' => $this->event->start_at,
            ]);

            // Adjust health score based on sentiment
            if ($mention['sentiment'] === 'negative') {
                $client->decrement('health_score', 5);
            } elseif ($mention['sentiment'] === 'positive') {
                $client->increment('health_score', 2);
            }
        }
    }

    protected function updateLeads(array $followUps): void
    {
        foreach ($followUps as $followUp) {
            $lead = Lead::where('company_name', 'like', "%{$followUp['contact']}%")
                ->orWhere('contact_name', 'like', "%{$followUp['contact']}%")
                ->first();

            if (! $lead) {
                continue;
            }

            $lead->update([
                'last_contacted_at' => $this->event->start_at,
                'notes' => ($lead->notes ?? '')."\n\n[{$this->event->start_at?->format('M j')}] {$followUp['reason']}",
            ]);

            // Move lead forward in pipeline if engagement is happening
            if ($lead->stage === 'new') {
                $lead->update(['stage' => 'qualified']);
            }
        }
    }

    protected function storeSummary(?string $summary, array $decisions): void
    {
        $this->event->update([
            'parsed_summary' => $summary,
            'key_decisions' => $decisions,
            'parsed_at' => now(),
        ]);
    }

    protected function notifyParsed(array $parsed): void
    {
        $actionCount = count($parsed['action_items'] ?? []);
        $clientCount = count($parsed['client_mentions'] ?? []);

        if ($actionCount === 0 && $clientCount === 0) {
            return;
        }

        $notification = Notification::create([
            'type' => 'meeting_parsed',
            'title' => "Meeting parsed: {$this->event->title}",
            'message' => "{$actionCount} action items, {$clientCount} client mentions extracted",
            'icon' => 'calendar',
            'severity' => 'info',
            'action_url' => "/calendar/{$this->event->id}",
            'action_label' => 'View Meeting',
        ]);

        event(new NotificationCreated($notification));
    }

    protected function formatAttendees(): string
    {
        $attendees = $this->event->attendees ?? [];
        if (empty($attendees)) {
            return 'Not specified';
        }

        return is_array($attendees) ? implode(', ', $attendees) : $attendees;
    }

    protected function parseDueDate(?string $dateStr): ?\Carbon\Carbon
    {
        if (! $dateStr) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }
}
