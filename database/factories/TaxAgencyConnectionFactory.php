<?php

namespace Database\Factories;

use App\Models\TaxAgencyConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxAgencyConnection>
 */
class TaxAgencyConnectionFactory extends Factory
{
    protected $model = TaxAgencyConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agencyCode = fake()->randomElement([TaxAgencyConnection::AGENCY_IRS, TaxAgencyConnection::AGENCY_OREGON_DOR]);
        $agencyMeta = $agencyCode === TaxAgencyConnection::AGENCY_IRS
            ? ['agency_name' => 'Internal Revenue Service', 'portal_name' => 'IRS Individual Online Account']
            : ['agency_name' => 'Oregon Department of Revenue', 'portal_name' => 'Revenue Online'];

        return [
            'user_id' => User::factory(),
            'agency_code' => $agencyCode,
            'agency_name' => $agencyMeta['agency_name'],
            'portal_name' => $agencyMeta['portal_name'],
            'status' => TaxAgencyConnection::STATUS_ACTIVE,
            'auth_mode' => 'browser_session_bridge',
            'auth_payload' => [
                'bridge_url' => 'https://bridge.test',
                'bridge_token' => 'bridge-token',
            ],
            'capabilities' => ['balances', 'payments', 'notices', 'transcripts'],
            'sync_enabled' => true,
            'sync_status' => 'pending',
            'sync_progress' => 0,
            'sync_error' => null,
            'latest_balance_amount' => null,
            'latest_balance_status' => null,
            'last_synced_at' => null,
            'latest_notice_at' => null,
            'latest_transcript_at' => null,
            'sync_started_at' => null,
            'sync_completed_at' => null,
        ];
    }

    public function irs(): static
    {
        return $this->state(fn (): array => [
            'agency_code' => TaxAgencyConnection::AGENCY_IRS,
            'agency_name' => 'Internal Revenue Service',
            'portal_name' => 'IRS Individual Online Account',
        ]);
    }

    public function oregon(): static
    {
        return $this->state(fn (): array => [
            'agency_code' => TaxAgencyConnection::AGENCY_OREGON_DOR,
            'agency_name' => 'Oregon Department of Revenue',
            'portal_name' => 'Revenue Online',
        ]);
    }

    public function needsAuth(): static
    {
        return $this->state(fn (): array => [
            'status' => TaxAgencyConnection::STATUS_NEEDS_AUTH,
            'auth_payload' => null,
        ]);
    }
}
