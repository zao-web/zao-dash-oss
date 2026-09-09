<?php

namespace App\Services\Rfp;

use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Models\User;
use App\Services\Slack\SlackApiService;
use Illuminate\Support\Facades\Log;

class RfpSlackNotifier
{
    public function __construct(
        private SlackApiService $slack,
    ) {}

    public function notifyQualified(RfpOpportunity $rfp): void
    {
        $score = $rfp->fit_score ?? 0;
        $budget = $this->formatBudget($rfp);
        $url = config('app.url')."/rfp/{$rfp->id}";

        $this->sendDm(
            ':star: *New Qualified RFP — Proposal Generation Starting*',
            [
                $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}"),
                $this->fields([
                    ['Fit Score', "{$score}/100"],
                    ['Budget', $budget],
                    ['Deadline', $rfp->submission_deadline?->format('M j, Y') ?? 'Unknown'],
                ]),
                $this->actions([
                    ['text' => 'View RFP', 'url' => $url],
                ]),
            ]
        );
    }

    public function notifyNeedsReview(RfpOpportunity $rfp): void
    {
        $score = $rfp->fit_score ?? 0;
        $url = config('app.url')."/rfp/{$rfp->id}";

        $this->sendDm(
            ":eyes: *RFP Needs Manual Review (Score: {$score}/100)*",
            [
                $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}\nScore {$score}/100 — in the 35–49 review band. Qualify or decline manually."),
                $this->actions([
                    ['text' => 'Review RFP', 'url' => $url, 'style' => 'primary'],
                ]),
            ]
        );
    }

    public function notifyProposalReady(RfpOpportunity $rfp, RfpProposal $proposal): void
    {
        $reviewUrl = config('app.url')."/rfp/{$rfp->id}/proposals/{$proposal->id}/review";
        $budget = $this->formatBudget($rfp);

        $critiqueLine = $proposal->critique_summary
            ? ':white_check_mark: '.($proposal->critique_revised ? 'Critique applied (auto-revised once)' : 'Reviewed').": _{$proposal->critique_summary}_"
            : '_Reply in this thread to request revisions. I\'ll update the proposal and confirm what changed._';

        // Build action buttons. "Send Proposal" only appears when we have a
        // submission email — that's the agent-native equivalent of "Justin
        // clicks the send-email form" and is safe to expose once contact is set.
        $actionButtons = [];
        if ($rfp->submission_email) {
            $actionButtons[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Send Proposal'],
                'style' => 'primary',
                'action_id' => 'rfp_send_proposal',
                'value' => (string) $proposal->id,
                'confirm' => [
                    'title' => ['type' => 'plain_text', 'text' => 'Send proposal?'],
                    'text' => ['type' => 'mrkdwn', 'text' => "This will email the proposal to *{$rfp->submission_email}* and mark the opportunity as submitted."],
                    'confirm' => ['type' => 'plain_text', 'text' => 'Send'],
                    'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
                ],
            ];
        }
        $actionButtons[] = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Review First'],
            'url' => $reviewUrl,
        ];

        $blocks = [
            $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}"),
            $this->fields(array_filter([
                ['Version', "v{$proposal->version}"],
                ['Budget', $budget],
                ['Deadline', $rfp->submission_deadline?->format('M j, Y') ?? 'Unknown'],
                ['Critique', "{$proposal->critique_blocker_count} blocker(s)".($proposal->critique_revised ? ' — auto-revised' : '')],
                $rfp->submission_email ? ['Send To', $rfp->submission_email] : null,
            ])),
            $this->section($critiqueLine),
            ['type' => 'actions', 'elements' => $actionButtons],
        ];

        $result = $this->uploadProposalPdf($rfp, $proposal, ':memo: *Proposal Ready for Review*', $blocks);

        if ($result && isset($result['message_ts'])) {
            $proposal->update([
                'slack_notification_ts' => $result['message_ts'],
                'slack_channel_id' => $result['channel_id'] ?? null,
            ]);
        }
    }

    /**
     * Upload the proposal PDF as a DM attachment with action buttons.
     * Falls back to a text-only DM if upload fails (network/permissions),
     * so we never silently lose the notification.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{message_ts: ?string, channel_id: ?string}|null
     */
    private function uploadProposalPdf(RfpOpportunity $rfp, RfpProposal $proposal, string $fallbackText, array $blocks): ?array
    {
        $user = User::first();
        $slackUserId = $user?->slack_user_id ?? config('services.slack.owner_user_id');

        if (! $slackUserId) {
            Log::info('RfpSlackNotifier: no Slack user ID configured, skipping PDF upload');

            return null;
        }

        $storagePath = "rfp-proposals/{$rfp->id}/{$proposal->id}.pdf";
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));

        if (! $disk->exists($storagePath)) {
            Log::info('RfpSlackNotifier: PDF not yet rendered, sending text-only notification', [
                'proposal_id' => $proposal->id,
            ]);

            $textResult = $this->sendDm($fallbackText, $blocks);

            return $textResult ? ['message_ts' => $textResult['ts'] ?? null, 'channel_id' => $textResult['channel'] ?? null] : null;
        }

        $localPath = $disk->path($storagePath);
        $filename = sprintf('Proposal-%s-v%d.pdf', \Illuminate\Support\Str::slug($rfp->issuing_organization), $proposal->version);

        try {
            return $this->slack->uploadFileToUser(
                userId: $slackUserId,
                filePath: $localPath,
                filename: $filename,
                title: "Proposal for {$rfp->issuing_organization}",
                initialComment: $fallbackText,
                blocks: $blocks,
            );
        } catch (\Exception $e) {
            Log::warning('RfpSlackNotifier: PDF upload failed, falling back to text DM', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);

            $textResult = $this->sendDm($fallbackText, $blocks);

            return $textResult ? ['message_ts' => $textResult['ts'] ?? null, 'channel_id' => $textResult['channel'] ?? null] : null;
        }
    }

    public function notifyProposalFailed(RfpOpportunity $rfp, string $error): void
    {
        $url = config('app.url')."/rfp/{$rfp->id}";
        $shortError = mb_substr($error, 0, 200);

        $this->sendDm(
            ':x: *Proposal Generation Failed*',
            [
                $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}\n\nError: `{$shortError}`"),
                $this->actions([
                    ['text' => 'Retry Proposal', 'url' => $url],
                ]),
            ]
        );
    }

    public function notifyContactNeeded(RfpOpportunity $rfp): void
    {
        $url = config('app.url')."/rfp/{$rfp->id}";

        $this->sendDm(
            ':mag: *Contact Needed Before Proposal Generation*',
            [
                $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}\n\nI couldn't confidently locate a submission email from email history, prior documents, or the opportunity itself. Proposal generation is paused until you add a contact."),
                $this->actions([
                    ['text' => 'Add Contact', 'url' => $url, 'style' => 'primary'],
                ]),
            ]
        );
    }

    public function notifyProposalStuck(RfpOpportunity $rfp): void
    {
        $stuck = $rfp->generation_started_at?->diffForHumans() ?? 'unknown time';
        $url = config('app.url')."/rfp/{$rfp->id}";

        $this->sendDm(
            ':warning: *Proposal Generation Stuck*',
            [
                $this->section("*{$rfp->title}*\n{$rfp->issuing_organization}\n\nStarted {$stuck} and hasn't completed. Reset and retried automatically."),
                $this->actions([
                    ['text' => 'View RFP', 'url' => $url],
                ]),
            ]
        );
    }

    /** @param array<int, \App\Models\RfpSource> $sources */
    public function notifySourcesDiscovered(array $sources): void
    {
        $count = count($sources);
        $list = collect($sources)
            ->map(fn ($s) => "• *{$s->name}* ({$s->type}) — {$s->url}")
            ->join("\n");

        $this->sendDm(
            ":mag: *{$count} New RFP Source".($count > 1 ? 's' : '').' Discovered*',
            [
                $this->section(":mag: *{$count} New RFP Source".($count > 1 ? 's' : '')." Added*\n\n{$list}"),
                $this->actions([
                    ['text' => 'View Sources', 'url' => config('app.url').'/rfp/sources'],
                ]),
            ]
        );
    }

    public function notifyProposalReply(RfpOpportunity $rfp, string $subject, string $result, ?string $summary = null): void
    {
        $url = config('app.url')."/rfp/{$rfp->id}";

        $emoji = match ($result) {
            'won' => ':trophy:',
            'lost' => ':x:',
            default => ':email:',
        };

        $headline = match ($result) {
            'won' => ":trophy: *We Won! {$rfp->issuing_organization}*",
            'lost' => ':x: *Proposal Outcome: Not Selected*',
            default => ":email: *Reply Received from {$rfp->issuing_organization}*",
        };

        $body = "*{$rfp->title}*\nSubject: _{$subject}_";
        if ($summary) {
            $body .= "\n\n{$summary}";
        }

        $this->sendDm(
            "{$emoji} {$headline}",
            [
                $this->section("{$headline}\n\n{$body}"),
                $this->actions([
                    ['text' => 'View RFP', 'url' => $url, 'style' => $result === 'won' ? 'primary' : null],
                ]),
            ]
        );
    }

    private function sendDm(string $fallbackText, array $blocks): ?array
    {
        $user = User::first();
        $slackUserId = $user?->slack_user_id ?? config('services.slack.owner_user_id');

        if (! $slackUserId) {
            Log::info('RfpSlackNotifier: no Slack user ID configured, skipping notification');

            return null;
        }

        try {
            return $this->slack->postMessageDirect($slackUserId, $fallbackText, null, $blocks);
        } catch (\Exception $e) {
            Log::warning('RfpSlackNotifier: failed to send Slack DM', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function section(string $text): array
    {
        return [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
        ];
    }

    /** @param array<int, array{0: string, 1: string}> $pairs */
    private function fields(array $pairs): array
    {
        return [
            'type' => 'section',
            'fields' => collect($pairs)->map(fn ($pair) => [
                'type' => 'mrkdwn',
                'text' => "*{$pair[0]}*\n{$pair[1]}",
            ])->toArray(),
        ];
    }

    /** @param array<int, array{text: string, url: string, style?: string}> $buttons */
    private function actions(array $buttons): array
    {
        return [
            'type' => 'actions',
            'elements' => collect($buttons)->map(fn ($btn) => array_filter([
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => $btn['text']],
                'url' => $btn['url'],
                'style' => $btn['style'] ?? null,
            ]))->toArray(),
        ];
    }

    private function formatBudget(RfpOpportunity $rfp): string
    {
        if ($rfp->budget_min && $rfp->budget_max) {
            return '$'.number_format((float) $rfp->budget_min / 1000).'K–$'.number_format((float) $rfp->budget_max / 1000).'K';
        }
        if ($rfp->budget_max) {
            return 'Up to $'.number_format((float) $rfp->budget_max / 1000).'K';
        }
        if ($rfp->budget_min) {
            return '$'.number_format((float) $rfp->budget_min / 1000).'K+';
        }

        return 'Not specified';
    }
}
