<?php

namespace App\Services\Tax;

use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\PersonalFinance\BookkeepingAccountScopeService;
use App\Services\PersonalFinance\TransactionDirectionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnerPaymentReviewService
{
    public const CLASSIFICATION_DISTRIBUTION = 'distribution';

    public const CLASSIFICATION_OWNER_CONTRIBUTION = 'owner_contribution';

    public const CLASSIFICATION_OWNER_REIMBURSEMENT = 'owner_reimbursement';

    public const CLASSIFICATION_OWNER_LOAN = 'owner_loan';

    public const CLASSIFICATION_POTENTIAL_COMPENSATION = 'potential_compensation';

    public const CLASSIFICATION_NOT_OWNER_PAYMENT = 'not_owner_payment';

    public function __construct(
        protected BookkeepingAccountScopeService $bookkeepingAccountScopeService,
        protected TransactionDirectionService $transactionDirectionService,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function classificationValues(): array
    {
        return [
            self::CLASSIFICATION_DISTRIBUTION,
            self::CLASSIFICATION_OWNER_CONTRIBUTION,
            self::CLASSIFICATION_OWNER_REIMBURSEMENT,
            self::CLASSIFICATION_OWNER_LOAN,
            self::CLASSIFICATION_POTENTIAL_COMPENSATION,
            self::CLASSIFICATION_NOT_OWNER_PAYMENT,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, group: string}>
     */
    public static function classificationOptions(): array
    {
        return [
            [
                'value' => self::CLASSIFICATION_DISTRIBUTION,
                'label' => 'Distribution',
                'group' => 'Owner equity',
            ],
            [
                'value' => self::CLASSIFICATION_OWNER_CONTRIBUTION,
                'label' => 'Owner contribution',
                'group' => 'Owner equity',
            ],
            [
                'value' => self::CLASSIFICATION_OWNER_REIMBURSEMENT,
                'label' => 'Owner reimbursement',
                'group' => 'Business support',
            ],
            [
                'value' => self::CLASSIFICATION_OWNER_LOAN,
                'label' => 'Loan to/from owner',
                'group' => 'Business support',
            ],
            [
                'value' => self::CLASSIFICATION_POTENTIAL_COMPENSATION,
                'label' => 'Potential compensation',
                'group' => 'Payroll review',
            ],
            [
                'value' => self::CLASSIFICATION_NOT_OWNER_PAYMENT,
                'label' => 'Not owner-related',
                'group' => 'Exclude',
            ],
        ];
    }

    public static function classificationLabel(?string $classification): ?string
    {
        return collect(self::classificationOptions())
            ->firstWhere('value', $classification)['label'] ?? null;
    }

    /**
     * @return array{
     *     is_applicable: bool,
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     next_action: string,
     *     review_complete: bool,
     *     classification_options: array<int, array{value: string, label: string, group: string}>,
     *     metrics: array<string, int|float>,
     *     items: array<int, array<string, mixed>>,
     *     remaining_item_count: int,
     * }
     */
    public function summarize(int $userId, int $year): array
    {
        $user = User::query()->find($userId);
        $activeAccounts = PersonalAccount::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
        $accountScope = $this->bookkeepingAccountScopeService->resolve($activeAccounts);
        $accountIds = $accountScope['accounts']->modelKeys();

        if ($accountIds === []) {
            return $this->emptySummary();
        }

        $candidateTransactions = PersonalTransaction::query()
            ->with([
                'account:id,user_id,name,institution_name,account_type',
                'category:id,name,type',
            ])
            ->whereIn('personal_account_id', $accountIds)
            ->whereYear('transaction_date', $year)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PersonalTransaction $transaction): bool => $this->isOwnerPaymentCandidate($transaction, $user))
            ->values();

        if ($candidateTransactions->isEmpty()) {
            return $this->emptySummary();
        }

        $items = $candidateTransactions
            ->map(fn (PersonalTransaction $transaction): array => $this->reviewItem($transaction, $user))
            ->values();
        $unresolvedItems = $items->where('classification', null)->values();

        return [
            'is_applicable' => true,
            'status' => $unresolvedItems->isNotEmpty() ? 'needs_review' : 'clear',
            'status_label' => $unresolvedItems->isNotEmpty() ? 'Review owner payments' : 'Owner payments reviewed',
            'summary' => $this->summary($items, $unresolvedItems),
            'next_action' => $this->nextAction($items, $unresolvedItems),
            'review_complete' => $unresolvedItems->isEmpty(),
            'classification_options' => self::classificationOptions(),
            'metrics' => $this->metrics($items),
            'items' => $unresolvedItems->take(24)->values()->all(),
            'remaining_item_count' => max($unresolvedItems->count() - 24, 0),
        ];
    }

    public function review(
        PersonalTransaction $transaction,
        string $classification,
        bool $learnMatch = true,
    ): PersonalTransaction {
        if (! in_array($classification, self::classificationValues(), true)) {
            throw ValidationException::withMessages([
                'classification' => 'Choose a valid owner-payment classification.',
            ]);
        }

        $transaction->update([
            'owner_payment_classification' => $classification,
            'owner_payment_reviewed_at' => now(),
        ]);

        if ($learnMatch) {
            $this->learnApprovedClassification($transaction, $classification);
        }

        return $transaction->refresh();
    }

    /**
     * @return array{
     *     is_applicable: bool,
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     next_action: string,
     *     review_complete: bool,
     *     classification_options: array<int, array{value: string, label: string, group: string}>,
     *     metrics: array<string, int|float>,
     *     items: array<int, array<string, mixed>>,
     *     remaining_item_count: int,
     * }
     */
    protected function emptySummary(): array
    {
        return [
            'is_applicable' => false,
            'status' => 'not_applicable',
            'status_label' => 'No owner-payment review needed',
            'summary' => 'No business-to-owner payment review items were found in the current ledger scope.',
            'next_action' => 'No owner-payment review is needed right now.',
            'review_complete' => true,
            'classification_options' => self::classificationOptions(),
            'metrics' => [
                'candidate_count' => 0,
                'candidate_total' => 0.0,
                'unresolved_count' => 0,
                'unresolved_total' => 0.0,
                'reviewed_count' => 0,
                'reviewed_total' => 0.0,
                'distribution_count' => 0,
                'distribution_total' => 0.0,
                'owner_contribution_count' => 0,
                'owner_contribution_total' => 0.0,
                'owner_reimbursement_count' => 0,
                'owner_reimbursement_total' => 0.0,
                'owner_loan_count' => 0,
                'owner_loan_total' => 0.0,
                'potential_compensation_count' => 0,
                'potential_compensation_total' => 0.0,
                'not_owner_payment_count' => 0,
                'not_owner_payment_total' => 0.0,
            ],
            'items' => [],
            'remaining_item_count' => 0,
        ];
    }

    protected function isOwnerPaymentCandidate(PersonalTransaction $transaction, ?User $user): bool
    {
        $categoryType = Str::lower((string) ($transaction->category?->type ?? ''));
        $categoryName = Str::lower((string) ($transaction->category?->name ?? ''));
        $text = $this->transactionText($transaction);

        if ($transaction->owner_payment_classification === self::CLASSIFICATION_NOT_OWNER_PAYMENT) {
            return true;
        }

        if ($categoryType === 'transfer' && $this->containsAny($categoryName, [
            'business <> personal',
            'business personal',
            'owner',
            'shareholder',
            'distribution',
            'contribution',
            'draw',
            'loan',
            'reimburse',
        ])) {
            return true;
        }

        if ($this->containsAny($text, [
            'owner draw',
            'owner contribution',
            'shareholder distribution',
            'shareholder contribution',
            'member draw',
            'member contribution',
            'capital contribution',
            'shareholder loan',
            'owner loan',
            'loan from owner',
            'loan to owner',
            'member loan',
            'officer loan',
            'due to owner',
            'due from owner',
            'reimbursement',
            'accountable plan',
        ])) {
            return true;
        }

        return $this->containsOwnerIdentity($text, $user) && $categoryType === 'transfer';
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviewItem(PersonalTransaction $transaction, ?User $user): array
    {
        $suggestion = $this->suggestClassification($transaction, $user);
        $classification = $transaction->owner_payment_classification;

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->format('M d, Y') ?? 'Unknown date',
            'date_iso' => $transaction->transaction_date?->toDateString(),
            'description' => $transaction->description,
            'merchant_name' => $transaction->merchant_name,
            'account_label' => $transaction->account?->name ?? 'Unknown account',
            'amount' => round(abs((float) $transaction->amount), 2),
            'direction' => $this->transactionDirectionService->isInflow($transaction) ? 'inflow' : 'outflow',
            'category_name' => $transaction->category?->name,
            'classification' => $classification,
            'classification_label' => self::classificationLabel($classification),
            'suggested_classification' => $suggestion['classification'],
            'suggested_classification_label' => self::classificationLabel($suggestion['classification']),
            'confidence' => $suggestion['confidence'],
            'rationale' => $suggestion['rationale'],
            'reviewed_at' => $transaction->owner_payment_reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{classification: string, confidence: float, rationale: string}
     */
    protected function suggestClassification(PersonalTransaction $transaction, ?User $user): array
    {
        $text = $this->transactionText($transaction);
        $isInflow = $this->transactionDirectionService->isInflow($transaction);
        $containsOwnerIdentity = $this->containsOwnerIdentity($text, $user);

        if ($this->containsAny($text, [
            'salary',
            'payroll',
            'wages',
            'officer compensation',
            'bonus',
            'employee pay',
        ])) {
            return [
                'classification' => self::CLASSIFICATION_POTENTIAL_COMPENSATION,
                'confidence' => 0.95,
                'rationale' => 'The transaction description looks like payroll or compensation rather than a pure owner transfer.',
            ];
        }

        if ($this->containsAny($text, [
            'shareholder loan',
            'owner loan',
            'loan from owner',
            'loan to owner',
            'member loan',
            'officer loan',
            'loan receivable',
            'loan payable',
            'due to owner',
            'due from owner',
        ])) {
            return [
                'classification' => self::CLASSIFICATION_OWNER_LOAN,
                'confidence' => 0.94,
                'rationale' => 'The description reads like a loan between the business and the owner.',
            ];
        }

        if ($this->containsAny($text, [
            'reimbursement',
            'reimburse',
            'expense report',
            'accountable plan',
        ])) {
            return [
                'classification' => self::CLASSIFICATION_OWNER_REIMBURSEMENT,
                'confidence' => 0.93,
                'rationale' => 'The description looks like a reimbursement rather than equity movement or compensation.',
            ];
        }

        if ($isInflow) {
            if ($containsOwnerIdentity || $this->containsAny($text, [
                'owner contribution',
                'shareholder contribution',
                'member contribution',
                'capital contribution',
                'contribution',
            ])) {
                return [
                    'classification' => self::CLASSIFICATION_OWNER_CONTRIBUTION,
                    'confidence' => 0.9,
                    'rationale' => 'Money moving from the owner into the business is usually an owner contribution unless it is explicitly a loan.',
                ];
            }

            return [
                'classification' => self::CLASSIFICATION_OWNER_CONTRIBUTION,
                'confidence' => 0.72,
                'rationale' => 'This is an owner-related inflow, so the conservative starting point is an owner contribution.',
            ];
        }

        if ($containsOwnerIdentity || $this->containsAny($text, [
            'owner draw',
            'shareholder distribution',
            'member draw',
            'distribution',
            'draw',
        ])) {
            return [
                'classification' => self::CLASSIFICATION_DISTRIBUTION,
                'confidence' => 0.9,
                'rationale' => 'Money moving from the business to the owner is usually a distribution unless it was actually payroll, reimbursement, or a loan.',
            ];
        }

        return [
            'classification' => self::CLASSIFICATION_DISTRIBUTION,
            'confidence' => 0.7,
            'rationale' => 'This owner-related outflow looks more like an equity distribution than ordinary business expense activity.',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    protected function summary(Collection $items, Collection $unresolvedItems): string
    {
        $candidateTotal = round((float) $items->sum('amount'), 2);

        if ($items->isEmpty()) {
            return 'No owner-related cash movement needs review in the current filing year.';
        }

        if ($unresolvedItems->isNotEmpty()) {
            return sprintf(
                '%d owner-related transfer item(s) totaling %s still need an explicit label so distributions, reimbursements, loans, and compensation candidates stop being guesswork.',
                $unresolvedItems->count(),
                number_format((float) $unresolvedItems->sum('amount'), 2)
            );
        }

        return sprintf(
            'Owner-related cash movement totaling %s has been explicitly reviewed, so the filing packet can distinguish distributions from reimbursements, loans, and compensation candidates.',
            number_format($candidateTotal, 2)
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    protected function nextAction(Collection $items, Collection $unresolvedItems): string
    {
        if ($items->isEmpty()) {
            return 'No owner-payment review is needed right now.';
        }

        if ($unresolvedItems->isNotEmpty()) {
            return 'Label the remaining owner-related transfers before relying on the S-corp distribution and compensation posture.';
        }

        $potentialCompensationCount = $items
            ->where('classification', self::CLASSIFICATION_POTENTIAL_COMPENSATION)
            ->count();

        if ($potentialCompensationCount > 0) {
            return 'Review the compensation candidates in payroll posture before filing final forms.';
        }

        return 'Owner-related transfers are explicitly labeled and no longer block the filing posture.';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, int|float>
     */
    protected function metrics(Collection $items): array
    {
        $classifications = [
            self::CLASSIFICATION_DISTRIBUTION,
            self::CLASSIFICATION_OWNER_CONTRIBUTION,
            self::CLASSIFICATION_OWNER_REIMBURSEMENT,
            self::CLASSIFICATION_OWNER_LOAN,
            self::CLASSIFICATION_POTENTIAL_COMPENSATION,
            self::CLASSIFICATION_NOT_OWNER_PAYMENT,
        ];

        $metrics = [
            'candidate_count' => $items->count(),
            'candidate_total' => round((float) $items->sum('amount'), 2),
            'unresolved_count' => $items->where('classification', null)->count(),
            'unresolved_total' => round((float) $items->where('classification', null)->sum('amount'), 2),
            'reviewed_count' => $items
                ->filter(fn (array $item): bool => filled($item['classification']) && $item['classification'] !== self::CLASSIFICATION_NOT_OWNER_PAYMENT)
                ->count(),
            'reviewed_total' => round((float) $items
                ->filter(fn (array $item): bool => filled($item['classification']) && $item['classification'] !== self::CLASSIFICATION_NOT_OWNER_PAYMENT)
                ->sum('amount'), 2),
        ];

        foreach ($classifications as $classification) {
            $prefix = $classification;
            $metrics["{$prefix}_count"] = $items->where('classification', $classification)->count();
            $metrics["{$prefix}_total"] = round((float) $items->where('classification', $classification)->sum('amount'), 2);
        }

        return $metrics;
    }

    protected function learnApprovedClassification(PersonalTransaction $transaction, string $classification): void
    {
        $matchValues = collect([
            'merchant_name' => $transaction->merchant_name,
            'description' => $transaction->description,
        ])
            ->filter(fn (?string $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->unique()
            ->all();

        if ($matchValues === []) {
            return;
        }
        $userId = $transaction->account?->user_id;
        $year = (int) $transaction->transaction_date?->year;

        if (! is_int($userId) || $year <= 0) {
            return;
        }

        PersonalTransaction::query()
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->whereYear('transaction_date', $year)
            ->where(function ($query) use ($matchValues): void {
                foreach ($matchValues as $matchValue) {
                    $query->orWhereRaw("lower(trim(coalesce(merchant_name, ''))) = ?", [$matchValue])
                        ->orWhereRaw("lower(trim(coalesce(description, ''))) = ?", [$matchValue]);
                }
            })
            ->where('id', '!=', $transaction->id)
            ->whereNull('owner_payment_classification')
            ->update([
                'owner_payment_classification' => $classification,
                'owner_payment_reviewed_at' => now(),
            ]);
    }

    protected function transactionText(PersonalTransaction $transaction): string
    {
        return Str::lower(collect([
            $transaction->description,
            $transaction->original_description,
            $transaction->merchant_name,
            $transaction->category?->name,
        ])->filter()->implode(' '));
    }

    protected function containsOwnerIdentity(string $text, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $tokens = collect([
            ...preg_split('/\s+/', Str::lower((string) $user->name)) ?: [],
            ...preg_split('/[^a-z0-9]+/', Str::lower((string) Str::before((string) $user->email, '@'))) ?: [],
        ])
            ->filter(fn (?string $token): bool => is_string($token) && strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();

        return $this->containsAny($text, $tokens);
    }

    /**
     * @param  array<int, string>  $needles
     */
    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }
}
