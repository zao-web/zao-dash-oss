<?php

namespace Database\Seeders;

use App\Models\RfpSource;
use Illuminate\Database\Seeder;

class RfpSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RfpSource::firstOrCreate(
            ['slug' => 'rob-williams-folyo'],
            [
                'name' => 'Rob Williams (Folyo)',
                'type' => 'email_sender',
                'config' => [
                    'sender_emails' => ['rob@folyo.me'],
                    'subject_keywords' => ['RFP', 'opportunity', 'agencies', 'seeking'],
                ],
                'filters' => [
                    'industries' => ['web', 'digital', 'design'],
                    'budget_min' => 15000,
                ],
                'is_active' => true,
                'check_frequency_minutes' => 30,
            ]
        );
    }
}
