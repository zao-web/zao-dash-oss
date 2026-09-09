<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\MetaAdAccount;
use Illuminate\Database\Seeder;

class MetaAdAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create a default client (Zao)
        $client = Client::firstOrCreate(
            ['slug' => 'zao'],
            [
                'name' => 'Zao',
                'status' => 'active',
                'email' => config('mail.from.address'),
            ]
        );

        // Create sandbox account if credentials exist
        if (config('services.meta_ads.sandbox_access_token')) {
            MetaAdAccount::updateOrCreate(
                [
                    'account_id' => config('services.meta_ads.sandbox_account_id', 'act_sandbox'),
                    'is_sandbox' => true,
                ],
                [
                    'client_id' => $client->id,
                    'name' => 'Meta Ads Sandbox',
                    'access_token' => config('services.meta_ads.sandbox_access_token'),
                    'business_id' => null,
                    'currency' => 'USD',
                    'timezone' => 'America/Los_Angeles',
                    'status' => 'active',
                    'metadata' => [
                        'app_id' => config('services.meta_ads.sandbox_app_id'),
                        'environment' => 'sandbox',
                    ],
                ]
            );

            $this->command->info('✓ Created/updated Meta Ads Sandbox account');
        }

        // Create production account if credentials exist
        if (config('services.meta_ads.access_token')) {
            MetaAdAccount::updateOrCreate(
                [
                    'account_id' => config('services.meta_ads.account_id', 'act_00000000'),
                    'is_sandbox' => false,
                ],
                [
                    'client_id' => $client->id,
                    'name' => 'Meta Ads Production',
                    'access_token' => config('services.meta_ads.access_token'),
                    'business_id' => config('services.meta_ads.business_id'),
                    'currency' => 'USD',
                    'timezone' => 'America/Los_Angeles',
                    'status' => 'active',
                    'metadata' => [
                        'app_id' => config('services.meta_ads.app_id'),
                        'environment' => 'production',
                    ],
                ]
            );

            $this->command->info('✓ Created/updated Meta Ads Production account');
        }

        if (! config('services.meta_ads.access_token') && ! config('services.meta_ads.sandbox_access_token')) {
            $this->command->warn('No Meta Ads credentials found in .env file');
        }
    }
}
