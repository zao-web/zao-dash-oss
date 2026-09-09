<?php

namespace App\Services\PersonalFinance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class BookkeepingCleanupStatusService
{
    protected int $ttlSeconds = 86400;

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     auto_applied_count: int,
     *     suggested_count: int,
     *     unresolved_count: int|null,
     *     queued_at: string|null,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     scope: string,
     *     error: string|null,
     * }
     */
    public function get(int $userId, int $taxYear, bool $businessOnly = true): array
    {
        return Cache::get(
            $this->cacheKey($userId, $taxYear, $businessOnly),
            $this->defaultStatus($businessOnly)
        );
    }

    public function isActive(int $userId, int $taxYear, bool $businessOnly = true): bool
    {
        return in_array($this->get($userId, $taxYear, $businessOnly)['status'], ['queued', 'running'], true);
    }

    public function markQueued(
        int $userId,
        int $taxYear,
        bool $businessOnly,
        int $autoAppliedCount,
        int $unresolvedCount
    ): void {
        $status = $this->defaultStatus($businessOnly);
        $status['status'] = 'queued';
        $status['status_label'] = 'Cleanup queued';
        $status['summary'] = "Queued AI bookkeeping cleanup for {$unresolvedCount} remaining transaction(s).";
        $status['auto_applied_count'] = $autoAppliedCount;
        $status['unresolved_count'] = $unresolvedCount;
        $status['queued_at'] = now()->toIso8601String();

        $this->store($userId, $taxYear, $businessOnly, $status);
    }

    public function markStarted(int $userId, int $taxYear, bool $businessOnly): void
    {
        $status = $this->get($userId, $taxYear, $businessOnly);
        $status['status'] = 'running';
        $status['status_label'] = 'Cleanup running';
        $status['summary'] = 'AI bookkeeping cleanup is processing in the background.';
        $status['started_at'] = now()->toIso8601String();

        $this->store($userId, $taxYear, $businessOnly, $status);
    }

    /**
     * @param  array{auto_applied_count: int, suggested_count?: int, unresolved_count: int}  $result
     */
    public function markCompleted(int $userId, int $taxYear, bool $businessOnly, array $result): void
    {
        $suggestedCount = (int) ($result['suggested_count'] ?? 0);
        $status = $this->defaultStatus($businessOnly);
        $status['status'] = 'completed';
        $status['status_label'] = 'Cleanup finished';
        $status['summary'] = "Applied {$result['auto_applied_count']} categorization update(s)"
            .($suggestedCount > 0 ? " and left {$suggestedCount} suggestion(s) for review." : '.');
        $status['auto_applied_count'] = (int) $result['auto_applied_count'];
        $status['suggested_count'] = $suggestedCount;
        $status['unresolved_count'] = (int) $result['unresolved_count'];
        $status['finished_at'] = now()->toIso8601String();

        $this->store($userId, $taxYear, $businessOnly, $status);
    }

    public function markFailed(int $userId, int $taxYear, bool $businessOnly, string $error): void
    {
        $status = $this->get($userId, $taxYear, $businessOnly);
        $status['status'] = 'failed';
        $status['status_label'] = 'Cleanup failed';
        $status['summary'] = 'The background bookkeeping cleanup failed before it could finish.';
        $status['finished_at'] = now()->toIso8601String();
        $status['error'] = $error;

        $this->store($userId, $taxYear, $businessOnly, $status);
    }

    protected function cacheKey(int $userId, int $taxYear, bool $businessOnly): string
    {
        $scope = $businessOnly ? 'business' : 'all';

        return "bookkeeping_cleanup_status:{$userId}:{$taxYear}:{$scope}";
    }

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     auto_applied_count: int,
     *     suggested_count: int,
     *     unresolved_count: int|null,
     *     queued_at: string|null,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     scope: string,
     *     error: string|null,
     * }
     */
    protected function defaultStatus(bool $businessOnly): array
    {
        return [
            'status' => 'idle',
            'status_label' => 'Not running',
            'summary' => 'No background bookkeeping cleanup is running.',
            'auto_applied_count' => 0,
            'suggested_count' => 0,
            'unresolved_count' => null,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'scope' => $businessOnly ? 'business' : 'all',
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function store(int $userId, int $taxYear, bool $businessOnly, array $status): void
    {
        Cache::put(
            $this->cacheKey($userId, $taxYear, $businessOnly),
            $status,
            Carbon::now()->addSeconds($this->ttlSeconds)
        );
    }
}
