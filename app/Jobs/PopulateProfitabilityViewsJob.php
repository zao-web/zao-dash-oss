<?php

namespace App\Jobs;

use App\Models\ProfitabilityView;
use App\Services\PersonalFinance\RevenueOptimizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PopulateProfitabilityViewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(RevenueOptimizationService $revenueService): void
    {
        $periodStart = now()->subMonth()->startOfMonth();
        $periodEnd = now()->subMonth()->endOfMonth();

        $profitability = $revenueService->getClientProfitability('quarterly');

        $upsertCount = 0;

        foreach ($profitability as $clientData) {
            ProfitabilityView::updateOrCreate(
                [
                    'client_id' => $clientData['client_id'],
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                ],
                [
                    'revenue' => $clientData['revenue'],
                    'cost' => $clientData['cost'],
                    'profit' => $clientData['profit'],
                    'margin' => $clientData['margin'],
                    'hours_logged' => $clientData['hours'],
                    'effective_rate' => $clientData['effective_rate'],
                ]
            );

            $upsertCount++;
        }

        Log::info('PopulateProfitabilityViewsJob completed.', [
            'clients_processed' => $upsertCount,
            'period' => $periodStart->format('Y-m').' to '.$periodEnd->format('Y-m'),
        ]);
    }
}
