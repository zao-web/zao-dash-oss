<?php

namespace App\Console\Commands;

use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Console\Command;

class TestNotificationCommand extends Command
{
    protected $signature = 'notifications:test
        {--type=info : Notification type (info, success, warning, error)}
        {--user= : User ID to target (optional)}';

    protected $description = 'Send a test notification to verify real-time broadcasting';

    public function handle(): int
    {
        $type = $this->option('type');
        $userId = $this->option('user');

        $messages = [
            'info' => ['title' => 'System Update', 'message' => 'A new feature has been deployed.'],
            'success' => ['title' => 'Task Completed', 'message' => 'Agent finished processing your request.'],
            'warning' => ['title' => 'Attention Needed', 'message' => 'Client health score dropped below threshold.'],
            'error' => ['title' => 'Action Required', 'message' => 'Invoice payment is overdue.'],
        ];

        $content = $messages[$type] ?? $messages['info'];

        $notification = Notification::create([
            'user_id' => $userId,
            'type' => 'system',
            'title' => $content['title'],
            'message' => $content['message'],
            'icon' => $this->getIcon($type),
            'severity' => $type,
            'action_url' => '/dashboard',
            'action_label' => 'View Dashboard',
        ]);

        // Broadcast the event
        event(new NotificationCreated($notification));

        $this->info('Test notification sent!');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $notification->id],
                ['Type', $type],
                ['Title', $content['title']],
                ['User', $userId ?? 'All users (public)'],
                ['Channel', $userId ? "notifications.{$userId}" : 'notifications'],
            ]
        );

        return Command::SUCCESS;
    }

    protected function getIcon(string $type): string
    {
        return match ($type) {
            'success' => 'check-circle',
            'warning' => 'exclamation-triangle',
            'error' => 'x-circle',
            default => 'information-circle',
        };
    }
}
