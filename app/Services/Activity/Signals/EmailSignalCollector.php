<?php

namespace App\Services\Activity\Signals;

use App\Models\Client;
use App\Models\Email;
use App\Services\Reports\RetainerHealthService;
use Carbon\Carbon;

class EmailSignalCollector
{
    public function __construct(protected RetainerHealthService $health) {}

    /**
     * Collect inbound emails from the client's domain(s) within the window.
     * Pulls all of them (not just action_required=true) so the synthesis
     * layer can judge in context — but flags the action_required signal
     * on each as a hint.
     *
     * @return array<int, array{source_type:string, external_id:string, permalink:?string, occurred_at:Carbon, actor:?string, content:string, meta:array<string,mixed>}>
     */
    public function collect(Client $client, Carbon $since): array
    {
        $domains = $this->health->clientEmailDomains($client);

        $emails = Email::query()
            ->where('received_at', '>=', $since)
            ->where(function ($q) use ($client, $domains): void {
                $q->where('client_id', $client->id);
                foreach ($domains as $domain) {
                    $needle = '%@'.strtolower($domain);
                    $q->orWhereRaw('lower(from_address) like ?', [$needle]);
                }
            })
            ->orderBy('received_at')
            ->limit(150)
            ->get();

        return $emails->map(function (Email $email): array {
            $snippet = trim((string) $email->body_text);
            $snippet = mb_substr($snippet, 0, 1200);

            return [
                'source_type' => 'email',
                'external_id' => '<'.$email->google_message_id.'>',
                'permalink' => null,
                'occurred_at' => $email->received_at,
                'actor' => $email->from_name ?: $email->from_address,
                'content' => trim($email->subject."\n\n".$snippet),
                'meta' => [
                    'from_address' => $email->from_address,
                    'subject' => $email->subject,
                    'action_required' => (bool) $email->action_required,
                    'urgency' => $email->urgency,
                    'thread_id' => $email->thread_id,
                ],
            ];
        })->all();
    }
}
