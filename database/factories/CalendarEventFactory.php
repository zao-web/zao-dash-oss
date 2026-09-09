<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->subDays(fake()->numberBetween(1, 30));

        return [
            'google_event_id' => fake()->uuid(),
            'title' => fake()->sentence(4),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'attendees' => [],
            'is_all_day' => false,
            'is_client_meeting' => false,
        ];
    }

    public function clientMeeting(?int $clientId = null): static
    {
        return $this->state(fn () => [
            'is_client_meeting' => true,
            'client_id' => $clientId ?? Client::factory(),
        ]);
    }
}
