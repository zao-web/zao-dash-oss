<?php

namespace App\Http\Controllers;

use App\Jobs\SyncWordPressJob;
use App\Models\WordPressPost;
use App\Models\WordPressSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WordPressWebhookController extends Controller
{
    /**
     * Handle incoming WordPress webhook notifications.
     *
     * WordPress can send webhooks via plugins like WP Webhooks or custom REST API hooks:
     * - Post published/updated/deleted
     * - Comment added
     * - User registered
     * - Custom post type changes
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Verify webhook secret
        if (! $this->verifyWebhook($request)) {
            Log::warning('WordPress webhook verification failed');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('WordPress webhook received', [
            'event' => $payload['event'] ?? 'unknown',
            'site' => $payload['site_url'] ?? 'unknown',
        ]);

        $event = $payload['event'] ?? $payload['action'] ?? null;
        $siteUrl = $payload['site_url'] ?? $payload['home_url'] ?? null;

        if (! $event) {
            return response()->json(['error' => 'Missing event type'], 400);
        }

        // Find the WordPress site
        $site = $this->findSite($siteUrl, $payload);

        if (! $site) {
            Log::warning('WordPress webhook for unknown site', ['site_url' => $siteUrl]);

            return response()->json(['error' => 'Unknown site'], 404);
        }

        // Route to appropriate handler
        match ($event) {
            'post_published', 'publish_post', 'post.published' => $this->handlePostPublished($site, $payload),
            'post_updated', 'edit_post', 'post.updated' => $this->handlePostUpdated($site, $payload),
            'post_deleted', 'delete_post', 'post.deleted' => $this->handlePostDeleted($site, $payload),
            'comment_added', 'wp_insert_comment', 'comment.created' => $this->handleCommentAdded($site, $payload),
            'user_registered', 'user_register' => $this->handleUserRegistered($site, $payload),
            default => Log::info("Unhandled WordPress event: {$event}"),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle post published event.
     */
    protected function handlePostPublished(WordPressSite $site, array $payload): void
    {
        $postId = $payload['post_id'] ?? $payload['ID'] ?? null;
        $postData = $payload['post'] ?? $payload;

        Log::info('WordPress post published', [
            'site_id' => $site->id,
            'post_id' => $postId,
            'title' => $postData['post_title'] ?? $postData['title'] ?? 'Unknown',
        ]);

        // Update or create local record
        if ($postId) {
            WordPressPost::updateOrCreate(
                [
                    'wordpress_site_id' => $site->id,
                    'wp_post_id' => $postId,
                ],
                [
                    'title' => $postData['post_title'] ?? $postData['title'] ?? '',
                    'slug' => $postData['post_name'] ?? $postData['slug'] ?? '',
                    'status' => 'publish',
                    'post_type' => $postData['post_type'] ?? 'post',
                    'content' => $postData['post_content'] ?? $postData['content'] ?? null,
                    'excerpt' => $postData['post_excerpt'] ?? $postData['excerpt'] ?? null,
                    'published_at' => now(),
                ]
            );
        }

        // Trigger full sync to get complete data
        SyncWordPressJob::dispatch($site->user_id)->onQueue('integrations');
    }

    /**
     * Handle post updated event.
     */
    protected function handlePostUpdated(WordPressSite $site, array $payload): void
    {
        $postId = $payload['post_id'] ?? $payload['ID'] ?? null;

        Log::info('WordPress post updated', [
            'site_id' => $site->id,
            'post_id' => $postId,
        ]);

        // Trigger sync to get updated data
        SyncWordPressJob::dispatch($site->user_id)->onQueue('integrations');
    }

    /**
     * Handle post deleted event.
     */
    protected function handlePostDeleted(WordPressSite $site, array $payload): void
    {
        $postId = $payload['post_id'] ?? $payload['ID'] ?? null;

        if ($postId) {
            WordPressPost::where('wordpress_site_id', $site->id)
                ->where('wp_post_id', $postId)
                ->delete();

            Log::info('WordPress post deleted', [
                'site_id' => $site->id,
                'post_id' => $postId,
            ]);
        }
    }

    /**
     * Handle comment added event.
     */
    protected function handleCommentAdded(WordPressSite $site, array $payload): void
    {
        $commentId = $payload['comment_ID'] ?? $payload['comment_id'] ?? null;
        $postId = $payload['comment_post_ID'] ?? $payload['post_id'] ?? null;

        Log::info('WordPress comment added', [
            'site_id' => $site->id,
            'comment_id' => $commentId,
            'post_id' => $postId,
        ]);

        // Could trigger notification or sync
        // For now, just log it
    }

    /**
     * Handle user registered event.
     */
    protected function handleUserRegistered(WordPressSite $site, array $payload): void
    {
        $userId = $payload['user_id'] ?? $payload['ID'] ?? null;
        $userEmail = $payload['user_email'] ?? $payload['email'] ?? null;

        Log::info('WordPress user registered', [
            'site_id' => $site->id,
            'user_id' => $userId,
            'email' => $userEmail,
        ]);

        // Could be a lead! Consider creating prospect
    }

    /**
     * Find the WordPress site from the payload.
     */
    protected function findSite(?string $siteUrl, array $payload): ?WordPressSite
    {
        if ($siteUrl) {
            $site = WordPressSite::where('url', $siteUrl)
                ->orWhere('url', rtrim($siteUrl, '/'))
                ->orWhere('url', $siteUrl.'/')
                ->first();

            if ($site) {
                return $site;
            }
        }

        // Try to find by webhook secret in header or payload
        $secret = $payload['webhook_secret'] ?? null;
        if ($secret) {
            return WordPressSite::where('webhook_secret', $secret)->first();
        }

        return null;
    }

    /**
     * Verify the webhook is legitimate.
     */
    protected function verifyWebhook(Request $request): bool
    {
        // Check for X-WP-Webhook-Signature header (WP Webhooks plugin)
        $signature = $request->header('X-WP-Webhook-Signature');

        if ($signature) {
            // Find site by the signature/secret
            $payload = $request->getContent();
            $sites = WordPressSite::whereNotNull('webhook_secret')->get();

            foreach ($sites as $site) {
                $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $site->webhook_secret, true));
                if (hash_equals($expectedSignature, $signature)) {
                    return true;
                }
            }

            return false;
        }

        // Check for secret in payload (custom implementation)
        $secret = $request->input('webhook_secret');
        if ($secret) {
            return WordPressSite::where('webhook_secret', $secret)->exists();
        }

        // No verification configured - allow in dev, deny in production
        return ! app()->isProduction();
    }
}
