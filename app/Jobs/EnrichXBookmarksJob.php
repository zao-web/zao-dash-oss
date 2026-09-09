<?php

namespace App\Jobs;

use App\Models\XBookmark;
use App\Models\XCredential;
use App\Services\X\XBookmarkEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichXBookmarksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $credentialId,
        public int $batchSize = 20
    ) {}

    public function handle(XBookmarkEnricher $enricher): void
    {
        $credential = XCredential::find($this->credentialId);

        if (! $credential) {
            Log::warning('EnrichXBookmarks: credential not found', ['id' => $this->credentialId]);

            return;
        }

        $bookmarks = XBookmark::where('x_credential_id', $this->credentialId)
            ->needsEnrichment()
            ->orderBy('created_at', 'desc')
            ->limit($this->batchSize)
            ->get();

        if ($bookmarks->isEmpty()) {
            Log::info('EnrichXBookmarks: no bookmarks need enrichment', [
                'credential_id' => $this->credentialId,
            ]);

            AnalyzeXBookmarksJob::dispatch($this->credentialId);

            return;
        }

        Log::info('Enriching X bookmarks', [
            'credential_id' => $this->credentialId,
            'count' => $bookmarks->count(),
        ]);

        $enrichedCount = $enricher->enrichBatch($bookmarks);

        Log::info('X bookmarks enrichment completed', [
            'credential_id' => $this->credentialId,
            'enriched' => $enrichedCount,
            'batch_size' => $bookmarks->count(),
        ]);

        $remaining = XBookmark::where('x_credential_id', $this->credentialId)
            ->needsEnrichment()
            ->count();

        if ($remaining > 0) {
            self::dispatch($this->credentialId, $this->batchSize)
                ->delay(now()->addSeconds(10));
        } else {
            AnalyzeXBookmarksJob::dispatch($this->credentialId);
        }
    }
}
