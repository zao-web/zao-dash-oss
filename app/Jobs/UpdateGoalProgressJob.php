<?php

namespace App\Jobs;

use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateGoalProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(BusinessIntelligenceService $biService): void
    {
        $goals = StrategicGoal::where('status', StrategicGoal::STATUS_ACTIVE)->get();

        foreach ($goals as $goal) {
            try {
                $biService->updateGoalActuals($goal);

                Log::info('Updated goal progress', [
                    'goal_id' => $goal->id,
                    'fiscal_year' => $goal->fiscal_year,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to update goal progress', [
                    'goal_id' => $goal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
