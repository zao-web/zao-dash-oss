<?php

namespace App\Jobs;

use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpContactLocator;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Locate a submission contact for an RFP, then decide whether to continue
 * proposal generation or halt and ask Justin for help.
 *
 * Runs after RetrieveRfpDocumentJob, before GenerateRfpProposalJob. The
 * proposal generator gates on a present contact, so this job either fills
 * it in or marks the opportunity 'contact_needed' for human triage.
 */
class LocateRfpContactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $rfpOpportunityId)
    {
        $this->onQueue('slack-mentions');
    }

    public function handle(RfpContactLocator $locator, RfpSlackNotifier $slack): void
    {
        $opportunity = RfpOpportunity::find($this->rfpOpportunityId);

        if (! $opportunity) {
            Log::warning('LocateRfpContactJob: opportunity not found', ['id' => $this->rfpOpportunityId]);

            return;
        }

        $result = $locator->locate($opportunity);

        if ($result['found']) {
            $updates = ['submission_email' => $result['email']];

            if (! $opportunity->contact_name && $result['name']) {
                $updates['contact_name'] = $result['name'];
            }

            $opportunity->update($updates);

            Log::info('LocateRfpContactJob: contact located', [
                'opportunity_id' => $opportunity->id,
                'source' => $result['source'],
                'email' => $result['email'],
            ]);

            GenerateRfpProposalJob::dispatch($opportunity->id);

            return;
        }

        $opportunity->update(['status' => 'contact_needed']);

        Log::info('LocateRfpContactJob: no contact found — halting and notifying', [
            'opportunity_id' => $opportunity->id,
            'organization' => $opportunity->issuing_organization,
        ]);

        $slack->notifyContactNeeded($opportunity);
    }
}
