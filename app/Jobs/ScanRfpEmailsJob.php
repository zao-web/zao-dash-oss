<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\RfpSource;
use App\Services\Rfp\RfpDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scan emails from configured RFP sources and extract opportunities.
 *
 * Runs on schedule (every 30 minutes) to process recent emails
 * from known RFP teaser senders.
 */
class ScanRfpEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(
        public ?string $fromOverride = null,
        public int $daysOverride = 14,
    ) {}

    public function handle(RfpDiscoveryService $discoveryService): void
    {
        Log::info('ScanRfpEmailsJob: starting RFP email scan', [
            'from_override' => $this->fromOverride,
            'days_override' => $this->daysOverride,
        ]);

        // If a specific sender was provided (manual trigger), scan just that
        if ($this->fromOverride) {
            $this->scanSender($this->fromOverride, $this->daysOverride, $discoveryService);

            return;
        }

        $sources = RfpSource::query()
            ->active()
            ->where('type', 'email_sender')
            ->get();

        if ($sources->isEmpty()) {
            Log::info('ScanRfpEmailsJob: no active email_sender sources configured');

            return;
        }

        $totalCreated = 0;
        $totalScanned = 0;

        foreach ($sources as $source) {
            $created = $this->processSource($source, $discoveryService);
            $totalCreated += $created;

            $source->update(['last_checked_at' => now()]);
        }

        Log::info('ScanRfpEmailsJob: completed', [
            'sources_checked' => $sources->count(),
            'emails_scanned' => $totalScanned,
            'opportunities_created' => $totalCreated,
        ]);
    }

    /**
     * Process a single RFP source for new emails.
     */
    protected function processSource(RfpSource $source, RfpDiscoveryService $discoveryService): int
    {
        $senderEmails = $source->config['sender_emails'] ?? [];

        if (empty($senderEmails)) {
            Log::debug('ScanRfpEmailsJob: no sender emails configured for source', [
                'source_id' => $source->id,
                'source_name' => $source->name,
            ]);

            return 0;
        }

        // Look back 7 days to catch weekly digest emails (e.g., Folyo)
        // The rfp_opportunity_id check prevents re-processing already-handled emails
        $emails = Email::query()
            ->whereIn('from_address', $senderEmails)
            ->where('received_at', '>=', now()->subDays(7))
            ->whereNull('rfp_opportunity_id')
            ->get();

        if ($emails->isEmpty()) {
            Log::debug('ScanRfpEmailsJob: no new emails from source', [
                'source_id' => $source->id,
                'sender_emails' => $senderEmails,
            ]);

            return 0;
        }

        $created = 0;

        foreach ($emails as $email) {
            $emailBody = $email->body_text ?? $email->body_html ?? '';

            if (empty(trim($emailBody))) {
                continue;
            }

            $opportunities = $discoveryService->parseEmailTeasers($emailBody, [
                'sender_name' => $email->from_name,
                'sender_email' => $email->from_address,
                'rfp_source_id' => $source->id,
            ]);

            foreach ($opportunities as $oppData) {
                $title = $oppData['title'] ?? '';
                $organization = $oppData['organization'] ?? '';

                if (empty($title) || empty($organization)) {
                    continue;
                }

                if ($discoveryService->isDuplicate($title, $organization)) {
                    Log::debug('ScanRfpEmailsJob: skipping duplicate opportunity', [
                        'title' => $title,
                        'organization' => $organization,
                    ]);

                    continue;
                }

                $discoveryService->createFromTeaser(
                    $oppData,
                    'email_teaser',
                    $source->id
                );

                $created++;
            }
        }

        // Update source stats
        if ($created > 0) {
            $source->increment('total_opportunities_found', $created);
        }

        Log::info('ScanRfpEmailsJob: processed source', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'emails_scanned' => $emails->count(),
            'opportunities_created' => $created,
        ]);

        return $created;
    }

    /**
     * Scan a specific sender (manual trigger from UI or Slack).
     */
    protected function scanSender(string $from, int $days, RfpDiscoveryService $discoveryService): void
    {
        $emails = Email::query()
            ->where(function ($q) use ($from) {
                $q->where('from_address', 'like', "%{$from}%")
                    ->orWhere('from_name', 'like', "%{$from}%");
            })
            ->where('received_at', '>=', now()->subDays($days))
            ->orderBy('received_at', 'desc')
            ->get();

        Log::info('ScanRfpEmailsJob: manual scan', [
            'from' => $from,
            'days' => $days,
            'emails_found' => $emails->count(),
        ]);

        $created = 0;

        foreach ($emails as $email) {
            $emailBody = $email->body_text ?? $email->body_html ?? '';

            if (empty(trim($emailBody))) {
                continue;
            }

            $opportunities = $discoveryService->parseEmailTeasers($emailBody, [
                'sender_name' => $email->from_name,
                'sender_email' => $email->from_address,
            ]);

            foreach ($opportunities as $oppData) {
                $opportunity = $discoveryService->createFromTeaser($oppData, 'email_teaser');

                if ($opportunity) {
                    $created++;
                }
            }
        }

        Log::info('ScanRfpEmailsJob: manual scan complete', [
            'from' => $from,
            'emails_scanned' => $emails->count(),
            'opportunities_created' => $created,
        ]);
    }
}
