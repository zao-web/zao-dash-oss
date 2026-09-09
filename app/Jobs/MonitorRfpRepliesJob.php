<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\RfpOpportunity;
use App\Models\RfpOutcome;
use App\Services\AI\ClaudeCliService;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scan synced emails for replies to submitted RFP proposals.
 *
 * Matches emails from known contact addresses against submitted
 * opportunities, then uses Claude to classify the reply intent
 * (award notification, rejection, follow-up question, etc.).
 */
class MonitorRfpRepliesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    // Keywords that suggest a decision email
    private const AWARD_SIGNALS = ['award', 'selected', 'pleased to inform', 'congratulations', 'contract award', 'successful'];

    private const REJECTION_SIGNALS = ['regret', 'unsuccessful', 'not selected', 'another vendor', 'did not', 'decline to award', 'no award'];

    public function handle(ClaudeCliService $claude, RfpSlackNotifier $notifier): void
    {
        // Only look at submitted opportunities that don't yet have an outcome
        $submitted = RfpOpportunity::query()
            ->where('status', 'submitted')
            ->whereNotNull('contact_email')
            ->whereDoesntHave('outcome')
            ->with('proposals')
            ->get();

        if ($submitted->isEmpty()) {
            return;
        }

        Log::info('MonitorRfpRepliesJob: checking replies for submitted opportunities', [
            'count' => $submitted->count(),
        ]);

        foreach ($submitted as $opportunity) {
            $this->checkForReply($opportunity, $claude, $notifier);
        }
    }

    private function checkForReply(RfpOpportunity $opportunity, ClaudeCliService $claude, RfpSlackNotifier $notifier): void
    {
        $submittedAt = $opportunity->proposals->max('submitted_at');
        if (! $submittedAt) {
            return;
        }

        // Look for emails FROM the contact address after we submitted
        $replies = Email::query()
            ->where('from_address', $opportunity->contact_email)
            ->where('received_at', '>=', $submittedAt)
            ->orderBy('received_at', 'asc')
            ->get();

        if ($replies->isEmpty()) {
            // Also check for emails with org name in from_name
            $orgWords = array_filter(
                explode(' ', strtolower($opportunity->issuing_organization ?? '')),
                fn ($w) => strlen($w) > 3
            );

            if (! empty($orgWords)) {
                $query = Email::query()->where('received_at', '>=', $submittedAt);
                foreach ($orgWords as $word) {
                    $query->where(fn ($q) => $q
                        ->whereRaw('LOWER(from_address) LIKE ?', ["%{$word}%"])
                        ->orWhereRaw('LOWER(from_name) LIKE ?', ["%{$word}%"])
                    );
                }
                $replies = $query->orderBy('received_at')->get();
            }
        }

        if ($replies->isEmpty()) {
            return;
        }

        $latestReply = $replies->last();
        $body = $latestReply->body_text ?? strip_tags($latestReply->body_html ?? '');
        $subject = $latestReply->subject ?? '';
        $combined = strtolower($subject.' '.$body);

        // Fast-path: keyword detection before spending AI tokens
        $isAward = collect(self::AWARD_SIGNALS)->contains(fn ($s) => str_contains($combined, $s));
        $isRejection = collect(self::REJECTION_SIGNALS)->contains(fn ($s) => str_contains($combined, $s));

        if (! $isAward && ! $isRejection) {
            // General reply — notify but don't auto-classify
            $notifier->notifyProposalReply($opportunity, $subject, 'follow_up');
            Log::info('MonitorRfpRepliesJob: reply received, not a decision', [
                'opportunity_id' => $opportunity->id,
                'subject' => $subject,
            ]);

            return;
        }

        // Use AI to confirm and extract details for decision emails
        $classifyPrompt = <<<PROMPT
An organization has sent a reply email about an RFP proposal we submitted. Classify this email.

Organization: {$opportunity->issuing_organization}
RFP: {$opportunity->title}
Email Subject: {$subject}
Email Body (first 2000 chars):
{$body}

Return JSON with:
- "result": "won", "lost", or "follow_up"
- "confidence": 0.0-1.0
- "feedback": any specific feedback mentioned (or null)
- "awarded_amount": contract value if mentioned as a number (or null)
- "competitor": winning competitor name if mentioned (or null)
- "summary": 1-2 sentence summary of what the email says
PROMPT;

        $classification = $claude->messageJson($classifyPrompt, null, 'haiku', 60);

        if (! $classification || ($classification['confidence'] ?? 0) < 0.7) {
            $notifier->notifyProposalReply($opportunity, $subject, 'uncertain');

            return;
        }

        $result = $classification['result'] ?? 'follow_up';

        if (in_array($result, ['won', 'lost'], true)) {
            $outcome = RfpOutcome::create([
                'rfp_opportunity_id' => $opportunity->id,
                'result' => $result,
                'feedback_text' => $classification['feedback'] ?? null,
                'awarded_amount' => $classification['awarded_amount'] ?? null,
                'competitor_info' => $classification['competitor'] ? ['name' => $classification['competitor']] : null,
            ]);

            $opportunity->update(['status' => $result]);

            AnalyzeRfpOutcomesJob::dispatch();

            Log::info('MonitorRfpRepliesJob: outcome auto-detected', [
                'opportunity_id' => $opportunity->id,
                'result' => $result,
                'confidence' => $classification['confidence'],
            ]);
        }

        $notifier->notifyProposalReply($opportunity, $subject, $result, $classification['summary'] ?? null);
    }
}
