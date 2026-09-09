<?php

namespace App\Services\PersonalFinance;

use App\Models\CollectionsAccount;
use App\Models\Debt;
use App\Models\FinancialAlert;
use App\Models\Invoice;
use App\Models\TaxCalendarEvent;
use Illuminate\Support\Collection;

class UrgencyEngine
{
    public function __construct(
        protected CashFlowService $cashFlowService,
        protected RevenueOptimizationService $revenueService,
    ) {}

    /**
     * Scan all financial data and generate/update alerts.
     *
     * @return Collection<int, FinancialAlert>
     */
    public function scan(int $userId): Collection
    {
        $alerts = collect();

        $alerts = $alerts->merge($this->detectOverdueInvoices($userId));
        $alerts = $alerts->merge($this->detectUpcomingDebtPayments($userId));
        $alerts = $alerts->merge($this->detectTaxDeadlines($userId));
        $alerts = $alerts->merge($this->detectShortfalls($userId));
        $alerts = $alerts->merge($this->detectCollectionsDeadlines($userId));
        $alerts = $alerts->merge($this->detectRevenueGap($userId));

        $this->expireStaleAlerts($userId);

        return $alerts;
    }

    /**
     * Detect overdue invoices and create alerts.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectOverdueInvoices(int $userId): Collection
    {
        $overdueInvoices = Invoice::where('status', 'sent')
            ->where('due_date', '<', now())
            ->with('client')
            ->get();

        $highestRate = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->max('interest_rate') ?? 0;

        $alerts = collect();

        foreach ($overdueInvoices as $invoice) {
            $daysOverdue = (int) $invoice->due_date->diffInDays(now());
            $amount = (float) $invoice->amount_due;
            $costOfInaction = $amount * ((float) $highestRate / 100 / 365) * $daysOverdue;

            $alert = FinancialAlert::updateOrCreate(
                [
                    'user_id' => $userId,
                    'alert_type' => 'invoice_overdue',
                    'related_model_type' => Invoice::class,
                    'related_model_id' => $invoice->id,
                ],
                [
                    'severity' => $daysOverdue > 30 ? 'critical' : ($daysOverdue > 14 ? 'high' : 'medium'),
                    'title' => "Invoice #{$invoice->number} overdue by {$daysOverdue} days",
                    'description' => "Invoice #{$invoice->number} for {$invoice->client?->name} ($".number_format($amount, 2).") is {$daysOverdue} days past due.",
                    'action_text' => 'Send a payment reminder or call the client directly. Consider offering a 2% discount for immediate payment.',
                    'deadline' => $invoice->due_date,
                    'dollar_impact' => $amount,
                    'dollar_cost_of_inaction' => round($costOfInaction, 2),
                    'status' => 'open',
                    'metadata' => [
                        'days_overdue' => $daysOverdue,
                        'client_name' => $invoice->client?->name,
                        'invoice_number' => $invoice->number,
                    ],
                ]
            );

            $alerts->push($alert);
        }

        return $alerts;
    }

    /**
     * Detect upcoming debt payments due within 3-7 days.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectUpcomingDebtPayments(int $userId): Collection
    {
        $debts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        $alerts = collect();
        $today = now();

        foreach ($debts as $debt) {
            $dueDay = $debt->payment_due_day;
            $daysInMonth = $today->daysInMonth;
            $effectiveDueDay = min($dueDay, $daysInMonth);

            $nextDue = $today->copy()->day($effectiveDueDay);
            if ($nextDue->isPast()) {
                $nextDue = $nextDue->addMonth();
                $nextDue->day(min($dueDay, $nextDue->daysInMonth));
            }

            $daysUntilDue = (int) $today->diffInDays($nextDue, false);

            if ($daysUntilDue >= 0 && $daysUntilDue <= 7) {
                $alert = FinancialAlert::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'alert_type' => 'debt_payment_due',
                        'related_model_type' => Debt::class,
                        'related_model_id' => $debt->id,
                    ],
                    [
                        'severity' => $daysUntilDue <= 3 ? 'high' : 'medium',
                        'title' => "{$debt->name} payment due in {$daysUntilDue} days",
                        'description' => 'Payment of $'.number_format((float) $debt->minimum_payment, 2)." for {$debt->name} is due on {$nextDue->format('M d')}.",
                        'action_text' => 'Ensure sufficient funds in your account for the upcoming payment.',
                        'deadline' => $nextDue,
                        'dollar_impact' => $debt->minimum_payment,
                        'dollar_cost_of_inaction' => round((float) $debt->current_balance * ((float) $debt->interest_rate / 100 / 12), 2),
                        'status' => 'open',
                        'metadata' => [
                            'debt_name' => $debt->name,
                            'debt_type' => $debt->debt_type,
                            'days_until_due' => $daysUntilDue,
                        ],
                    ]
                );

                $alerts->push($alert);
            }
        }

        return $alerts;
    }

    /**
     * Detect upcoming tax deadlines within 14 days.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectTaxDeadlines(int $userId): Collection
    {
        $events = TaxCalendarEvent::where('user_id', $userId)
            ->where('due_date', '<=', now()->addDays(14))
            ->where('due_date', '>=', now())
            ->whereNotIn('status', ['completed'])
            ->get();

        $alerts = collect();

        foreach ($events as $event) {
            $daysUntil = (int) now()->diffInDays($event->due_date, false);
            $estimatedAmount = (float) ($event->estimated_amount ?? 0);

            $alert = FinancialAlert::updateOrCreate(
                [
                    'user_id' => $userId,
                    'alert_type' => 'tax_deadline',
                    'related_model_type' => TaxCalendarEvent::class,
                    'related_model_id' => $event->id,
                ],
                [
                    'severity' => $daysUntil <= 3 ? 'critical' : ($daysUntil <= 7 ? 'high' : 'medium'),
                    'title' => ($event->title ?? "{$event->event_type} ({$event->form_type})")." due in {$daysUntil} days",
                    'description' => "Tax deadline for {$event->form_type} on {$event->due_date->format('M d, Y')}."
                        .($estimatedAmount > 0 ? ' Estimated amount: $'.number_format($estimatedAmount, 2).'.' : ''),
                    'action_text' => 'Ensure payment is submitted before the deadline. Late payment accrues penalties and interest.',
                    'deadline' => $event->due_date,
                    'dollar_impact' => $estimatedAmount > 0 ? $estimatedAmount : null,
                    'dollar_cost_of_inaction' => $estimatedAmount > 0 ? round($estimatedAmount * 0.005, 2) : null,
                    'status' => 'open',
                    'metadata' => [
                        'event_type' => $event->event_type,
                        'form_type' => $event->form_type,
                        'tax_year' => $event->tax_year,
                        'days_until_due' => $daysUntil,
                    ],
                ]
            );

            $alerts->push($alert);
        }

        return $alerts;
    }

    /**
     * Detect projected cash shortfalls from the forecast.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectShortfalls(int $userId): Collection
    {
        $alerts = collect();

        try {
            $forecast = $this->cashFlowService->generateForecast($userId, 60);
        } catch (\Exception) {
            return $alerts;
        }

        $shortfalls = $forecast['summary']['shortfalls'] ?? [];

        if (empty($shortfalls)) {
            // Resolve any existing shortfall alerts
            FinancialAlert::where('user_id', $userId)
                ->where('alert_type', 'shortfall_warning')
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            return $alerts;
        }

        $firstShortfall = $shortfalls[0];

        $alert = FinancialAlert::updateOrCreate(
            [
                'user_id' => $userId,
                'alert_type' => 'shortfall_warning',
                'related_model_type' => null,
                'related_model_id' => null,
            ],
            [
                'severity' => 'critical',
                'title' => 'Cash shortfall projected on '.$firstShortfall['date'],
                'description' => 'Balance projected to reach -$'.number_format($firstShortfall['shortfall_amount'], 2)." by {$firstShortfall['date']}.",
                'action_text' => 'Accelerate invoice collection, defer non-critical payments, or arrange a line of credit.',
                'deadline' => $firstShortfall['date'],
                'dollar_impact' => $firstShortfall['shortfall_amount'],
                'dollar_cost_of_inaction' => $firstShortfall['shortfall_amount'],
                'status' => 'open',
                'metadata' => [
                    'shortfall_count' => count($shortfalls),
                    'first_shortfall_date' => $firstShortfall['date'],
                    'projected_balance' => $firstShortfall['projected_balance'],
                ],
            ]
        );

        $alerts->push($alert);

        return $alerts;
    }

    /**
     * Detect collections accounts with approaching dispute deadlines.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectCollectionsDeadlines(int $userId): Collection
    {
        $collectionsAccounts = CollectionsAccount::whereHas('debt', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('dispute_deadline')
            ->where('dispute_deadline', '>=', now())
            ->where('dispute_deadline', '<=', now()->addDays(14))
            ->with('debt')
            ->get();

        $alerts = collect();

        foreach ($collectionsAccounts as $account) {
            $daysUntil = (int) now()->diffInDays($account->dispute_deadline, false);

            $alert = FinancialAlert::updateOrCreate(
                [
                    'user_id' => $userId,
                    'alert_type' => 'collections_deadline',
                    'related_model_type' => CollectionsAccount::class,
                    'related_model_id' => $account->id,
                ],
                [
                    'severity' => $daysUntil <= 5 ? 'critical' : 'high',
                    'title' => "Dispute deadline for {$account->debt?->name} in {$daysUntil} days",
                    'description' => "Collections dispute deadline for {$account->collection_agency} ({$account->debt?->name}) is {$account->dispute_deadline->format('M d, Y')}. Missing this deadline waives your right to dispute.",
                    'action_text' => 'File a dispute letter via certified mail before the deadline.',
                    'deadline' => $account->dispute_deadline,
                    'dollar_impact' => $account->debt?->current_balance,
                    'dollar_cost_of_inaction' => $account->debt?->current_balance,
                    'status' => 'open',
                    'metadata' => [
                        'collection_agency' => $account->collection_agency,
                        'debt_name' => $account->debt?->name,
                        'days_until_deadline' => $daysUntil,
                    ],
                ]
            );

            $alerts->push($alert);
        }

        return $alerts;
    }

    /**
     * Detect if the revenue gap exceeds 30% of obligations.
     *
     * @return Collection<int, FinancialAlert>
     */
    protected function detectRevenueGap(int $userId): Collection
    {
        $alerts = collect();

        $gap = $this->revenueService->calculateRevenueGap($userId);

        if (! $gap['is_deficit']) {
            FinancialAlert::where('user_id', $userId)
                ->where('alert_type', 'revenue_gap_critical')
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            return $alerts;
        }

        $gapPercent = $gap['monthly_obligations'] > 0
            ? ($gap['gap'] / $gap['monthly_obligations']) * 100
            : 0;

        if ($gapPercent < 30) {
            return $alerts;
        }

        $alert = FinancialAlert::updateOrCreate(
            [
                'user_id' => $userId,
                'alert_type' => 'revenue_gap_critical',
                'related_model_type' => null,
                'related_model_id' => null,
            ],
            [
                'severity' => $gapPercent >= 50 ? 'critical' : 'high',
                'title' => 'Revenue gap: $'.number_format($gap['gap'], 2).'/mo shortfall ('.round($gapPercent).'%)',
                'description' => 'Monthly income ($'.number_format($gap['monthly_income'], 2).') is $'.number_format($gap['gap'], 2).' below monthly obligations ($'.number_format($gap['monthly_obligations'], 2).').'
                    .($gap['hours_needed'] ? " Need ~{$gap['hours_needed']} more billable hours at \$".number_format($gap['average_rate'], 2).'/hr.' : ''),
                'action_text' => 'Increase billable hours, raise rates, or reduce discretionary spending. Focus on your most profitable clients.',
                'dollar_impact' => $gap['gap'],
                'dollar_cost_of_inaction' => round($gap['gap'] * 3, 2),
                'status' => 'open',
                'metadata' => [
                    'gap_percent' => round($gapPercent, 1),
                    'hours_needed' => $gap['hours_needed'],
                    'breakdown' => $gap['breakdown'],
                ],
            ]
        );

        $alerts->push($alert);

        return $alerts;
    }

    /**
     * Expire alerts that have passed their deadline without resolution.
     */
    protected function expireStaleAlerts(int $userId): void
    {
        FinancialAlert::where('user_id', $userId)
            ->where('status', 'open')
            ->whereNotNull('deadline')
            ->where('deadline', '<', now()->subDays(7))
            ->update(['status' => 'expired']);
    }
}
