<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RetainerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $client = $this->whenLoaded('client');

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'initials' => $this->getClientInitials($client->name),
                'contacts' => $client->contacts?->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'role' => $c->role,
                ]) ?? [],
            ] : null,

            // Period
            'period_start' => $this->period_start->format('Y-m-d'),
            'period_end' => $this->period_end->format('Y-m-d'),
            'status' => $this->status,

            // Service tier
            'tier' => $this->tier,
            'monthly_amount' => (float) $this->monthly_amount,
            'included_services' => $this->included_services,

            // Hours
            'hours_included' => (float) $this->hours_included,
            'hours_used' => (float) $this->hours_used,
            'rollover_hours' => (float) $this->rollover_hours,
            'total_hours' => $this->total_hours,
            'remaining_hours' => $this->remaining_hours,
            'usage_percent' => $this->usage_percent,
            'is_over_budget' => $this->isOverage(),
            'overage_hours' => $this->overage_hours,
            'overage_rate' => (float) $this->overage_rate,

            // Rates
            'internal_hourly_rate' => (float) $this->internal_hourly_rate,
            'ai_equivalent_hourly_rate' => (float) $this->ai_equivalent_hourly_rate,

            // Agent metrics
            'agent_cost_usd' => (float) $this->agent_cost_usd,
            'agent_tasks_completed' => $this->agent_tasks_completed,
            'agent_equivalent_hours' => $this->agent_equivalent_hours,

            // Health
            'effective_margin_percent' => $this->effective_margin_percent !== null
                ? (float) $this->effective_margin_percent
                : null,
            'health_status' => $this->health_status,
            'last_client_activity_at' => $this->last_client_activity_at?->toIso8601String(),
        ];
    }

    protected function getClientInitials(string $name): string
    {
        $words = explode(' ', $name);
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }

        return $initials;
    }
}
