<?php

use App\Models\TransactionCategory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $businessParent = TransactionCategory::query()->firstOrCreate(
            [
                'user_id' => null,
                'parent_id' => null,
                'name' => 'Business Expenses',
                'type' => 'expense',
            ],
            [
                'is_system' => true,
                'budget_trackable' => true,
                'tax_category' => null,
            ],
        );

        $categories = [
            [
                'name' => 'Contractors',
                'tax_category' => 'schedule_c_contract_labor',
            ],
            [
                'name' => 'Bank Fees',
                'tax_category' => 'schedule_c_bank_fees',
            ],
            [
                'name' => 'Merchant Processing Fees',
                'tax_category' => 'schedule_c_commissions_and_fees',
            ],
        ];

        foreach ($categories as $category) {
            TransactionCategory::query()->firstOrCreate(
                [
                    'user_id' => null,
                    'parent_id' => $businessParent->id,
                    'name' => $category['name'],
                    'type' => 'expense',
                ],
                [
                    'is_system' => true,
                    'budget_trackable' => true,
                    'tax_category' => $category['tax_category'],
                ],
            );
        }
    }

    public function down(): void
    {
        $businessParentId = TransactionCategory::query()
            ->whereNull('user_id')
            ->whereNull('parent_id')
            ->where('name', 'Business Expenses')
            ->where('type', 'expense')
            ->value('id');

        if (! $businessParentId) {
            return;
        }

        TransactionCategory::query()
            ->whereNull('user_id')
            ->where('parent_id', $businessParentId)
            ->where('type', 'expense')
            ->whereIn('name', [
                'Contractors',
                'Bank Fees',
                'Merchant Processing Fees',
            ])
            ->delete();
    }
};
