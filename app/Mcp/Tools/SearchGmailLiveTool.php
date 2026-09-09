<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Google\GmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchGmailLiveTool extends Tool
{
    protected string $name = 'search-gmail-live';

    protected string $title = 'Search Gmail Live';

    protected string $description = 'Search Gmail directly via the API (not just synced emails). Finds emails in real-time using Gmail query syntax. Also syncs found emails to the local database for future reference.';

    public function __construct(
        protected GmailService $gmail,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'query' => 'nullable|string|max:500',
            'from' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'after' => 'nullable|string',
            'before' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:20',
            'sync' => 'nullable|boolean',
        ]);

        // Build Gmail query string
        $queryParts = [];

        if ($from = $request->get('from')) {
            $queryParts[] = "from:{$from}";
        }
        if ($subject = $request->get('subject')) {
            $queryParts[] = "subject:{$subject}";
        }
        if ($after = $request->get('after')) {
            $queryParts[] = "after:{$after}";
        }
        if ($before = $request->get('before')) {
            $queryParts[] = "before:{$before}";
        }
        if ($rawQuery = $request->get('query')) {
            $queryParts[] = $rawQuery;
        }

        $gmailQuery = implode(' ', $queryParts);

        if (empty($gmailQuery)) {
            return Response::error('Provide at least one search parameter (query, from, subject, after, or before).');
        }

        // Find any user with a Google credential (prefer admin)
        $user = User::whereHas('googleCredential')
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->first();

        if (! $user) {
            return Response::error('No active Google account found. A user needs to connect Google Workspace in the dashboard under Settings > Integrations.');
        }

        // Verify the token can be refreshed
        try {
            $this->gmail->listMessages($user, ['maxResults' => 1]);
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::warning('[SearchGmailLive] Google token validation failed, attempting refresh', [
                'user_id' => $user->id,
                'error' => $errorMsg,
            ]);

            // If it's a token issue, try to refresh explicitly
            if (str_contains($errorMsg, 'token') || str_contains($errorMsg, '401') || str_contains($errorMsg, 'auth')) {
                try {
                    $oauth = app(\App\Services\Google\GoogleOAuthService::class);
                    $credential = $user->googleCredential;
                    if ($credential->refresh_token) {
                        $oauth->refreshAccessToken($credential);
                        Log::info('[SearchGmailLive] Token refreshed successfully');
                    } else {
                        return Response::error("Google token expired and no refresh token available. User {$user->email} needs to reconnect Google in the dashboard.");
                    }
                } catch (\Exception $refreshError) {
                    return Response::error("Google token refresh failed: {$refreshError->getMessage()}. User may need to reconnect Google in the dashboard.");
                }
            } else {
                return Response::error("Gmail API error: {$errorMsg}");
            }
        }

        $limit = $request->get('limit', 10);
        $shouldSync = $request->get('sync', true);

        Log::info('[SearchGmailLive] Searching Gmail', [
            'query' => $gmailQuery,
            'limit' => $limit,
        ]);

        try {
            $messageList = $this->gmail->listMessages($user, [
                'q' => $gmailQuery,
                'maxResults' => $limit,
            ]);

            $messageIds = collect($messageList['messages'] ?? [])
                ->pluck('id')
                ->take($limit);

            if ($messageIds->isEmpty()) {
                return Response::structured([
                    'count' => 0,
                    'query' => $gmailQuery,
                    'emails' => [],
                    'message' => "No emails found matching: {$gmailQuery}",
                ]);
            }

            $emails = [];
            foreach ($messageIds as $messageId) {
                try {
                    if ($shouldSync) {
                        // Sync to local DB and return
                        $email = $this->gmail->syncAndStoreEmail($user, $messageId);
                        if ($email) {
                            $emails[] = [
                                'id' => $email->id,
                                'google_message_id' => $email->google_message_id,
                                'from_name' => $email->from_name,
                                'from_address' => $email->from_address,
                                'subject' => $email->subject,
                                'body_text' => $email->body_text,
                                'body_excerpt' => $email->body_text ? mb_substr($email->body_text, 0, 500) : null,
                                'received_at' => $email->received_at?->toIso8601String(),
                                'received_ago' => $email->received_at?->diffForHumans(),
                                'synced' => true,
                            ];
                        }
                    } else {
                        // Just fetch metadata without syncing
                        $message = $this->gmail->getMessage($user, $messageId, 'metadata');
                        $headers = collect($message['payload']['headers'] ?? [])
                            ->keyBy(fn ($h) => strtolower($h['name']));

                        $emails[] = [
                            'google_message_id' => $messageId,
                            'from' => $headers->get('from')['value'] ?? '',
                            'subject' => $headers->get('subject')['value'] ?? '',
                            'date' => $headers->get('date')['value'] ?? '',
                            'synced' => false,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning('[SearchGmailLive] Failed to fetch message', [
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return Response::structured([
                'count' => count($emails),
                'query' => $gmailQuery,
                'emails' => $emails,
                'message' => 'Found '.count($emails)." email(s) matching: {$gmailQuery}".($shouldSync ? ' (synced to local DB)' : ''),
            ]);
        } catch (\Exception $e) {
            Log::error('[SearchGmailLive] Gmail search failed', [
                'query' => $gmailQuery,
                'error' => $e->getMessage(),
            ]);

            return Response::error('Gmail search failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Raw Gmail search query (e.g., "from:rob@example.com RFP")'),
            'from' => $schema->string()->description('Search by sender name or email (adds "from:" to query)'),
            'subject' => $schema->string()->description('Search by subject (adds "subject:" to query)'),
            'after' => $schema->string()->description('Only emails after this date (YYYY/MM/DD format)'),
            'before' => $schema->string()->description('Only emails before this date (YYYY/MM/DD format)'),
            'limit' => $schema->integer()->description('Max results (default: 10, max: 20)'),
            'sync' => $schema->boolean()->description('Whether to sync found emails to local DB (default: true). Set false for quick metadata-only search.'),
        ];
    }
}
