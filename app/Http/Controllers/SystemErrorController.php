<?php

namespace App\Http\Controllers;

use App\DTOs\NightwatchError;
use App\Jobs\SelfHealingJob;
use App\Models\Notification;
use App\Models\SelfHealingAttempt;
use App\Services\SelfHealing\SelfHealingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SystemErrorController extends Controller
{
    public function __construct(
        protected SelfHealingService $selfHealingService
    ) {}

    /**
     * Show error details from a notification.
     */
    public function show(Notification $notification)
    {
        // Only show error-type notifications
        if (! in_array($notification->type, ['ollie_page_analysis_failed', 'system_error', 'job_failed'])) {
            abort(404, 'Not an error notification');
        }

        $metadata = $notification->metadata ?? [];

        // Check for existing self-healing attempts for this error
        $healingAttempts = [];
        if (! empty($metadata['error'])) {
            $signature = md5(implode('|', [
                $metadata['exception_class'] ?? 'Exception',
                $metadata['error'],
                $metadata['source_job'] ?? '',
            ]));

            $healingAttempts = SelfHealingAttempt::where('error_signature', $signature)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn ($attempt) => [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                    'commit_sha' => $attempt->commit_sha,
                    'commit_url' => $attempt->commit_url,
                    'fix_description' => $attempt->fix_description,
                    'failure_reason' => $attempt->failure_reason,
                    'created_at' => $attempt->created_at->diffForHumans(),
                    'completed_at' => $attempt->completed_at?->diffForHumans(),
                ]);
        }

        // Get self-healing status
        $selfHealingStatus = [
            'enabled' => $this->selfHealingService->isEnabled(),
            'rate_limit' => $this->selfHealingService->getRateLimitStatus(),
            'circuit_breaker_open' => $this->selfHealingService->isCircuitBreakerOpen(),
        ];

        return Inertia::render('System/ErrorDetails', [
            'notification' => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'severity' => $notification->severity,
                'created_at' => $notification->created_at->format('M d, Y H:i:s'),
                'created_at_human' => $notification->created_at->diffForHumans(),
            ],
            'error' => [
                'message' => $metadata['error'] ?? $notification->message,
                'exception_class' => $metadata['exception_class'] ?? null,
                'file' => $metadata['file'] ?? null,
                'line' => $metadata['line'] ?? null,
                'stack_trace' => $metadata['stack_trace'] ?? null,
                'batch_id' => $metadata['batch_id'] ?? null,
                'source_job' => $metadata['source_job'] ?? null,
            ],
            'healingAttempts' => $healingAttempts,
            'selfHealingStatus' => $selfHealingStatus,
        ]);
    }

    /**
     * Trigger self-healing for an error.
     */
    public function triggerFix(Request $request, Notification $notification)
    {
        $metadata = $notification->metadata ?? [];

        if (empty($metadata['error'])) {
            return back()->with('error', 'No error information available for this notification.');
        }

        // Check if self-healing is enabled
        if (! $this->selfHealingService->isEnabled()) {
            return back()->with('error', 'Self-healing is not enabled. Set SELF_HEALING_ENABLED=true in your environment.');
        }

        // Check rate limits
        if ($this->selfHealingService->hasExceededRateLimit()) {
            return back()->with('error', 'Self-healing rate limit exceeded. Please wait before trying again.');
        }

        // Check circuit breaker
        if ($this->selfHealingService->isCircuitBreakerOpen()) {
            return back()->with('error', 'Self-healing circuit breaker is open due to recent failures. Please wait for cooldown.');
        }

        // Create the NightwatchError DTO
        $error = new NightwatchError(
            exceptionClass: $metadata['exception_class'] ?? 'Exception',
            message: $metadata['error'],
            file: $metadata['file'] ?? null,
            line: $metadata['line'] ?? null,
            sourceJob: $metadata['source_job'] ?? 'AnalyzeOlliePagesJob',
            environment: config('app.env'),
            occurrenceCount: 1,
        );

        // Check if already being fixed
        if ($this->selfHealingService->isAlreadyBeingFixed($error)) {
            return back()->with('warning', 'This error is already being processed by self-healing.');
        }

        // Check if recently fixed
        if ($this->selfHealingService->wasRecentlyFixed($error)) {
            return back()->with('info', 'This error was recently addressed. Check the healing attempts below.');
        }

        // Dispatch the self-healing job
        SelfHealingJob::dispatch($error);

        return back()->with('success', 'Self-healing job dispatched. The system will attempt to fix this error automatically.');
    }
}
