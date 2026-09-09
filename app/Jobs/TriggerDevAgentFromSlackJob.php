<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\SlackMessage;
use App\Services\Agents\AgentExecutor;
use App\Services\GitHub\RepoMatcherService;
use App\Services\Slack\SlackContextGathererService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriggerDevAgentFromSlackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $messageId
    ) {}

    public function handle(
        SlackContextGathererService $contextService,
        RepoMatcherService $repoMatcher,
        AgentExecutor $executor
    ): void {
        $message = SlackMessage::with(['channel.workspace', 'client'])->find($this->messageId);

        if (! $message) {
            Log::warning('TriggerDevAgentFromSlackJob: Message not found', ['id' => $this->messageId]);

            return;
        }

        if (! $message->has_action_item || ! $message->action_item_extracted) {
            Log::debug('TriggerDevAgentFromSlackJob: No action item', ['id' => $this->messageId]);

            return;
        }

        $existingRun = AgentRun::where('trigger_metadata->slack_message_id', $this->messageId)
            ->whereIn('status', ['running', 'pending_approval', 'completed'])
            ->exists();

        if ($existingRun) {
            Log::debug('TriggerDevAgentFromSlackJob: Agent run already exists for message', ['id' => $this->messageId]);

            return;
        }

        $context = $contextService->gatherContext($message);
        $userEmail = $context['user_info']['email'] ?? null;

        $repos = $repoMatcher->findMatchingRepos($message, $userEmail);

        if ($repos->isEmpty()) {
            Log::warning('TriggerDevAgentFromSlackJob: No repos found for client', [
                'message_id' => $message->id,
                'client_id' => $message->client_id,
            ]);

            return;
        }

        $targetRepo = $repos->first();
        $allRepoNames = $repos->pluck('full_name')->toArray();

        Log::info('TriggerDevAgentFromSlackJob: Matched repos', [
            'message_id' => $message->id,
            'target_repo' => $targetRepo->full_name,
            'all_repos' => $allRepoNames,
            'user_email' => $userEmail,
        ]);

        $devAgent = Agent::where('slug', 'dev')->first();

        if (! $devAgent) {
            Log::error('TriggerDevAgentFromSlackJob: Dev agent not found');

            return;
        }

        $prompt = $this->buildPrompt($message, $context, $targetRepo, $allRepoNames);

        $config = [
            'prompt' => $prompt,
            'context' => [
                'slack_message_id' => $message->id,
                'slack_channel' => $message->channel?->name,
                'client_id' => $message->client_id,
                'client_name' => $message->client?->name,
                'repository' => $targetRepo->full_name,
                'available_repos' => $allRepoNames,
                'user_email' => $userEmail,
                'action_item' => $message->action_item_extracted,
                'chat_context' => $contextService->buildPromptContext($context),
            ],
        ];

        $run = $executor->execute(
            agent: $devAgent,
            config: $config,
            invocationSource: AgentRun::SOURCE_SLACK,
            invokedBy: "slack:{$message->channel?->name}",
            triggerMetadata: [
                'slack_message_id' => $message->id,
                'action_item' => $message->action_item_extracted,
            ]
        );

        Log::info('TriggerDevAgentFromSlackJob: Agent run created', [
            'message_id' => $message->id,
            'run_id' => $run->id,
            'status' => $run->status,
        ]);
    }

    protected function buildPrompt(SlackMessage $message, array $context, $targetRepo, array $allRepos): string
    {
        $prompt = "## Slack Support Request\n\n";
        $prompt .= "A client has reported an issue that needs investigation and a fix.\n\n";

        $prompt .= "### Action Item\n";
        $prompt .= "{$message->action_item_extracted}\n\n";

        $prompt .= "### Original Message\n";
        $prompt .= "From: {$message->user_name}";
        if (! empty($context['user_info']['email'])) {
            $prompt .= " ({$context['user_info']['email']})";
        }
        $prompt .= "\n";
        $prompt .= "Content: {$message->content}\n\n";

        $prompt .= "### Target Repository\n";
        $prompt .= "Primary: {$targetRepo->full_name}\n";
        if (count($allRepos) > 1) {
            $prompt .= 'Other client repos: '.implode(', ', array_slice($allRepos, 1))."\n";
        }
        $prompt .= "\n";

        $prompt .= "### Instructions\n";
        $prompt .= "1. Clone the repository and investigate the issue\n";
        $prompt .= "2. Identify the root cause\n";
        $prompt .= "3. Implement a fix\n";
        $prompt .= "4. Create a pull request with a clear description\n";
        $prompt .= "5. Reference this Slack message in the PR\n";

        return $prompt;
    }
}
