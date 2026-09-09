<?php

namespace App\Services\Slack;

use App\Jobs\RunAgentJob;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\AgentRun;
use App\Models\SlackThreadContext;
use Illuminate\Support\Str;

class SlackThreadRunService
{
    public function restartRun(
        AgentRun $run,
        string $userId,
        string $teamId,
        string $channelId,
        ?SlackThreadContext $threadContext = null
    ): AgentRun {
        $context = $run->context ?? [];
        $context['slack'] = array_merge($context['slack'] ?? [], array_filter([
            'workspace_id' => $teamId,
            'channel_id' => $channelId,
            'thread_ts' => $threadContext?->thread_ts ?? ($context['slack']['thread_ts'] ?? null),
            'user_id' => $userId,
        ], fn ($value) => ! is_null($value) && $value !== ''));

        $newRun = $run->agent->runs()->create([
            'session_id' => (string) Str::uuid(),
            'status' => AgentRun::STATUS_RUNNING,
            'task' => $run->task,
            'context' => $context,
            'project_id' => $run->project_id,
            'task_id' => $run->task_id,
            'trigger_metadata' => array_merge($run->trigger_metadata ?? [], [
                'restarted_from_run_id' => $run->id,
            ]),
            'invocation_source' => AgentRun::SOURCE_SLACK,
            'invoked_by' => $userId,
            'started_at' => now(),
        ]);

        if ($threadContext) {
            $threadContext->update([
                'agent_run_id' => $newRun->id,
                'current_state' => 'processing',
                'last_interaction_at' => now(),
                'context_data' => array_merge($threadContext->context_data ?? [], [
                    'agent_run_id' => $newRun->id,
                ]),
            ]);
        }

        if ($this->shouldRunInteractively($run)) {
            RunInteractiveAgentJob::dispatch($newRun);
        } else {
            RunAgentJob::dispatch($newRun);
        }

        return $newRun;
    }

    public function shouldRunInteractively(AgentRun $run): bool
    {
        return ($run->agent?->slug === 'compound-engineering')
            || isset(($run->context ?? [])['skill'])
            || $run->status === AgentRun::STATUS_AWAITING_INPUT;
    }
}
