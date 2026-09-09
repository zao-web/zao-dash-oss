<?php

namespace App\Jobs;

use App\Models\EstimatedTaxPayment;
use App\Models\Notification;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Tax\TaxBracketEngine;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recalculates quarterly estimated tax payments monthly.
 * Pulls YTD data, computes using TaxBracketEngine, alerts if estimates change.
 */
class RecalculateTaxEstimatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(): void
    {
        $user = User::first();
        if (! $user) {
            return;
        }

        $year = now()->year;
        $currentQuarter = (int) ceil(now()->month / 3);

        $profile = TaxProfile::where('user_id', $user->id)
            ->forYear($year)
            ->first();

        if (! $profile) {
            Log::info('RecalculateTaxEstimatesJob: no tax profile for '.$year);

            return;
        }

        // Try to get YTD income from QBO
        $connection = $user->quickBooksConnections()->active()->first();
        $ytdIncome = 0;

        if ($connection) {
            try {
                $taxCalcService = app(TaxCalculationService::class);
                $estimate = $taxCalcService->calculateQuarterlyEstimate($connection, $currentQuarter, $year);
                $ytdIncome = (float) ($estimate->projected_annual_income ?? 0);

                Log::info('RecalculateTaxEstimatesJob: used QBO data', [
                    'ytd_income' => $estimate->ytd_net_income,
                    'projected_annual' => $ytdIncome,
                ]);
            } catch (\Exception $e) {
                Log::warning('RecalculateTaxEstimatesJob: QBO failed, using budget data', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: use budget income data if QBO not available
        if ($ytdIncome <= 0) {
            $budgetService = app(\App\Services\PersonalFinance\BudgetService::class);
            $budgetStatus = $budgetService->getMonthlyBudgetStatus($user->id);
            $monthlyIncome = collect($budgetStatus['categories'] ?? [])
                ->filter(fn ($c) => $c['category_type'] === 'income')
                ->sum('target');
            $ytdIncome = $monthlyIncome * 12;
        }

        if ($ytdIncome <= 0) {
            return;
        }

        // Compute tax using TaxBracketEngine
        $salary = (float) $profile->reasonable_salary;
        $taxResult = TaxBracketEngine::computeSCorpTax(
            netBusinessIncome: $ytdIncome,
            salary: $salary > 0 ? $salary : $ytdIncome * 0.40,
            year: $year,
            filingStatus: $profile->filing_status,
            qualifyingChildren: $profile->dependent_count,
            isPortlandResident: strtolower($profile->resident_city ?? '') === 'portland',
        );

        // Get payments made
        $ytdPayments = EstimatedTaxPayment::ytdPaymentsByJurisdiction($user->id, $year);

        // Calculate quarterly amounts using safe harbor
        $remainingQuarters = max(1, 4 - $currentQuarter + 1);
        $quarterlyEstimate = TaxBracketEngine::calculateQuarterlyEstimate(
            projectedCurrentYearTax: $taxResult['federal_tax'] + $taxResult['total_fica'],
            priorYearTaxLiability: (float) $profile->prior_year_tax_liability,
            priorYearAgi: (float) $profile->prior_year_agi,
            ytdPaymentsMade: $ytdPayments['federal'],
            remainingQuarters: $remainingQuarters,
        );

        // Alert if estimate changed significantly
        $previousEstimate = \App\Models\TaxEstimate::where('quick_books_connection_id', $connection?->id)
            ->where('tax_year', $year)
            ->where('quarter', $currentQuarter)
            ->value('quarterly_payment_due') ?? 0;

        $newEstimate = $quarterlyEstimate['quarterly_payment'];
        $diff = abs($newEstimate - $previousEstimate);

        if ($diff > 500) {
            Notification::system(
                "Tax estimate updated: Q{$currentQuarter} payment is now \$".number_format($newEstimate, 0),
                'Your estimated quarterly tax payment changed by $'.number_format($diff, 0).
                '. Based on projected annual income of $'.number_format($ytdIncome, 0).
                ", your Q{$currentQuarter} federal payment should be \$".number_format($newEstimate, 0).'.',
                'warning'
            );
        }

        Log::info('RecalculateTaxEstimatesJob: completed', [
            'year' => $year,
            'quarter' => $currentQuarter,
            'projected_income' => $ytdIncome,
            'total_tax' => $taxResult['total_tax'],
            'quarterly_payment' => $newEstimate,
            'previous_estimate' => $previousEstimate,
            'change' => $diff,
        ]);
    }
}
