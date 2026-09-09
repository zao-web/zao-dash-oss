<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTranscriptEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Email $email
    ) {}

    public function handle(): void
    {
        if (! $this->email->isGeminiTranscript()) {
            Log::info('Email is not a Gemini transcript, skipping', ['email_id' => $this->email->id]);

            return;
        }

        Log::info('Processing Gemini transcript email', [
            'email_id' => $this->email->id,
            'subject' => $this->email->subject,
        ]);

        $calendarEvent = $this->findOrCreateCalendarEvent();

        if (! $calendarEvent) {
            Log::warning('Could not find or create calendar event for transcript', [
                'email_id' => $this->email->id,
            ]);

            return;
        }

        $transcriptContent = $this->extractTranscriptContent();

        if (empty($transcriptContent)) {
            Log::warning('No transcript content found in email', ['email_id' => $this->email->id]);

            return;
        }

        $calendarEvent->update([
            'notes' => $transcriptContent,
            'transcript_email_id' => $this->email->id,
        ]);

        $this->email->update(['is_processed' => true]);

        ParseMeetingJob::dispatch($calendarEvent);

        Log::info('Dispatched ParseMeetingJob for transcript', [
            'email_id' => $this->email->id,
            'calendar_event_id' => $calendarEvent->id,
        ]);
    }

    protected function findOrCreateCalendarEvent(): ?CalendarEvent
    {
        $meetingTitle = $this->extractMeetingTitle();
        $meetingDate = $this->extractMeetingDate();

        if ($meetingDate) {
            $event = CalendarEvent::where('title', 'like', "%{$meetingTitle}%")
                ->whereDate('start_at', $meetingDate->toDateString())
                ->first();

            if ($event) {
                return $event;
            }
        }

        $event = CalendarEvent::where('title', 'like', "%{$meetingTitle}%")
            ->where('start_at', '>=', now()->subDays(7))
            ->where('start_at', '<=', now())
            ->orderBy('start_at', 'desc')
            ->first();

        if ($event) {
            return $event;
        }

        return CalendarEvent::create([
            'title' => $meetingTitle ?: 'Meeting from transcript',
            'start_at' => $meetingDate ?? now()->subHour(),
            'end_at' => $meetingDate ? $meetingDate->copy()->addHour() : now(),
            'is_client_meeting' => true,
            'google_event_id' => 'transcript_'.$this->email->id,
        ]);
    }

    protected function extractMeetingTitle(): string
    {
        $subject = $this->email->subject ?? '';

        $subject = preg_replace('/^(Fwd?:|Re:)\s*/i', '', $subject);

        if (preg_match('/Meeting transcript[:\s-]+(.+)/i', $subject, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/transcript[:\s-]+(.+)/i', $subject, $matches)) {
            return trim($matches[1]);
        }

        return trim($subject) ?: 'Untitled Meeting';
    }

    protected function extractMeetingDate(): ?Carbon
    {
        $body = $this->email->body_text ?? $this->email->body ?? '';

        if (preg_match('/(\w+day,?\s+)?(\w+\s+\d{1,2},?\s+\d{4})/i', $body, $matches)) {
            try {
                return Carbon::parse($matches[0]);
            } catch (\Exception $e) {
            }
        }

        if (preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', $body, $matches)) {
            try {
                return Carbon::parse($matches[1]);
            } catch (\Exception $e) {
            }
        }

        return $this->email->received_at;
    }

    protected function extractTranscriptContent(): string
    {
        $body = $this->email->body_text ?? '';

        if (empty($body)) {
            $body = $this->email->body ?? '';
            $body = strip_tags($body);
        }

        $body = preg_replace('/^.*?(transcript|meeting notes)/ims', '$1', $body);

        $body = preg_replace('/---+\s*(This email was sent|Unsubscribe|View in browser).*$/ims', '', $body);

        return trim($body);
    }
}
