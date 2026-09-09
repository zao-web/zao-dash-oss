<?php

namespace App\Jobs;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSeoContentQueueJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum concurrent page generations to prevent resource exhaustion.
     */
    private const MAX_CONCURRENT_GENERATIONS = 3;

    /**
     * Maximum pages to process per run.
     */
    private const MAX_PAGES_PER_RUN = 5;

    public function __construct(
        private int $maxPages = self::MAX_PAGES_PER_RUN
    ) {}

    public function handle(): void
    {
        // Check how many pages are currently generating
        $generating = SeoPage::where('status', SeoPageStatus::Generating)->count();

        if ($generating >= self::MAX_CONCURRENT_GENERATIONS) {
            Log::info('ProcessSeoContentQueueJob: Max concurrent generations reached', [
                'generating' => $generating,
                'max' => self::MAX_CONCURRENT_GENERATIONS,
            ]);

            return;
        }

        // Calculate how many slots we have available
        $availableSlots = min(
            self::MAX_CONCURRENT_GENERATIONS - $generating,
            $this->maxPages
        );

        // Get next queued pages ordered by priority
        $pages = SeoPage::queued()
            ->limit($availableSlots)
            ->get();

        if ($pages->isEmpty()) {
            Log::debug('ProcessSeoContentQueueJob: No queued pages to process');

            return;
        }

        Log::info('ProcessSeoContentQueueJob: Processing queued pages', [
            'count' => $pages->count(),
            'available_slots' => $availableSlots,
        ]);

        foreach ($pages as $page) {
            $this->dispatchPageGeneration($page);
        }
    }

    private function dispatchPageGeneration(SeoPage $page): void
    {
        // Mark as generating before dispatching to prevent race conditions
        $page->update([
            'status' => SeoPageStatus::Generating,
            'generation_started_at' => now(),
        ]);

        // Dispatch the content generation job
        GenerateSeoContentJob::dispatch(
            title: $page->meta_title,
            keyword: $page->target_keyword,
            playbook: $page->playbook ?? 'Vertical',
            urlSlug: ltrim($page->page_url, '/'),
            proprietaryData: $this->getProprietaryData($page),
            priority: $page->priority,
            orchestratorRunId: null
        );

        Log::info('ProcessSeoContentQueueJob: Dispatched generation', [
            'page_id' => $page->id,
            'keyword' => $page->target_keyword,
            'playbook' => $page->playbook,
            'priority' => $page->priority,
        ]);
    }

    private function getProprietaryData(SeoPage $page): array
    {
        // Gather proprietary data based on playbook type
        // This data makes content unique and valuable
        return [
            'projects_count' => \App\Models\Project::count(),
            'clients_count' => \App\Models\Client::where('status', 'active')->count(),
            'years_experience' => 12,
            'team_size' => 15,
            'technologies' => ['Laravel', 'WordPress', 'React Native'],
            'industries' => ['Healthcare', 'Fintech', 'SaaS', 'E-commerce'],
            'playbook' => $page->playbook,
            'target_keyword' => $page->target_keyword,
        ];
    }

    public function tags(): array
    {
        return ['seo-orchestrator'];
    }
}
