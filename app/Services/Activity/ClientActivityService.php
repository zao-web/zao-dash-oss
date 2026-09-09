<?php

namespace App\Services\Activity;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Synthesises raw activity signals (Slack, email, GitHub, internal tasks)
 * into a client-facing snapshot of what's currently in flight. Mirrors
 * RetainerNarrativeService's LLM-calling pattern (Cloudflare → Anthropic
 * fallback) but with a forward-looking, client-voice prompt and a 30-min
 * cache so dashboards stay fresh.
 */
class ClientActivityService
{
    /**
     * Valid client-facing statuses the synthesis can emit. Anything else
     * coming back from the LLM is coerced to `noise` and dropped.
     */
    public const STATUSES = [
        'not_started',
        'in_progress',
        'waiting_on_client',
        'completed_recently',
        'noise',
    ];

    public function __construct(
        protected SignalCollector $collector,
    ) {}

    /**
     * @return array{
     *   items: array<int, array{
     *     title: string,
     *     client_summary: string,
     *     status: string,
     *     first_raised_at: ?string,
     *     last_activity_at: ?string,
     *     evidence: array<int, string>,
     *     external_ids: array<int, array{source: string, id: string}>,
     *   }>,
     *   generated_at: string,
     *   warnings: array<int, string>,
     * }
     */
    public function synthesize(Client $client, bool $force = false): array
    {
        $cacheKey = sprintf('client.activity.%d', $client->id);

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($client) {
            return $this->compute($client);
        });
    }

    public function getCached(Client $client): ?array
    {
        return Cache::get(sprintf('client.activity.%d', $client->id));
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(Client $client): array
    {
        $evidence = $this->collector->collect($client);

        if (empty($evidence['signals'])) {
            return $this->emptyResponse('No activity observed in the last '.$evidence['window_days'].' days.');
        }

        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->buildUserPrompt($evidence);

        $text = $this->callCloudflare($systemPrompt, $userPrompt, $client)
            ?? $this->callAnthropic($systemPrompt, $userPrompt, $client);

        if ($text === null) {
            return $this->emptyResponse('No LLM provider available.');
        }

        return $this->parseResponse($text);
    }

    protected function callCloudflare(string $systemPrompt, string $userPrompt, Client $client): ?string
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $model = config('services.cloudflare.narrative_model', '@cf/moonshotai/kimi-k2-instruct');

        if (! $accountId || ! $apiToken) {
            return null;
        }

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(120)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}", [
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_tokens' => 4096,
                    'temperature' => 0.2,
                    'seed' => crc32('activity.'.$client->id.'.'.now()->format('Y-m-d-H')),
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful() || ! $response->json('success', false)) {
                Log::warning('ClientActivityService: Cloudflare error', [
                    'client_id' => $client->id,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json('result.response') ?? $response->json('result.output.0.content') ?? null;
        } catch (\Throwable $e) {
            Log::warning('ClientActivityService: Cloudflare threw', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function callAnthropic(string $systemPrompt, string $userPrompt, Client $client): ?string
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-sonnet-4-20250514',
                    'max_tokens' => 4096,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('ClientActivityService: Anthropic error', [
                    'client_id' => $client->id,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json('content.0.text', '');
        } catch (\Throwable $e) {
            Log::warning('ClientActivityService: Anthropic threw', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an analyst preparing a "currently active work" snapshot for an
agency owner who needs to show their client what's in flight. You receive
raw activity from the last 60 DAYS: inbound Slack messages from the
client team, action-required emails from the client domain, open GitHub
PRs/issues, and existing internal tasks. Cluster this into discrete work
items, classify each item's status from the CLIENT'S perspective, and
drop anything that's noise.

OUTPUT STRICT JSON ONLY, no prose around it:

{
  "items": [
    {
      "title": "Short title in the client's language",
      "client_summary": "1-2 sentences written as if the client is the reader",
      "status": "not_started" | "in_progress" | "waiting_on_client" | "completed_recently" | "noise",
      "first_raised_at": "YYYY-MM-DD",
      "last_activity_at": "YYYY-MM-DD",
      "evidence": ["Slack: <paraphrase>", "GitHub: PR #234", "Email: <subject>"],
      "external_ids": [
        {"source": "slack", "id": "C0123:1715000000.123456"},
        {"source": "github_pr", "id": "zao/locum-site:234"},
        {"source": "email", "id": "<message-id@gmail.com>"},
        {"source": "internal_task", "id": "task:1234"}
      ]
    }
  ]
}

STATUS DEFINITIONS — read carefully, these drive what the client sees:
  * not_started      = client raised it, we haven't substantively
                       responded or moved on it
  * in_progress      = we're actively working (recent commits, replies,
                       internal task in_progress/review)
  * waiting_on_client = STRICT. The most recent message in the conversation
                        is FROM US, AND that message contains an explicit
                        question or asks for input, a decision, or info
                        from them. An FYI or status update with no
                        question is NOT waiting_on_client.
  * completed_recently = shipped, merged, or resolved within the last 14 days
  * noise            = not a real work item; see filtering rules below

FILTERING RULES — be aggressive about marking things `noise`. Drop:
  - Pure FYIs and forwarded notifications with no explicit ask (e.g.,
    forwarded ManageWP failure emails, plugin-vulnerability notices, vendor
    alerts). They want us to know, not act.
  - Status check-ins ("any update on X?", "where are we on Y?"). These
    aren't new asks — they're echoes of an existing ask. If the underlying
    ask is already captured as another item, drop the check-in. If not,
    cluster it WITH the underlying ask, not as a separate item.
  - Acknowledgments, thanks, "got it", "perfect", emoji-only reactions.
  - Social or contextual chatter (sports, weather, business news, weekend
    plans, family stuff).
  - Venting about vendors or platforms (WP Engine, ManageWP, GoDaddy,
    Elementor, etc.) that doesn't end in a request to us.
  - A client message that's just commentary on something we already did
    or shipped, without a new ask attached.

The threshold: would a reasonable account manager open a ticket for this?
If no, it's noise.

CLUSTERING:
- Group multiple messages/PRs/emails about the same underlying work into
  ONE item, even when they span weeks. A Slack ask in early May plus a PR
  in mid-May for the same issue = one item.
- When uncertain whether two pieces of evidence describe the same item,
  err on merging — the client doesn't care about our internal threading.
- Status check-ins ("can I get an update on X?") merge INTO the original
  item, never become their own.

WRITING TONE — match the agency owner's voice:
  - Direct and terse. No corporate filler ("just wanted to circle back",
    "happy to help", "I hope this finds you well").
  - No greetings or sign-offs in the summary copy itself.
  - Speak as if the client is reading it. "You asked about cache headers
    on May 12; we have a fix in review." Not: "Cory asked about cache
    headers."
  - Plain English with light contractions. Conversational, not corporate.
  - Subtle warmth, not effusive — "We're on it" not "We're delighted to
    be working on it."
  - No exclamation points.

EVIDENCE FIDELITY:
  - Every item needs at least one entry in `evidence` AND at least one in
    `external_ids`.
  - `external_ids` is the dedup key across runs — be precise:
      slack:         channel_id:message_ts
      github_pr:     repo_full_name:number   (e.g. "zao/locum-site:234")
      github_issue:  repo_full_name:i:number (e.g. "zao/locum-site:i:42")
      email:         <message-id@domain>
      internal_task: task:<numeric_id>
  - If an item has no defensible external_id from the input, omit the item.
PROMPT;
    }

    /**
     * @param  array{client_name: string, window_days: int, since: string, signals: array<int, mixed>}  $evidence
     */
    protected function buildUserPrompt(array $evidence): string
    {
        $client = $evidence['client_name'];
        $window = $evidence['window_days'];
        $since = $evidence['since'];

        $bySource = [];
        foreach ($evidence['signals'] as $sig) {
            $bySource[$sig['source_type']][] = $sig;
        }

        $sections = [];
        foreach (['slack', 'email', 'github_pr', 'github_issue', 'internal_task'] as $type) {
            $rows = $bySource[$type] ?? [];
            if (empty($rows)) {
                continue;
            }
            $heading = strtoupper($type).' ('.count($rows).')';
            $body = collect($rows)->map(function (array $row): string {
                $when = $row['occurred_at']->toDateString();
                $who = $row['actor'] ?? 'unknown';
                $id = $row['external_id'];
                $content = trim(preg_replace('/\s+/', ' ', (string) $row['content']) ?? '');
                $content = mb_substr($content, 0, 600);

                return "[{$when}] [{$id}] {$who}: {$content}";
            })->implode("\n");

            $sections[] = "=== {$heading} ===\n{$body}";
        }

        $body = implode("\n\n", $sections);

        return <<<PROMPT
Client: {$client}
Window: last {$window} days (since {$since})

{$body}

Now cluster these into work items per the system prompt, classify each,
and return strict JSON. Drop noise aggressively.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(string $text): array
    {
        $original = $text;
        $parsed = $this->extractJsonObject($text);

        if (! is_array($parsed) || ! isset($parsed['items'])) {
            Log::warning('ClientActivityService: parseResponse failed', [
                'raw_excerpt' => substr($original, 0, 800),
                'json_last_error' => json_last_error_msg(),
            ]);

            return $this->emptyResponse('LLM returned unparseable response.');
        }

        $items = [];
        foreach ($parsed['items'] as $raw) {
            $status = in_array($raw['status'] ?? null, self::STATUSES, true)
                ? $raw['status']
                : 'noise';

            if ($status === 'noise') {
                continue;
            }

            $externalIds = [];
            foreach (($raw['external_ids'] ?? []) as $ref) {
                if (! is_array($ref) || empty($ref['source']) || empty($ref['id'])) {
                    continue;
                }
                $externalIds[] = [
                    'source' => (string) $ref['source'],
                    'id' => (string) $ref['id'],
                ];
            }

            if (empty($externalIds)) {
                continue;
            }

            $items[] = [
                'title' => (string) ($raw['title'] ?? 'Untitled'),
                'client_summary' => (string) ($raw['client_summary'] ?? ''),
                'status' => $status,
                'first_raised_at' => $this->parseDate($raw['first_raised_at'] ?? null),
                'last_activity_at' => $this->parseDate($raw['last_activity_at'] ?? $raw['first_raised_at'] ?? null),
                'evidence' => array_values(array_filter(array_map('strval', $raw['evidence'] ?? []))),
                'external_ids' => $externalIds,
            ];
        }

        return [
            'items' => $items,
            'generated_at' => now()->toIso8601String(),
            'warnings' => [],
        ];
    }

    protected function extractJsonObject(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $stripped = preg_replace('/```(?:json)?\s*|\s*```/m', '', $text) ?? $text;
        $decoded = json_decode(trim($stripped), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($stripped, '{');
        $end = strrpos($stripped, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($stripped, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyResponse(string $warning): array
    {
        return [
            'items' => [],
            'generated_at' => now()->toIso8601String(),
            'warnings' => [$warning],
        ];
    }
}
