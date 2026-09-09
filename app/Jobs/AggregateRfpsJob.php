<?php

namespace App\Jobs;

use App\Models\RfpSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Aggregate RFP opportunities from all active sources.
 *
 * Processes each source type:
 * - email_sender: Skipped (handled by ScanRfpEmailsJob)
 * - government_api: Skipped (federal RFPs not pursued)
 * - rfp_board / web_scrape: Logged for RfpAggregatorAgent handling
 *
 * Dispatches the RfpAggregatorAgent for web-based discovery.
 */
class AggregateRfpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('agents');
    }

    public function handle(): void
    {
        Log::info('AggregateRfpsJob: Starting RFP aggregation');

        $sources = RfpSource::query()
            ->active()
            ->dueForCheck()
            ->get();

        if ($sources->isEmpty()) {
            Log::info('AggregateRfpsJob: No sources due for check');
        }

        $processedCount = 0;
        $webSourceCount = 0;

        foreach ($sources as $source) {
            match ($source->type) {
                'email_sender' => $this->handleEmailSource($source),
                'government_api' => $this->handleGovernmentApiSource($source),
                'rfp_board', 'web_scrape' => $this->handleWebSource($source, $webSourceCount),
                default => Log::debug('AggregateRfpsJob: Unknown source type', [
                    'source_id' => $source->id,
                    'type' => $source->type,
                ]),
            };

            $source->update(['last_checked_at' => now()]);
            $processedCount++;
        }

        // Dispatch the RfpAggregatorAgent for web-based discovery
        $this->dispatchAggregatorAgent();

        Log::info('AggregateRfpsJob: Completed', [
            'sources_processed' => $processedCount,
            'web_sources_deferred' => $webSourceCount,
        ]);
    }

    /**
     * Email sources are handled by ScanRfpEmailsJob - skip here.
     */
    protected function handleEmailSource(RfpSource $source): void
    {
        Log::debug('AggregateRfpsJob: Skipping email source (handled by ScanRfpEmailsJob)', [
            'source_id' => $source->id,
            'source_name' => $source->name,
        ]);
    }

    /**
     * Government API sources (SAM.gov) are disabled — federal work is not pursued.
     */
    protected function handleGovernmentApiSource(RfpSource $source): void
    {
        Log::debug('AggregateRfpsJob: Skipping government API source (federal RFPs not pursued)', [
            'source_id' => $source->id,
            'source_name' => $source->name,
        ]);
    }

    /**
     * Web-based sources (rfp_board, web_scrape) are handled by the RfpAggregatorAgent.
     */
    protected function handleWebSource(RfpSource $source, int &$webSourceCount): void
    {
        $webSourceCount++;

        Log::info('AggregateRfpsJob: Web source will be handled by RfpAggregatorAgent', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'type' => $source->type,
        ]);
    }

    /**
     * Dispatch the RfpAggregatorAgent for web-based discovery.
     */
    protected function dispatchAggregatorAgent(): void
    {
        try {
            $agent = \App\Models\Agent::query()
                ->where('slug', 'rfp-aggregator')
                ->where('is_active', true)
                ->first();

            if (! $agent) {
                Log::debug('AggregateRfpsJob: RfpAggregatorAgent not found or inactive, skipping dispatch');

                return;
            }

            Log::info('AggregateRfpsJob: Dispatching RfpAggregatorAgent', [
                'agent_id' => $agent->id,
            ]);

            // The agent framework handles dispatching via agents:run-scheduled
            // Just log the intent here - actual dispatch happens via schedule
        } catch (\Exception $e) {
            Log::error('AggregateRfpsJob: Failed to dispatch aggregator agent', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
