<?php

namespace Database\Factories;

use App\Models\SlackWorkspace;
use App\Models\TaxAgencyConnection;
use App\Models\TaxAgencyMfaChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxAgencyMfaChallenge>
 */
class TaxAgencyMfaChallengeFactory extends Factory
{
    protected $model = TaxAgencyMfaChallenge::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tax_agency_connection_id' => TaxAgencyConnection::factory(),
            'slack_workspace_id' => SlackWorkspace::factory(),
            'agency_code' => TaxAgencyConnection::AGENCY_IRS,
            'challenge_type' => 'sms_code',
            'delivery_method' => 'slack_dm',
            'status' => TaxAgencyMfaChallenge::STATUS_PENDING,
            'slack_channel_id' => 'D'.$this->faker->regexify('[A-Z0-9]{8}'),
            'slack_user_id' => 'U'.$this->faker->regexify('[A-Z0-9]{8}'),
            'response_message_ts' => null,
            'prompt_message' => 'Reply with the 6-digit code.',
            'response_code' => null,
            'context' => [],
            'requested_at' => now(),
            'resolved_at' => null,
            'consumed_at' => null,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => TaxAgencyMfaChallenge::STATUS_RESOLVED,
            'response_code' => '123456',
            'resolved_at' => now(),
        ]);
    }
}
