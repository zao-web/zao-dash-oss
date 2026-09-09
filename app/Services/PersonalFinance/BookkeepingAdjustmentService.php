<?php

namespace App\Services\PersonalFinance;

use App\Models\BookkeepingAdjustment;
use App\Models\CategorizationRule;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BookkeepingAdjustmentService
{
    public function approve(
        BookkeepingAdjustment $adjustment,
        ?int $categoryId = null,
        bool $learnMatch = true,
    ): BookkeepingAdjustment {
        if ($adjustment->status !== BookkeepingAdjustment::STATUS_SUGGESTED) {
            throw ValidationException::withMessages([
                'adjustment' => 'Only open bookkeeping suggestions can be approved.',
            ]);
        }

        $transaction = $adjustment->transaction;
        $userId = (int) $adjustment->user_id;

        if ($transaction === null) {
            throw ValidationException::withMessages([
                'adjustment' => 'This bookkeeping suggestion is missing the transaction.',
            ]);
        }

        $approvedCategory = $this->resolveApprovedCategory(
            adjustment: $adjustment,
            categoryId: $categoryId,
            userId: $userId,
        );
        $currentCategoryId = $transaction->category_id;

        $transaction->update([
            'category_id' => $approvedCategory->id,
        ]);

        BookkeepingAdjustment::query()
            ->where('personal_transaction_id', $transaction->id)
            ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
            ->where('id', '!=', $adjustment->id)
            ->update([
                'status' => BookkeepingAdjustment::STATUS_DISMISSED,
                'dismissed_at' => now(),
                'resolved_at' => now(),
            ]);

        $adjustment->update([
            'current_category_id' => $currentCategoryId,
            'suggested_category_id' => $approvedCategory->id,
            'status' => BookkeepingAdjustment::STATUS_APPLIED,
            'applied_at' => now(),
            'dismissed_at' => null,
            'resolved_at' => now(),
        ]);

        if ($learnMatch) {
            $this->learnApprovedCategory($transaction, $approvedCategory);
        }

        return $adjustment->refresh();
    }

    public function dismiss(BookkeepingAdjustment $adjustment): BookkeepingAdjustment
    {
        if ($adjustment->status !== BookkeepingAdjustment::STATUS_SUGGESTED) {
            throw ValidationException::withMessages([
                'adjustment' => 'Only open bookkeeping suggestions can be dismissed.',
            ]);
        }

        $adjustment->update([
            'status' => BookkeepingAdjustment::STATUS_DISMISSED,
            'dismissed_at' => now(),
            'resolved_at' => now(),
        ]);

        return $adjustment->refresh();
    }

    protected function resolveApprovedCategory(
        BookkeepingAdjustment $adjustment,
        ?int $categoryId,
        int $userId,
    ): TransactionCategory {
        $resolvedCategoryId = $categoryId ?? $adjustment->suggested_category_id;

        if ($resolvedCategoryId === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Choose a bookkeeping category before approving this suggestion.',
            ]);
        }

        $category = TransactionCategory::query()
            ->where('id', $resolvedCategoryId)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId))
            ->first();

        if (! $category instanceof TransactionCategory) {
            throw ValidationException::withMessages([
                'category_id' => 'The selected bookkeeping category is not available.',
            ]);
        }

        return $category;
    }

    protected function learnApprovedCategory(PersonalTransaction $transaction, TransactionCategory $category): void
    {
        $matchValue = $transaction->merchant_name ?: $transaction->description;

        if (! is_string($matchValue) || trim($matchValue) === '') {
            return;
        }

        $matchField = $transaction->merchant_name ? 'merchant_name' : 'description';
        $normalizedMatchValue = strtolower(trim($matchValue));

        CategorizationRule::query()->updateOrCreate(
            [
                'user_id' => $transaction->account?->user_id,
                'match_field' => $matchField,
                'match_value' => $normalizedMatchValue,
            ],
            [
                'category_id' => $category->id,
            ],
        );

        $matchingTransactions = $this->matchingTransactionsForLearnedRule(
            transaction: $transaction,
            matchField: $matchField,
            normalizedMatchValue: $normalizedMatchValue,
        );

        foreach ($matchingTransactions as $matchingTransaction) {
            $matchingTransaction->update([
                'category_id' => $category->id,
            ]);

            BookkeepingAdjustment::query()
                ->where('personal_transaction_id', $matchingTransaction->id)
                ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
                ->update([
                    'suggested_category_id' => $category->id,
                    'status' => BookkeepingAdjustment::STATUS_DISMISSED,
                    'dismissed_at' => now(),
                    'resolved_at' => now(),
                ]);
        }
    }

    /**
     * @return Collection<int, PersonalTransaction>
     */
    protected function matchingTransactionsForLearnedRule(
        PersonalTransaction $transaction,
        string $matchField,
        string $normalizedMatchValue,
    ): Collection {
        $userId = $transaction->account?->user_id;

        if (! is_int($userId)) {
            return collect();
        }

        $column = $matchField === 'merchant_name' ? 'merchant_name' : 'description';

        return PersonalTransaction::query()
            ->with('account')
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->whereRaw("lower(trim(coalesce({$column}, ''))) = ?", [$normalizedMatchValue])
            ->where('id', '!=', $transaction->id)
            ->get();
    }
}
