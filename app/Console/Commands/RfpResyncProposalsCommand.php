<?php

namespace App\Console\Commands;

use App\Jobs\CritiqueRfpProposalJob;
use App\Models\RfpProposal;
use App\Services\Rfp\RfpContactLocator;
use Illuminate\Console\Command;

/**
 * Re-runs the critique + revision + PDF + Slack pipeline against every
 * proposal that's still in-play (not submitted, deadline not passed).
 *
 * Useful when the critic rubric or PDF template changes — running this
 * picks up the new rules against existing drafts and re-DMs Justin with
 * fresh Slack messages including PDF + "Send Proposal" buttons.
 */
class RfpResyncProposalsCommand extends Command
{
    protected $signature = 'rfp:resync-proposals
                            {--dry-run : Show what would happen without dispatching jobs}
                            {--include-without-contact : Process proposals whose opportunity has no submission email (no Send button will appear)}';

    protected $description = 'Re-critique + re-Slack every in-play RFP proposal';

    public function handle(RfpContactLocator $locator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeWithoutContact = (bool) $this->option('include-without-contact');

        $candidates = RfpProposal::query()
            ->with('opportunity')
            ->whereNotIn('status', ['submitted', 'superseded'])
            ->whereHas('opportunity', function ($q) {
                $q->whereNotIn('status', ['won', 'lost', 'declined', 'expired', 'submitted', 'withdrawn'])
                    ->where(function ($q) {
                        $q->whereNull('submission_deadline')
                            ->orWhere('submission_deadline', '>', now());
                    });
            })
            ->orderByDesc('id')
            ->get()
            ->groupBy('rfp_opportunity_id')
            ->map(fn ($versions) => $versions->sortByDesc('version')->first())
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('No eligible proposals found.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} eligible proposal(s):");
        $this->newLine();

        $dispatched = 0;
        $skipped = 0;
        $contactsLocated = 0;

        foreach ($candidates as $proposal) {
            $opportunity = $proposal->opportunity;
            $deadline = $opportunity->submission_deadline?->format('M j, Y') ?? 'no deadline';
            $row = "• #{$proposal->id} v{$proposal->version} — {$opportunity->issuing_organization} ({$deadline})";

            if (! $opportunity->submission_email) {
                if ($dryRun) {
                    $this->line("$row :mag: would attempt contact lookup");
                } else {
                    $result = $locator->locate($opportunity);
                    if ($result['found']) {
                        $opportunity->update(array_filter([
                            'submission_email' => $result['email'],
                            'contact_name' => $opportunity->contact_name ?: $result['name'],
                        ]));
                        $this->line("$row :white_check_mark: located {$result['email']} ({$result['source']})");
                        $contactsLocated++;
                    } else {
                        if (! $includeWithoutContact) {
                            $this->line("$row :warning: no contact, skipping (use --include-without-contact to dispatch anyway)");
                            $skipped++;

                            continue;
                        }

                        $this->line("$row :warning: no contact, dispatching (Send button will be hidden)");
                    }
                }
            } else {
                $this->line("$row :email: contact: {$opportunity->submission_email}");
            }

            if (! $dryRun) {
                CritiqueRfpProposalJob::dispatch($proposal->id);
                $dispatched++;
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete. Re-run without --dry-run to dispatch jobs.');
        } else {
            $this->info("Dispatched {$dispatched} critique job(s). Contacts located: {$contactsLocated}. Skipped: {$skipped}.");
            $this->comment('Slack messages will arrive as each job processes through the queue.');
        }

        return self::SUCCESS;
    }
}
