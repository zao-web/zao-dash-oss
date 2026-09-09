<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\GoogleCredential;
use App\Services\Google\CalendarService;
use App\Services\Google\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleWebhookController extends Controller
{
    public function __construct(
        private GmailService $gmail,
        private CalendarService $calendar
    ) {}

    /**
     * Handle Gmail push notifications from Pub/Sub
     */
    public function gmail(Request $request)
    {
        // Verify the request is from Google Pub/Sub
        $message = $request->input('message');
        if (! $message) {
            Log::warning('Gmail webhook: No message in request');

            return response()->json(['error' => 'No message'], 400);
        }

        // Decode the Pub/Sub message
        $data = json_decode(base64_decode($message['data'] ?? ''), true);
        if (! $data) {
            Log::warning('Gmail webhook: Could not decode message data');

            return response()->json(['error' => 'Invalid data'], 400);
        }

        $emailAddress = $data['emailAddress'] ?? null;
        $historyId = $data['historyId'] ?? null;

        if (! $emailAddress || ! $historyId) {
            Log::warning('Gmail webhook: Missing emailAddress or historyId', $data);

            return response()->json(['error' => 'Missing data'], 400);
        }

        Log::info('Gmail webhook received', [
            'email' => $emailAddress,
            'historyId' => $historyId,
        ]);

        // Find the credential by email
        $credential = GoogleCredential::where('email', $emailAddress)->first();
        if (! $credential) {
            Log::warning('Gmail webhook: No credential found for email', ['email' => $emailAddress]);

            return response()->json(['ok' => true]);
        }

        $user = $credential->user;
        $lastHistoryId = $credential->watch_resource_id;

        // Fetch new messages since last history ID
        try {
            if ($lastHistoryId) {
                $history = $this->gmail->getHistory($user, $lastHistoryId);

                foreach ($history['history'] ?? [] as $record) {
                    foreach ($record['messagesAdded'] ?? [] as $added) {
                        $messageId = $added['message']['id'] ?? null;
                        if ($messageId) {
                            $this->processNewEmail($user, $messageId);
                        }
                    }
                }
            }

            // Update the last known history ID
            $credential->update(['watch_resource_id' => $historyId]);

        } catch (\Exception $e) {
            Log::error('Gmail webhook processing error', [
                'error' => $e->getMessage(),
                'email' => $emailAddress,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Handle Calendar push notifications
     */
    public function calendar(Request $request)
    {
        // Google Calendar sends headers for channel info
        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceId = $request->header('X-Goog-Resource-ID');
        $resourceState = $request->header('X-Goog-Resource-State');

        Log::info('Calendar webhook received', [
            'channelId' => $channelId,
            'resourceId' => $resourceId,
            'state' => $resourceState,
        ]);

        // Ignore sync messages (initial setup confirmation)
        if ($resourceState === 'sync') {
            return response()->json(['ok' => true]);
        }

        // Extract user ID from channel ID (format: zao-calendar-{userId}-{timestamp})
        if (preg_match('/zao-calendar-(\d+)-/', $channelId, $matches)) {
            $userId = $matches[1];
            $credential = GoogleCredential::where('user_id', $userId)->first();

            if ($credential) {
                $user = $credential->user;

                try {
                    // Sync calendar events
                    $this->calendar->syncEvents($user);
                    Log::info('Calendar synced from webhook', ['userId' => $userId]);
                } catch (\Exception $e) {
                    Log::error('Calendar webhook processing error', [
                        'error' => $e->getMessage(),
                        'userId' => $userId,
                    ]);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Process a new email from Gmail
     */
    private function processNewEmail($user, string $messageId): void
    {
        try {
            $email = $this->gmail->syncAndStoreEmail($user, $messageId);

            if (! $email) {
                return;
            }

            Log::info('New email synced', [
                'id' => $email->id,
                'from' => $email->from_address,
                'subject' => $email->subject,
                'is_transcript' => $email->is_transcript,
            ]);

            // Queue for processing by agents
            if ($email->is_transcript) {
                // Queue MeetingTranscriptAgent
                $this->queueTranscriptProcessing($email);
            } elseif ($email->client_id) {
                // Queue EmailProcessorAgent for client emails
                $this->queueEmailProcessing($email);
            }

        } catch (\Exception $e) {
            Log::error('Error processing new email', [
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function queueTranscriptProcessing(Email $email): void
    {
        \App\Jobs\ProcessTranscriptEmailJob::dispatch($email);
        Log::info('Dispatched ProcessTranscriptEmailJob for transcript email', ['id' => $email->id]);
    }

    /**
     * Queue email for EmailProcessorAgent processing
     */
    private function queueEmailProcessing(Email $email): void
    {
        // TODO: Dispatch job to trigger EmailProcessorAgent
        Log::info('Would queue EmailProcessorAgent for email', ['id' => $email->id]);
    }
}
