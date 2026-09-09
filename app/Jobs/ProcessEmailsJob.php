<?php

namespace App\Jobs;

use App\Models\Email;
use App\Services\Email\EmailAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process client emails with AI analysis.
 *
 * This job:
 * 1. Analyzes unprocessed client emails for action items
 * 2. Determines urgency and sentiment
 * 3. Auto-matches emails to projects
 * 4. Optionally creates tasks from high-confidence action items
 */
class ProcessEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum emails to process per run.
     */
    private const MAX_EMAILS = 50;

    /**
     * Minimum confidence to auto-create tasks.
     */
    private const AUTO_TASK_CONFIDENCE = 0.75;

    public function __construct(
        public ?int $clientId = null,
        public bool $autoCreateTasks = false,
        public int $emailLimit = self::MAX_EMAILS
    ) {}

    public function handle(EmailAnalyzerService $analyzer): void
    {
        Log::info('Starting email processing', [
            'client_id' => $this->clientId,
            'auto_create_tasks' => $this->autoCreateTasks,
        ]);

        $stats = [
            'emails_analyzed' => 0,
            'action_items_found' => 0,
            'tasks_created' => 0,
            'projects_matched' => 0,
        ];

        try {
            $emails = $this->getUnprocessedEmails();

            if ($emails->isEmpty()) {
                Log::info('No unprocessed client emails found');

                return;
            }

            foreach ($emails as $email) {
                $hadProject = $email->project_id !== null;

                $analysis = $analyzer->analyzeEmail($email);
                $stats['emails_analyzed']++;

                // Check if project was matched
                $email->refresh();
                if (! $hadProject && $email->project_id) {
                    $stats['projects_matched']++;
                }

                if ($analysis['action_required'] ?? false) {
                    $stats['action_items_found']++;

                    // Auto-create task if confidence is high enough
                    if ($this->autoCreateTasks) {
                        $confidence = $analysis['confidence'] ?? 0;

                        if ($confidence >= self::AUTO_TASK_CONFIDENCE) {
                            $task = $analyzer->createTaskFromEmail($email, $analysis);

                            if ($task) {
                                $stats['tasks_created']++;
                            }
                        }
                    }
                }
            }

            Log::info('Email processing complete', $stats);
        } catch (\Exception $e) {
            Log::error('Email processing failed', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);

            throw $e;
        }
    }

    /**
     * Get unprocessed client emails to analyze.
     */
    protected function getUnprocessedEmails()
    {
        $query = Email::needsAnalysis()
            ->orderBy('received_at', 'desc')
            ->limit($this->emailLimit);

        if ($this->clientId) {
            $query->where('client_id', $this->clientId);
        }

        // Prioritize emails with attachments and recent emails
        // Cast JSON to text for PostgreSQL comparison
        $query->orderByRaw("
            CASE
                WHEN attachments IS NOT NULL AND attachments::text != '[]' THEN 0
                ELSE 1
            END
        ");

        return $query->get();
    }
}
