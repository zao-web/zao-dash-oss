<?php

namespace App\Jobs;

use App\Events\SlackActionItemDetected;
use App\Models\SlackMessage;
use App\Services\Slack\SlackMessageAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Real-time single message analysis job.
 *
 * Dispatched for individual messages (typically from client channels)
 * for immediate AI analysis. For batch processing, use ProcessSlackMessagesJob.
 */
class AnalyzeSlackMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    public function __construct(
        public int $messageId,
        public bool $createTask = false
    ) {}

    public function handle(SlackMessageAnalyzerService $analyzer): void
    {
        $message = SlackMessage::find($this->messageId);

        if (! $message) {
            Log::warning('AnalyzeSlackMessageJob: Message not found', ['id' => $this->messageId]);

            return;
        }

        // Skip if already processed
        if ($message->processed_at !== null) {
            Log::debug('AnalyzeSlackMessageJob: Message already processed', ['id' => $this->messageId]);

            return;
        }

        try {
            $analysis = $analyzer->analyzeMessage($message);

            $hasActionItem = $analysis['has_action_item'] ?? false;
            $confidence = $analysis['confidence'] ?? 0;

            Log::info('AnalyzeSlackMessageJob: Analysis complete', [
                'message_id' => $this->messageId,
                'has_action_item' => $hasActionItem,
                'confidence' => $confidence,
                'intent' => $analysis['intent'] ?? 'unknown',
            ]);

            $message->refresh();

            if ($hasActionItem && $message->user_is_external && $message->action_item_extracted) {
                SlackActionItemDetected::dispatch(
                    $message,
                    $message->action_item_extracted,
                    $confidence
                );

                Log::info('AnalyzeSlackMessageJob: Fired SlackActionItemDetected event', [
                    'message_id' => $this->messageId,
                    'action_item' => $message->action_item_extracted,
                ]);
            }

            if ($this->createTask && $hasActionItem && $confidence >= 0.9) {
                $task = $analyzer->createTaskFromMessage($message, [
                    'urgency' => $analysis['urgency'] ?? 'normal',
                ]);

                if ($task) {
                    Log::info('AnalyzeSlackMessageJob: Task created', [
                        'task_id' => $task->id,
                        'message_id' => $this->messageId,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('AnalyzeSlackMessageJob: Analysis failed', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeSlackMessageJob: Job failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);

        // Mark the message as processed to avoid infinite retries
        SlackMessage::where('id', $this->messageId)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }
}
