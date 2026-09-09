<?php

namespace App\Services\Google;

use App\Models\ClientContact;
use App\Models\Email;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class GmailService
{
    private const BASE_URL = 'https://gmail.googleapis.com/gmail/v1';

    public function __construct(
        private GoogleOAuthService $oauth
    ) {}

    public function watchInbox(User $user): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)->post(self::BASE_URL.'/users/me/watch', [
            'labelIds' => ['INBOX'],
            'topicName' => config('services.google.pubsub_topic'),
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to set up Gmail watch: '.$response->body());
        }

        $data = $response->json();

        // Update credential with watch expiration
        $user->googleCredential->update([
            'watch_expiration' => now()->createFromTimestamp($data['expiration'] / 1000),
            'watch_resource_id' => $data['historyId'] ?? null,
        ]);

        return $data;
    }

    public function stopWatch(User $user): bool
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)->post(self::BASE_URL.'/users/me/stop');

        $user->googleCredential->update([
            'watch_expiration' => null,
            'watch_resource_id' => null,
        ]);

        return $response->successful();
    }

    public function listMessages(User $user, array $params = []): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $defaults = [
            'maxResults' => 20,
            'labelIds' => 'INBOX',
        ];

        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/users/me/messages', array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list messages: '.$response->body());
        }

        return $response->json();
    }

    public function getMessage(User $user, string $messageId, string $format = 'full'): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)
            ->get(self::BASE_URL."/users/me/messages/{$messageId}", [
                'format' => $format,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get message: '.$response->body());
        }

        return $response->json();
    }

    public function getHistory(User $user, string $startHistoryId): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/users/me/history', [
                'startHistoryId' => $startHistoryId,
                'historyTypes' => ['messageAdded'],
                'labelId' => 'INBOX',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get history: '.$response->body());
        }

        return $response->json();
    }

    public function syncAndStoreEmail(User $user, string $messageId): ?Email
    {
        $message = $this->getMessage($user, $messageId);

        $headers = collect($message['payload']['headers'] ?? [])
            ->keyBy(fn ($h) => strtolower($h['name']));

        $fromHeader = $headers->get('from')['value'] ?? '';
        preg_match('/<?([^<>]+@[^<>]+)>?/', $fromHeader, $matches);
        $fromEmail = $matches[1] ?? $fromHeader;
        $fromName = trim(str_replace("<{$fromEmail}>", '', $fromHeader), ' "');

        $toHeader = $headers->get('to')['value'] ?? '';
        $toAddresses = array_map('trim', explode(',', $toHeader));

        // Extract body
        $body = $this->extractBody($message['payload']);

        // Match to client
        $clientId = $this->matchToClient($fromEmail);

        // Check if it's a Gemini transcript
        $isTranscript = str_contains($fromEmail, 'meet-recordings-noreply@google.com') ||
            str_contains($headers->get('subject')['value'] ?? '', 'Meeting transcript');

        return Email::updateOrCreate(
            ['google_message_id' => $messageId],
            [
                'thread_id' => $message['threadId'] ?? null,
                'client_id' => $clientId,
                'from_address' => $fromEmail,
                'from_name' => $fromName ?: null,
                'to_addresses' => $toAddresses,
                'subject' => $headers->get('subject')['value'] ?? null,
                'body_text' => $body['text'] ?? null,
                'body_html' => $body['html'] ?? null,
                'received_at' => now()->createFromTimestamp($message['internalDate'] / 1000),
                'is_transcript' => $isTranscript,
            ]
        );
    }

    private function extractBody(array $payload): array
    {
        $text = null;
        $html = null;

        if (isset($payload['body']['data'])) {
            $decoded = base64_decode(strtr($payload['body']['data'], '-_', '+/'));
            if ($payload['mimeType'] === 'text/plain') {
                $text = $decoded;
            } elseif ($payload['mimeType'] === 'text/html') {
                $html = $decoded;
            }
        }

        foreach ($payload['parts'] ?? [] as $part) {
            $extracted = $this->extractBody($part);
            $text = $text ?? $extracted['text'];
            $html = $html ?? $extracted['html'];
        }

        return ['text' => $text, 'html' => $html];
    }

    private function matchToClient(string $email): ?int
    {
        // First try exact match
        $contact = ClientContact::where('email', $email)->first();
        if ($contact) {
            return $contact->client_id;
        }

        // Try domain match
        $domain = substr($email, strpos($email, '@') + 1);
        $contact = ClientContact::where('email', 'like', "%@{$domain}")->first();

        return $contact?->client_id;
    }
}
