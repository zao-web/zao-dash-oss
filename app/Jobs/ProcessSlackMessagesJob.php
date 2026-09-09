<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\SlackMessage;
use App\Models\SlackThread;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackChannelMatcherService;
use App\Services\Slack\SlackMessageAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Slack messages with AI analysis.
 *
 * This job:
 * 1. Auto-links channels to clients
 * 2. Analyzes unprocessed messages for action items
 * 3. Summarizes threads that need it
 * 4. Optionally creates tasks from high-confidence action items
 */
class ProcessSlackMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    /**
     * Maximum messages to process per run.
     */
    private const MAX_MESSAGES = 100;

    /**
     * Maximum threads to summarize per run.
     */
    private const MAX_THREADS = 20;

    /**
     * Minimum confidence to auto-create tasks.
     * 0.75 balances catching real action items vs false positives.
     */
    private const AUTO_TASK_CONFIDENCE = 0.75;

    public function __construct(
        public ?int $workspaceId = null,
        public ?int $channelId = null,
        public bool $autoCreateTasks = false,
        public int $messageLimit = self::MAX_MESSAGES
    ) {}

    public function handle(
        SlackMessageAnalyzerService $analyzer,
        SlackChannelMatcherService $matcher
    ): void {
        Log::info('Starting Slack message processing', [
            'workspace_id' => $this->workspaceId,
            'channel_id' => $this->channelId,
            'auto_create_tasks' => $this->autoCreateTasks,
        ]);

        $stats = [
            'channels_linked' => 0,
            'messages_analyzed' => 0,
            'action_items_found' => 0,
            'tasks_created' => 0,
            'threads_summarized' => 0,
            'repeated_requests' => 0,
        ];

        try {
            // Step 1: Auto-link channels to clients
            $linkResult = $matcher->autoLinkAllChannels();
            $stats['channels_linked'] = $linkResult['linked'];

            Log::info('Channel auto-linking complete', [
                'linked' => $linkResult['linked'],
                'suggestions' => count($linkResult['suggestions']),
            ]);

            // Step 2: Process unprocessed messages
            $messages = $this->getUnprocessedMessages();

            if ($messages->isNotEmpty()) {
                $this->initSyncTracking(
                    $this->workspaceId
                        ? SlackWorkspace::find($this->workspaceId)
                        : SlackWorkspace::where('is_primary', true)->first()
                );

                $totalMessages = $messages->count();
                $processed = 0;

                foreach ($messages->chunk(10) as $batch) {
                    $results = $analyzer->analyzeMessages($batch->pluck('id')->toArray());

                    foreach ($results as $messageId => $analysis) {
                        $stats['messages_analyzed']++;
                        $processed++;

                        if ($analysis['has_action_item'] ?? false) {
                            $stats['action_items_found']++;

                            // Auto-create task if confidence is high enough
                            if ($this->autoCreateTasks) {
                                $message = SlackMessage::find($messageId);
                                $confidence = $analysis['confidence'] ?? 0;

                                if ($message && $confidence >= self::AUTO_TASK_CONFIDENCE) {
                                    $task = $analyzer->createTaskFromMessage($message, [
                                        'urgency' => $analysis['urgency'] ?? 'normal',
                                    ]);

                                    if ($task) {
                                        $stats['tasks_created']++;
                                    }
                                }
                            }
                        }

                        if ($analysis['is_repeated_request'] ?? false) {
                            $stats['repeated_requests']++;
                        }
                    }

                    // Update progress
                    $progress = (int) (($processed / $totalMessages) * 70);
                    $this->updateSyncProgress($progress, "Analyzed {$processed}/{$totalMessages} messages");
                }
            }

            // Step 3: Summarize threads that need it
            $threads = $this->getThreadsNeedingSummarization();
            $threadCount = $threads->count();

            foreach ($threads as $index => $thread) {
                $result = $analyzer->summarizeThread($thread);

                if (! isset($result['error'])) {
                    $stats['threads_summarized']++;
                }

                $progress = 70 + (int) (($index + 1) / max(1, $threadCount) * 25);
                $this->updateSyncProgress($progress, "Summarized {$index}/{$threadCount} threads");
            }

            $this->completeSyncTracking();

            Log::info('Slack message processing complete', $stats);
        } catch (\Exception $e) {
            Log::error('Slack message processing failed', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);

            if (isset($this->syncProgress)) {
                $this->failSyncTracking($e);
            }

            throw $e;
        }
    }

    /**
     * Get unprocessed messages to analyze.
     */
    protected function getUnprocessedMessages()
    {
        $query = SlackMessage::whereNull('processed_at')
            ->whereRaw('LENGTH(content) >= 10')
            ->orderBy('created_at', 'desc')
            ->limit($this->messageLimit);

        // Filter by workspace if specified
        if ($this->workspaceId) {
            $query->where('workspace_id', $this->workspaceId);
        }

        // Filter by channel if specified
        if ($this->channelId) {
            $query->where('channel_id', $this->channelId);
        }

        // Prioritize client channels and external user messages
        $query->orderByRaw('
            CASE
                WHEN user_is_external = true THEN 0
                WHEN client_id IS NOT NULL THEN 1
                ELSE 2
            END
        ');

        return $query->get();
    }

    /**
     * Get threads that need summarization.
     */
    protected function getThreadsNeedingSummarization()
    {
        $query = SlackThread::where('message_count', '>=', 5)
            ->whereNull('summary')
            ->orderBy('last_reply_at', 'desc')
            ->limit(self::MAX_THREADS);

        if ($this->channelId) {
            $query->where('channel_id', $this->channelId);
        } elseif ($this->workspaceId) {
            $query->whereHas('channel', function ($q) {
                $q->whereHas('workspace', function ($wq) {
                    $wq->where('id', $this->workspaceId);
                });
            });
        }

        // Prioritize threads with external participants
        $query->orderByRaw('
            CASE WHEN has_external_participant = true THEN 0 ELSE 1 END
        ');

        return $query->get();
    }
}
