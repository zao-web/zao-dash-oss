<?php

namespace App\Services\Rfp;

use App\Mail\RfpProposalMail;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared "send the proposal email" logic used by both the web UI form and
 * the Slack "Send Proposal" button. Composes a default subject + body from
 * the proposal, mails the submission contact, and advances the opportunity
 * status to 'submitted'.
 */
class RfpProposalSender
{
    public function send(RfpProposal $proposal, RfpOpportunity $opportunity, ?string $subject = null, ?string $body = null): void
    {
        if (! $opportunity->submission_email) {
            throw new \RuntimeException('Cannot send proposal — no submission_email on opportunity.');
        }

        $subject ??= "Proposal: {$opportunity->title} — Zao";
        $body ??= $this->composeDefaultBody($opportunity, $proposal);

        Mail::to($opportunity->submission_email, $opportunity->contact_name)
            ->send(new RfpProposalMail(
                proposal: $proposal,
                opportunity: $opportunity,
                emailSubject: $subject,
                emailBody: $body,
            ));

        $proposal->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_via' => 'email',
        ]);

        if (in_array($opportunity->status, ['qualified', 'pursuing', 'proposal_drafting', 'proposal_review'])) {
            $opportunity->update(['status' => 'submitted']);
        }

        Log::info('RfpProposalSender: proposal emailed', [
            'proposal_id' => $proposal->id,
            'opportunity_id' => $opportunity->id,
            'submission_email' => $opportunity->submission_email,
        ]);
    }

    private function composeDefaultBody(RfpOpportunity $opportunity, RfpProposal $proposal): string
    {
        $greeting = $opportunity->contact_name ? "Dear {$opportunity->contact_name}," : 'Hello,';
        $summary = $proposal->executive_summary
            ? mb_substr($proposal->executive_summary, 0, 500)
            : 'Please find our proposal attached for your review.';

        return <<<BODY
{$greeting}

Please find attached Zao's proposal for {$opportunity->title}.

{$summary}

We'd welcome the opportunity to discuss our approach in more detail. Feel free to reply directly with any questions.

Best regards,
Owner User
Zao
https://example.com
BODY;
    }
}
