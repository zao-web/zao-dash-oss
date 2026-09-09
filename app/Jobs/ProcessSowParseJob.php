<?php

namespace App\Jobs;

use App\Services\SowParsingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessSowParseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  string  $parseId  Unique ID for this parse request
     * @param  string  $combinedContent  Pre-extracted text from all documents
     */
    public function __construct(
        public string $parseId,
        public string $combinedContent
    ) {}

    public function handle(SowParsingService $service): void
    {
        Log::info('ProcessSowParseJob: starting AI extraction', [
            'parse_id' => $this->parseId,
            'content_length' => strlen($this->combinedContent),
        ]);

        Cache::put("sow_parse:{$this->parseId}", ['status' => 'processing'], now()->addMinutes(30));

        try {
            $startTime = microtime(true);
            $result = $service->extractWithAI($this->combinedContent);
            $elapsed = round(microtime(true) - $startTime, 1);

            if (! $result) {
                Cache::put("sow_parse:{$this->parseId}", [
                    'status' => 'failed',
                    'error' => 'Could not extract project data from the provided documents. Please check the files and try again.',
                ], now()->addMinutes(30));

                return;
            }

            Cache::put("sow_parse:{$this->parseId}", [
                'status' => 'completed',
                'data' => $result,
            ], now()->addMinutes(30));

            Log::info('ProcessSowParseJob: extraction complete', [
                'parse_id' => $this->parseId,
                'elapsed_seconds' => $elapsed,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessSowParseJob: extraction failed', [
                'parse_id' => $this->parseId,
                'error' => $e->getMessage(),
            ]);

            Cache::put("sow_parse:{$this->parseId}", [
                'status' => 'failed',
                'error' => 'AI extraction failed. Please try again.',
            ], now()->addMinutes(30));
        }
    }
}
