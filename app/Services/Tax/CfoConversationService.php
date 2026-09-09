<?php

namespace App\Services\Tax;

use App\Models\CfoConversation;
use App\Models\Client;
use App\Models\Debt;
use App\Models\EstimatedTaxPayment;
use App\Models\Invoice;
use App\Models\PersonalTransaction;
use App\Models\TaxProfile;
use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\BudgetService;
use Illuminate\Support\Facades\Log;

/**
 * Conversational CFO/CPA interface.
 * Injects full financial context before every AI call.
 * Replaces the need to email or call a CPA.
 */
class CfoConversationService
{
    public function __construct(
        protected ClaudeCliService $claude,
    ) {}

    /**
     * Send a message to the CFO and get a response.
     */
    public function ask(int $userId, string $question, ?int $conversationId = null): array
    {
        // Get or create conversation
        $conversation = $conversationId
            ? CfoConversation::where('user_id', $userId)->findOrFail($conversationId)
            : CfoConversation::create([
                'user_id' => $userId,
                'title' => substr($question, 0, 100),
                'messages' => [],
                'context_snapshot' => $this->buildContextSnapshot($userId),
            ]);

        if (! $conversation->context_snapshot) {
            $conversation->update(['context_snapshot' => $this->buildContextSnapshot($userId)]);
        }

        // Add user message
        $conversation->addMessage('user', $question);

        // Build prompt with financial context
        $systemPrompt = $this->buildSystemPrompt($userId);
        $conversationHistory = $this->formatConversationHistory($conversation);

        $fullPrompt = $conversationHistory;

        try {
            $response = $this->claude->messageText($fullPrompt, $systemPrompt, 'sonnet', 120);

            $conversation->addMessage('assistant', $response);

            // Auto-title from first exchange
            if (count($conversation->messages) <= 2 && ! $conversation->title) {
                $conversation->update(['title' => substr($question, 0, 100)]);
            }

            return [
                'conversation_id' => $conversation->id,
                'response' => $response,
                'title' => $conversation->title,
            ];
        } catch (\Exception $e) {
            Log::error('CfoConversationService: AI call failed', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
            ]);

            $errorMsg = 'I encountered an error processing your question. Please try again.';
            $conversation->addMessage('assistant', $errorMsg);

            return [
                'conversation_id' => $conversation->id,
                'response' => $errorMsg,
                'title' => $conversation->title,
            ];
        }
    }

    /**
     * Build the system prompt with full financial context.
     */
    protected function buildSystemPrompt(int $userId): string
    {
        $context = $this->buildContextSnapshot($userId);
        $contextJson = json_encode($context, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are the CFO and CPA for Zao Web Design, LLC, a web development S-corp based in Oregon (Portland area). You have complete access to the business's financial data. Your client is Justin, the sole owner and operator.

IMPORTANT RULES:
- Give specific dollar amounts, not vague advice
- When discussing tax impact, compute the actual numbers using the financial data below
- Be direct and honest — don't sugarcoat bad news
- When the user asks about a purchase or decision, compute the tax impact at their marginal rate
- Reference specific line items from their financial data
- If you need information that isn't in the context, say what you need
- Oregon does NOT conform to the federal QBI deduction — always mention this when relevant
- The user has an S-corp, so distributions are not subject to SE tax (only salary is)

FINANCIAL CONTEXT (current as of now):
{$contextJson}

When the user asks about:
- Buying property → Run cost segregation analysis, check STR loophole, model depreciation impact
- Tax savings → Check Solo 401(k) room, HSA, Augusta rule, home office, mileage, health insurance, R&D credit
- Revenue decisions → Compute marginal tax rate, show take-home after taxes
- Distributions → Check S-corp basis, AAA balance, IRS installment obligations
- Hiring → Compare W-2 vs 1099 cost with employer FICA, FUTA, SUTA
- Debt strategy → Factor interest deductibility, prioritize tax debt (penalties compound)

Always end with a clear recommended action.
PROMPT;
    }

    /**
     * Build a snapshot of all financial data for context injection.
     */
    protected function buildContextSnapshot(int $userId): array
    {
        $year = now()->year;
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->first();

        // YTD income
        $paidInvoiceQuery = Invoice::where('status', 'paid')
            ->whereYear('paid_at', $year);

        $ytdRevenue = (float) (clone $paidInvoiceQuery)->sum('total');
        $ytdRecurringRevenue = (float) (clone $paidInvoiceQuery)->where('is_recurring', true)->sum('total');
        $monthlyNonRecurringAverage = now()->month > 0
            ? max(0, $ytdRevenue - $ytdRecurringRevenue) / now()->month
            : 0;

        // Retainer income
        $retainerMonthly = (float) Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->sum('recurring_invoice_amount');

        // Budget obligations
        $budgetService = app(BudgetService::class);
        $budgetStatus = $budgetService->getMonthlyBudgetStatus($userId);
        $monthlyExpenses = collect($budgetStatus['categories'] ?? [])
            ->filter(fn ($c) => in_array($c['category_type'], ['expense', 'debt_payment', 'tax_payment']))
            ->sum('target');

        // Debts
        $debts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->get(['name', 'debt_type', 'current_balance', 'interest_rate', 'minimum_payment']);

        // Tax payments
        $payments = EstimatedTaxPayment::ytdPaymentsByJurisdiction($userId, $year);

        // Recent transactions (last 30 days)
        $recentTransactions = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->where('transaction_date', '>=', now()->subDays(30))
            ->where('amount', '>', 50)
            ->orderBy('amount', 'desc')
            ->limit(20)
            ->get(['transaction_date', 'description', 'merchant_name', 'amount'])
            ->map(fn ($t) => [
                'date' => $t->transaction_date->format('M j'),
                'description' => $t->merchant_name ?? $t->description,
                'amount' => (float) $t->amount,
            ]);

        // Open invoices
        $openInvoices = Invoice::whereIn('status', ['sent', 'viewed', 'overdue'])
            ->with('client')
            ->get(['id', 'client_id', 'amount_due', 'status', 'due_date'])
            ->map(fn ($i) => [
                'client' => $i->client?->name,
                'amount' => (float) $i->amount_due,
                'status' => $i->status,
                'due' => $i->due_date?->format('M j'),
            ]);

        // Active clients
        $activeClients = Client::where('status', 'active')
            ->get(['name', 'recurring_invoice_enabled', 'recurring_invoice_amount'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'retainer' => $c->recurring_invoice_enabled ? (float) $c->recurring_invoice_amount : null,
            ]);

        return [
            'tax_year' => $year,
            'filing_status' => $profile->filing_status ?? 'unknown',
            'entity_type' => $profile->entity_type ?? 'unknown',
            'state' => $profile->resident_state ?? 'OR',
            'reasonable_salary' => (float) ($profile->reasonable_salary ?? 0),
            'ytd_revenue' => $ytdRevenue,
            'retainer_monthly' => $retainerMonthly,
            'monthly_expenses' => round($monthlyExpenses, 2),
            'annual_projected_revenue' => round($ytdRevenue + (($retainerMonthly + $monthlyNonRecurringAverage) * (12 - now()->month)), 2),
            'debts' => $debts->toArray(),
            'total_debt' => round($debts->sum('current_balance'), 2),
            'estimated_tax_payments' => $payments,
            'recent_transactions' => $recentTransactions->toArray(),
            'open_invoices' => $openInvoices->toArray(),
            'active_clients' => $activeClients->toArray(),
            'prior_year_tax' => (float) ($profile->prior_year_tax_liability ?? 0),
            'prior_year_agi' => (float) ($profile->prior_year_agi ?? 0),
            'has_solo_401k' => (bool) ($profile->has_solo_401k ?? false),
            'has_hsa' => (bool) ($profile->has_hsa ?? false),
            'dependents' => $profile->dependent_count ?? 0,
        ];
    }

    protected function formatConversationHistory(CfoConversation $conversation): string
    {
        $messages = $conversation->messages ?? [];

        // Only include last 10 messages to stay within context limits
        $recent = array_slice($messages, -10);

        $formatted = '';
        foreach ($recent as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'CFO';
            $formatted .= "{$role}: {$msg['content']}\n\n";
        }

        return $formatted;
    }

    /**
     * Get conversation history for a user.
     */
    public function getConversations(int $userId, int $limit = 20): array
    {
        return CfoConversation::where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'updated_at' => $c->updated_at->diffForHumans(),
            ])
            ->toArray();
    }
}
