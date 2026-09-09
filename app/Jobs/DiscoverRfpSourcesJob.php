<?php

namespace App\Jobs;

use App\Models\RfpSource;
use App\Services\AI\ClaudeCliService;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Discover new RFP sources using AI-powered web research.
 *
 * Searches for RFP boards, listing sites, and email newsletters
 * matching the agency's target industries, then validates each
 * candidate has active RFPs before saving as a source.
 */
class DiscoverRfpSourcesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @param array<int, string> $industries */
    public function __construct(
        public array $industries = [],
    ) {
        $this->onQueue('agents');
    }

    public function handle(ClaudeCliService $claude, RfpSlackNotifier $notifier): void
    {
        $industries = $this->industries ?: [
            'tourism', 'destination marketing', 'DMO', 'convention and visitors bureau',
            'municipality', 'city government', 'county government',
            'nonprofit', 'community foundation',
            'education', 'university', 'school district',
        ];

        $existingUrls = RfpSource::pluck('url')->filter()->values()->toArray();
        $existingNames = RfpSource::pluck('name')->map(fn ($n) => strtolower($n))->toArray();

        $industryList = implode(', ', $industries);

        $systemPrompt = <<<'PROMPT'
You are a research assistant helping a web development agency find RFP (Request for Proposal) opportunities.
Return ONLY valid JSON. No explanations, no markdown fences — just the raw JSON array.
PROMPT;

        $prompt = <<<PROMPT
Find 10-15 high-quality, FREE sources for web development RFP opportunities targeting these industries: {$industryList}.

Focus on:
- Government procurement portals (state, county, city)
- DMO/tourism organization bidding pages
- Nonprofit and foundation RFP bulletin boards
- RSS feeds or email newsletters that aggregate RFPs
- LinkedIn groups or association pages that share RFPs
- Specific websites known to post web/digital RFPs regularly

Exclude: SAM.gov federal procurement, paid subscription services, anything requiring a paid account.

Already have these URLs (skip them):
{$existingUrls}

Return a JSON array of objects with these fields:
- "name": Human-readable source name
- "type": one of: rfp_board, rss_feed, web_scrape, email_sender
- "url": The direct URL to the RFP listings page or RSS feed
- "description": 1 sentence on what types of RFPs appear here
- "industries": array of industries this source covers
- "check_frequency_minutes": suggested check interval (240 for boards, 60 for RSS)
- "confidence": 0.0-1.0 how confident you are this has active relevant RFPs

Only include sources you are highly confident (>0.7) actually exist and have relevant RFPs.
PROMPT;

        $existingUrlsStr = implode("\n", $existingUrls);
        $prompt = str_replace('{$existingUrls}', $existingUrlsStr, $prompt);

        Log::info('DiscoverRfpSourcesJob: requesting AI source discovery', [
            'industries' => $industries,
        ]);

        $candidates = $claude->messageJson($prompt, $systemPrompt, 'sonnet', 120);

        if (! $candidates || ! is_array($candidates)) {
            Log::warning('DiscoverRfpSourcesJob: no valid candidates returned from AI');

            return;
        }

        $added = [];
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $url = $candidate['url'] ?? null;
            $name = $candidate['name'] ?? null;
            $confidence = (float) ($candidate['confidence'] ?? 0);

            if (! $url || ! $name || $confidence < 0.7) {
                $skipped++;

                continue;
            }

            // Skip if URL already exists
            if (in_array($url, $existingUrls, true)) {
                $skipped++;

                continue;
            }

            // Skip if very similar name already exists
            if (in_array(strtolower($name), $existingNames, true)) {
                $skipped++;

                continue;
            }

            // Quick validation: confirm the URL is reachable
            if (! $this->isReachable($url)) {
                Log::debug('DiscoverRfpSourcesJob: URL not reachable, skipping', ['url' => $url]);
                $skipped++;

                continue;
            }

            $source = RfpSource::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4),
                'type' => $candidate['type'] ?? 'rfp_board',
                'url' => $url,
                'config' => ['description' => $candidate['description'] ?? '', 'industries' => $candidate['industries'] ?? []],
                'filters' => [],
                'check_frequency_minutes' => $candidate['check_frequency_minutes'] ?? 240,
                'is_active' => true,
                'total_opportunities_found' => 0,
            ]);

            $added[] = $source;
            $existingUrls[] = $url;
            $existingNames[] = strtolower($name);

            Log::info('DiscoverRfpSourcesJob: added new source', [
                'source_id' => $source->id,
                'name' => $name,
                'url' => $url,
                'confidence' => $confidence,
            ]);
        }

        Log::info('DiscoverRfpSourcesJob: discovery complete', [
            'added' => count($added),
            'skipped' => $skipped,
        ]);

        if (! empty($added)) {
            $notifier->notifySourcesDiscovered($added);
        }
    }

    private function isReachable(string $url): bool
    {
        try {
            $response = Http::timeout(8)->head($url);

            return $response->successful() || $response->status() < 500;
        } catch (\Exception) {
            return false;
        }
    }
}
