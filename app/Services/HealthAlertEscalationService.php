<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Client;
use App\Models\EscalationTarget;
use App\Models\HealthAlert;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HealthAlertEscalationService
{
    /**
     * Create a new health alert from a health score change.
     */
    public function createAlertFromHealthDrop(Client $client, float $oldScore, float $newScore): HealthAlert
    {
        $alert = HealthAlert::createFromHealthDrop($client, $oldScore, $newScore);
        $this->notifyForAlert($alert);

        Log::info('Health alert created', [
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'severity' => $alert->severity,
            'health_score' => $newScore,
        ]);

        return $alert;
    }

    /**
     * Process all alerts that need escalation.
     */
    public function processEscalations(): array
    {
        $alerts = HealthAlert::needsEscalation()->with('client')->get();
        $escalated = [];

        foreach ($alerts as $alert) {
            $this->escalateAlert($alert);
            $escalated[] = [
                'alert_id' => $alert->id,
                'client' => $alert->client->name,
                'new_level' => $alert->escalation_level,
            ];
        }

        return $escalated;
    }

    /**
     * Escalate a single alert to the next level.
     */
    public function escalateAlert(HealthAlert $alert): void
    {
        $previousLevel = $alert->escalation_level;
        $alert->escalate();

        $this->notifyForEscalation($alert, $previousLevel);

        Log::info('Alert escalated', [
            'alert_id' => $alert->id,
            'client_id' => $alert->client_id,
            'from_level' => $previousLevel,
            'to_level' => $alert->escalation_level,
        ]);
    }

    /**
     * Send notifications for a new alert.
     */
    protected function notifyForAlert(HealthAlert $alert): void
    {
        $targets = $this->getNotificationTargets($alert, $alert->escalation_level);

        foreach ($targets as $user) {
            $this->sendAlertNotification($alert, $user);
        }

        // Also broadcast a global notification for the dashboard
        $this->broadcastDashboardNotification($alert);
    }

    /**
     * Send notifications for an escalation.
     */
    protected function notifyForEscalation(HealthAlert $alert, int $previousLevel): void
    {
        // Notify new level
        $newTargets = $this->getNotificationTargets($alert, $alert->escalation_level);
        foreach ($newTargets as $user) {
            $this->sendEscalationNotification($alert, $user, $previousLevel);
        }

        // Also notify previous level that it's been escalated
        $previousTargets = $this->getNotificationTargets($alert, $previousLevel);
        foreach ($previousTargets as $user) {
            $this->sendEscalatedAwayNotification($alert, $user);
        }
    }

    /**
     * Get users to notify for a given escalation level.
     */
    protected function getNotificationTargets(HealthAlert $alert, int $level): array
    {
        // First, try to get configured escalation targets
        $targets = EscalationTarget::getTargetsForLevel($level);

        if ($targets->isNotEmpty()) {
            return $targets->pluck('user')->all();
        }

        // Fallback: use any admin users for higher levels
        if ($level >= EscalationTarget::LEVEL_DIRECTOR) {
            return User::where('is_admin', true)->get()->all();
        }

        // Fallback: notify all users for lower levels
        return User::all()->all();
    }

    /**
     * Send initial alert notification.
     */
    protected function sendAlertNotification(HealthAlert $alert, User $user): void
    {
        $icon = match ($alert->severity) {
            HealthAlert::SEVERITY_CRITICAL => '🚨',
            HealthAlert::SEVERITY_HIGH => '❗',
            HealthAlert::SEVERITY_MEDIUM => '⚠️',
            default => 'ℹ️',
        };

        Notification::create([
            'user_id' => $user->id,
            'type' => 'health_alert',
            'title' => "Client Health Alert: {$alert->client->name}",
            'message' => $alert->description,
            'icon' => $icon,
            'severity' => $alert->severity,
            'action_url' => "/clients/{$alert->client->slug}",
            'action_label' => 'View Client',
            'metadata' => [
                'alert_id' => $alert->id,
                'client_id' => $alert->client_id,
                'health_score' => $alert->health_score,
                'escalation_level' => $alert->escalation_level,
            ],
        ]);
    }

    /**
     * Send escalation notification to new level.
     */
    protected function sendEscalationNotification(HealthAlert $alert, User $user, int $previousLevel): void
    {
        $levelName = EscalationTarget::getLevelName($alert->escalation_level);
        $previousLevelName = EscalationTarget::getLevelName($previousLevel);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'health_alert_escalation',
            'title' => "🔺 ESCALATED: {$alert->client->name} Health Alert",
            'message' => "Alert escalated from {$previousLevelName} to {$levelName}. {$alert->description}",
            'icon' => '🔺',
            'severity' => 'error',
            'action_url' => "/clients/{$alert->client->slug}",
            'action_label' => 'Respond Now',
            'metadata' => [
                'alert_id' => $alert->id,
                'client_id' => $alert->client_id,
                'escalation_level' => $alert->escalation_level,
                'previous_level' => $previousLevel,
            ],
        ]);
    }

    /**
     * Notify previous level that their alert was escalated.
     */
    protected function sendEscalatedAwayNotification(HealthAlert $alert, User $user): void
    {
        $newLevelName = EscalationTarget::getLevelName($alert->escalation_level);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'health_alert_escalated_away',
            'title' => "Alert Escalated: {$alert->client->name}",
            'message' => "This alert has been escalated to {$newLevelName} due to no response.",
            'icon' => '📤',
            'severity' => 'warning',
            'action_url' => "/clients/{$alert->client->slug}",
            'action_label' => 'View Client',
            'metadata' => [
                'alert_id' => $alert->id,
                'escalated_to' => $alert->escalation_level,
            ],
        ]);
    }

    /**
     * Broadcast notification for dashboard.
     */
    protected function broadcastDashboardNotification(HealthAlert $alert): void
    {
        $notification = Notification::create([
            'user_id' => null, // Global
            'type' => $alert->alert_type,
            'title' => $alert->severity === HealthAlert::SEVERITY_CRITICAL
                ? 'Client Health Critical'
                : 'Client Health Warning',
            'message' => $alert->description,
            'icon' => $alert->severity === HealthAlert::SEVERITY_CRITICAL ? '💔' : '⚠️',
            'severity' => $alert->severity,
            'action_url' => "/clients/{$alert->client->slug}",
            'action_label' => 'View Client',
            'metadata' => [
                'alert_id' => $alert->id,
                'client_id' => $alert->client_id,
                'health_score' => $alert->health_score,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledgeAlert(HealthAlert $alert, User $user): void
    {
        $alert->acknowledge($user->id);

        Log::info('Alert acknowledged', [
            'alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Resolve an alert.
     */
    public function resolveAlert(HealthAlert $alert, User $user, ?string $note = null): void
    {
        $alert->resolve($user->id, $note);

        // Notify interested parties
        $this->notifyResolution($alert, $user, $note);

        Log::info('Alert resolved', [
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'note' => $note,
        ]);
    }

    /**
     * Notify about resolution.
     */
    protected function notifyResolution(HealthAlert $alert, User $resolver, ?string $note): void
    {
        // Notify anyone who was notified about this alert
        $interestedUsers = collect($alert->escalation_history ?? [])
            ->pluck('notified_users')
            ->flatten()
            ->unique();

        $notification = Notification::create([
            'user_id' => null,
            'type' => 'health_alert_resolved',
            'title' => "✅ Resolved: {$alert->client->name}",
            'message' => $note ?? "Health alert resolved by {$resolver->name}",
            'icon' => '✅',
            'severity' => 'success',
            'action_url' => "/clients/{$alert->client->slug}",
            'action_label' => 'View Client',
            'metadata' => [
                'alert_id' => $alert->id,
                'resolved_by' => $resolver->name,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }

    /**
     * Get dashboard summary of alerts.
     */
    public function getDashboardSummary(): array
    {
        return [
            'open_alerts' => HealthAlert::open()->count(),
            'critical_alerts' => HealthAlert::unresolved()->critical()->count(),
            'pending_escalation' => HealthAlert::needsEscalation()->count(),
            'escalated_today' => HealthAlert::where('status', HealthAlert::STATUS_ESCALATED)
                ->whereDate('updated_at', today())
                ->count(),
            'resolved_today' => HealthAlert::where('status', HealthAlert::STATUS_RESOLVED)
                ->whereDate('resolved_at', today())
                ->count(),
            'by_severity' => [
                'critical' => HealthAlert::unresolved()->where('severity', HealthAlert::SEVERITY_CRITICAL)->count(),
                'high' => HealthAlert::unresolved()->where('severity', HealthAlert::SEVERITY_HIGH)->count(),
                'medium' => HealthAlert::unresolved()->where('severity', HealthAlert::SEVERITY_MEDIUM)->count(),
                'low' => HealthAlert::unresolved()->where('severity', HealthAlert::SEVERITY_LOW)->count(),
            ],
            'at_risk_clients' => HealthAlert::unresolved()
                ->whereIn('severity', [HealthAlert::SEVERITY_CRITICAL, HealthAlert::SEVERITY_HIGH])
                ->distinct('client_id')
                ->count('client_id'),
        ];
    }
}
