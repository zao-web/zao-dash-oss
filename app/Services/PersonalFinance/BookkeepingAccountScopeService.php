<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalAccount;
use Illuminate\Support\Collection;

class BookkeepingAccountScopeService
{
    /**
     * @param  Collection<int, PersonalAccount>  $activeAccounts
     * @return array{
     *     accounts: Collection<int, PersonalAccount>,
     *     scope_code: string,
     *     uses_explicit_business_accounts: bool,
     * }
     */
    public function resolve(Collection $activeAccounts): array
    {
        $businessAccounts = $activeAccounts
            ->where('is_business', true)
            ->values();

        if ($businessAccounts->isNotEmpty()) {
            return [
                'accounts' => $businessAccounts,
                'scope_code' => 'explicit_business',
                'uses_explicit_business_accounts' => true,
            ];
        }

        $likelyBusinessAccounts = $activeAccounts
            ->filter(fn (PersonalAccount $account): bool => $this->looksLikeBusinessAccount($account))
            ->values();

        if ($likelyBusinessAccounts->isNotEmpty()) {
            return [
                'accounts' => $likelyBusinessAccounts,
                'scope_code' => 'inferred_business',
                'uses_explicit_business_accounts' => false,
            ];
        }

        return [
            'accounts' => $activeAccounts->values(),
            'scope_code' => $activeAccounts->isNotEmpty() ? 'all_active' : 'none',
            'uses_explicit_business_accounts' => false,
        ];
    }

    protected function looksLikeBusinessAccount(PersonalAccount $account): bool
    {
        $label = strtolower(trim(($account->institution_name ?? '').' '.($account->name ?? '')));

        return str_contains($label, 'business')
            || str_contains($label, 'incoming/')
            || str_contains($label, 'outgoing/');
    }
}
