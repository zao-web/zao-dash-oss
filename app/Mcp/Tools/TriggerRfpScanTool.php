<?php

namespace App\Mcp\Tools;

use App\Models\Email;
use App\Models\RfpSource;
use App\Services\Rfp\RfpDiscoveryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerRfpScanTool extends Tool
{
    protected string $name = 'trigger-rfp-scan';

    protected string $title = 'Trigger RFP Email Scan';

    protected string $description = 'Scan emails from a specific sender for RFP opportunities and create pipeline entries. Use this when asked to review emails for RFP teasers or bid opportunities.';

    public function __construct(
        protected RfpDiscoveryService $discoveryService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'from' => 'nullable|string|max:255',
            'days' => 'nullable|integer|min:1|max:90',
            'email_ids' => 'nullable|array',
            'email_ids.*' => 'integer|exists:emails,id',
        ]);

        $from = $request->get('from');
        $days = $request->get('days', 14);
        $emailIds = $request->get('email_ids');

        // Find emails to scan
        $query = Email::query()->orderBy('received_at', 'desc');

        if ($emailIds) {
            $query->whereIn('id', $emailIds);
        } else {
            if ($from) {
                $query->where(function ($q) use ($from) {
                    $q->where('from_address', 'like', "%{$from}%")
                        ->orWhere('from_name', 'like', "%{$from}%");
                });
            } else {
                // Fall back to configured RFP email sources
                $senderEmails = RfpSource::active()
                    ->where('type', 'email_sender')
                    ->get()
                    ->flatMap(fn (RfpSource $s) => $s->config['sender_emails'] ?? [])
                    ->toArray();

                if (empty($senderEmails)) {
                    return Response::structured([
                        'scanned' => 0,
                        'opportunities_created' => 0,
                        'message' => 'No sender specified and no email RFP sources configured. Provide a "from" parameter or configure an email_sender RFP source.',
                    ]);
                }

                $query->where(function ($q) use ($senderEmails) {
                    foreach ($senderEmails as $email) {
                        $q->orWhere('from_address', 'like', "%{$email}%");
                    }
                });
            }

            $query->where('received_at', '>=', now()->subDays($days));
        }

        $emails = $query->get();

        if ($emails->isEmpty()) {
            return Response::structured([
                'scanned' => 0,
                'opportunities_created' => 0,
                'message' => $from
                    ? "No emails found from '{$from}' in the last {$days} days."
                    : "No emails found from configured RFP sources in the last {$days} days.",
            ]);
        }

        Log::info('[TriggerRfpScan] Scanning emails for RFP opportunities', [
            'email_count' => $emails->count(),
            'from' => $from,
            'days' => $days,
        ]);

        $totalCreated = 0;
        $totalSkipped = 0;
        $scannedEmails = [];

        foreach ($emails as $email) {
            // Use body_text first, fall back to stripped body_html
            $bodyText = $email->body_text;
            if (empty(trim($bodyText ?? '')) && $email->body_html) {
                $bodyText = strip_tags($email->body_html);
            }
            $contentToParse = "Subject: {$email->subject}\n\n".($bodyText ?? '');

            Log::info('[TriggerRfpScan] Processing email', [
                'email_id' => $email->id,
                'subject' => $email->subject,
                'body_text_length' => strlen($email->body_text ?? ''),
                'body_html_length' => strlen($email->body_html ?? ''),
                'content_to_parse_length' => strlen($contentToParse),
                'content_preview' => mb_substr($contentToParse, 0, 200),
            ]);

            $teasers = $this->discoveryService->parseEmailTeasers(
                $contentToParse,
                ['sender_email' => $email->from_address]
            );

            // If no teasers from body, try to create one from the subject line alone
            if (empty($teasers) && $email->subject && strlen($email->subject) > 20) {
                $teasers = $this->discoveryService->parseEmailTeasers(
                    "RFP Opportunity: {$email->subject}",
                    ['sender_email' => $email->from_address]
                );
            }

            $created = 0;
            foreach ($teasers as $teaser) {
                $opportunity = $this->discoveryService->createFromTeaser($teaser, 'email_teaser');

                if ($opportunity) {
                    $created++;
                    $totalCreated++;
                } else {
                    $totalSkipped++;
                }
            }

            $scannedEmails[] = [
                'email_id' => $email->id,
                'from' => $email->from_name ?? $email->from_address,
                'subject' => $email->subject,
                'received' => $email->received_at?->diffForHumans(),
                'teasers_found' => count($teasers),
                'opportunities_created' => $created,
            ];
        }

        return Response::structured([
            'scanned' => $emails->count(),
            'opportunities_created' => $totalCreated,
            'duplicates_skipped' => $totalSkipped,
            'emails_processed' => $scannedEmails,
            'message' => "Scanned {$emails->count()} email(s), created {$totalCreated} new RFP opportunity(ies)".($totalSkipped > 0 ? " ({$totalSkipped} duplicates skipped)" : '').'.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Sender name or email to scan (e.g., "Rob Williams"). If omitted, uses configured RFP email sources.'),
            'days' => $schema->integer()->description('Scan emails from the last N days (default: 14, max: 90)'),
            'email_ids' => $schema->array()->items(
                $schema->integer()->description('Email ID')
            )->description('Specific email IDs to scan (overrides from/days filters)'),
        ];
    }
}
