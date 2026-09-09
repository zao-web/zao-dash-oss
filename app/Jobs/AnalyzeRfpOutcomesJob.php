<?php

namespace App\Jobs;

use App\Models\RfpOutcome;
use App\Services\Rfp\RfpLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analyze RFP outcomes for learning insights.
 *
 * Runs weekly to process unanalyzed feedback and generate
 * aggregate insights from win/loss patterns.
 */
class AnalyzeRfpOutcomesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('agents');
    }

    public function handle(RfpLearningService $learningService): void
    {
        $cutoff = now()->subDays(30);

        $outcomes = RfpOutcome::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('feedback_raw')
            ->whereNull('feedback_structured')
            ->get();

        if ($outcomes->isEmpty()) {
            Log::info('AnalyzeRfpOutcomesJob: no unanalyzed outcomes found');

            return;
        }

        Log::info('AnalyzeRfpOutcomesJob: starting analysis', [
            'outcomes_count' => $outcomes->count(),
            'cutoff_date' => $cutoff->toDateString(),
        ]);

        $analyzed = 0;
        $failed = 0;

        foreach ($outcomes as $outcome) {
            try {
                $result = $learningService->analyzeFeedback($outcome);

                if (! empty($result)) {
                    $analyzed++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error('AnalyzeRfpOutcomesJob: failed to analyze outcome', [
                    'outcome_id' => $outcome->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('AnalyzeRfpOutcomesJob: analysis completed', [
            'total' => $outcomes->count(),
            'analyzed' => $analyzed,
            'failed' => $failed,
        ]);
    }
}
