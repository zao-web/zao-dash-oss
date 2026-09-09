<?php

namespace App\Jobs;

use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\LifeStrategyService;
use App\Services\PersonalFinance\NorthStarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyzeFinancialAssumptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $analysisKey,
        public int $userId,
        public string $question,
        public array $scenario,
    ) {
        $this->onQueue('agents');
    }

    public function handle(): void
    {
        Cache::put($this->analysisKey, ['status' => 'processing'], 600);

        try {
            $strategy = app(LifeStrategyService::class)->generateStrategy($this->userId);
            $northStar = app(NorthStarService::class)->getActiveGoal($this->userId);

            $context = json_encode([
                'current_monthly_income' => $strategy['snapshot']['monthly_income'],
                'current_monthly_obligations' => $strategy['snapshot']['monthly_obligations'],
                'current_disposable' => $strategy['snapshot']['disposable_income'],
                'total_debt' => $strategy['snapshot']['total_debt'],
                'tax_debt' => $strategy['snapshot']['tax_debt'],
                'collections_debt' => $strategy['snapshot']['collections_debt'],
                'monthly_minimums' => $strategy['snapshot']['monthly_minimums'],
                'north_star_goal' => $northStar['title'] ?? 'Not set',
                'north_star_cost' => $northStar['total_cost_estimate'] ?? 0,
                'north_star_target_date' => $northStar['target_date'] ?? null,
                'scenario_adjustments' => $this->scenario,
                'expense_optimization_available' => $strategy['expense_optimization']['total_monthly_savings'] ?? 0,
                'tax_savings_available' => $strategy['tax_optimization']['total_annual_savings'] ?? 0,
                'revenue_targets' => $strategy['revenue_targets'] ?? [],
            ], JSON_PRETTY_PRINT);

            $prompt = "You are a financial advisor analyzing assumptions for a freelance web development agency owner.\n\n".
                "Financial Context:\n{$context}\n\n".
                "User's Question:\n{$this->question}\n\n".
                "Provide a clear, honest, specific analysis. Include:\n".
                "1. Whether the assumption is realistic and why/why not\n".
                "2. What factors are being overlooked (taxes on income, interest accrual, inflation, lifestyle creep, irregular expenses, business downturns)\n".
                "3. A more realistic estimate with numbers\n".
                "4. Key risks that could derail the plan\n".
                "5. What would need to be true for the assumption to hold\n\n".
                "Be direct and honest. Use specific dollar amounts. Don't sugarcoat.";

            $claude = app(ClaudeCliService::class);
            $text = $claude->messageText($prompt, null, 'sonnet', 300);

            Cache::put($this->analysisKey, [
                'status' => 'complete',
                'analysis' => $text,
            ], 3600);

        } catch (\Exception $e) {
            Log::error('AnalyzeFinancialAssumptionsJob failed', [
                'error' => $e->getMessage(),
                'key' => $this->analysisKey,
            ]);

            Cache::put($this->analysisKey, [
                'status' => 'failed',
                'analysis' => 'Analysis failed: '.$e->getMessage(),
            ], 3600);
        }
    }
}
