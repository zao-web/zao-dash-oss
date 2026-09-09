<?php

namespace App\Jobs;

use App\Models\FinancialSnapshot;
use App\Models\Invoice;
use App\Models\PersonalAccount;
use App\Models\TaxCalendarEvent;
use App\Models\TaxObligation;
use App\Models\User;
use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\CashFlowService;
use App\Services\PersonalFinance\DebtManagementService;
use App\Services\PersonalFinance\LifeStrategyService;
use App\Services\PersonalFinance\NorthStarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate a comprehensive daily financial briefing.
 *
 * Combines business revenue pipeline, personal cash positions,
 * debt obligations, tax deadlines, and cash flow projections
 * into a prioritized daily briefing sent via Slack/notification.
 */
class GenerateDailyBriefingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('agents');
    }

    public function handle(
        CashFlowService $cashFlowService,
        DebtManagementService $debtService,
        ClaudeCliService $claude,
    ): void {
        $user = User::where('role', 'admin')->first();

        if (! $user) {
            Log::warning('GenerateDailyBriefingJob: No admin user found, skipping briefing.');

            return;
        }

        Log::info('GenerateDailyBriefingJob: Generating daily financial briefing', [
            'user_id' => $user->id,
        ]);

        // Gather all financial data
        $cashFlowForecast = $cashFlowService->generateForecast($user->id, 30);
        $debtSummary = $debtService->getDebtSummary($user->id);
        $businessData = $this->getBusinessData();
        $taxData = $this->getTaxData($user->id);
        $accountData = $this->getAccountData($user->id);
        $northStarData = app(NorthStarService::class)->getActiveGoal($user->id);

        // Generate holistic strategy for top action items
        $strategyData = null;
        try {
            $strategyData = app(LifeStrategyService::class)->generateStrategy($user->id);
        } catch (\Throwable $e) {
            Log::warning('GenerateDailyBriefingJob: Failed to generate life strategy', ['error' => $e->getMessage()]);
        }

        // Build the data context for Claude
        $dataContext = $this->buildDataContext(
            $cashFlowForecast,
            $debtSummary,
            $businessData,
            $taxData,
            $accountData,
            $northStarData,
            $strategyData,
        );

        // Generate the narrative briefing using Claude
        $systemPrompt = $this->getSystemPrompt();
        $prompt = "Generate today's daily financial briefing based on this data:\n\n{$dataContext}";

        try {
            $response = $claude->message(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                model: 'haiku',
                maxTokens: 2048,
                timeout: 120,
            );

            $briefing = $response['content'] ?? 'Failed to generate briefing.';

            // Send via Slack notification if configured
            $this->sendBriefing($user, $briefing);

            Log::info('GenerateDailyBriefingJob: Briefing generated and sent', [
                'user_id' => $user->id,
                'briefing_length' => strlen($briefing),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateDailyBriefingJob: Failed to generate briefing', [
                'error' => $e->getMessage(),
            ]);

            // Send a basic data-only briefing as fallback
            $this->sendBriefing($user, $this->buildFallbackBriefing($cashFlowForecast, $debtSummary, $businessData));
        }
    }

    /**
     * Build structured data context for the AI to process.
     */
    protected function buildDataContext(
        array $cashFlowForecast,
        array $debtSummary,
        array $businessData,
        array $taxData,
        array $accountData,
        ?array $northStarData = null,
        ?array $strategyData = null,
    ): string {
        $today = now()->format('l, F j, Y');

        $sections = [];

        $sections[] = "DATE: {$today}";

        // Cash Position
        $sections[] = "CASH POSITION:\n".
            "  Personal checking: \${$accountData['checking_balance']}\n".
            "  Personal savings: \${$accountData['savings_balance']}\n".
            "  Business cash: \${$cashFlowForecast['current_cash']['business']}\n".
            "  Combined total: \${$cashFlowForecast['current_cash']['total']}";

        // Revenue Pipeline
        $sections[] = "REVENUE PIPELINE:\n".
            "  Outstanding invoices: \${$businessData['outstanding_total']} ({$businessData['outstanding_count']} invoices)\n".
            "  Overdue invoices: \${$businessData['overdue_total']} ({$businessData['overdue_count']} invoices)\n".
            "  Draft invoices: \${$businessData['draft_total']} ({$businessData['draft_count']} invoices)\n".
            "  Monthly retainer income: \${$businessData['retainer_income']}";

        // Debt Summary
        $sections[] = "DEBT SUMMARY:\n".
            "  Total balance: \${$debtSummary['total_balance']}\n".
            "  Monthly minimums: \${$debtSummary['total_minimum_payments']}\n".
            "  Total original: \${$debtSummary['total_original']}\n".
            "  Total paid: \${$debtSummary['total_paid']}\n".
            "  Active debts: {$debtSummary['debt_count']}\n".
            "  Tax debt: \${$debtSummary['tax_debt_total']}\n".
            "  Collections: \${$debtSummary['collections_total']}";

        // Tax Data
        if (! empty($taxData['upcoming_deadlines'])) {
            $deadlineLines = collect($taxData['upcoming_deadlines'])
                ->map(fn ($d) => "  - {$d['title']}: due {$d['due_date']} ({$d['days_until']} days)")
                ->implode("\n");
            $sections[] = "TAX DEADLINES:\n{$deadlineLines}";
        }

        if (! empty($taxData['obligations'])) {
            $obligationLines = collect($taxData['obligations'])
                ->map(fn ($o) => "  - {$o['tax_type']} ({$o['tax_year']}): \${$o['balance']} — {$o['resolution_status']}")
                ->implode("\n");
            $sections[] = "TAX OBLIGATIONS:\n{$obligationLines}";
        }

        // Cash Flow Summary
        $summary = $cashFlowForecast['summary'];
        $sections[] = "30-DAY CASH FLOW FORECAST:\n".
            "  Total projected inflows: \${$summary['total_projected_inflows']}\n".
            "  Total projected outflows: \${$summary['total_projected_outflows']}\n".
            "  Ending balance: \${$summary['ending_balance']}\n".
            "  Lowest balance: \${$summary['lowest_balance']} on {$summary['lowest_balance_date']}\n".
            "  Shortfall days: {$summary['shortfall_count']}";

        // Recommendations
        if (! empty($cashFlowForecast['recommendations'])) {
            $recLines = collect($cashFlowForecast['recommendations'])
                ->map(fn ($r) => "  [{$r['type']}] {$r['title']}: {$r['description']} — Action: {$r['action']}")
                ->implode("\n");
            $sections[] = "RECOMMENDATIONS:\n{$recLines}";
        }

        // North Star Goal
        if ($northStarData) {
            $currentMilestone = $northStarData['current_milestone']['title'] ?? 'None';
            $currentMilestonePercent = $northStarData['current_milestone']['percent_complete'] ?? 0;
            $estimatedCompletion = $northStarData['estimated_completion'] ?? 'TBD';
            $velocity = $northStarData['velocity']
                ? "{$northStarData['velocity']['progress_last_30_days']}% ({$northStarData['velocity']['direction']})"
                : 'Not enough data yet';

            $sections[] = "NORTH STAR GOAL: {$northStarData['title']}\n".
                "  Overall progress: {$northStarData['overall_progress']}%\n".
                "  Current milestone: {$currentMilestone} ({$currentMilestonePercent}% complete)\n".
                "  Days on journey: {$northStarData['days_on_journey']}\n".
                "  Estimated completion: {$estimatedCompletion}\n".
                "  30-day velocity: {$velocity}\n".
                "  Why this matters: {$northStarData['why']}\n".
                "  IMPORTANT: End the briefing by connecting today's actions to this North Star goal. Remind the user that every dollar earned and saved brings them closer to Chehalem Mountain.";
        }

        // Life Strategy: top action items and key insight
        if ($strategyData) {
            $topActions = array_slice($strategyData['action_plan'] ?? [], 0, 3);
            if (! empty($topActions)) {
                $actionLines = collect($topActions)
                    ->map(fn ($a) => "  [{$a['urgency']}] {$a['action']}")
                    ->implode("\n");
                $sections[] = "TOP STRATEGIC PRIORITIES:\n{$actionLines}";
            }

            $keyInsight = $strategyData['north_star_projection']['key_insight'] ?? null;
            if ($keyInsight) {
                $sections[] = "KEY STRATEGIC INSIGHT: {$keyInsight}";
            }
        }

        return implode("\n\n", $sections);
    }

    protected function getBusinessData(): array
    {
        $outstanding = Invoice::whereIn('status', ['sent', 'viewed', 'partial', 'overdue'])->get();
        $overdue = $outstanding->filter(fn ($i) => $i->due_date && $i->due_date->isPast());
        $drafts = Invoice::where('status', 'draft')->get();

        $retainerIncome = \App\Models\RetainerPeriod::where('status', 'active')
            ->with('client')
            ->get()
            ->sum(fn ($r) => (float) ($r->client?->recurring_invoice_amount ?? 0));

        $snapshot = FinancialSnapshot::latest('monthly');

        return [
            'outstanding_total' => number_format($outstanding->sum('amount_due'), 2),
            'outstanding_count' => $outstanding->count(),
            'overdue_total' => number_format($overdue->sum('amount_due'), 2),
            'overdue_count' => $overdue->count(),
            'draft_total' => number_format($drafts->sum('total'), 2),
            'draft_count' => $drafts->count(),
            'retainer_income' => number_format($retainerIncome, 2),
            'monthly_revenue' => $snapshot ? number_format($snapshot->total_income, 2) : '0.00',
            'monthly_expenses' => $snapshot ? number_format($snapshot->total_expenses, 2) : '0.00',
            'net_profit' => $snapshot ? number_format($snapshot->net_profit, 2) : '0.00',
        ];
    }

    protected function getTaxData(int $userId): array
    {
        $upcoming = TaxCalendarEvent::where('due_date', '>', now())
            ->where('due_date', '<=', now()->addDays(90))
            ->whereIn('status', ['upcoming', 'reminder_sent'])
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $obligations = TaxObligation::whereHas('debt', fn ($q) => $q->where('user_id', $userId))
            ->with('debt')
            ->get();

        return [
            'upcoming_deadlines' => $upcoming->map(fn ($e) => [
                'title' => $e->title ?? "{$e->event_type} — {$e->form_type}",
                'due_date' => $e->due_date->format('M d, Y'),
                'days_until' => $e->daysUntilDue(),
                'is_urgent' => $e->isUrgent(),
            ])->toArray(),
            'obligations' => $obligations->map(fn ($o) => [
                'tax_type' => $o->tax_type,
                'tax_year' => $o->tax_year,
                'balance' => number_format($o->debt?->current_balance ?? 0, 2),
                'resolution_status' => $o->resolution_status ?? 'pending',
            ])->toArray(),
        ];
    }

    protected function getAccountData(int $userId): array
    {
        $accounts = PersonalAccount::where('user_id', $userId)
            ->where('is_closed', false)
            ->get();

        return [
            'checking_balance' => number_format(
                $accounts->where('account_type', 'checking')->sum('current_balance'),
                2,
            ),
            'savings_balance' => number_format(
                $accounts->where('account_type', 'savings')->sum('current_balance'),
                2,
            ),
            'credit_card_balance' => number_format(
                $accounts->where('account_type', 'credit_card')->sum('current_balance'),
                2,
            ),
            'total_accounts' => $accounts->count(),
        ];
    }

    protected function getSystemPrompt(): string
    {
        $skillPath = storage_path('app/skills/daily-life-briefing/SKILL.md');

        if (file_exists($skillPath)) {
            return file_get_contents($skillPath);
        }

        return 'You are a financial briefing generator. Create a concise, actionable daily financial briefing from the provided data. Focus on priorities, alerts, and action items.';
    }

    /**
     * Send the briefing to the user via database notification.
     */
    protected function sendBriefing(User $user, string $briefing): void
    {
        $user->notify(new \App\Notifications\DailyBriefingNotification($briefing));

        Log::info('GenerateDailyBriefingJob: Briefing sent to user', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Build a basic fallback briefing from raw data when AI generation fails.
     */
    protected function buildFallbackBriefing(array $cashFlow, array $debtSummary, array $businessData): string
    {
        $today = now()->format('l, F j, Y');

        return <<<BRIEFING
DAILY FINANCIAL BRIEFING — {$today}

CASH POSITION
  Combined: \${$cashFlow['current_cash']['total']}

REVENUE PIPELINE
  Outstanding: \${$businessData['outstanding_total']} ({$businessData['outstanding_count']} invoices)
  Overdue: \${$businessData['overdue_total']} ({$businessData['overdue_count']} invoices)

DEBT SNAPSHOT
  Total: \${$debtSummary['total_balance']}
  Monthly minimums: \${$debtSummary['total_minimum_payments']}

[AI narrative generation failed — raw data summary above]
BRIEFING;
    }
}
