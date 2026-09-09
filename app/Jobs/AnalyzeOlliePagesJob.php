<?php

namespace App\Jobs;

use App\Agents\Tools\Ollie\OllieAnalyzePageTool;
use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyzeOlliePagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $batchId,
        public array $pages,
        public string $baseUrl,
        public ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $tool = new OllieAnalyzePageTool;
        $results = [];
        $total = count($this->pages);
        $completed = 0;

        $this->broadcastProgress($completed, $total, 'Starting page analysis...');

        foreach ($this->pages as $page) {
            $pageUrl = $this->resolvePageUrl($page);
            $pageName = is_array($page) ? ($page['name'] ?? $page['path'] ?? 'Page') : $page;

            try {
                $result = $tool->execute([
                    'url' => $pageUrl,
                    'page_name' => $pageName,
                ]);

                $results[$pageName] = $result;
                $completed++;

                $this->broadcastProgress(
                    $completed,
                    $total,
                    "Analyzed: {$pageName}",
                    $pageName,
                    $result['success'] ? 'completed' : 'failed'
                );

            } catch (\Exception $e) {
                Log::error('Page analysis failed', [
                    'batch_id' => $this->batchId,
                    'page' => $pageName,
                    'url' => $pageUrl,
                    'error' => $e->getMessage(),
                ]);

                $results[$pageName] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'url' => $pageUrl,
                ];
                $completed++;

                $this->broadcastProgress($completed, $total, "Failed: {$pageName}", $pageName, 'failed');
            }
        }

        Cache::put("ollie_page_analysis:{$this->batchId}", $results, now()->addHours(2));

        $this->broadcastCompletion($results);
    }

    private function resolvePageUrl(mixed $page): string
    {
        if (is_array($page) && isset($page['url'])) {
            return $page['url'];
        }

        $path = is_array($page) ? ($page['path'] ?? '/') : $page;

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function broadcastProgress(
        int $completed,
        int $total,
        string $message,
        ?string $currentPage = null,
        ?string $pageStatus = null
    ): void {
        Cache::put("ollie_page_analysis_progress:{$this->batchId}", [
            'completed' => $completed,
            'total' => $total,
            'message' => $message,
            'current_page' => $currentPage,
            'page_status' => $pageStatus,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(1));

        broadcast(new \App\Events\OlliePageAnalysisProgress(
            $this->batchId,
            $completed,
            $total,
            $message,
            $currentPage,
            $pageStatus
        ))->toOthers();
    }

    private function broadcastCompletion(array $results): void
    {
        $successCount = collect($results)->filter(fn ($r) => $r['success'] ?? false)->count();
        $failCount = count($results) - $successCount;

        $notification = Notification::create([
            'user_id' => $this->userId,
            'type' => 'ollie_page_analysis_complete',
            'title' => 'Page Analysis Complete',
            'message' => "Analyzed {$successCount} pages".($failCount > 0 ? " ({$failCount} failed)" : ''),
            'icon' => $failCount === 0 ? '✅' : '⚠️',
            'severity' => $failCount === 0 ? 'success' : 'warning',
            'metadata' => [
                'batch_id' => $this->batchId,
                'total' => count($results),
                'success_count' => $successCount,
                'fail_count' => $failCount,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeOlliePagesJob failed', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $notification = Notification::create([
            'user_id' => $this->userId,
            'type' => 'ollie_page_analysis_failed',
            'title' => 'Page Analysis Failed',
            'message' => 'Failed to analyze pages: '.substr($exception->getMessage(), 0, 100),
            'icon' => '❌',
            'severity' => 'error',
            'metadata' => [
                'batch_id' => $this->batchId,
                'error' => $exception->getMessage(),
                'stack_trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'exception_class' => get_class($exception),
            ],
        ]);

        // Set action_url after creation so we have the ID
        $notification->update([
            'action_url' => "/system/errors/{$notification->id}",
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }
}
