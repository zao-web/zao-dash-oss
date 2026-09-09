<?php

namespace Database\Seeders;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\TaxCalendarEvent;
use App\Models\TaxStrategy;
use App\Models\User;
use App\Models\WiseConnection;
use App\Models\WiseTransfer;
use Illuminate\Database\Seeder;

/**
 * Seeds test data for CFO Agent and Contractor features.
 *
 * Run with: php artisan db:seed --class=CFOTestSeeder
 */
class CFOTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding CFO test data...');

        $seededAt = now();

        // Get or create test user (owner)
        $owner = User::where('role', 'owner')->first();
        if (! $owner) {
            $owner = User::factory()->create([
                'name' => 'Test Owner',
                'email' => 'owner@test.com',
                'role' => 'owner',
            ]);
        }

        // Create Wise connection (mock - no real API calls)
        $wiseConnection = WiseConnection::firstOrCreate(
            ['user_id' => $owner->id],
            [
                'profile_id' => 'test-profile-123',
                'api_token' => encrypt('test-api-token'),
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );
        $this->command->info("  Created Wise connection: {$wiseConnection->id}");

        // Create test contractors
        $contractors = $this->createContractors($owner, $seededAt);
        $this->command->info('  Created '.count($contractors).' contractors');

        // Create invoices for contractors
        $this->createInvoices($contractors, $seededAt);
        $this->command->info('  Created contractor invoices');

        // Create some completed transfers (payment history)
        $this->createTransfers($wiseConnection, $contractors);
        $this->command->info('  Created payment history');

        // Create tax calendar events
        $this->createTaxCalendar($owner);
        $this->command->info('  Created tax calendar events');

        // Create tax strategies
        $this->createTaxStrategies($owner);
        $this->command->info('  Created tax strategies');

        $this->command->info('CFO test data seeded successfully!');
        $this->command->newLine();
        $this->command->info('Test the contractor features:');
        $this->command->info('  1. Log in as any team member');
        $this->command->info('  2. Visit /my/payment-setup');
        $this->command->info('  3. Visit /my/invoices');
        $this->command->newLine();
        $this->command->info('Test CFO agent tools via tinker:');
        $this->command->info('  php artisan tinker');
        $this->command->info('  app(App\Agents\Tools\FinancialSummaryTool::class)->execute([])');
    }

    protected function createContractors(User $owner, $seededAt): array
    {
        $contractors = [];

        // Contractor 1: Fully onboarded, has invoices
        $contractors[] = Contractor::firstOrCreate(
            ['email' => 'john.dev@example.com'],
            [
                'name' => 'John Developer',
                'company_name' => 'JD Consulting LLC',
                'phone' => '+1-555-0101',
                'country_code' => 'US',
                'is_us_person' => true,
                'payment_type' => 'invoice',
                'status' => Contractor::STATUS_ACTIVE,
                'onboarding_status' => Contractor::ONBOARDING_COMPLETE,
                'has_w9_on_file' => true,
                'w9_received_at' => now()->subMonths(3),
                'wise_recipient_id' => 'recipient-john-123',
                'seeded_at' => $seededAt,
            ]
        );

        // Contractor 2: Recurring payment contractor
        $contractors[] = Contractor::firstOrCreate(
            ['email' => 'sarah.design@example.com'],
            [
                'name' => 'Sarah Designer',
                'company_name' => null,
                'phone' => '+1-555-0102',
                'country_code' => 'US',
                'is_us_person' => true,
                'payment_type' => 'recurring',
                'recurring_amount' => 3500.00,
                'recurring_currency' => 'USD',
                'recurring_schedule' => 'monthly',
                'status' => Contractor::STATUS_ACTIVE,
                'onboarding_status' => Contractor::ONBOARDING_COMPLETE,
                'has_w9_on_file' => true,
                'w9_received_at' => now()->subMonths(6),
                'wise_recipient_id' => 'recipient-sarah-456',
                'seeded_at' => $seededAt,
            ]
        );

        // Contractor 3: International (Pakistan)
        $contractors[] = Contractor::firstOrCreate(
            ['email' => 'ali.backend@example.com'],
            [
                'name' => 'Ali Backend',
                'company_name' => 'Ali Tech Services',
                'phone' => '+92-300-1234567',
                'country_code' => 'PK',
                'is_us_person' => false,
                'payment_type' => 'recurring',
                'recurring_amount' => 2000.00,
                'recurring_currency' => 'USD',
                'recurring_schedule' => 'monthly',
                'status' => Contractor::STATUS_ACTIVE,
                'onboarding_status' => Contractor::ONBOARDING_COMPLETE,
                'has_w9_on_file' => false,
                'wise_recipient_id' => 'recipient-ali-789',
                'seeded_at' => $seededAt,
            ]
        );

        // Contractor 4: Pending onboarding (needs W9)
        $contractors[] = Contractor::firstOrCreate(
            ['email' => 'new.contractor@example.com'],
            [
                'name' => 'New Contractor',
                'country_code' => 'US',
                'is_us_person' => true,
                'payment_type' => 'invoice',
                'status' => Contractor::STATUS_PENDING,
                'onboarding_status' => Contractor::ONBOARDING_INFO_SUBMITTED,
                'has_w9_on_file' => false,
                'seeded_at' => $seededAt,
            ]
        );

        return $contractors;
    }

    protected function createInvoices(array $contractors, $seededAt): void
    {
        $john = $contractors[0]; // John Developer

        // Submitted invoice (awaiting approval)
        ContractorInvoice::firstOrCreate(
            ['invoice_number' => 'JOH-24-001'],
            [
                'contractor_id' => $john->id,
                'invoice_date' => now()->subDays(5),
                'due_date' => now()->addDays(25),
                'amount' => 4500.00,
                'currency' => 'USD',
                'description' => 'December development work - API integration',
                'status' => ContractorInvoice::STATUS_SUBMITTED,
                'seeded_at' => $seededAt,
            ]
        );

        // Approved invoice (ready for payment)
        ContractorInvoice::firstOrCreate(
            ['invoice_number' => 'JOH-24-002'],
            [
                'contractor_id' => $john->id,
                'invoice_date' => now()->subDays(10),
                'due_date' => now()->addDays(5),
                'amount' => 3200.00,
                'currency' => 'USD',
                'description' => 'November bug fixes and maintenance',
                'status' => ContractorInvoice::STATUS_APPROVED,
                'approved_at' => now()->subDays(2),
                'approved_by' => 1,
                'seeded_at' => $seededAt,
            ]
        );

        // Overdue approved invoice
        ContractorInvoice::firstOrCreate(
            ['invoice_number' => 'JOH-24-003'],
            [
                'contractor_id' => $john->id,
                'invoice_date' => now()->subDays(40),
                'due_date' => now()->subDays(10),
                'amount' => 2800.00,
                'currency' => 'USD',
                'description' => 'October development sprint',
                'status' => ContractorInvoice::STATUS_APPROVED,
                'approved_at' => now()->subDays(30),
                'approved_by' => 1,
                'seeded_at' => $seededAt,
            ]
        );

        // Draft invoice
        ContractorInvoice::firstOrCreate(
            ['invoice_number' => 'JOH-24-004'],
            [
                'contractor_id' => $john->id,
                'invoice_date' => now(),
                'due_date' => now()->addDays(30),
                'amount' => 1500.00,
                'currency' => 'USD',
                'description' => 'Work in progress - not yet submitted',
                'status' => ContractorInvoice::STATUS_DRAFT,
                'seeded_at' => $seededAt,
            ]
        );
    }

    protected function createTransfers(WiseConnection $connection, array $contractors): void
    {
        $john = $contractors[0];
        $sarah = $contractors[1];

        // Past payment to John
        WiseTransfer::firstOrCreate(
            ['wise_transfer_id' => 'transfer-001'],
            [
                'wise_connection_id' => $connection->id,
                'contractor_id' => $john->id,
                'wise_quote_id' => 'quote-001',
                'source_amount' => 5000.00,
                'source_currency' => 'USD',
                'target_amount' => 5000.00,
                'target_currency' => 'USD',
                'exchange_rate' => 1.0,
                'fee' => 4.50,
                'recipient_id' => 'recipient-john-123',
                'recipient_name' => 'John Developer',
                'reference' => 'OCT-2024',
                'payment_type' => 'invoice',
                'status' => WiseTransfer::STATUS_COMPLETED,
                'created_at' => now()->subMonths(2),
            ]
        );

        // Past recurring payment to Sarah
        WiseTransfer::firstOrCreate(
            ['wise_transfer_id' => 'transfer-002'],
            [
                'wise_connection_id' => $connection->id,
                'contractor_id' => $sarah->id,
                'wise_quote_id' => 'quote-002',
                'source_amount' => 3500.00,
                'source_currency' => 'USD',
                'target_amount' => 3500.00,
                'target_currency' => 'USD',
                'exchange_rate' => 1.0,
                'fee' => 3.50,
                'recipient_id' => 'recipient-sarah-456',
                'recipient_name' => 'Sarah Designer',
                'reference' => 'NOV-2024',
                'payment_type' => 'recurring',
                'status' => WiseTransfer::STATUS_COMPLETED,
                'created_at' => now()->subMonth(),
            ]
        );
    }

    protected function createTaxCalendar(User $owner): void
    {
        $year = now()->year;

        // Q4 estimated payment
        TaxCalendarEvent::firstOrCreate(
            ['event_type' => 'q4_estimated', 'tax_year' => $year],
            [
                'user_id' => $owner->id,
                'quarter' => 4,
                'due_date' => ($year + 1).'-01-15',
                'reminder_date' => ($year + 1).'-01-08',
                'form_type' => '1040-ES',
                'status' => 'pending',
            ]
        );

        // 1099 filing deadline
        TaxCalendarEvent::firstOrCreate(
            ['event_type' => '1099_filing', 'tax_year' => $year],
            [
                'user_id' => $owner->id,
                'due_date' => ($year + 1).'-01-31',
                'reminder_date' => ($year + 1).'-01-15',
                'form_type' => '1099-NEC',
                'status' => 'pending',
            ]
        );
    }

    protected function createTaxStrategies(User $owner): void
    {
        // Check if QBO connection exists
        $qboConnection = \App\Models\QuickBooksConnection::first();
        if (! $qboConnection) {
            return;
        }

        TaxStrategy::firstOrCreate(
            ['strategy_type' => TaxStrategy::TYPE_RETIREMENT_CONTRIBUTION, 'tax_year' => now()->year],
            [
                'qbo_connection_id' => $qboConnection->id,
                'title' => 'Maximize Solo 401(k) Contributions',
                'description' => 'You could contribute up to $69,000 to a Solo 401(k) this year, reducing taxable income significantly.',
                'estimated_savings' => 20700,
                'timing_sensitivity' => TaxStrategy::TIMING_YEAR_END,
                'complexity' => TaxStrategy::COMPLEXITY_LOW,
                'status' => TaxStrategy::STATUS_IDENTIFIED,
                'requirements' => ['Self-employed or S-Corp owner', 'No full-time employees'],
                'action_items' => ['Open Solo 401(k) by Dec 31', 'Make contribution before tax filing deadline'],
            ]
        );
    }
}
