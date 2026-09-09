<?php

namespace App\Jobs;

use App\Models\RfpProposal;
use App\Services\AI\ClaudeCliService;
use App\Services\Slack\SlackApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleProposalSlackRevisionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $proposalId,
        public readonly string $instruction,
        public readonly string $threadTs,
        public readonly string $channelId,
    ) {
        $this->onQueue('slack-mentions');
    }

    public function handle(ClaudeCliService $claude, SlackApiService $slack): void
    {
        $proposal = RfpProposal::with('opportunity')->find($this->proposalId);

        if (! $proposal) {
            Log::warning('HandleProposalSlackRevisionJob: proposal not found', ['id' => $this->proposalId]);

            return;
        }

        // Post a "working on it" acknowledgement immediately
        try {
            $slack->postMessageDirect($this->channelId, '_Revising proposal..._', $this->threadTs);
        } catch (\Exception) {
            // Non-fatal — keep going
        }

        $rfp = $proposal->opportunity;
        $currentSummary = $proposal->executive_summary ?? '';
        $currentContent = mb_substr($proposal->full_content ?? '', 0, 6000);
        $sections = collect($proposal->proposal_sections ?? [])
            ->map(fn ($s) => "**{$s['title']}**: ".mb_substr($s['content'] ?? '', 0, 300))
            ->join("\n");

        $prompt = <<<PROMPT
You are revising an RFP proposal. Apply the user's instruction and return ONLY a JSON object — no prose, no markdown wrapper.

## Current Proposal
Opportunity: {$rfp->title} ({$rfp->issuing_organization})
Executive Summary: {$currentSummary}
Sections: {$sections}
Full Content (excerpt): {$currentContent}

## User's Revision Instruction
{$this->instruction}

## Required JSON Output
Return a JSON object with ONLY the fields that need to change. Valid keys:
- "executive_summary": string (if the summary needs updating)
- "full_content": string (if the full body needs updating)
- "sections": [{"title": "...", "content": "..."}] (if specific sections need updating)
- "summary_of_changes": string (one sentence describing what changed — shown to user in Slack)

Only include keys that actually changed. Do not include unchanged content.
PROMPT;

        try {
            $result = $claude->message($prompt, null, 'sonnet', 4096, 120);
            $raw = $result['content'] ?? '';

            // Strip markdown code fences if present
            $json = preg_replace('/^```(?:json)?\s*/m', '', $raw);
            $json = preg_replace('/\s*```$/m', '', $json);
            $data = json_decode(trim($json), true);

            if (! is_array($data)) {
                throw new \RuntimeException('Claude returned non-JSON: '.mb_substr($raw, 0, 200));
            }

            $updates = [];
            $changed = [];

            if (isset($data['executive_summary'])) {
                $updates['executive_summary'] = $data['executive_summary'];
                $changed[] = 'Executive Summary';
            }

            if (isset($data['full_content'])) {
                $updates['full_content'] = $data['full_content'];
                $updates['pdf_path'] = null;
                $changed[] = 'Full Content';
            }

            if (! empty($data['sections'])) {
                $existing = $proposal->proposal_sections ?? [];
                foreach ($data['sections'] as $updated) {
                    $found = false;
                    foreach ($existing as &$section) {
                        if (strtolower($section['title'] ?? '') === strtolower($updated['title'] ?? '')) {
                            $section['content'] = $updated['content'];
                            $found = true;
                            $changed[] = $updated['title'];
                            break;
                        }
                    }
                    unset($section);
                    if (! $found) {
                        $existing[] = $updated;
                        $changed[] = $updated['title'].' (new)';
                    }
                }
                $updates['proposal_sections'] = $existing;
                $updates['pdf_path'] = null;
            }

            if (empty($updates)) {
                $this->replyInThread($slack, "I reviewed your instruction but didn't find specific fields to update. Try being more explicit, e.g. \"rewrite the executive summary to emphasize mobile-first design\".");

                return;
            }

            $proposal->update($updates);

            $reviewUrl = config('app.url')."/rfp/{$rfp->id}/proposals/{$proposal->id}/review";
            $changedList = implode(', ', $changed);
            $summary = $data['summary_of_changes'] ?? "Updated: {$changedList}.";

            $this->replyInThread($slack, ":white_check_mark: *Done.* {$summary}\n\nUpdated: _{$changedList}_\n<{$reviewUrl}|View revised proposal>");

            Log::info('HandleProposalSlackRevisionJob: revised proposal', [
                'proposal_id' => $this->proposalId,
                'changed' => $changed,
            ]);
        } catch (\Exception $e) {
            Log::error('HandleProposalSlackRevisionJob: failed', [
                'proposal_id' => $this->proposalId,
                'error' => $e->getMessage(),
            ]);

            $this->replyInThread($slack, ':x: Revision failed: `'.mb_substr($e->getMessage(), 0, 200).'`');
        }
    }

    private function replyInThread(SlackApiService $slack, string $text): void
    {
        try {
            $slack->postMessageDirect($this->channelId, $text, $this->threadTs);
        } catch (\Exception $e) {
            Log::warning('HandleProposalSlackRevisionJob: failed to reply in thread', ['error' => $e->getMessage()]);
        }
    }
}
