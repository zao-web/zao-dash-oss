<?php

namespace App\Services\PersonalFinance;

use App\Models\BookkeepingAdjustment;
use App\Models\CategorizationRule;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Services\AI\ClaudeCliService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TransactionCategorizationService
{
    protected int $bookkeepingAiBatchSize = 20;

    public function __construct(
        protected ClaudeCliService $claude,
        protected BookkeepingAccountScopeService $bookkeepingAccountScopeService,
    ) {}

    /**
     * Keyword-based rules for auto-categorization.
     * Maps lowercase merchant/description keywords to category names.
     *
     * @var array<string, string>
     */
    protected array $rules = [
        // === SPECIFIC MATCHES FIRST (before generic patterns) ===

        // Business income
        'agency llc' => 'Business Income',
        'modus cre dir dep' => 'Business Income',
        'allium us holding' => 'Business Income',
        'allium us' => 'Business Income',
        'payment reversal' => 'Refunds',

        // Debt payments (specific creditors before generic "payment")
        'tesla finance' => 'Car Payment',
        'tesla motor' => 'Car Payment',
        'tesla lease' => 'Car Payment',
        'upstart' => 'Loan Payment',
        'affirm' => 'Loan Payment',
        'pay over time purchases' => 'Loan Payment',
        'expansioncap' => 'Loan Payment',
        'expansion capita' => 'Loan Payment',
        'ecg ' => 'ECG',
        'credit card payment' => 'Credit Card Payment',
        'minimum payment' => 'Credit Card Payment',
        'debt payment' => 'Loan Payment',

        // Mortgage / Housing (before generic "payment")
        'mortgage' => 'Rent/Mortgage',
        'rocket mortgage' => 'Rent/Mortgage',
        'quicken loan' => 'Rent/Mortgage',
        'nationstar' => 'Rent/Mortgage',
        'mr. cooper' => 'Rent/Mortgage',
        'loancare' => 'Rent/Mortgage',

        // Tuition / Education
        'veritas' => 'Tuition - Veritas',
        'westside' => 'Tuition - Westside',
        'tuition' => 'Education',
        'school' => 'Education',

        // Insurance (specific before generic)
        'health insurance' => 'Health Insurance',
        'medical insurance' => 'Health Insurance',
        'dental insurance' => 'Health Insurance',
        'life insurance' => 'Life Insurance',
        'ladder life' => 'Life Insurance',
        'fabric' => 'Life Insurance',
        'auto insurance' => 'Car Insurance',
        'car insurance' => 'Car Insurance',
        'geico' => 'Car Insurance',
        'state farm' => 'Car Insurance',
        'progressive' => 'Car Insurance',
        'allstate' => 'Car Insurance',
        'usaa' => 'Car Insurance',
        'liberty mutual' => 'Car Insurance',

        // Tax payments
        'irs' => 'Federal Income Tax',
        'internal revenue' => 'Federal Income Tax',
        'eftps' => 'Federal Income Tax',
        'state tax' => 'State Income Tax',
        'franchise tax' => 'State Income Tax',

        // Structural movement
        'owner draw' => 'Business <> Personal',
        'owner contribution' => 'Business <> Personal',
        'shareholder distribution' => 'Business <> Personal',
        'shareholder contribution' => 'Business <> Personal',
        'member draw' => 'Business <> Personal',
        'member contribution' => 'Business <> Personal',
        'capital contribution' => 'Business <> Personal',
        'shareholder loan' => 'Business <> Personal',
        'owner loan' => 'Business <> Personal',
        'loan from owner' => 'Business <> Personal',
        'loan to owner' => 'Business <> Personal',
        'member loan' => 'Business <> Personal',
        'officer loan' => 'Business <> Personal',
        'reimbursement' => 'Business <> Personal',
        'accountable plan' => 'Business <> Personal',
        'greenlight' => 'Business <> Personal',
        'apple cash' => 'Business <> Personal',
        'owner transfer' => 'Business <> Personal',
        'agency business checking' => 'Between Accounts',

        // Collections
        'collections' => 'Collections',
        'collection agency' => 'Collections',
        'portfolio recovery' => 'Collections',
        'midland credit' => 'Collections',

        // === FOOD ===
        'starbucks' => 'Coffee Shops',
        'dunkin' => 'Coffee Shops',
        'peet' => 'Coffee Shops',
        'mcdonald' => 'Fast Food',
        'burger king' => 'Fast Food',
        'wendy' => 'Fast Food',
        'chipotle' => 'Fast Food',
        'subway' => 'Fast Food',
        'domino' => 'Fast Food',
        'pizza hut' => 'Fast Food',
        'taco bell' => 'Fast Food',
        'chick-fil-a' => 'Fast Food',
        'panda express' => 'Fast Food',
        'walmart' => 'Groceries',
        'target' => 'Groceries',
        'kroger' => 'Groceries',
        'whole foods' => 'Groceries',
        'trader joe' => 'Groceries',
        'costco' => 'Groceries',
        'safeway' => 'Groceries',
        'publix' => 'Groceries',
        'aldi' => 'Groceries',
        'h-e-b' => 'Groceries',
        'winco' => 'Groceries',
        'fred meyer' => 'Groceries',
        'meijer' => 'Groceries',
        'sam\'s club' => 'Groceries',
        'grubhub' => 'Restaurants',
        'doordash' => 'Restaurants',
        'uber eats' => 'Restaurants',

        // === TRANSPORTATION ===
        'shell' => 'Gas/Fuel',
        'exxon' => 'Gas/Fuel',
        'chevron' => 'Gas/Fuel',
        'bp ' => 'Gas/Fuel',
        'speedway' => 'Gas/Fuel',
        'supercharger' => 'Gas/Fuel',
        'chargepoint' => 'Gas/Fuel',
        'electrify america' => 'Gas/Fuel',
        'uber' => 'Public Transit',
        'lyft' => 'Public Transit',

        // === HOUSING UTILITIES ===
        'electric' => 'Electricity',
        'power company' => 'Electricity',
        'energy' => 'Electricity',
        'pge' => 'Electricity',
        'duke energy' => 'Electricity',
        'natural gas' => 'Natural Gas',
        'gas utility' => 'Natural Gas',
        'water district' => 'Water',
        'water utility' => 'Water',
        'sewer' => 'Water',
        'waste management' => 'Trash',
        'republic services' => 'Trash',
        'trash' => 'Trash',
        'comcast' => 'Cable/Internet',
        'xfinity' => 'Cable/Internet',
        'spectrum' => 'Cable/Internet',
        'cox' => 'Cable/Internet',
        'centurylink' => 'Cable/Internet',
        'frontier' => 'Cable/Internet',
        'starlink' => 'Cable/Internet',
        'verizon fios' => 'Cable/Internet',

        // === PHONE ===
        'verizon' => 'Phone',
        'at&t' => 'Phone',
        't-mobile' => 'Phone',
        'mint mobile' => 'Phone',
        'visible' => 'Phone',

        // === SUBSCRIPTIONS ===
        'netflix' => 'Subscriptions',
        'spotify' => 'Subscriptions',
        'hulu' => 'Subscriptions',
        'disney+' => 'Subscriptions',
        'apple.com/bill' => 'Subscriptions',
        'apple.com bill' => 'Subscriptions',
        'amazon prime' => 'Subscriptions',
        'aviron' => 'Subscriptions',
        'universaly' => 'Subscriptions',
        'talkshop live' => 'Subscriptions',
        'youtube' => 'Subscriptions',
        'hbo max' => 'Subscriptions',
        'paramount' => 'Subscriptions',
        'peacock' => 'Subscriptions',

        // === BUSINESS / SAAS ===
        'github' => 'Software/SaaS',
        'adobe' => 'Software/SaaS',
        'cursor' => 'Software/SaaS',
        'digitalocean' => 'Software/SaaS',
        'aws' => 'Software/SaaS',
        'google cloud' => 'Software/SaaS',
        'harvest' => 'Software/SaaS',
        'heroku' => 'Software/SaaS',
        'vercel' => 'Software/SaaS',
        'figma' => 'Software/SaaS',
        '1password' => 'Software/SaaS',
        'slack' => 'Software/SaaS',
        'zoom' => 'Software/SaaS',
        'notion' => 'Software/SaaS',
        'linear' => 'Software/SaaS',
        'laravel' => 'Software/SaaS',
        'forge' => 'Software/SaaS',
        'vapor' => 'Software/SaaS',
        'cloudflare' => 'Software/SaaS',
        'riversidefm' => 'Software/SaaS',
        'serverpilot' => 'Software/SaaS',
        'intuit' => 'Software/SaaS',
        'openai' => 'Software/SaaS',
        'anthropic' => 'Software/SaaS',
        'stripe' => 'Software/SaaS',

        // === HEALTH ===
        'pharmacy' => 'Pharmacy',
        'cvs' => 'Pharmacy',
        'walgreens' => 'Pharmacy',
        'rite aid' => 'Pharmacy',
        'doctor' => 'Medical',
        'medical' => 'Medical',
        'hospital' => 'Medical',
        'urgent care' => 'Medical',
        'dental' => 'Dental',
        'dentist' => 'Dental',
        'vitamin' => 'Pharmacy',

        // === PERSONAL ===
        'haircut' => 'Personal Care',
        'salon' => 'Personal Care',
        'barber' => 'Personal Care',
        'spa' => 'Personal Care',
        'pet' => 'Pet Care',
        'petco' => 'Pet Care',
        'petsmart' => 'Pet Care',
        'veterinar' => 'Pet Care',
        'daycare' => 'Childcare',
        'child care' => 'Childcare',

        // === GIVING ===
        'tithe' => 'Church',
        'church' => 'Church',
        'donation' => 'Charity',
        'charity' => 'Charity',

        // Amazon (general shopping)
        'amazon' => 'Clothing',
        'amzn' => 'Clothing',

        // === GENERIC CATCH-ALLS (last) ===
        'autopay' => 'Credit Card Payment',
    ];

    /**
     * Suggest a category for a transaction based on its description/merchant.
     */
    public function suggestCategory(PersonalTransaction $transaction): ?TransactionCategory
    {
        $text = strtolower(trim(collect([
            $transaction->merchant_name,
            $transaction->description,
        ])->filter()->implode(' ')));

        if (empty($text)) {
            return null;
        }

        $userId = $transaction->account?->user_id;

        // 1. Check user-defined categorization rules first
        if ($userId) {
            $merchantText = strtolower(trim($transaction->merchant_name ?? ''));
            $descText = strtolower(trim($transaction->description ?? ''));

            $rule = CategorizationRule::where('user_id', $userId)
                ->where(function ($q) use ($merchantText, $descText) {
                    $q->where(fn ($q2) => $q2->where('match_field', 'merchant_name')->where('match_value', $merchantText))
                        ->orWhere(fn ($q2) => $q2->where('match_field', 'description')->where('match_value', $descText));
                })
                ->first();

            if ($rule) {
                return TransactionCategory::find($rule->category_id);
            }
        }

        if (str_contains($text, 'paypal transfer') && str_contains($text, 'agency')) {
            return TransactionCategory::where('name', 'Between Accounts')->first();
        }

        if (str_contains($text, 'money transfer authorized') || str_contains($text, 'visa direct')) {
            return TransactionCategory::where('name', 'Business <> Personal')->first();
        }

        foreach ($this->rules as $keyword => $categoryName) {
            if (str_contains($text, $keyword)) {
                $category = TransactionCategory::where('name', $categoryName)->first();

                if ($category instanceof TransactionCategory) {
                    return $category;
                }
            }
        }

        // 3. Check previous transactions from same merchant
        if ($transaction->merchant_name) {
            $previousMatch = PersonalTransaction::where('merchant_name', $transaction->merchant_name)
                ->whereNotNull('category_id')
                ->where('id', '!=', $transaction->id)
                ->orderBy('transaction_date', 'desc')
                ->first();

            if ($previousMatch) {
                return TransactionCategory::find($previousMatch->category_id);
            }
        }

        if ($this->looksLikeBusinessPersonalMovementText($text)) {
            return TransactionCategory::where('name', 'Business <> Personal')->first();
        }

        if ($this->looksLikeTransferMovementText($text)) {
            return TransactionCategory::where('name', 'Between Accounts')->first();
        }

        return null;
    }

    /**
     * Auto-categorize all uncategorized transactions for a user.
     * Returns count of transactions categorized.
     */
    public function autoCategorizeAll(int $userId): int
    {
        $uncategorized = PersonalTransaction::query()
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->whereNull('category_id')
            ->get();
        $categorized = 0;

        foreach ($uncategorized as $transaction) {
            $category = $this->suggestCategory($transaction);

            if (! $category instanceof TransactionCategory) {
                continue;
            }

            $this->applyCategorySuggestion(
                transaction: $transaction,
                category: $category,
                source: BookkeepingAdjustment::SOURCE_RULE,
                confidence: 0.96,
                rationale: 'Matched from an existing rule, merchant history, or deterministic keyword pattern.',
            );

            $categorized++;
        }

        if ($categorized < $uncategorized->count() && $this->claude->isConfigured()) {
            $aiCleanup = $this->classifyWithAi(
                userId: $userId,
                transactions: $uncategorized
                    ->map(fn (PersonalTransaction $transaction): PersonalTransaction => $transaction->fresh(['account', 'category']))
                    ->filter(fn (PersonalTransaction $transaction): bool => $transaction->category_id === null)
                    ->values(),
                categories: $this->availableCategories($userId),
                allowSuggestions: false,
            );

            $categorized += (int) ($aiCleanup['auto_applied_count'] ?? 0);
        }

        Log::info('[AutoCategorize] Categorized transactions', [
            'user_id' => $userId,
            'total_uncategorized' => $uncategorized->count(),
            'categorized' => $categorized,
        ]);

        return $categorized;
    }

    /**
     * @return array{
     *     auto_applied_count: int,
     *     suggested_count: int,
     *     unresolved_count: int,
     *     ai_available: bool,
     * }
     */
    public function runBookkeepingCleanup(int $userId, ?int $year = null, bool $businessOnly = true): array
    {
        $year ??= (int) now()->year;
        $candidateTransactions = $this->candidateTransactions($userId, $year, $businessOnly);
        $categories = $this->availableCategories($userId);
        $autoAppliedCount = $this->applyDeterministicCleanup($candidateTransactions);
        $suggestedCount = 0;
        $remainingTransactions = $this->refreshRemainingCleanupCandidates($candidateTransactions);

        if ($remainingTransactions->isNotEmpty() && $this->claude->isConfigured()) {
            $aiCleanup = $this->classifyWithAi(
                userId: $userId,
                transactions: $remainingTransactions,
                categories: $categories,
                allowSuggestions: true,
            );

            $autoAppliedCount += (int) ($aiCleanup['auto_applied_count'] ?? 0);
            $suggestedCount += (int) ($aiCleanup['suggested_count'] ?? 0);
        }

        $autoAppliedCount += $this->applyConsensusSuggestions($userId, $year);

        $openSuggestions = BookkeepingAdjustment::query()
            ->where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
            ->count();

        Log::info('[BookkeepingCleanup] Completed bookkeeping cleanup run', [
            'user_id' => $userId,
            'tax_year' => $year,
            'business_only' => $businessOnly,
            'candidates' => $candidateTransactions->count(),
            'auto_applied' => $autoAppliedCount,
            'suggested' => $suggestedCount,
            'open_suggestions' => $openSuggestions,
            'ai_available' => $this->claude->isConfigured(),
        ]);

        return [
            'auto_applied_count' => $autoAppliedCount,
            'suggested_count' => $openSuggestions,
            'unresolved_count' => $remainingTransactions
                ->map(fn (PersonalTransaction $transaction): PersonalTransaction => $transaction->fresh())
                ->filter(fn (PersonalTransaction $transaction): bool => $this->needsBookkeepingCleanup($transaction))
                ->count(),
            'ai_available' => $this->claude->isConfigured(),
        ];
    }

    /**
     * @return array{
     *     auto_applied_count: int,
     *     unresolved_count: int,
     *     ai_available: bool,
     * }
     */
    public function runDeterministicBookkeepingCleanup(int $userId, ?int $year = null, bool $businessOnly = true): array
    {
        $year ??= (int) now()->year;
        $candidateTransactions = $this->candidateTransactions($userId, $year, $businessOnly);
        $autoAppliedCount = $this->applyDeterministicCleanup($candidateTransactions);
        $remainingTransactions = $this->refreshRemainingCleanupCandidates($candidateTransactions);

        Log::info('[BookkeepingCleanup] Deterministic bookkeeping cleanup pass completed', [
            'user_id' => $userId,
            'tax_year' => $year,
            'business_only' => $businessOnly,
            'candidates' => $candidateTransactions->count(),
            'auto_applied' => $autoAppliedCount,
            'unresolved' => $remainingTransactions->count(),
            'ai_available' => $this->claude->isConfigured(),
        ]);

        return [
            'auto_applied_count' => $autoAppliedCount,
            'unresolved_count' => $remainingTransactions->count(),
            'ai_available' => $this->claude->isConfigured(),
        ];
    }

    /**
     * @return Collection<int, PersonalTransaction>
     */
    protected function candidateTransactions(int $userId, int $year, bool $businessOnly): Collection
    {
        $activeAccounts = PersonalAccount::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
        $scopedAccounts = $businessOnly
            ? $this->bookkeepingAccountScopeService->resolve($activeAccounts)['accounts']
            : $activeAccounts->values();

        if ($scopedAccounts->isEmpty()) {
            return collect();
        }

        return PersonalTransaction::query()
            ->with(['account', 'category'])
            ->whereIn('personal_account_id', $scopedAccounts->modelKeys())
            ->whereYear('transaction_date', $year)
            ->get()
            ->filter(fn (PersonalTransaction $transaction): bool => $this->needsBookkeepingCleanup($transaction))
            ->values();
    }

    protected function needsBookkeepingCleanup(PersonalTransaction $transaction): bool
    {
        if ($transaction->category_id === null) {
            return true;
        }

        $suggestedCategory = $this->suggestCategory($transaction);

        if (
            $suggestedCategory instanceof TransactionCategory
            && (int) $transaction->category_id !== $suggestedCategory->id
        ) {
            return true;
        }

        $currentCategoryType = (string) ($transaction->category?->type ?? '');

        if ($this->looksLikeBusinessPersonalMovement($transaction) || $this->looksLikeTransferMovement($transaction)) {
            return $currentCategoryType !== 'transfer';
        }

        return false;
    }

    /**
     * @param  Collection<int, PersonalTransaction>  $candidateTransactions
     */
    protected function applyDeterministicCleanup(Collection $candidateTransactions): int
    {
        $autoAppliedCount = 0;

        foreach ($candidateTransactions as $transaction) {
            $category = $this->suggestCategory($transaction);

            if (! $category instanceof TransactionCategory || (int) $transaction->category_id === $category->id) {
                continue;
            }

            $this->applyCategorySuggestion(
                transaction: $transaction,
                category: $category,
                source: BookkeepingAdjustment::SOURCE_RULE,
                confidence: 0.96,
                rationale: 'Matched from an existing rule, merchant history, or deterministic keyword pattern.',
            );
            $autoAppliedCount++;
        }

        return $autoAppliedCount;
    }

    /**
     * @param  Collection<int, PersonalTransaction>  $candidateTransactions
     * @return Collection<int, PersonalTransaction>
     */
    protected function refreshRemainingCleanupCandidates(Collection $candidateTransactions): Collection
    {
        return $candidateTransactions
            ->map(fn (PersonalTransaction $transaction): PersonalTransaction => $transaction->fresh(['account', 'category']))
            ->filter(fn (PersonalTransaction $transaction): bool => $this->needsBookkeepingCleanup($transaction))
            ->values();
    }

    protected function looksLikeTransferMovement(PersonalTransaction $transaction): bool
    {
        return $this->looksLikeTransferMovementText(
            strtolower(trim(($transaction->merchant_name ?? '').' '.($transaction->description ?? '')))
        );
    }

    protected function looksLikeBusinessPersonalMovement(PersonalTransaction $transaction): bool
    {
        return $this->looksLikeBusinessPersonalMovementText(
            strtolower(trim(($transaction->merchant_name ?? '').' '.($transaction->description ?? '')))
        );
    }

    protected function looksLikeTransferMovementText(string $text): bool
    {
        if (str_contains($text, 'paypal inst xfer')) {
            return false;
        }

        if (str_contains($text, 'zelle from')) {
            return false;
        }

        return str_contains($text, 'transfer')
            || str_contains($text, 'xfer to')
            || str_contains($text, 'xfer from')
            || str_contains($text, 'online xfer')
            || str_contains($text, 'internal xfer')
            || str_contains($text, 'zelle')
            || str_contains($text, 'venmo')
            || str_contains($text, 'cash app')
            || str_contains($text, 'wire')
            || str_contains($text, 'from savings')
            || str_contains($text, 'to savings');
    }

    protected function looksLikeBusinessPersonalMovementText(string $text): bool
    {
        return str_contains($text, 'owner draw')
            || str_contains($text, 'owner contribution')
            || str_contains($text, 'shareholder distribution')
            || str_contains($text, 'shareholder contribution')
            || str_contains($text, 'member draw')
            || str_contains($text, 'member contribution')
            || str_contains($text, 'capital contribution')
            || str_contains($text, 'shareholder loan')
            || str_contains($text, 'owner loan')
            || str_contains($text, 'loan from owner')
            || str_contains($text, 'loan to owner')
            || str_contains($text, 'member loan')
            || str_contains($text, 'officer loan')
            || str_contains($text, 'loan receivable')
            || str_contains($text, 'loan payable')
            || str_contains($text, 'note receivable')
            || str_contains($text, 'note payable')
            || str_contains($text, 'due to owner')
            || str_contains($text, 'due from owner')
            || str_contains($text, 'reimbursement')
            || str_contains($text, 'reimburse')
            || str_contains($text, 'expense report')
            || str_contains($text, 'accountable plan');
    }

    /**
     * @return Collection<int, TransactionCategory>
     */
    protected function availableCategories(int $userId): Collection
    {
        return TransactionCategory::query()
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'tax_category']);
    }

    /**
     * @param  Collection<int, PersonalTransaction>  $transactions
     * @param  Collection<int, TransactionCategory>  $categories
     * @return array{auto_applied_count: int, suggested_count: int}
     */
    protected function classifyWithAi(int $userId, Collection $transactions, Collection $categories, bool $allowSuggestions): array
    {
        if ($transactions->isEmpty()) {
            return [
                'auto_applied_count' => 0,
                'suggested_count' => 0,
            ];
        }

        $autoAppliedCount = 0;
        $suggestedCount = 0;

        foreach ($transactions->chunk($this->bookkeepingAiBatchSize) as $transactionBatch) {
            $batchResult = $this->classifyWithAiBatch(
                userId: $userId,
                transactions: $transactionBatch->values(),
                categories: $categories,
                allowSuggestions: $allowSuggestions,
            );

            $autoAppliedCount += (int) ($batchResult['auto_applied_count'] ?? 0);
            $suggestedCount += (int) ($batchResult['suggested_count'] ?? 0);
        }

        return [
            'auto_applied_count' => $autoAppliedCount,
            'suggested_count' => $suggestedCount,
        ];
    }

    protected function applyConsensusSuggestions(int $userId, int $year): int
    {
        $suggestions = BookkeepingAdjustment::query()
            ->with(['transaction.account', 'suggestedCategory'])
            ->where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
            ->get()
            ->groupBy(fn (BookkeepingAdjustment $adjustment): string => $this->merchantConsensusKey($adjustment));
        $autoAppliedCount = 0;

        foreach ($suggestions as $group) {
            if (! $this->shouldAutoApplyConsensusGroup($group)) {
                continue;
            }

            /** @var BookkeepingAdjustment $canonicalSuggestion */
            $canonicalSuggestion = $group->first();
            $suggestedCategory = $canonicalSuggestion->suggestedCategory;

            if (! $suggestedCategory instanceof TransactionCategory) {
                continue;
            }

            foreach ($group as $suggestion) {
                $transaction = $suggestion->transaction;

                if (! $transaction instanceof PersonalTransaction) {
                    continue;
                }

                $this->applyCategorySuggestion(
                    transaction: $transaction,
                    category: $suggestedCategory,
                    source: BookkeepingAdjustment::SOURCE_AI,
                    confidence: round((float) $suggestion->confidence, 2),
                    rationale: (string) ($suggestion->rationale ?: 'Repeated merchant-level AI consensus suggestion.'),
                );
                $autoAppliedCount++;
            }
        }

        return $autoAppliedCount;
    }

    protected function merchantConsensusKey(BookkeepingAdjustment $adjustment): string
    {
        return strtolower(trim(
            (string) ($adjustment->transaction?->merchant_name
                ?: $adjustment->transaction?->description
                ?: 'unknown')
        ));
    }

    /**
     * @param  Collection<int, BookkeepingAdjustment>  $group
     */
    protected function shouldAutoApplyConsensusGroup(Collection $group): bool
    {
        if ($group->isEmpty()) {
            return false;
        }

        $categoryIds = $group->pluck('suggested_category_id')->filter()->unique()->values();

        if ($categoryIds->count() !== 1) {
            return false;
        }

        $minimumConfidence = (float) $group->min('confidence');

        if ($group->count() >= 3) {
            return $minimumConfidence >= 0.65;
        }

        return $group->count() >= 2 && $minimumConfidence >= 0.70;
    }

    /**
     * @param  Collection<int, PersonalTransaction>  $transactions
     * @param  Collection<int, TransactionCategory>  $categories
     * @return array{auto_applied_count: int, suggested_count: int}
     */
    protected function classifyWithAiBatch(int $userId, Collection $transactions, Collection $categories, bool $allowSuggestions): array
    {
        if ($transactions->isEmpty()) {
            return [
                'auto_applied_count' => 0,
                'suggested_count' => 0,
            ];
        }

        $systemPrompt = <<<'PROMPT'
You are a bookkeeping assistant. Choose the most appropriate category for each transaction from the provided category list.

Rules:
- Amount polarity depends on account type.
- For checking and savings accounts, negative amounts are usually outflows and positive amounts are usually inflows.
- For credit-card and loan accounts, positive amounts are usually outflows and negative amounts are usually inflows.
- Use account type, merchant, and description together. Do not rely on amount sign alone.
- Transfers, owner draws, owner contributions, shareholder distributions, shareholder or owner loans, reimbursements, and between-account movement should be categorized as transfer-style categories, not revenue or operating expense.
- Return valid JSON only.

Return this shape:
{
  "classifications": [
    {
      "transaction_id": 123,
      "category_id": 45,
      "confidence": 0.91,
      "rationale": "Why this category fits"
    }
  ]
}
PROMPT;

        $categoryPayload = $categories->map(fn (TransactionCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'type' => $category->type,
            'tax_category' => $category->tax_category,
        ])->values()->all();
        $transactionPayload = $transactions->map(fn (PersonalTransaction $transaction): array => [
            'transaction_id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString(),
            'amount' => round((float) $transaction->amount, 2),
            'account_name' => $transaction->account?->name,
            'account_type' => $transaction->account?->account_type,
            'description' => $transaction->description,
            'merchant_name' => $transaction->merchant_name,
            'current_category_id' => $transaction->category_id,
            'current_category_name' => $transaction->category?->name,
        ])->values()->all();
        $prompt = json_encode([
            'categories' => $categoryPayload,
            'transactions' => $transactionPayload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->claude->messageJson(
                prompt: (string) $prompt,
                systemPrompt: $systemPrompt,
                model: 'haiku',
                timeout: 60,
            );
        } catch (\Throwable $exception) {
            if ($transactions->count() > 1 && $this->shouldSplitAndRetryAiBatch($exception->getMessage())) {
                $splitIndex = (int) ceil($transactions->count() / 2);
                $leftBatch = $transactions->slice(0, $splitIndex)->values();
                $rightBatch = $transactions->slice($splitIndex)->values();
                $leftResult = $this->classifyWithAiBatch($userId, $leftBatch, $categories, $allowSuggestions);
                $rightResult = $this->classifyWithAiBatch($userId, $rightBatch, $categories, $allowSuggestions);

                return [
                    'auto_applied_count' => (int) ($leftResult['auto_applied_count'] ?? 0) + (int) ($rightResult['auto_applied_count'] ?? 0),
                    'suggested_count' => (int) ($leftResult['suggested_count'] ?? 0) + (int) ($rightResult['suggested_count'] ?? 0),
                ];
            }

            Log::warning('[BookkeepingCleanup] AI classification failed', [
                'user_id' => $userId,
                'transaction_count' => $transactions->count(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'auto_applied_count' => 0,
                'suggested_count' => 0,
            ];
        }

        $classifications = collect(data_get($response, 'classifications', []))
            ->filter(fn (mixed $row): bool => is_array($row) && is_numeric($row['transaction_id'] ?? null) && is_numeric($row['category_id'] ?? null))
            ->values();
        $categoryMap = $categories->keyBy('id');
        $autoAppliedCount = 0;
        $suggestedCount = 0;

        foreach ($classifications as $classification) {
            $transaction = $transactions->firstWhere('id', (int) $classification['transaction_id']);
            $category = $categoryMap->get((int) $classification['category_id']);

            if (! $transaction instanceof PersonalTransaction || ! $category instanceof TransactionCategory) {
                continue;
            }

            $confidence = round((float) ($classification['confidence'] ?? 0), 2);
            $rationale = (string) ($classification['rationale'] ?? 'AI bookkeeping cleanup suggestion.');

            if ((int) $transaction->category_id === $category->id) {
                $this->clearOpenSuggestions($transaction);

                continue;
            }

            if ($confidence >= 0.85) {
                $this->applyCategorySuggestion(
                    transaction: $transaction,
                    category: $category,
                    source: BookkeepingAdjustment::SOURCE_AI,
                    confidence: $confidence,
                    rationale: $rationale,
                );
                $autoAppliedCount++;

                continue;
            }

            if ($allowSuggestions && $confidence >= 0.65) {
                $this->recordSuggestion(
                    transaction: $transaction,
                    category: $category,
                    source: BookkeepingAdjustment::SOURCE_AI,
                    confidence: $confidence,
                    rationale: $rationale,
                );
                $suggestedCount++;
            }
        }

        return [
            'auto_applied_count' => $autoAppliedCount,
            'suggested_count' => $suggestedCount,
        ];
    }

    protected function shouldSplitAndRetryAiBatch(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'prompt is too long')
            || str_contains($normalized, 'input is too long')
            || str_contains($normalized, 'context length')
            || str_contains($normalized, 'maximum context length')
            || str_contains($normalized, 'exceeded the timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'timeout');
    }

    protected function applyCategorySuggestion(
        PersonalTransaction $transaction,
        TransactionCategory $category,
        string $source,
        float $confidence,
        string $rationale,
    ): void {
        $currentCategoryId = $transaction->category_id;

        $transaction->update([
            'category_id' => $category->id,
        ]);

        $this->clearOpenSuggestions($transaction);

        BookkeepingAdjustment::create([
            'user_id' => $transaction->account?->user_id,
            'personal_transaction_id' => $transaction->id,
            'current_category_id' => $currentCategoryId,
            'suggested_category_id' => $category->id,
            'tax_year' => (int) $transaction->transaction_date->year,
            'period_month' => (int) $transaction->transaction_date->month,
            'adjustment_type' => BookkeepingAdjustment::TYPE_CATEGORY_RECLASS,
            'source' => $source,
            'status' => BookkeepingAdjustment::STATUS_APPLIED,
            'confidence' => $confidence,
            'rationale' => $rationale,
            'context' => [
                'description' => $transaction->description,
                'merchant_name' => $transaction->merchant_name,
                'amount' => round((float) $transaction->amount, 2),
            ],
            'applied_at' => now(),
            'resolved_at' => now(),
        ]);
    }

    protected function recordSuggestion(
        PersonalTransaction $transaction,
        TransactionCategory $category,
        string $source,
        float $confidence,
        string $rationale,
    ): void {
        $this->clearOpenSuggestions($transaction);

        BookkeepingAdjustment::create([
            'user_id' => $transaction->account?->user_id,
            'personal_transaction_id' => $transaction->id,
            'current_category_id' => $transaction->category_id,
            'suggested_category_id' => $category->id,
            'tax_year' => (int) $transaction->transaction_date->year,
            'period_month' => (int) $transaction->transaction_date->month,
            'adjustment_type' => BookkeepingAdjustment::TYPE_CATEGORY_RECLASS,
            'source' => $source,
            'status' => BookkeepingAdjustment::STATUS_SUGGESTED,
            'confidence' => $confidence,
            'rationale' => $rationale,
            'context' => [
                'description' => $transaction->description,
                'merchant_name' => $transaction->merchant_name,
                'amount' => round((float) $transaction->amount, 2),
            ],
        ]);
    }

    protected function clearOpenSuggestions(PersonalTransaction $transaction): void
    {
        BookkeepingAdjustment::query()
            ->where('personal_transaction_id', $transaction->id)
            ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
            ->update([
                'status' => BookkeepingAdjustment::STATUS_DISMISSED,
                'dismissed_at' => now(),
                'resolved_at' => now(),
            ]);
    }
}
