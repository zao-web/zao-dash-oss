<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\CalendarService;
use App\Services\Google\GmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync Gmail emails and Calendar events for all connected users.
 *
 * Run daily or triggered by webhooks for incremental updates.
 */
class SyncGSuiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public ?int $userId = null,
        public bool $fullSync = false
    ) {}

    public function handle(GmailService $gmail, CalendarService $calendar): void
    {
        $credentials = $this->userId
            ? GoogleCredential::where('user_id', $this->userId)->get()
            : GoogleCredential::where('is_active', true)->get();

        foreach ($credentials as $credential) {
            $this->initSyncTracking($credential);

            try {
                $user = $credential->user;

                if (! $user) {
                    $this->completeSyncTracking();

                    continue;
                }

                $this->syncEmails($gmail, $user);
                $this->updateSyncProgress(40, 'emails');

                $this->syncCalendar($calendar, $user);
                $this->updateSyncProgress(70, 'calendar');

                $this->refreshWatches($gmail, $calendar, $user, $credential);
                $this->updateSyncProgress(90, 'watches');

                $credential->update(['last_synced_at' => now()]);
                $this->completeSyncTracking();

            } catch (\Exception $e) {
                $this->failSyncTracking($e);
                Log::error('GSuite sync failed for user', [
                    'user_id' => $credential->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncEmails(GmailService $gmail, User $user): int
    {
        $count = 0;

        try {
            // Get recent messages
            $params = [
                'maxResults' => $this->fullSync ? 100 : 25,
                'labelIds' => 'INBOX',
            ];

            // Only get messages from last 7 days unless full sync
            if (! $this->fullSync) {
                $params['q'] = 'after:'.now()->subDays(7)->format('Y/m/d');
            }

            $response = $gmail->listMessages($user, $params);
            $messages = $response['messages'] ?? [];

            foreach ($messages as $message) {
                try {
                    $gmail->syncAndStoreEmail($user, $message['id']);
                    $count++;
                } catch (\Exception $e) {
                    Log::warning('Failed to sync individual email', [
                        'message_id' => $message['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Emails synced', ['user_id' => $user->id, 'count' => $count]);

        } catch (\Exception $e) {
            Log::error('Email sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $count;
    }

    protected function syncCalendar(CalendarService $calendar, User $user): int
    {
        try {
            $count = $calendar->syncEvents($user);

            Log::info('Calendar synced', ['user_id' => $user->id, 'count' => $count]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Calendar sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function refreshWatches(
        GmailService $gmail,
        CalendarService $calendar,
        User $user,
        GoogleCredential $credential
    ): void {
        // Refresh Gmail watch if expiring within 24 hours
        if ($credential->watch_expiration && $credential->watch_expiration->lt(now()->addDay())) {
            try {
                $gmail->watchInbox($user);
                Log::info('Gmail watch refreshed', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::warning('Failed to refresh Gmail watch', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Refresh Calendar watch if expiring within 24 hours
        if ($credential->calendar_watch_expiration && $credential->calendar_watch_expiration->lt(now()->addDay())) {
            try {
                $calendar->watchCalendar($user);
                Log::info('Calendar watch refreshed', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::warning('Failed to refresh Calendar watch', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
