<?php

namespace App\Jobs;

use App\Models\FunnelMetrics;
use App\Services\BusinessIntelligenceService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateFunnelMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(BusinessIntelligenceService $biService): void
    {
        $now = Carbon::now();

        try {
            // Daily snapshot
            $biService->storeFunnelSnapshot(
                FunnelMetrics::TYPE_DAILY,
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay()
            );

            // Weekly snapshot (only on Sundays)
            if ($now->isSunday()) {
                $biService->storeFunnelSnapshot(
                    FunnelMetrics::TYPE_WEEKLY,
                    $now->copy()->startOfWeek(Carbon::MONDAY),
                    $now->copy()->endOfWeek(Carbon::SUNDAY)
                );
            }

            // Monthly snapshot (only on last day of month)
            if ($now->isLastOfMonth()) {
                $biService->storeFunnelSnapshot(
                    FunnelMetrics::TYPE_MONTHLY,
                    $now->copy()->startOfMonth(),
                    $now->copy()->endOfMonth()
                );
            }

            Log::info('Calculated funnel metrics', [
                'date' => $now->toDateString(),
                'is_sunday' => $now->isSunday(),
                'is_month_end' => $now->isLastOfMonth(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to calculate funnel metrics', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
