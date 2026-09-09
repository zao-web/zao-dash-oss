<?php

namespace App\Jobs;

use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerHealthService;
use App\Services\Reports\RetainerNarrativeService;
use App\Services\Reports\RetainerReportPdfGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Full retainer report refresh: bust the commit/narrative/PDF caches,
 * re-persist the health snapshot, and regenerate the LLM narrative.
 *
 * Runs on the queue because the GitHub re-pulls plus the Cloudflare
 * narrative call (120s timeout, retried once) can take several minutes —
 * far past the gateway timeout when run inside a web request.
 *
 * Progress is reported through a cache entry (see statusKey()) that the
 * admin report view renders as a banner: running → done/error. The
 * controller sets `running` before dispatch; this job overwrites it with
 * the outcome, and adminShow() clears done/error after displaying once.
 */
class RefreshRetainerReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public RetainerPeriod $period,
    ) {}

    public static function statusKey(int $periodId): string
    {
        return "retainer.refresh.status.{$periodId}";
    }

    public function handle(
        RetainerHealthService $health,
        RetainerNarrativeService $narrativeService,
        RetainerReportPdfGenerator $generator,
    ): void {
        $period = $this->period;
        $start = $period->windowStart();
        $end = $period->windowEnd();

        // Clear cached commit pulls for every repo linked to the client
        // (direct OR via project — same resolution as the aggregator).
        foreach ($health->reposForClient($period->client_id) as $repo) {
            Cache::forget(sprintf(
                'retainer.commits.%d.%s.%s',
                $repo->id,
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        // Bust the LLM narrative cache too — same data window, different cache.
        Cache::forget(sprintf('retainer.narrative.%d', $period->id));

        // Clear the cached PDF so the next download regenerates with fresh data.
        $pdfPath = $generator->getStoragePath($period);
        if (Storage::exists($pdfPath)) {
            Storage::delete($pdfPath);
        }

        // Re-persist the snapshot so retainer_periods.health_status etc. update.
        $health->computeAndPersistSnapshot($period, $start, $end, persist: true);

        // Regenerate the narrative (LLM call). This is the only place the
        // narrative is built synchronously — render paths only read cache.
        try {
            $narrative = $narrativeService->buildNarrative($period, force: true);
            $topicCount = count($narrative['topics'] ?? []);
            $warnings = $narrative['warnings'] ?? [];

            if (! empty($warnings)) {
                $state = 'error';
                $message = 'Report data refreshed, but narrative had issues: '.implode(' / ', $warnings);
            } elseif ($topicCount === 0) {
                $state = 'done';
                $message = 'Report data refreshed. No work topics identified for this period.';
            } else {
                $state = 'done';
                $message = "Report data refreshed. Narrative regenerated with {$topicCount} topic(s).";
            }
        } catch (\Throwable $e) {
            Log::warning('Narrative regen failed during refresh', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);
            $state = 'error';
            $message = 'Report data refreshed. Narrative threw an exception: '.$e->getMessage();
        }

        $this->putStatus($state, $message);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->putStatus('error', 'Refresh failed: '.($exception?->getMessage() ?? 'unknown error'));
    }

    protected function putStatus(string $state, string $message): void
    {
        Cache::put(self::statusKey($this->period->id), [
            'state' => $state,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }
}
