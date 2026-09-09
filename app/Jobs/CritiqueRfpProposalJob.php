<?php

namespace App\Jobs;

use App\Models\RfpProposal;
use App\Services\Pdf\TailwindPdf;
use App\Services\Rfp\RfpProposalCritic;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Runs an adversarial review pass over a draft proposal and (when blockers
 * are found) applies a one-shot revision before notifying Justin.
 *
 * Dispatched by GenerateRfpProposalJob after the draft saves. The Slack
 * notification (notifyProposalReady) is moved here so it carries the
 * critique summary into the message body.
 */
class CritiqueRfpProposalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $rfpProposalId)
    {
        $this->onQueue('agents');
    }

    public function handle(RfpProposalCritic $critic, RfpSlackNotifier $slack): void
    {
        $proposal = RfpProposal::with('opportunity')->find($this->rfpProposalId);

        if (! $proposal || ! $proposal->opportunity) {
            Log::warning('CritiqueRfpProposalJob: proposal/opportunity not found', [
                'proposal_id' => $this->rfpProposalId,
            ]);

            return;
        }

        $opportunity = $proposal->opportunity;

        $critique = $critic->critique($proposal, $opportunity);

        $proposal->update([
            'critique_findings' => $critique['findings'],
            'critique_summary' => $critique['summary'],
            'critique_blocker_count' => $critique['blocker_count'],
            'critique_revised' => false,
        ]);

        if ($critique['blocker_count'] > 0) {
            Log::info('CritiqueRfpProposalJob: revising for blockers', [
                'proposal_id' => $proposal->id,
                'blocker_count' => $critique['blocker_count'],
            ]);

            $critic->revise($proposal, $opportunity, $critique);
            $proposal->update(['critique_revised' => true]);
        }

        $proposal = $proposal->refresh();

        $this->renderProposalPdf($opportunity, $proposal);

        $slack->notifyProposalReady($opportunity, $proposal);
    }

    /**
     * Pre-render the proposal PDF so the Slack notification can attach it.
     * Mirrors RfpController::generateProposalPdf so storage paths match.
     */
    private function renderProposalPdf($opportunity, RfpProposal $proposal): void
    {
        $storagePath = "rfp-proposals/{$opportunity->id}/{$proposal->id}.pdf";

        if (Storage::exists($storagePath)) {
            return;
        }

        try {
            $company = [
                'name' => config('app.company_name', 'Zao'),
                'email' => config('mail.from.address', 'billing@example.com'),
                'website' => 'https://example.com',
                'phone' => config('app.company_phone'),
                'contact_name' => config('app.company_contact_name'),
            ];

            TailwindPdf::view('pdf.rfp-proposal', [
                'proposal' => $proposal,
                'opportunity' => $opportunity,
                'company' => $company,
            ])->save($storagePath);
        } catch (\Throwable $e) {
            Log::warning('CritiqueRfpProposalJob: PDF render failed (notification will fall back to text)', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
