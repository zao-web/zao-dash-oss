<?php

namespace App\Jobs;

use App\Models\FinancialAlert;
use App\Models\User;
use App\Services\PersonalFinance\UrgencyEngine;
use App\Services\Slack\SlackApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckFinancialAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(UrgencyEngine $urgencyEngine, SlackApiService $slackApi): void
    {
        $user = User::where('role', 'admin')->first();

        if (! $user) {
            Log::warning('CheckFinancialAlertsJob: No admin user found.');

            return;
        }

        $alerts = $urgencyEngine->scan($user->id);

        $criticalOrHigh = $alerts->filter(
            fn (FinancialAlert $alert) => in_array($alert->severity, ['critical', 'high'])
                && $alert->wasRecentlyCreated
        );

        if ($criticalOrHigh->isEmpty()) {
            Log::info('CheckFinancialAlertsJob: No new critical/high alerts.', [
                'total_alerts' => $alerts->count(),
            ]);

            return;
        }

        $this->sendSlackNotification($criticalOrHigh, $user, $slackApi);

        Log::info('CheckFinancialAlertsJob completed.', [
            'total_alerts' => $alerts->count(),
            'critical_high' => $criticalOrHigh->count(),
        ]);
    }

    /**
     * Send critical/high alerts as a Slack DM to the owner.
     */
    protected function sendSlackNotification($alerts, User $user, SlackApiService $slackApi): void
    {
        $slackUserId = $user->slack_user_id ?? config('services.slack.owner_user_id');

        if (! $slackUserId) {
            Log::info('CheckFinancialAlertsJob: No Slack user ID configured for DM notifications.');

            return;
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => ':rotating_light: Financial Alerts',
                ],
            ],
        ];

        foreach ($alerts->take(10) as $alert) {
            $emoji = $alert->severity === 'critical' ? ':red_circle:' : ':large_orange_circle:';
            $impact = $alert->dollar_impact ? ' | $'.number_format((float) $alert->dollar_impact, 2) : '';

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$emoji} *{$alert->title}*{$impact}\n{$alert->description}",
                ],
            ];
        }

        try {
            $response = $slackApi->postMessageDirect($slackUserId, 'Financial alerts require attention', null);

            // Update alerts with Slack message timestamp for threading
            $messageTs = $response['ts'] ?? null;
            if ($messageTs) {
                $alerts->each(fn (FinancialAlert $alert) => $alert->update(['slack_message_ts' => $messageTs]));
            }

            // Send the rich blocks as a threaded reply
            $slackApi->postMessageDirect($slackUserId, '', $messageTs);
        } catch (\Exception $e) {
            Log::warning('CheckFinancialAlertsJob: Failed to send Slack DM.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
