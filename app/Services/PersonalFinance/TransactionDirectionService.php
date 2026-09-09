<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalTransaction;

class TransactionDirectionService
{
    /**
     * @var array<int, string>
     */
    protected array $liabilityAccountTypes = [
        'credit_card',
        'loan',
        'collections',
        'tax_debt',
    ];

    public function isOutflow(PersonalTransaction $transaction): bool
    {
        $amount = (float) $transaction->amount;

        if ($amount === 0.0) {
            return false;
        }

        return $this->usesLiabilityPolarity($transaction)
            ? $amount > 0
            : $amount < 0;
    }

    public function isInflow(PersonalTransaction $transaction): bool
    {
        $amount = (float) $transaction->amount;

        if ($amount === 0.0) {
            return false;
        }

        return ! $this->isOutflow($transaction);
    }

    protected function usesLiabilityPolarity(PersonalTransaction $transaction): bool
    {
        $accountType = (string) ($transaction->account?->account_type ?? $transaction->personalAccount?->account_type ?? '');

        return in_array($accountType, $this->liabilityAccountTypes, true);
    }
}
