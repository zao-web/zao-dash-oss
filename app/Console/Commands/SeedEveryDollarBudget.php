<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Seed the budget system with data extracted from EveryDollar (April 2026).
 *
 * Maps EveryDollar budget groups/items to TransactionCategory entries,
 * creating new categories where no match exists.
 */
class SeedEveryDollarBudget extends Command
{
    protected $signature = 'budget:seed-everydollar {--user= : User ID (defaults to first admin)}';

    protected $description = 'Seed monthly budgets from EveryDollar April 2026 data';

    public function handle(): int
    {
        $userId = $this->option('user') ?: User::first()?->id;

        if (! $userId) {
            $this->error('No user found. Pass --user=ID.');

            return self::FAILURE;
        }

        $this->info("Seeding budget for user #{$userId}...");

        // EveryDollar budget extracted April 2026
        // Format: EveryDollar Group => [ [item_name, amount, existing_category_name, category_type, parent_category_name] ]
        $budget = [
            // === INCOME ===
            ['Zao (Gross)', 21317.50, 'Business Income', 'income', 'Income'],
            ['RockPoint', 4000.00, 'Other Income', 'income', 'Income'],

            // === GIVING ===
            ['Church', 2535.00, 'Church', 'expense', 'Giving'],
            ['Charity', 0.00, 'Charity', 'expense', 'Giving'],
            ['Vision', 200.00, 'Vision', 'expense', 'Giving'],

            // === SAVINGS ===
            ['Emergency Fund', 0.00, 'Emergency Fund', 'expense', 'Savings'],
            ['Investment Fund', 0.00, 'Investment Fund', 'expense', 'Savings'],
            ['Sinking Funds', 2800.00, 'Sinking Funds', 'expense', 'Savings'],

            // === HOUSING ===
            ['Mortgage/Rent', 6000.00, 'Rent/Mortgage', 'expense', 'Housing'],
            ['Water', 375.00, 'Water', 'expense', 'Housing'],
            ['Natural Gas', 100.00, 'Natural Gas', 'expense', 'Housing'],
            ['Electricity', 353.00, 'Electricity', 'expense', 'Housing'],
            ['Cable/Internet', 140.00, 'Cable/Internet', 'expense', 'Housing'],
            ['Trash', 115.00, 'Trash', 'expense', 'Housing'],
            ['Household Items', 3000.00, 'Home Maintenance', 'expense', 'Housing'],

            // === TRANSPORTATION ===
            ['Gas', 150.00, 'Gas/Fuel', 'expense', 'Transportation'],
            ['Maintenance', 200.00, 'Maintenance/Repair', 'expense', 'Transportation'],
            ['Tesla', 729.00, 'Car Payment', 'expense', 'Transportation'],

            // === FOOD ===
            ['Groceries', 2000.00, 'Groceries', 'expense', 'Food & Dining'],
            ['Restaurants', 0.00, 'Restaurants', 'expense', 'Food & Dining'],

            // === PERSONAL ===
            ['Clothing', 400.00, 'Clothing', 'expense', 'Personal'],
            ['Phone', 315.00, 'Phone', 'expense', 'Personal'],
            ['Fun Money', 100.00, 'Fun Money', 'expense', 'Personal'],
            ['Hair/Cosmetics', 400.00, 'Personal Care', 'expense', 'Personal'],
            ['Subscriptions', 500.00, 'Subscriptions', 'expense', 'Personal'],
            ['Tuition - Veritas', 0.00, 'Tuition - Veritas', 'expense', 'Family'],
            ['Tuition - Westside', 1412.50, 'Tuition - Westside', 'expense', 'Family'],

            // === LIFESTYLE ===
            ['Pet Care', 75.00, 'Pet Care', 'expense', 'Personal'],
            ['Child Care', 0.00, 'Childcare', 'expense', 'Family'],
            ['Entertainment', 100.00, 'Entertainment', 'expense', 'Personal'],
            ['Miscellaneous', 250.00, 'Miscellaneous', 'expense', 'Personal'],

            // === HEALTH ===
            ['Medicine/Vitamins', 300.00, 'Pharmacy', 'expense', 'Healthcare'],
            ['Doctor Visits', 380.00, 'Medical', 'expense', 'Healthcare'],

            // === INSURANCE ===
            ['Health Insurance', 0.00, 'Health Insurance', 'expense', 'Healthcare'],
            ['Life Insurance', 200.00, 'Life Insurance', 'expense', 'Insurance'],
            ['Auto Insurance', 870.00, 'Car Insurance', 'expense', 'Transportation'],

            // === DEBT ===
            ['IRS', 0.00, 'IRS Installment', 'tax_payment', 'Tax Payments'],
            ['Collections', 0.00, 'Collections', 'debt_payment', 'Debt Payments'],
            ['Upstart', 318.00, 'Loan Payment', 'debt_payment', 'Debt Payments'],
            ['Credit Cards', 600.00, 'Credit Card Payment', 'debt_payment', 'Debt Payments'],
            ['ECG', 0.00, 'ECG', 'debt_payment', 'Debt Payments'],

            // === BUSINESS ===
            ['Contractors', 0.00, 'Professional Services', 'expense', 'Business Expenses'],
            ['Tech Subscriptions', 400.00, 'Software/SaaS', 'expense', 'Business Expenses'],
        ];

        $created = 0;

        foreach ($budget as [$itemName, $amount, $categoryName, $categoryType, $parentName]) {

            // Find or create the parent category
            $parent = TransactionCategory::firstOrCreate(
                ['name' => $parentName, 'type' => in_array($categoryType, ['tax_payment', 'debt_payment']) ? $categoryType : 'expense', 'parent_id' => null, 'user_id' => null],
                ['is_system' => true, 'budget_trackable' => true]
            );

            // Find or create the child category
            $category = TransactionCategory::firstOrCreate(
                ['name' => $categoryName, 'parent_id' => $parent->id, 'user_id' => null],
                ['type' => $categoryType, 'is_system' => false, 'budget_trackable' => true]
            );

            // Create or update the budget entry
            Budget::updateOrCreate(
                ['user_id' => $userId, 'category_id' => $category->id],
                [
                    'amount' => $amount,
                    'period_type' => 'monthly',
                    'effective_from' => now()->startOfMonth(),
                    'effective_to' => null,
                ]
            );

            $this->line("  {$parentName} > {$categoryName}: \${$amount}");
            $created++;
        }

        $this->newLine();
        $this->info("Done! Created/updated {$created} budget entries.");

        return self::SUCCESS;
    }
}
