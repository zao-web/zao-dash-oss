<?php

namespace App\Jobs;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AlertStuckSeoGenerationsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Hours after which a generation is considered stuck.
     */
    private const STUCK_THRESHOLD_HOURS = 2;

    public function handle(): void
    {
        $stuck = SeoPage::where('status', SeoPageStatus::Generating)
            ->where('generation_started_at', '<', now()->subHours(self::STUCK_THRESHOLD_HOURS))
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        $count = $stuck->count();
        $pages = $stuck->take(5)->map(fn ($p) => "- {$p->target_keyword} ({$p->page_url})")->join("\n");

        Log::warning("SEO Generation Alert: {$count} pages stuck for over ".self::STUCK_THRESHOLD_HOURS.' hours', [
            'count' => $count,
            'pages' => $stuck->pluck('page_url')->toArray(),
        ]);

        // Send Slack notification if configured
        $webhookUrl = config('services.slack.alerts_webhook');
        if ($webhookUrl) {
            $this->sendSlackAlert($count, $pages, $webhookUrl);
        }
    }

    private function sendSlackAlert(int $count, string $pages, string $webhookUrl): void
    {
        $payload = [
            'text' => ":warning: *SEO Generation Alert*\n\n{$count} pages have been stuck in 'generating' status for over ".self::STUCK_THRESHOLD_HOURS." hours:\n\n{$pages}\n\nCheck Laravel Horizon for issues.",
            'username' => 'SEO Monitor',
            'icon_emoji' => ':robot_face:',
        ];

        try {
            $client = new \Illuminate\Http\Client\Factory;
            $client->post($webhookUrl, $payload);
        } catch (\Throwable $e) {
            Log::error('Failed to send Slack alert: '.$e->getMessage());
        }
    }

    public function tags(): array
    {
        return ['seo-alerts'];
    }
}
