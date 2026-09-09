<?php

namespace App\Jobs;

use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reset proposals stuck in proposal_drafting for over 30 minutes.
 *
 * Jobs on the 'agents' queue can time out silently if Horizon restarts or the
 * process is killed. This watchdog catches those orphaned records, resets them
 * to 'pursuing' so the UI isn't frozen, and notifies via Slack.
 */
class WatchStuckRfpProposalsJob implements ShouldQueue
{
    use Queueable;

    private const STUCK_MINUTES = 30;

    public function handle(RfpSlackNotifier $notifier): void
    {
        $stuck = RfpOpportunity::query()
            ->where('status', 'proposal_drafting')
            ->where('generation_started_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        Log::warning('WatchStuckRfpProposalsJob: resetting stuck proposals', [
            'count' => $stuck->count(),
            'ids' => $stuck->pluck('id')->toArray(),
        ]);

        foreach ($stuck as $rfp) {
            $rfp->update([
                'status' => 'pursuing',
                'generation_stage' => 'failed',
                'generation_error' => 'Generation timed out after '.self::STUCK_MINUTES.' minutes. Re-trigger from the RFP detail page.',
            ]);

            $notifier->notifyProposalStuck($rfp);
        }
    }
}
