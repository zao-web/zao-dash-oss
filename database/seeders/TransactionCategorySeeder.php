<?php

namespace Database\Seeders;

use App\Models\TransactionCategory;
use Illuminate\Database\Seeder;

class TransactionCategorySeeder extends Seeder
{
    /**
     * Seed the default transaction categories hierarchy.
     */
    public function run(): void
    {
        // Income
        $income = $this->createCategory('Income', 'income', isSystem: true);
        $this->createCategory('Salary/Wages', 'income', parentId: $income->id);
        $this->createCategory('Business Income', 'income', parentId: $income->id, taxCategory: 'schedule_c_income');
        $this->createCategory('Freelance/Contract', 'income', parentId: $income->id, taxCategory: 'schedule_c_income');
        $this->createCategory('Investment Income', 'income', parentId: $income->id);
        $this->createCategory('Refunds', 'income', parentId: $income->id);
        $this->createCategory('Other Income', 'income', parentId: $income->id);

        // Giving
        $giving = $this->createCategory('Giving', 'expense', isSystem: true);
        $this->createCategory('Church', 'expense', parentId: $giving->id);
        $this->createCategory('Charity', 'expense', parentId: $giving->id);
        $this->createCategory('Vision', 'expense', parentId: $giving->id);

        // Savings
        $savings = $this->createCategory('Savings', 'expense', isSystem: true);
        $this->createCategory('Emergency Fund', 'expense', parentId: $savings->id);
        $this->createCategory('Investment Fund', 'expense', parentId: $savings->id);
        $this->createCategory('Sinking Funds', 'expense', parentId: $savings->id);

        // Insurance
        $insurance = $this->createCategory('Insurance', 'expense', isSystem: true);
        $this->createCategory('Life Insurance', 'expense', parentId: $insurance->id);

        // Housing
        $housing = $this->createCategory('Housing', 'expense', isSystem: true);
        $this->createCategory('Rent/Mortgage', 'expense', parentId: $housing->id);
        $this->createCategory('Utilities', 'expense', parentId: $housing->id);
        $this->createCategory('Water', 'expense', parentId: $housing->id);
        $this->createCategory('Natural Gas', 'expense', parentId: $housing->id);
        $this->createCategory('Electricity', 'expense', parentId: $housing->id);
        $this->createCategory('Cable/Internet', 'expense', parentId: $housing->id);
        $this->createCategory('Trash', 'expense', parentId: $housing->id);
        $this->createCategory('Home Insurance', 'expense', parentId: $housing->id);
        $this->createCategory('Home Maintenance', 'expense', parentId: $housing->id);
        $this->createCategory('Property Tax', 'expense', parentId: $housing->id);

        // Transportation
        $transport = $this->createCategory('Transportation', 'expense', isSystem: true);
        $this->createCategory('Gas/Fuel', 'expense', parentId: $transport->id);
        $this->createCategory('Car Insurance', 'expense', parentId: $transport->id);
        $this->createCategory('Car Payment', 'expense', parentId: $transport->id);
        $this->createCategory('Maintenance/Repair', 'expense', parentId: $transport->id);
        $this->createCategory('Public Transit', 'expense', parentId: $transport->id);
        $this->createCategory('Parking/Tolls', 'expense', parentId: $transport->id);

        // Food & Dining
        $food = $this->createCategory('Food & Dining', 'expense', isSystem: true);
        $this->createCategory('Groceries', 'expense', parentId: $food->id);
        $this->createCategory('Restaurants', 'expense', parentId: $food->id);
        $this->createCategory('Coffee Shops', 'expense', parentId: $food->id);
        $this->createCategory('Fast Food', 'expense', parentId: $food->id);

        // Healthcare
        $health = $this->createCategory('Healthcare', 'expense', isSystem: true);
        $this->createCategory('Health Insurance', 'expense', parentId: $health->id, taxCategory: 'self_employed_health');
        $this->createCategory('Medical', 'expense', parentId: $health->id);
        $this->createCategory('Dental', 'expense', parentId: $health->id);
        $this->createCategory('Pharmacy', 'expense', parentId: $health->id);

        // Personal
        $personal = $this->createCategory('Personal', 'expense', isSystem: true);
        $this->createCategory('Clothing', 'expense', parentId: $personal->id);
        $this->createCategory('Phone', 'expense', parentId: $personal->id);
        $this->createCategory('Fun Money', 'expense', parentId: $personal->id);
        $this->createCategory('Personal Care', 'expense', parentId: $personal->id);
        $this->createCategory('Subscriptions', 'expense', parentId: $personal->id);
        $this->createCategory('Pet Care', 'expense', parentId: $personal->id);
        $this->createCategory('Entertainment', 'expense', parentId: $personal->id);
        $this->createCategory('Miscellaneous', 'expense', parentId: $personal->id);
        $this->createCategory('Education', 'expense', parentId: $personal->id);

        // Family
        $family = $this->createCategory('Family', 'expense', isSystem: true);
        $this->createCategory('Childcare', 'expense', parentId: $family->id);
        $this->createCategory('Tuition - Veritas', 'expense', parentId: $family->id);
        $this->createCategory('Tuition - Westside', 'expense', parentId: $family->id);
        $this->createCategory('Child Activities', 'expense', parentId: $family->id);
        $this->createCategory('School Supplies', 'expense', parentId: $family->id);

        // Debt Payments
        $debt = $this->createCategory('Debt Payments', 'debt_payment', isSystem: true);
        $this->createCategory('Credit Card Payment', 'debt_payment', parentId: $debt->id);
        $this->createCategory('Loan Payment', 'debt_payment', parentId: $debt->id);
        $this->createCategory('Student Loan', 'debt_payment', parentId: $debt->id);
        $this->createCategory('Collections', 'debt_payment', parentId: $debt->id);
        $this->createCategory('ECG', 'debt_payment', parentId: $debt->id);

        // Tax Payments
        $tax = $this->createCategory('Tax Payments', 'tax_payment', isSystem: true);
        $this->createCategory('Federal Income Tax', 'tax_payment', parentId: $tax->id, taxCategory: 'federal_tax');
        $this->createCategory('State Income Tax', 'tax_payment', parentId: $tax->id, taxCategory: 'state_tax');
        $this->createCategory('Self-Employment Tax', 'tax_payment', parentId: $tax->id, taxCategory: 'se_tax');
        $this->createCategory('IRS Installment', 'tax_payment', parentId: $tax->id);
        $this->createCategory('Tax Penalty/Interest', 'tax_payment', parentId: $tax->id);

        // Transfers
        $transfer = $this->createCategory('Transfers', 'transfer', isSystem: true);
        $this->createCategory('Between Accounts', 'transfer', parentId: $transfer->id);
        $this->createCategory('Business <> Personal', 'transfer', parentId: $transfer->id);

        // Business Expenses (tax deductible)
        $business = $this->createCategory('Business Expenses', 'expense', isSystem: true);
        $this->createCategory('Software/SaaS', 'expense', parentId: $business->id, taxCategory: 'schedule_c_software');
        $this->createCategory('Office Supplies', 'expense', parentId: $business->id, taxCategory: 'schedule_c_supplies');
        $this->createCategory('Professional Services', 'expense', parentId: $business->id, taxCategory: 'schedule_c_professional');
        $this->createCategory('Contractors', 'expense', parentId: $business->id, taxCategory: 'schedule_c_contract_labor');
        $this->createCategory('Travel', 'expense', parentId: $business->id, taxCategory: 'schedule_c_travel');
        $this->createCategory('Meals (Business)', 'expense', parentId: $business->id, taxCategory: 'schedule_c_meals');
        $this->createCategory('Bank Fees', 'expense', parentId: $business->id, taxCategory: 'schedule_c_bank_fees');
        $this->createCategory('Merchant Processing Fees', 'expense', parentId: $business->id, taxCategory: 'schedule_c_commissions_and_fees');
        $this->createCategory('Equipment', 'expense', parentId: $business->id, taxCategory: 'schedule_c_equipment');
        $this->createCategory('Home Office', 'expense', parentId: $business->id, taxCategory: 'home_office');
    }

    /**
     * Create a category using firstOrCreate for idempotency.
     */
    private function createCategory(
        string $name,
        string $type,
        ?int $parentId = null,
        bool $isSystem = false,
        ?string $taxCategory = null,
    ): TransactionCategory {
        return TransactionCategory::firstOrCreate(
            [
                'name' => $name,
                'type' => $type,
                'parent_id' => $parentId,
                'user_id' => null,
            ],
            [
                'is_system' => $isSystem,
                'tax_category' => $taxCategory,
                'budget_trackable' => true,
            ]
        );
    }
}
