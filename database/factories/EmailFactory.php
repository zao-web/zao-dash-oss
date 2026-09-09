<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Email>
 */
class EmailFactory extends Factory
{
    protected $model = Email::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_message_id' => $this->faker->regexify('[a-f0-9]{16}'),
            'thread_id' => $this->faker->regexify('[a-f0-9]{16}'),
            'from_address' => $this->faker->safeEmail(),
            'from_name' => $this->faker->name(),
            'to_addresses' => [$this->faker->safeEmail()],
            'subject' => $this->faker->sentence(),
            'body_text' => $this->faker->paragraphs(3, true),
            'received_at' => now(),
            'is_transcript' => false,
            'is_processed' => false,
            'client_id' => null,
            'processed_at' => null,
        ];
    }

    /**
     * Indicate that this is a meeting transcript email.
     */
    public function transcript(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_address' => 'noreply@meet.google.com',
            'subject' => 'Meeting Transcript: '.$this->faker->sentence(),
            'is_transcript' => true,
        ]);
    }

    /**
     * Indicate that this email is associated with a client.
     */
    public function forClient(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => \App\Models\Client::factory(),
        ]);
    }

    /**
     * Indicate that this email has been processed.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => now(),
        ]);
    }
}
