<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DailyBriefingNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $briefing,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'daily_briefing',
            'title' => 'Daily Financial Briefing — '.now()->format('M d, Y'),
            'briefing' => $this->briefing,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
