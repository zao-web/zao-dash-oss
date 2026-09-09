<?php

namespace Database\Factories;

use App\Models\RfpSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfpSource>
 */
class RfpSourceFactory extends Factory
{
    protected $model = RfpSource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' RFP Source',
            'slug' => fake()->unique()->slug(),
            'type' => fake()->randomElement(['email_sender', 'government_api', 'rfp_board']),
            'config' => ['sender_emails' => [fake()->email()]],
            'is_active' => true,
            'check_frequency_minutes' => 60,
        ];
    }
}
