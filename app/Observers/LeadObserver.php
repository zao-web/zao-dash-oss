<?php

namespace App\Observers;

use App\Events\NotificationCreated;
use App\Models\Lead;
use App\Models\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LeadObserver
{
    public function created(Lead $lead): void
    {
        $this->broadcastNewLeadNotification($lead);
        $this->clearKpiCache();
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('stage') || $lead->wasChanged('deal_value')) {
            $this->clearKpiCache();
        }
    }

    protected function broadcastNewLeadNotification(Lead $lead): void
    {
        try {
            $notification = Notification::create([
                'user_id' => null,
                'type' => 'lead_created',
                'title' => 'New Lead',
                'message' => "New lead: {$lead->company_name}".($lead->source ? " from {$lead->source}" : ''),
                'data' => [
                    'lead_id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'contact_name' => $lead->contact_name,
                    'contact_email' => $lead->contact_email,
                    'source' => $lead->source,
                    'deal_value' => $lead->deal_value,
                ],
                'action_url' => "/leads#pipeline/lead-{$lead->id}",
            ]);

            broadcast(new NotificationCreated($notification))->toOthers();

            Log::info('Lead notification broadcast', ['lead_id' => $lead->id]);
        } catch (\Exception $e) {
            Log::error('Failed to broadcast lead notification', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function clearKpiCache(): void
    {
        $dates = [
            now()->startOfMonth()->format('Y-m-d'),
            now()->startOfWeek()->format('Y-m-d'),
            now()->startOfYear()->format('Y-m-d'),
        ];

        foreach ($dates as $from) {
            $to = now()->format('Y-m-d');
            Cache::forget("dashboard_kpis_{$from}_{$to}");
        }

        Cache::forget('pipeline_stats');
        Cache::forget('leads_this_week');
    }
}
