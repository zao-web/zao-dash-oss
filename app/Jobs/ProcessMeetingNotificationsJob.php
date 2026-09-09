<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\CalendarEvent;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process calendar events and create meeting notifications.
 *
 * Creates notifications for:
 * - Upcoming meetings (30 minutes before)
 * - Completed meetings (prompting for follow-up tasks)
 */
class ProcessMeetingNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $this->processUpcomingMeetings();
        $this->processCompletedMeetings();
    }

    /**
     * Create notifications for meetings starting in ~30 minutes.
     */
    protected function processUpcomingMeetings(): void
    {
        $upcomingMeetings = CalendarEvent::where('is_client_meeting', true)
            ->where('pre_brief_sent', false)
            ->where('start_at', '>', now())
            ->where('start_at', '<=', now()->addMinutes(35))
            ->get();

        foreach ($upcomingMeetings as $event) {
            $minutesUntil = (int) now()->diffInMinutes($event->start_at, false);

            if ($minutesUntil < 0 || $minutesUntil > 35) {
                continue;
            }

            $notification = Notification::create([
                'user_id' => null,
                'type' => 'meeting_reminder',
                'title' => "Meeting in {$minutesUntil} min: ".$this->truncate($event->title, 40),
                'message' => $this->getMeetingReminderMessage($event),
                'icon' => 'calendar',
                'severity' => 'info',
                'action_url' => "/calendar/{$event->id}",
                'action_label' => 'View Details',
                'metadata' => [
                    'event_id' => $event->id,
                    'start_at' => $event->start_at->toIso8601String(),
                    'client_id' => $event->client_id,
                ],
            ]);

            event(new NotificationCreated($notification));

            $event->update(['pre_brief_sent' => true]);

            Log::info('Meeting reminder notification sent', [
                'event_id' => $event->id,
                'title' => $event->title,
                'minutes_until' => $minutesUntil,
            ]);
        }
    }

    /**
     * Create notifications for meetings that have ended.
     */
    protected function processCompletedMeetings(): void
    {
        $completedMeetings = CalendarEvent::where('is_client_meeting', true)
            ->where('post_followup_sent', false)
            ->where('end_at', '<', now())
            ->where('end_at', '>', now()->subHour())
            ->get();

        foreach ($completedMeetings as $event) {
            $notification = Notification::create([
                'user_id' => null,
                'type' => 'meeting_ended',
                'title' => 'Meeting ended: '.$this->truncate($event->title, 40),
                'message' => 'Meeting completed. Consider adding notes and follow-up tasks.',
                'icon' => 'check-circle',
                'severity' => 'success',
                'action_url' => "/calendar/{$event->id}",
                'action_label' => 'Create Follow-Up Task',
                'metadata' => [
                    'event_id' => $event->id,
                    'ended_at' => $event->end_at->toIso8601String(),
                    'client_id' => $event->client_id,
                ],
            ]);

            event(new NotificationCreated($notification));

            $event->update(['post_followup_sent' => true]);

            Log::info('Meeting ended notification sent', [
                'event_id' => $event->id,
                'title' => $event->title,
            ]);
        }
    }

    /**
     * Generate reminder message based on meeting attributes.
     */
    protected function getMeetingReminderMessage(CalendarEvent $event): string
    {
        if ($event->client) {
            return "Meeting with {$event->client->name}. Review calendar for details.";
        }

        $externalAttendees = $event->external_attendees;
        if (count($externalAttendees) > 0) {
            return 'Meeting with external attendees. Review calendar for details.';
        }

        return 'Upcoming meeting. Check calendar for details.';
    }

    /**
     * Truncate string with ellipsis.
     */
    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3).'...';
    }
}
