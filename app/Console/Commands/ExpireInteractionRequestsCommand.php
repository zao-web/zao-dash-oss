<?php

namespace App\Console\Commands;

use App\Events\InteractionExpired;
use App\Models\AgentRun;
use App\Models\InteractionRequest;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireInteractionRequestsCommand extends Command
{
    protected $signature = 'interactions:expire
        {--dry-run : Show what would be expired without actually expiring}
        {--slack : Also update Slack messages for expired interactions}';

    protected $description = 'Expire pending interaction requests that have passed their deadline and fail associated agent runs';

    public function handle(SlackBotResponseService $slackService): int
    {
        $this->info('Checking for expired interaction requests...');

        // Find expired interactions that haven't been responded to
        $expiredInteractions = InteractionRequest::with(['agentRun'])
            ->whereNull('responded_at')
            ->where('expires_at', '<=', now())
            ->whereHas('agentRun', function ($query) {
                $query->where('status', 'awaiting_input');
            })
            ->get();

        if ($expiredInteractions->isEmpty()) {
            $this->info('No expired interactions found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Run ID', 'Question', 'Expired At', 'Agent Run Status'],
                $expiredInteractions->map(fn ($i) => [
                    $i->id,
                    $i->agent_run_id,
                    \Illuminate\Support\Str::limit($i->question, 50),
                    $i->expires_at->diffForHumans(),
                    $i->agentRun?->status ?? 'N/A',
                ])
            );

            $this->warn("Would expire {$expiredInteractions->count()} interactions (dry run)");

            return self::SUCCESS;
        }

        $expiredCount = 0;
        $failedRunCount = 0;

        foreach ($expiredInteractions as $interaction) {
            try {
                // Broadcast expiration event
                broadcast(new InteractionExpired($interaction));
                $expiredCount++;

                // Update the agent run to failed
                if ($interaction->agentRun && $interaction->agentRun->status === 'awaiting_input') {
                    $interaction->agentRun->update([
                        'status' => AgentRun::STATUS_FAILED,
                        'error' => 'Interaction request expired without response',
                        'output' => $interaction->agentRun->output."\n\n---\n\n**Expired**: The interaction request timed out without receiving a response.",
                        'finished_at' => now(),
                    ]);

                    $failedRunCount++;

                    Log::info('ExpireInteractionRequestsCommand: Expired interaction and failed run', [
                        'interaction_id' => $interaction->id,
                        'run_id' => $interaction->agent_run_id,
                        'question' => \Illuminate\Support\Str::limit($interaction->question, 100),
                    ]);

                    // Update Slack message if requested
                    if ($this->option('slack')) {
                        $this->updateSlackMessage($slackService, $interaction);
                    }
                }
            } catch (\Exception $e) {
                $this->error("Failed to expire interaction #{$interaction->id}: {$e->getMessage()}");

                Log::error('ExpireInteractionRequestsCommand: Failed to expire interaction', [
                    'interaction_id' => $interaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$expiredCount} interactions, failed {$failedRunCount} agent runs.");

        return self::SUCCESS;
    }

    /**
     * Update the Slack message to indicate expiration.
     */
    private function updateSlackMessage(SlackBotResponseService $slackService, InteractionRequest $interaction): void
    {
        $context = $interaction->context ?? [];
        $slackTs = $context['slack_ts'] ?? null;
        $slackChannel = $context['slack_channel'] ?? null;

        if (! $slackTs || ! $slackChannel) {
            return;
        }

        try {
            // Build expired message blocks
            $blocks = [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Agent Question* (Expired)\n\n_{$interaction->question}_",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => ':warning: This interaction expired without a response at '.now()->format('M j, Y g:i A'),
                        ],
                    ],
                ],
            ];

            $slackService->updateMessage(
                $slackChannel,
                $slackTs,
                'Agent question expired without a response.',
                $blocks
            );

            $this->line("  Updated Slack message for interaction #{$interaction->id}");
        } catch (\Exception $e) {
            $this->warn("  Failed to update Slack message for #{$interaction->id}: {$e->getMessage()}");
        }
    }
}
