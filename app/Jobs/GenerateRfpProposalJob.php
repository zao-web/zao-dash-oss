<?php

namespace App\Jobs;

use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpProposalService;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateRfpProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $backoff = 120;

    public function __construct(
        public int $rfpOpportunityId,
    ) {
        $this->onQueue('agents');
    }

    public function handle(RfpProposalService $proposalService): void
    {
        $opportunity = RfpOpportunity::find($this->rfpOpportunityId);

        if (! $opportunity) {
            Log::warning('GenerateRfpProposalJob: opportunity not found', [
                'opportunity_id' => $this->rfpOpportunityId,
            ]);

            return;
        }

        $terminalStatuses = ['won', 'lost', 'declined', 'expired'];
        if (in_array($opportunity->status, $terminalStatuses)) {
            return;
        }

        if (! $opportunity->submission_email) {
            Log::info('GenerateRfpProposalJob: no submission contact — routing through LocateRfpContactJob', [
                'opportunity_id' => $opportunity->id,
            ]);

            LocateRfpContactJob::dispatch($opportunity->id);

            return;
        }

        $opportunity->update([
            'status' => 'proposal_drafting',
            'generation_stage' => 'starting',
            'generation_error' => null,
            'generation_started_at' => now(),
        ]);

        try {
            $this->updateStage($opportunity, 'gathering_context', 'Gathering project history and references...');

            $proposal = $proposalService->generateProposal($opportunity, [
                'on_stage' => function (string $stage, string $message) use ($opportunity) {
                    $this->updateStage($opportunity, $stage, $message);
                },
            ]);

            $opportunity->update([
                'status' => 'proposal_review',
                'generation_stage' => 'complete',
                'generation_error' => null,
            ]);

            Log::info('GenerateRfpProposalJob: proposal generated, dispatching critique', [
                'opportunity_id' => $opportunity->id,
                'proposal_id' => $proposal->id,
                'version' => $proposal->version,
            ]);

            CritiqueRfpProposalJob::dispatch($proposal->id);
        } catch (\Exception $e) {
            Log::error('GenerateRfpProposalJob: failed', [
                'opportunity_id' => $opportunity->id,
                'error' => $e->getMessage(),
            ]);

            $opportunity->update([
                'status' => 'pursuing',
                'generation_stage' => 'failed',
                'generation_error' => $e->getMessage(),
            ]);

            app(RfpSlackNotifier::class)->notifyProposalFailed($opportunity, $e->getMessage());

            throw $e;
        }
    }

    protected function updateStage(RfpOpportunity $opportunity, string $stage, ?string $message = null): void
    {
        $opportunity->update(['generation_stage' => $stage]);

        Log::info("GenerateRfpProposalJob: {$stage}", [
            'opportunity_id' => $opportunity->id,
            'message' => $message,
        ]);
    }
}
