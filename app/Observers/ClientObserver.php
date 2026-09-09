<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\SlackChannel;
use App\Services\HealthAlertEscalationService;

class ClientObserver
{
    public function __construct(
        protected HealthAlertEscalationService $escalationService
    ) {}

    public function updated(Client $client): void
    {
        $this->syncSlackChannelLink($client);
        $this->checkHealthScoreAlerts($client);
    }

    protected function syncSlackChannelLink(Client $client): void
    {
        if (! $client->wasChanged('slack_channel_id')) {
            return;
        }

        $oldChannelId = $client->getOriginal('slack_channel_id');
        $newChannelId = $client->slack_channel_id;

        if ($oldChannelId && $oldChannelId !== $newChannelId) {
            SlackChannel::where('id', $oldChannelId)
                ->where('client_id', $client->id)
                ->update(['client_id' => null]);
        }

        if ($newChannelId) {
            SlackChannel::where('id', $newChannelId)
                ->update(['client_id' => $client->id, 'classification' => 'client']);
        }
    }

    protected function checkHealthScoreAlerts(Client $client): void
    {
        if (! $client->wasChanged('health_score')) {
            return;
        }

        $oldScore = (float) $client->getOriginal('health_score');
        $newScore = (float) $client->health_score;

        if ($this->shouldCreateAlert($oldScore, $newScore)) {
            $this->escalationService->createAlertFromHealthDrop($client, $oldScore, $newScore);
        }
    }

    /**
     * Determine if a health score drop warrants an alert.
     */
    protected function shouldCreateAlert(float $oldScore, float $newScore): bool
    {
        // Alert if dropped into critical range (below 4.0)
        if ($oldScore >= 4.0 && $newScore < 4.0) {
            return true;
        }

        // Alert if dropped into at-risk range (below 6.0)
        if ($oldScore >= 6.0 && $newScore < 6.0) {
            return true;
        }

        // Alert on significant drops (>1.5 points) even within ranges
        if ($oldScore - $newScore >= 1.5) {
            return true;
        }

        return false;
    }
}
