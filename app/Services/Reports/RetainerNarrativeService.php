<?php

namespace App\Services\Reports;

use App\Models\Email;
use App\Models\RetainerPeriod;
use App\Models\SlackMessage;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\GitHub\GitHubApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses an LLM to cluster a retainer period's raw activity (Slack messages +
 * emails + commits) into work topics, estimate hours per topic, classify
 * status, and produce a value-delivered summary. Output is what a human
 * would write if they sat down for an hour with all the evidence — but
 * grounded in observable data, not made up.
 */
class RetainerNarrativeService
{
    public function __construct(
        protected RetainerHealthService $health,
    ) {}

    /**
     * Build the narrative for a retainer period. Cached per period for 6 hrs
     * to avoid re-running an expensive LLM call on every report view.
     *
     * @return array{
     *   topics: array<int, array{title: string, summary: string, estimated_hours: float, status: string, evidence: array<int, string>}>,
     *   value_summary: string,
     *   total_estimated_hours: float,
     *   generated_at: string,
     *   warnings: array<int, string>,
     * }
     */
    public function buildNarrative(RetainerPeriod $period, bool $force = false): array
    {
        $cacheKey = sprintf('retainer.narrative.%d', $period->id);

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($period) {
            $result = $this->compute($period);

            // Only sync entries on a successful LLM call. If we got a warning
            // (parse fail, provider down, etc.) and no topics, leave the
            // existing ai_estimated rows alone so the report doesn't go blank
            // after a transient failure. Persist on success even if topics is
            // empty — that case is "genuinely no activity" and should clear.
            $hasWarning = ! empty($result['warnings'] ?? []);
            if (! $hasWarning) {
                $this->persistAsTimeEntries($period, $result['topics'] ?? []);
            }

            return $result;
        });
    }

    /**
     * Cache-only read — never invokes the LLM. Use this from page-render paths
     * where a 60-90s API call would 502. Returns null if no cached narrative
     * exists; UI can show "Click Refresh to generate."
     */
    public function getCached(RetainerPeriod $period): ?array
    {
        return Cache::get(sprintf('retainer.narrative.%d', $period->id));
    }

    /**
     * Build the narrative from a pasted transcript instead of synced evidence.
     *
     * Used when the work happened somewhere the sync can't reach (a Slack
     * conversation the bot/user-token isn't in, an inactive workspace, etc.)
     * so `gatherEvidence()` would return nothing. The transcript is run through
     * the SAME system prompt, LLM provider chain, parser, and persistence path
     * as a normal narrative — only the evidence source differs — so hour
     * estimates and report output stay consistent with every other period.
     *
     * On success it persists ai_estimated time entries and caches the result so
     * the report picks it up on next render, exactly like buildNarrative().
     *
     * @return array<string, mixed>
     */
    public function buildNarrativeFromText(RetainerPeriod $period, string $transcript, bool $persist = true): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            return $this->emptyNarrative('No transcript provided.');
        }

        $period->loadMissing('client');

        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->buildTranscriptPrompt($period, $transcript);

        // Cloudflare Workers AI only — no Anthropic fallback (see compute()).
        $text = $this->callCloudflare($systemPrompt, $userPrompt, $period);

        if ($text === null) {
            return $this->emptyNarrative('Cloudflare Workers AI unavailable — check CLOUDFLARE_ACCOUNT_ID + CLOUDFLARE_API_TOKEN and the configured model. See logs for the API response.');
        }

        $result = $this->parseResponse($text);

        if ($persist && empty($result['warnings'])) {
            $this->persistAsTimeEntries($period, $result['topics'] ?? []);
            Cache::put(sprintf('retainer.narrative.%d', $period->id), $result, now()->addHours(6));
        }

        return $result;
    }

    /**
     * User prompt for the pasted-transcript path. Mirrors buildUserPrompt() but
     * derives the period window from the period itself and asks the model to
     * estimate hours directly from the conversation, since there is no
     * observable-signal floor for evidence that never synced.
     */
    protected function buildTranscriptPrompt(RetainerPeriod $period, string $transcript): string
    {
        $client = $period->client?->name ?? 'Client';
        $start = Carbon::parse($period->period_start);
        $end = Carbon::parse($period->period_end);
        $periodStr = $start->format('M j').' – '.$end->format('M j, Y');

        return <<<PROMPT
Client: {$client}
Period: {$periodStr}

The following is a pasted transcript of the period's working conversation
(Slack, email, or meeting notes). It is the ONLY evidence for this period —
there are no synced commit/Slack/email signals, so estimate each topic's hours
directly from what the conversation shows was discussed, decided, and shipped.
Stay grounded in the transcript; do not invent work it doesn't reference.

Constrain start_date/end_date for every topic to within {$start->toDateString()}
and {$end->toDateString()}.

=== TRANSCRIPT ===
{$transcript}

Cluster the above into work topics and return JSON now.
PROMPT;
    }

    /**
     * Sync narrative topics to time_entries with source='ai_estimated'. Replaces
     * any prior AI entries for this period in a transaction so the report
     * always reflects the current narrative without duplicating rows.
     *
     * One entry per topic, dated at the topic's end (delivery) date. Topics
     * whose title matches an entry a human already promoted to source='manual'
     * are skipped entirely — otherwise every regenerate would re-add an AI twin
     * of the corrected row.
     *
     * @param  array<int, array{title: string, summary: string, estimated_hours: float, status: string}>  $topics
     */
    public function persistAsTimeEntries(RetainerPeriod $period, array $topics): void
    {
        if (! $period->client_id) {
            return;
        }

        $periodStart = Carbon::parse($period->period_start);
        $periodEnd = Carbon::parse($period->period_end);

        DB::transaction(function () use ($period, $topics, $periodStart, $periodEnd) {
            TimeEntry::query()
                ->where('source', 'ai_estimated')
                ->where('retainer_period_id', $period->id)
                ->delete();

            $manualTitles = TimeEntry::query()
                ->where('retainer_period_id', $period->id)
                ->where('source', 'manual')
                ->pluck('notes')
                ->map(fn (?string $notes) => $this->normalizeTopicTitle($notes ?? ''))
                ->filter()
                ->all();

            foreach ($topics as $i => $topic) {
                $hours = round((float) ($topic['estimated_hours'] ?? 0), 2);
                if ($hours <= 0) {
                    continue;
                }

                $title = trim($topic['title'] ?? 'Untitled');
                if (in_array($this->normalizeTopicTitle($title), $manualTitles, true)) {
                    continue;
                }

                [, $endDate] = $this->topicDateRange($topic, $periodStart, $periodEnd);
                $notes = trim($title.(empty($topic['summary']) ? '' : ' — '.$topic['summary']));

                TimeEntry::create([
                    'client_id' => $period->client_id,
                    'hours' => $hours,
                    'source' => 'ai_estimated',
                    'retainer_period_id' => $period->id,
                    'source_ref' => sprintf('narrative.%d.%d', $period->id, $i),
                    'spent_date' => $endDate,
                    'notes' => $notes,
                    'is_billable' => true,
                    'is_billed' => false,
                ]);
            }
        });
    }

    /**
     * Comparison key for matching a regenerated topic against a manually
     * corrected entry. Entry notes are stored as "Title — Summary", so the
     * portion before the em-dash separator is the topic title.
     */
    protected function normalizeTopicTitle(string $value): string
    {
        return mb_strtolower(trim(explode(' — ', $value, 2)[0]));
    }

    /**
     * Resolve a topic's [start, end] date window, clamped to the period
     * bounds. Both fall back gracefully when LLM omits one or both.
     *
     * @return array{0: string, 1: string} [start_date, end_date] as YYYY-MM-DD
     */
    protected function topicDateRange(array $topic, Carbon $periodStart, Carbon $periodEnd): array
    {
        $start = $this->safeDate($topic['start_date'] ?? null, $periodStart, $periodEnd) ?? $periodStart->copy()->toDateString();
        $end = $this->safeDate($topic['end_date'] ?? null, $periodStart, $periodEnd) ?? $start;

        // If end somehow ended up before start (LLM transposed them), swap.
        if (Carbon::parse($end)->lt(Carbon::parse($start))) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    protected function safeDate(?string $value, Carbon $min, Carbon $max): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            $parsed = Carbon::parse($value);
            if ($parsed->between($min, $max)) {
                return $parsed->toDateString();
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(RetainerPeriod $period): array
    {
        $start = $period->windowStart();
        $end = $period->windowEnd();
        $period->loadMissing('client');

        $evidence = $this->gatherEvidence($period, $start, $end);

        if ($evidence['empty']) {
            return $this->emptyNarrative('No observable activity for this period.');
        }

        // Pre-compute heuristic hour floor from observable signals. The LLM
        // is instructed to use these as a baseline so estimates don't drift
        // below what the raw data already justifies.
        $estimated = $this->health->aggregateEstimatedHours($period, $start, $end);

        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->buildUserPrompt($period, $evidence, $estimated);

        // Cloudflare Workers AI is the sole provider for the narrative — no
        // Anthropic fallback (we intentionally keep this path free-tier only).
        $text = $this->callCloudflare($systemPrompt, $userPrompt, $period);

        if ($text === null) {
            return $this->emptyNarrative('Cloudflare Workers AI unavailable — check CLOUDFLARE_ACCOUNT_ID + CLOUDFLARE_API_TOKEN and the configured model. See logs for the API response.');
        }

        return $this->parseResponse($text);
    }

    protected function callCloudflare(string $systemPrompt, string $userPrompt, RetainerPeriod $period): ?string
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $model = config('services.cloudflare.narrative_model', '@cf/moonshotai/kimi-k2-instruct');

        if (! $accountId || ! $apiToken) {
            return null;
        }

        // Prefer structured JSON output (response_format) — without it, some
        // models wrap responses in markdown fences or add preambles. But not
        // every Workers AI model supports response_format and those reject the
        // request outright (HTTP 400). extractJsonObject() already recovers
        // JSON from fenced/prefixed text, so if the structured call fails we
        // retry once without response_format rather than losing the narrative.
        $text = $this->postCloudflare($accountId, $apiToken, $model, $systemPrompt, $userPrompt, $period, true);

        if ($text === null) {
            $text = $this->postCloudflare($accountId, $apiToken, $model, $systemPrompt, $userPrompt, $period, false);
        }

        return $text;
    }

    /**
     * One Workers AI chat-completion call. Returns the response text, or null
     * on any failure (logged). $structured toggles response_format=json_object.
     */
    protected function postCloudflare(
        string $accountId,
        string $apiToken,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        RetainerPeriod $period,
        bool $structured,
    ): ?string {
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            // Narrative JSON is compact (a handful of topics); 2048 output
            // tokens is plenty and leaves more of the model's context window
            // for the evidence input.
            'max_tokens' => 2048,
            // Low temperature + period-derived seed so re-running the narrative
            // for the same period produces the same hour estimates (within ~5%).
            'temperature' => 0.2,
            'seed' => crc32('retainer.'.$period->id),
        ];

        if ($structured) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(120)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}", $payload);

            if (! $response->successful() || ! $response->json('success', false)) {
                Log::warning('RetainerNarrativeService: Cloudflare Workers AI error', [
                    'period_id' => $period->id,
                    'structured' => $structured,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json('result.response') ?? $response->json('result.output.0.content') ?? null;
        } catch (\Throwable $e) {
            Log::warning('RetainerNarrativeService: Cloudflare call threw', [
                'period_id' => $period->id,
                'structured' => $structured,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Pull raw period activity. Bounded to keep the LLM prompt under control:
     * up to 200 Slack messages, 50 emails, 50 commits.
     *
     * @return array{empty: bool, slack: array, emails: array, commits: array, meetings: array, client_name: string, period: string}
     */
    protected function gatherEvidence(RetainerPeriod $period, Carbon $start, Carbon $end): array
    {
        $slack = SlackMessage::query()
            ->where('client_id', $period->client_id)
            ->whereRaw('coalesce(sent_at, created_at) >= ?', [$start])
            ->whereRaw('coalesce(sent_at, created_at) <= ?', [$end])
            ->orderByRaw('coalesce(sent_at, created_at)')
            ->limit(200)
            ->get(['user_name', 'user_id', 'content', 'sent_at', 'created_at'])
            ->map(fn ($m) => [
                'when' => $this->localDate($m->sent_at ?? $m->created_at),
                'who' => $this->cleanUtf8((string) ($m->user_name ?: $m->user_id)),
                'text' => trim($this->cleanUtf8((string) $m->content)),
            ])
            ->filter(fn ($m) => $m['text'] !== '')
            ->values()
            ->all();

        $domains = $this->health->clientEmailDomains($period->client);
        $emailQuery = Email::query()
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($q) use ($period, $domains) {
                $q->where('client_id', $period->client_id);
                foreach ($domains as $domain) {
                    $needle = '%@'.strtolower($domain);
                    $q->orWhereRaw('lower(from_address) like ?', [$needle])
                        ->orWhereRaw('lower(cast(to_addresses as text)) like ?', [$needle.'%']);
                }
            });

        $emails = $emailQuery->orderBy('created_at')->limit(50)->get()->map(fn ($e) => [
            'when' => $this->localDate($e->received_at ?? $e->created_at),
            'from' => $this->cleanUtf8((string) ($e->from_name ?: $e->from_address)),
            'subject' => $this->cleanUtf8((string) $e->subject),
            'snippet' => $this->cleanUtf8(mb_substr((string) $e->body_text, 0, 400)),
        ])->all();

        $commits = [];
        $repos = $this->health->reposForClient($period->client_id);
        $api = app(GitHubApiService::class);
        foreach ($repos as $repo) {
            if (! $repo->installation_id) {
                continue;
            }
            try {
                $list = Cache::remember(
                    sprintf('retainer.commits.%d.%s.%s', $repo->id, $start->toDateString(), $end->toDateString()),
                    now()->addHour(),
                    fn () => $api->listCommits($repo, $start->toIso8601String(), $end->toIso8601String()),
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($list as $c) {
                $commits[] = [
                    'when' => $this->localDate($c['commit']['committer']['date'] ?? null) ?? '',
                    'author' => $this->cleanUtf8((string) ($c['author']['login'] ?? ($c['commit']['author']['name'] ?? '?'))),
                    'message' => $this->cleanUtf8((string) strtok($c['commit']['message'] ?? '', "\n")),
                    'repo' => $this->cleanUtf8((string) $repo->name),
                ];
            }
        }
        $commits = array_slice($commits, 0, 50);

        // Completed tasks (e.g. from the client's tracking spreadsheet). This is
        // delivered work that often never surfaces in Slack/commits/email, so
        // it's a first-class evidence source. Linked via project → client.
        $tasks = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $period->client_id))
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->orderBy('completed_at')
            ->limit(100)
            ->get(['title', 'description', 'completed_at'])
            ->map(fn ($t) => [
                'when' => $this->localDate($t->completed_at),
                'title' => $this->cleanUtf8((string) $t->title),
                'detail' => $this->cleanUtf8(mb_substr((string) ($t->description ?? ''), 0, 200)),
            ])
            ->all();

        $empty = empty($slack) && empty($emails) && empty($commits) && empty($tasks);

        return [
            'empty' => $empty,
            'slack' => $slack,
            'emails' => $emails,
            'commits' => $commits,
            'tasks' => $tasks,
            'meetings' => [], // future: pull from CalendarEvent
            'client_name' => $period->client?->name ?? 'Client',
            'period' => $start->format('M j').' – '.$end->format('M j, Y'),
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an analyst preparing a retainer report for an agency owner. You are
given raw activity from one month: Slack messages, emails, and Git commits.
Your job: cluster this evidence into discrete work topics, estimate hours
per topic, classify each topic's status, and write a brief paragraph
summarizing the value delivered to the client this period.

OUTPUT STRICT JSON ONLY, no prose around it, matching this shape:

{
  "topics": [
    {
      "title": "Short imperative title (e.g. 'Posted-date refresh for Salesforce jobs')",
      "summary": "1-2 sentence description of what was discussed/done",
      "estimated_hours": 7.5,
      "status": "completed" | "in_progress" | "incomplete" | "discussion_only",
      "start_date": "YYYY-MM-DD",  // first day of evidence for this topic
      "end_date": "YYYY-MM-DD",    // last day of evidence; same as start_date if single-day
      "evidence": ["Slack: brief quote or summary referencing what was seen", "Commit: sha or msg"]
    }
  ],
  "value_summary": "2-3 sentence paragraph framing what was delivered this period. Focus on outcomes for the client, not raw activity volume."
}

DATE DERIVATION: For start_date and end_date, look at the timestamps on
the Slack messages, emails, and commits you cite as evidence for the
topic. Use the earliest evidence timestamp as start_date and the latest
as end_date. Both fields are required; they may be the same date.

Estimation guidance:
- A back-and-forth discussion in Slack that resolves in 30 min is 0.5h.
- Plugin updates / dependency bumps discussed in passing: 0.25-0.5h total.
- A code change shipped via commits: estimate from message + scope (small fix = 0.5h, feature = 2-8h).
- An IP block unblock / quick admin task: 0.25-0.5h.
- Don't double-count across surfaces. A single piece of work usually shows up in
  MULTIPLE places — a completed task, the Slack thread discussing it, AND the
  commit that shipped it are the SAME work, not three. Cluster them into ONE
  topic and count the time once. Match on subject, not source: e.g. a "Plugin
  updates" task + a Slack thread about plugin updates + a deps-bump commit = one
  "Plugin updates" topic.
- COMPLETED TASKS are confirmed deliverables. Treat each as a real topic even if
  it has little or no Slack/commit/email evidence (the client tracks work in a
  spreadsheet that doesn't surface elsewhere). A completed task with no other
  signal still represents delivered work and should appear with a reasonable
  hour estimate.
- NEVER create a topic for billing, invoicing, payments, or the retainer fee
  itself. Processing the invoice, sending/collecting payment, "monthly retainer"
  bookkeeping, and similar finance admin are internal overhead — we do not bill
  the client for billing them. Omit this work entirely: no topic, no hours, and
  do not mention it in value_summary. This applies even if a completed task or
  email is explicitly about it.
- Status definitions:
  * completed = shipped, merged, resolved
  * in_progress = ongoing across multiple messages/commits, no clear close
  * incomplete = started but evidence suggests work stopped before finishing
  * discussion_only = talked about, no concrete delivery

CRITICAL: The user prompt will give you an OBSERVABLE HOUR FLOOR computed
from raw signals (Slack on-task, commit effort, email triage). Your topic
hours MUST sum to at least the floor — preferably ~10% above. Each topic
inherently includes the work-context-switching, code-review, deployment,
and follow-up that aren't captured by raw signal counts alone. Under-
estimating creates real harm: the agency uses these numbers to defend
invoices to clients. Be realistic, lean slightly generous.

If a topic genuinely has zero evidence of work happening, omit it
rather than padding with hours.
PROMPT;
    }

    protected function buildUserPrompt(RetainerPeriod $period, array $evidence, array $estimated = []): string
    {
        $client = $evidence['client_name'];
        $periodStr = $evidence['period'];

        // Cap each evidence block's character size. The combined prompt has to
        // fit the model's context window alongside the system prompt and 4096
        // output tokens; an unbounded month of Slack + emails + commits can
        // overflow it and the Workers AI call fails outright. ~4 chars/token,
        // so these budgets keep total input well under a 24k-token window.
        $slack = $this->truncateBlock(
            collect($evidence['slack'])->map(fn ($m) => "[{$m['when']}] {$m['who']}: {$m['text']}")->implode("\n"),
            24000,
        );
        $emails = $this->truncateBlock(
            collect($evidence['emails'])->map(fn ($e) => "[{$e['when']}] FROM {$e['from']} — {$e['subject']}\n  > ".str_replace("\n", ' ', $e['snippet']))->implode("\n"),
            8000,
        );
        $commits = $this->truncateBlock(
            collect($evidence['commits'])->map(fn ($c) => "[{$c['when']}] {$c['repo']} ({$c['author']}): {$c['message']}")->implode("\n"),
            6000,
        );
        $tasks = $this->truncateBlock(
            collect($evidence['tasks'] ?? [])->map(fn ($t) => "[{$t['when']}] {$t['title']}".($t['detail'] !== '' ? " — {$t['detail']}" : ''))->implode("\n"),
            8000,
        );

        $floor = (float) ($estimated['total_hours'] ?? 0);
        $slackHours = number_format((float) ($estimated['slack_hours'] ?? 0), 1);
        $commitHours = number_format((float) ($estimated['commit_hours'] ?? 0), 1);
        $emailHours = number_format((float) ($estimated['email_hours'] ?? 0), 1);
        $floorStr = number_format($floor, 1);
        // Target band: floor to 1.4× floor. Gives LLM room to upgrade
        // for complex work but prevents underestimation that would
        // shortchange the agency on invoicing defenses.
        $target = number_format($floor * 1.1, 1);
        $ceiling = number_format($floor * 1.4, 1);

        return <<<PROMPT
Client: {$client}
Period: {$periodStr}

=== OBSERVABLE HOUR FLOOR ===
Independent heuristic estimates from the raw data:
  Slack on-task: {$slackHours} hrs
  Commit effort: {$commitHours} hrs
  Email triage:  {$emailHours} hrs
  TOTAL FLOOR:   {$floorStr} hrs

YOUR TOPIC HOURS MUST SUM TO BETWEEN {$floorStr} AND {$ceiling} HOURS.
Aim for ~{$target} hrs unless one topic is clearly disproportionately
large or small. Anything below {$floorStr} hrs would underrepresent
the observable work and is not acceptable.

=== SLACK MESSAGES ({$this->count($evidence['slack'])}) ===
{$slack}

=== EMAILS ({$this->count($evidence['emails'])}) ===
{$emails}

=== COMMITS ({$this->count($evidence['commits'])}) ===
{$commits}

=== COMPLETED TASKS ({$this->count($evidence['tasks'] ?? [])}) ===
{$tasks}

Cluster the above into work topics. A completed task and any Slack/commit/email
about the same work are ONE topic — merge them and count the time once. Every
completed task should be represented (it's confirmed delivered work) even if it
has no other evidence. Distribute the total hours across topics in proportion to
their evidence weight. Return JSON now.
PROMPT;
    }

    /**
     * Cap a prompt evidence block to $maxChars, keeping the most recent lines
     * (the tail) since evidence is ordered oldest-first and recent activity is
     * the most relevant. Adds a marker noting earlier lines were trimmed.
     */
    protected function truncateBlock(string $block, int $maxChars): string
    {
        if (mb_strlen($block) <= $maxChars) {
            return $block;
        }

        // mb_substr keeps whole characters so we never slice a multibyte
        // sequence in half (which would create malformed UTF-8 that breaks
        // json_encode when the prompt is sent to the API).
        $kept = mb_substr($block, -$maxChars);
        // Drop a partial first line so the block starts cleanly.
        $newlinePos = strpos($kept, "\n");
        if ($newlinePos !== false) {
            $kept = substr($kept, $newlinePos + 1);
        }

        return "[… earlier entries trimmed to fit the model context window …]\n".$kept;
    }

    /**
     * Coerce a string to valid UTF-8. Slack/email content frequently carries
     * malformed byte sequences (truncated multibyte chars, mojibake from pasted
     * screenshots) that make json_encode() throw when the prompt is sent to the
     * API — which silently aborts the whole narrative. Drop invalid bytes.
     */
    protected function cleanUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Substitute/drop invalid sequences rather than throw.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $clean !== false ? $clean : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    protected function count(array $items): int
    {
        return count($items);
    }

    /**
     * Billing/invoicing/payment work is internal overhead — we never bill a
     * client for billing them, so it must not appear as a retainer line item.
     * Matches the topic's title + summary against finance-admin phrasing while
     * sparing deliverable work (e.g. building a payment-gateway integration):
     * a bare "payment" only counts when tied to the retainer or an invoice.
     *
     * @param  array{title?: string, summary?: string}  $topic
     */
    protected function isBillingTopic(array $topic): bool
    {
        $haystack = strtolower(($topic['title'] ?? '').' '.($topic['summary'] ?? ''));

        foreach (['billing', 'invoic', 'accounts payable', 'accounts receivable'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return str_contains($haystack, 'payment')
            && (str_contains($haystack, 'retainer') || str_contains($haystack, 'invoic'));
    }

    /**
     * Render a UTC timestamp as a calendar date in the agency's display
     * timezone, so evidence dates shown to the LLM line up with the Pacific
     * period window (and don't appear to spill a day past period_end).
     */
    protected function localDate(\Carbon\CarbonInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)
            ->setTimezone(config('app.display_timezone'))
            ->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(string $text): array
    {
        $original = $text;
        $parsed = $this->extractJsonObject($text);

        if (! is_array($parsed) || ! isset($parsed['topics']) || ! isset($parsed['value_summary'])) {
            // Capture the actual response so the next failure tells us what
            // shape we're dealing with — instead of just "unparseable."
            Log::warning('RetainerNarrativeService: parseResponse failed', [
                'raw_excerpt' => substr($original, 0, 800),
                'json_last_error' => json_last_error_msg(),
                'parsed_type' => gettype($parsed),
            ]);

            return $this->emptyNarrative('LLM returned unparseable response.');
        }

        $topics = array_map(function ($t) {
            return [
                'title' => (string) ($t['title'] ?? 'Untitled'),
                'summary' => (string) ($t['summary'] ?? ''),
                'estimated_hours' => round((float) ($t['estimated_hours'] ?? 0), 2),
                'status' => in_array($t['status'] ?? null, ['completed', 'in_progress', 'incomplete', 'discussion_only'], true)
                    ? $t['status']
                    : 'discussion_only',
                'start_date' => $this->parseDate($t['start_date'] ?? null),
                'end_date' => $this->parseDate($t['end_date'] ?? $t['start_date'] ?? null),
                'evidence' => array_values(array_filter(array_map('strval', $t['evidence'] ?? []))),
            ];
        }, $parsed['topics']);

        // Safety net: we never bill the client for billing them. Drop any topic
        // about invoicing/payments even if the model ignored the instruction.
        $topics = array_values(array_filter($topics, fn ($t) => ! $this->isBillingTopic($t)));

        return [
            'topics' => $topics,
            'value_summary' => (string) $parsed['value_summary'],
            'total_estimated_hours' => round(array_sum(array_column($topics, 'estimated_hours')), 2),
            'generated_at' => now()->toIso8601String(),
            'warnings' => [],
        ];
    }

    /**
     * Defensive JSON extraction. Handles:
     *  - bare JSON object
     *  - JSON wrapped in markdown fences (```json ... ```)
     *  - JSON with preamble/trailing prose ("Here's the JSON: {...} Hope this helps!")
     *  - JSON with stray whitespace / non-printables
     * Returns parsed array or null if no valid JSON object can be located.
     */
    protected function extractJsonObject(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Fast path: already pure JSON.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip common markdown fence patterns.
        $stripped = preg_replace('/```(?:json)?\s*|\s*```/m', '', $text) ?? $text;
        $decoded = json_decode(trim($stripped), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to locating the outermost balanced {...} block. This is
        // surprisingly common with open-source models that emit "Here is the
        // JSON: { ... } Let me know if you need more!"
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
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyNarrative(string $warning): array
    {
        return [
            'topics' => [],
            'value_summary' => '',
            'total_estimated_hours' => 0.0,
            'generated_at' => now()->toIso8601String(),
            'warnings' => [$warning],
        ];
    }
}
