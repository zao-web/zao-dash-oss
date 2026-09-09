<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\SlackWorkspace;
use App\Models\WordPressSite;
use App\Services\Slack\SlackService;
use App\Services\WordPress\WordPressService;
use Illuminate\Support\Facades\Log;

/**
 * Routes agent outputs to appropriate destinations based on agent type and configuration.
 *
 * Supports:
 * - WordPress: Create draft posts for content agents
 * - Slack: Send messages for notifications/alerts
 * - Email: Send drafts for proposals/outreach
 */
class AgentOutputRouter
{
    protected array $routingRules = [
        // Content generation agents → WordPress
        'landing-page-generator' => ['wordpress_draft'],
        'case-study-writer' => ['wordpress_draft'],
        'content-scheduler' => ['wordpress_draft', 'slack_notify'],

        // Outreach agents → Email + Slack
        'lead-nurture' => ['email_draft', 'slack_notify'],
        'upsell-proposal' => ['email_draft', 'slack_notify'],

        // Monitoring agents → Slack + Notification
        'client-health-monitor' => ['slack_alert', 'notification'],
        'communication-agent' => ['slack_notify'],

        // Dev agents → GitHub (future) + Slack
        'dev-agent' => ['slack_notify'],
    ];

    public function route(AgentRun $run): array
    {
        $agentSlug = $run->agent?->slug;
        $results = [];

        if (! $agentSlug || ! isset($this->routingRules[$agentSlug])) {
            Log::info("No routing rules for agent: {$agentSlug}");

            return $results;
        }

        $destinations = $this->routingRules[$agentSlug];
        $output = $run->output;
        $context = $run->config['context'] ?? [];

        foreach ($destinations as $destination) {
            try {
                $result = match ($destination) {
                    'wordpress_draft' => $this->routeToWordPress($run, $output, $context),
                    'email_draft' => $this->routeToEmail($run, $output, $context),
                    'slack_notify' => $this->routeToSlack($run, $output, $context, 'notification'),
                    'slack_alert' => $this->routeToSlack($run, $output, $context, 'alert'),
                    'notification' => $this->createNotification($run, $output, $context),
                    default => ['status' => 'skipped', 'reason' => "Unknown destination: {$destination}"],
                };
                $results[$destination] = $result;
            } catch (\Exception $e) {
                Log::error("Failed to route to {$destination}: {$e->getMessage()}");
                $results[$destination] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        // Store routing results in run metadata
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], ['routing_results' => $results]),
        ]);

        return $results;
    }

    protected function routeToWordPress(AgentRun $run, ?string $output, array $context): array
    {
        if (empty($output)) {
            return ['status' => 'skipped', 'reason' => 'No output to publish'];
        }

        // Find default WordPress site
        $site = WordPressSite::where('is_active', true)->first();
        if (! $site) {
            return ['status' => 'skipped', 'reason' => 'No active WordPress site configured'];
        }

        // Extract title from output (first line or generate from agent name)
        $lines = explode("\n", trim($output));
        $title = $this->extractTitle($lines[0] ?? '', $run->agent?->name ?? 'Agent Output');

        // Clean content (remove title if it was first line)
        $content = count($lines) > 1 ? implode("\n", array_slice($lines, 1)) : $output;

        try {
            $wpService = app(WordPressService::class);
            $post = $wpService->createPost($site, [
                'title' => $title,
                'content' => $content,
                'status' => 'draft',
                'meta' => [
                    'agent_run_id' => $run->id,
                    'agent_slug' => $run->agent?->slug,
                    'generated_at' => now()->toISOString(),
                ],
            ]);

            return [
                'status' => 'success',
                'post_id' => $post['id'] ?? null,
                'edit_url' => $site->url.'/wp-admin/post.php?post='.($post['id'] ?? 0).'&action=edit',
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function routeToEmail(AgentRun $run, ?string $output, array $context): array
    {
        if (empty($output)) {
            return ['status' => 'skipped', 'reason' => 'No output for email'];
        }

        // Extract email components from output
        $emailData = $this->parseEmailContent($output, $context);

        // For now, store as a draft notification
        // In production, integrate with email service (SendGrid, etc.)
        $draftPath = storage_path("app/email-drafts/{$run->id}.json");

        if (! is_dir(dirname($draftPath))) {
            mkdir(dirname($draftPath), 0755, true);
        }

        file_put_contents($draftPath, json_encode([
            'run_id' => $run->id,
            'agent' => $run->agent?->slug,
            'to' => $emailData['to'] ?? null,
            'subject' => $emailData['subject'],
            'body' => $emailData['body'],
            'created_at' => now()->toISOString(),
            'context' => $context,
        ], JSON_PRETTY_PRINT));

        return [
            'status' => 'success',
            'draft_path' => $draftPath,
            'subject' => $emailData['subject'],
        ];
    }

    protected function routeToSlack(AgentRun $run, ?string $output, array $context, string $type): array
    {
        // Find default Slack workspace
        $workspace = SlackWorkspace::where('is_active', true)->first();
        if (! $workspace) {
            return ['status' => 'skipped', 'reason' => 'No active Slack workspace configured'];
        }

        // Determine channel based on type and context
        $channel = $this->determineSlackChannel($type, $context, $workspace);

        // Format message based on type
        $message = $this->formatSlackMessage($run, $output, $type);

        try {
            $slackService = app(SlackService::class);
            $result = $slackService->postMessage($workspace, $channel, $message);

            return [
                'status' => 'success',
                'channel' => $channel,
                'ts' => $result['ts'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function createNotification(AgentRun $run, ?string $output, array $context): array
    {
        $notification = \App\Models\Notification::create([
            'user_id' => $run->invoked_by ? (int) str_replace('user:', '', $run->invoked_by) : null,
            'type' => 'agent_output',
            'title' => "{$run->agent?->name} completed",
            'message' => $this->truncate($output ?? 'Agent run completed successfully.', 200),
            'icon' => 'check-circle',
            'severity' => 'success',
            'action_url' => "/agents/{$run->agent?->slug}/runs/{$run->id}",
            'action_label' => 'View Results',
        ]);

        event(new \App\Events\NotificationCreated($notification));

        return ['status' => 'success', 'notification_id' => $notification->id];
    }

    protected function extractTitle(string $firstLine, string $fallback): string
    {
        // Remove markdown headers
        $title = preg_replace('/^#+\s*/', '', $firstLine);

        // If it looks like a title (short, no periods), use it
        if (strlen($title) > 0 && strlen($title) < 100 && ! str_contains($title, '.')) {
            return trim($title);
        }

        return $fallback.' - '.now()->format('M j, Y');
    }

    protected function parseEmailContent(string $output, array $context): array
    {
        // Try to parse structured email format
        $subject = $context['focus'] ?? 'Follow-up from '.config('app.name');
        $body = $output;

        // Look for Subject: line in output
        if (preg_match('/^Subject:\s*(.+)$/mi', $output, $matches)) {
            $subject = trim($matches[1]);
            $body = preg_replace('/^Subject:\s*.+\n/mi', '', $output);
        }

        return [
            'to' => $context['client_email'] ?? null,
            'subject' => $subject,
            'body' => trim($body),
        ];
    }

    protected function determineSlackChannel(string $type, array $context, SlackWorkspace $workspace): string
    {
        // Check for client-specific channel
        if (! empty($context['client_slug'])) {
            $clientChannel = "client-{$context['client_slug']}";
            // In production, verify channel exists
        }

        // Default channels by type
        return match ($type) {
            'alert' => $workspace->default_channel ?? '#alerts',
            'notification' => $workspace->default_channel ?? '#general',
            default => $workspace->default_channel ?? '#general',
        };
    }

    protected function formatSlackMessage(AgentRun $run, ?string $output, string $type): array
    {
        $emoji = match ($type) {
            'alert' => ':warning:',
            default => ':robot_face:',
        };

        $color = match ($type) {
            'alert' => 'warning',
            default => 'good',
        };

        return [
            'text' => "{$emoji} *{$run->agent?->name}* completed",
            'attachments' => [
                [
                    'color' => $color,
                    'text' => $this->truncate($output ?? 'No output', 500),
                    'footer' => "Run #{$run->id} | <".url("/agents/{$run->agent?->slug}/runs/{$run->id}").'|View Details>',
                    'ts' => $run->completed_at?->timestamp,
                ],
            ],
        ];
    }

    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3).'...';
    }

    /**
     * Register custom routing rules.
     */
    public function registerRule(string $agentSlug, array $destinations): void
    {
        $this->routingRules[$agentSlug] = $destinations;
    }

    /**
     * Get routing rules for an agent.
     */
    public function getRulesFor(string $agentSlug): array
    {
        return $this->routingRules[$agentSlug] ?? [];
    }
}
