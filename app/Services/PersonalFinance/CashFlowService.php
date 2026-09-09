<?php

namespace App\Services\PersonalFinance;

use App\Models\CashFlowForecast;
use App\Models\Client;
use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\Invoice;
use App\Models\PersonalAccount;
use App\Models\TaxCalendarEvent;
use App\Models\TaxObligation;
use App\Models\WiseTransfer;
use Carbon\Carbon;

class CashFlowService
{
    /**
     * Generate a unified cash flow forecast combining business + personal.
     * Returns daily projections for the specified number of days.
     *
     * @return array{
     *     start_date: string,
     *     days: int,
     *     current_cash: array{personal: float, business: float, total: float},
     *     projections: array,
     *     summary: array,
     *     recommendations: array,
     * }
     */
    public function generateForecast(int $userId, int $days = 90): array
    {
        $forecast = [];
        $today = now()->startOfDay();

        // Get current cash position
        $personalAccounts = PersonalAccount::where('user_id', $userId)
            ->where('is_closed', false)
            ->get();
        $personalCash = $personalAccounts
            ->whereIn('account_type', ['checking', 'savings'])
            ->sum('current_balance');

        // Business cash from QBO/Wise if available
        $businessCash = $this->getBusinessCashPosition();

        $runningBalance = $personalCash + $businessCash;
        $lowestBalance = $runningBalance;
        $lowestBalanceDate = $today;
        $shortfalls = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $dayKey = $date->format('Y-m-d');

            $inflows = $this->getProjectedInflows($date, $userId);
            $outflows = $this->getProjectedOutflows($date, $userId);

            $netFlow = $inflows['total'] - $outflows['total'];
            $runningBalance += $netFlow;

            if ($runningBalance < $lowestBalance) {
                $lowestBalance = $runningBalance;
                $lowestBalanceDate = $date;
            }

            $isShortfall = $runningBalance < 0;
            if ($isShortfall) {
                $shortfalls[] = [
                    'date' => $dayKey,
                    'projected_balance' => round($runningBalance, 2),
                    'shortfall_amount' => round(abs($runningBalance), 2),
                    'description' => 'Balance projected to reach $'.number_format(abs($runningBalance), 2).' negative',
                ];
            }

            $forecast[] = [
                'date' => $dayKey,
                'day_label' => $date->format('M d'),
                'is_today' => $i === 0,
                'inflows' => $inflows,
                'outflows' => $outflows,
                'net_flow' => round($netFlow, 2),
                'running_balance' => round($runningBalance, 2),
                'is_shortfall' => $isShortfall,
            ];
        }

        return [
            'start_date' => $today->format('Y-m-d'),
            'days' => $days,
            'current_cash' => [
                'personal' => round($personalCash, 2),
                'business' => round($businessCash, 2),
                'total' => round($personalCash + $businessCash, 2),
            ],
            'projections' => $forecast,
            'summary' => [
                'total_projected_inflows' => round(collect($forecast)->sum(fn ($d) => $d['inflows']['total']), 2),
                'total_projected_outflows' => round(collect($forecast)->sum(fn ($d) => $d['outflows']['total']), 2),
                'ending_balance' => round($runningBalance, 2),
                'lowest_balance' => round($lowestBalance, 2),
                'lowest_balance_date' => $lowestBalanceDate->format('M d, Y'),
                'shortfall_count' => count($shortfalls),
                'shortfalls' => array_slice($shortfalls, 0, 10),
            ],
            'recommendations' => $this->generateRecommendations($forecast, $shortfalls, $userId),
        ];
    }

    /**
     * Get projected inflows for a specific date.
     *
     * @return array{items: array, total: float}
     */
    protected function getProjectedInflows(Carbon $date, int $userId): array
    {
        $items = [];
        $total = 0;

        // Business: outstanding invoices expected to be paid on their due date only
        $invoices = Invoice::where('status', 'sent')
            ->whereDate('due_date', $date->format('Y-m-d'))
            ->get();

        foreach ($invoices as $inv) {
            $amount = (float) $inv->amount_due;
            $items[] = [
                'source' => 'invoice',
                'description' => "Invoice #{$inv->number} - {$inv->client?->name}",
                'amount' => $amount,
                'confidence' => 'likely',
            ];
            $total += $amount;
        }

        // Business: recurring invoice income on each client's configured billing day
        $recurringClients = Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->where('status', 'active')
            ->get();

        foreach ($recurringClients as $client) {
            $billingDay = min($client->recurring_invoice_day ?? 1, $date->daysInMonth);
            if ($date->day === $billingDay) {
                $amount = (float) $client->recurring_invoice_amount;
                $items[] = [
                    'source' => 'retainer',
                    'description' => "Recurring Invoice - {$client->name}",
                    'amount' => $amount,
                    'confidence' => 'confirmed',
                ];
                $total += $amount;
            }
        }

        // Personal: recurring income (detected from transactions)
        $recurringIncome = CashFlowForecast::where('user_id', $userId)
            ->where('type', 'income')
            ->where('forecast_date', $date->format('Y-m-d'))
            ->get();

        foreach ($recurringIncome as $f) {
            $items[] = [
                'source' => 'recurring',
                'description' => $f->description,
                'amount' => (float) $f->projected_amount,
                'confidence' => $f->confidence,
            ];
            $total += (float) $f->projected_amount;
        }

        return ['items' => $items, 'total' => round($total, 2)];
    }

    /**
     * Get projected outflows for a specific date.
     *
     * @return array{items: array, total: float}
     */
    protected function getProjectedOutflows(Carbon $date, int $userId): array
    {
        $items = [];
        $total = 0;

        // Debt payments (based on due day, with month-end rollover)
        $debts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        $daysInMonth = $date->daysInMonth;

        foreach ($debts as $debt) {
            // Roll over due day to last day of month if the month is shorter
            // e.g., due day 31 in February becomes 28/29
            $effectiveDueDay = min($debt->payment_due_day, $daysInMonth);

            if ($date->day === $effectiveDueDay) {
                $amount = (float) $debt->minimum_payment;
                $items[] = [
                    'source' => 'debt_payment',
                    'description' => "Payment: {$debt->name}",
                    'amount' => $amount,
                    'confidence' => 'confirmed',
                ];
                $total += $amount;
            }
        }

        // Tax deadlines (from TaxCalendarEvent)
        $taxEvents = TaxCalendarEvent::where('due_date', $date->format('Y-m-d'))->get();

        foreach ($taxEvents as $event) {
            $taxObligation = TaxObligation::whereHas('debt', fn ($q) => $q->where('user_id', $userId))
                ->where('tax_type', 'federal_income')
                ->first();
            $amount = $taxObligation?->installment_monthly ?? 0;
            if ($amount > 0) {
                $items[] = [
                    'source' => 'tax',
                    'description' => 'Tax: '.($event->title ?? "{$event->event_type} — {$event->form_type}"),
                    'amount' => (float) $amount,
                    'confidence' => 'confirmed',
                ];
                $total += (float) $amount;
            }
        }

        // Known recurring expenses from CashFlowForecast
        $recurringExpenses = CashFlowForecast::where('user_id', $userId)
            ->where('type', 'expense')
            ->where('forecast_date', $date->format('Y-m-d'))
            ->get();

        foreach ($recurringExpenses as $f) {
            $items[] = [
                'source' => 'recurring',
                'description' => $f->description,
                'amount' => (float) $f->projected_amount,
                'confidence' => $f->confidence,
            ];
            $total += (float) $f->projected_amount;
        }

        // Business: contractor payments (approximation based on historical)
        if (in_array($date->day, [1, 15])) {
            $contractorCosts = $this->getAverageContractorCosts();
            if ($contractorCosts > 0) {
                $items[] = [
                    'source' => 'contractor',
                    'description' => 'Contractor payments (est.)',
                    'amount' => $contractorCosts / 2,
                    'confidence' => 'estimated',
                ];
                $total += $contractorCosts / 2;
            }
        }

        return ['items' => $items, 'total' => round($total, 2)];
    }

    /**
     * Generate strategic recommendations based on the forecast.
     *
     * @return array<int, array{type: string, title: string, description: string, action: string}>
     */
    protected function generateRecommendations(array $forecast, array $shortfalls, int $userId): array
    {
        $recommendations = [];

        // Shortfall warnings
        if (! empty($shortfalls)) {
            $first = $shortfalls[0];
            $recommendations[] = [
                'type' => 'critical',
                'title' => 'Cash Shortfall Projected',
                'description' => "Balance projected to go negative by {$first['date']}. Shortfall of \${$first['shortfall_amount']}.",
                'action' => 'Accelerate invoice collection or defer non-critical payments.',
            ];
        }

        // Outstanding invoices that could be collected faster
        $overdueInvoices = Invoice::where('status', 'sent')
            ->where('due_date', '<', now())
            ->get();

        if ($overdueInvoices->count() > 0) {
            $overdueTotal = $overdueInvoices->sum('amount_due');
            $recommendations[] = [
                'type' => 'high',
                'title' => 'Collect $'.number_format($overdueTotal, 2).' in Overdue Invoices',
                'description' => "{$overdueInvoices->count()} invoice(s) are past due.",
                'action' => 'Send payment reminders today. Consider offering 2% discount for immediate payment.',
            ];
        }

        // Tax deadline approaching
        $upcomingTax = TaxCalendarEvent::where('due_date', '>', now())
            ->where('due_date', '<=', now()->addDays(30))
            ->first();

        if ($upcomingTax) {
            $recommendations[] = [
                'type' => 'high',
                'title' => 'Tax Deadline: '.($upcomingTax->title ?? "{$upcomingTax->event_type} — {$upcomingTax->form_type}"),
                'description' => "Due {$upcomingTax->due_date->format('M d, Y')}.",
                'action' => 'Ensure funds are available. Missing this deadline accrues penalties.',
            ];
        }

        // High-interest debt alert
        $highInterestDebts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->where('interest_rate', '>', 20)
            ->orderBy('interest_rate', 'desc')
            ->get();

        if ($highInterestDebts->count() > 0) {
            $worst = $highInterestDebts->first();
            $monthlyInterest = ($worst->current_balance * ($worst->interest_rate / 100)) / 12;
            $recommendations[] = [
                'type' => 'medium',
                'title' => "High Interest: {$worst->name} at {$worst->interest_rate}%",
                'description' => 'Costing $'.number_format($monthlyInterest, 2).'/month in interest alone.',
                'action' => 'Prioritize extra payments here. Every $100 extra saves $'.number_format($worst->interest_rate / 12, 2).'/month.',
            ];
        }

        // Revenue pipeline
        $pipelineValue = Invoice::where('status', 'draft')->sum('total');
        if ($pipelineValue > 0) {
            $recommendations[] = [
                'type' => 'medium',
                'title' => 'Send $'.number_format($pipelineValue, 2).' in Draft Invoices',
                'description' => 'You have unsent invoices that could improve cash flow.',
                'action' => 'Review and send draft invoices today.',
            ];
        }

        return $recommendations;
    }

    /**
     * Get current business cash position from the most recent financial snapshot.
     */
    protected function getBusinessCashPosition(): float
    {
        $snapshot = FinancialSnapshot::orderBy('created_at', 'desc')->first();

        return (float) ($snapshot?->cash_on_hand ?? 0);
    }

    /**
     * Get average monthly contractor costs from the last 3 months.
     */
    protected function getAverageContractorCosts(): float
    {
        $since = now()->subMonths(3);
        $total = WiseTransfer::where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->sum('source_amount');

        return (float) $total / 3;
    }
}
