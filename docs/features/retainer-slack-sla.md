# Retainer Slack SLA (first response and time-to-merge)

Retainer reports now include two Slack service stats, computed when the period snapshot is built. They sit under the existing Slack message counts. They are not hours and are not folded into budget math.

v1 clocks **time-to-merge** (a thread actually tied to a merged PR). It does not clock deploy, and it does not invent a Slack close from last-reply, emoji, "thanks", or `slack_request_patterns.is_resolved`.

## What the report shows

Under the Slack cell:

- First response: median / average, plus `N/M` client-originated threads that got a Zao reply
- Time to merge: median / average, plus `N/M` threads that shipped (merged)

Coverage also appears in the "What happened this period" subtitle.

Unreplied and unshipped threads stay in the denominator. They are not averaged as if they resolved.

## How first-response is computed

`RetainerHealthService::aggregateSlackSla()` (called from `aggregatePeriodActivity`):

1. Period-touching messages: `sent_at` in the snapshot window, or `sent_at` null **and** `message_ts` in that same unix window. This does not scan every future row or every legacy null `sent_at`.
2. Conversation key: `channel_id + COALESCE(thread_ts, message_ts)` for those period-touching rows
3. History load: earlier (and later) rows on those same keys are loaded so origin is the first message in the conversation, not the first row that happened to fall in the window
4. Origin: earliest message on that key. If that clock is outside `$start`/`$end`, the conversation is not attributed to this period — an in-period client follow-up is not a new origin
5. Client-originated: origin `user_id` is **not** in `resolveInternalSlackIds()`
6. First Zao reply: earliest later message whose `user_id` **is** in that internal set
7. Clock: `COALESCE(sent_at, to_timestamp(message_ts))` — never `created_at` (sync time) and never `user_is_external`

Workspace `bot_user_id` messages are skipped as first-response even when that bot id is also in the internal set. Bot acks are not a human reply.

If `resolveInternalSlackIds()` is empty, SLA is reported as zero. The fallback `user_is_external` path used for message *counts* is not used here.

## How time-to-merge is computed

Only a merged PR after the origin counts. Two joins, precise first:

1. Slack-sourced `AgentRun` (`invocation_source = slack`) whose `context.slack.thread_ts` matches the conversation, with `output.pr_number` (and `output.pr_url` when present) → `github_pull_requests.merged_at` on a **client** repo
2. Heuristic: repo-qualified `https://github.com/{client-repo}/pull/N` permalinks in the thread. Do not reuse greedy `#(\d+)` issue matching.

The PR must merge **after** the origin. If several PRs match, the earliest merge after origin is used.

Slack-sourced `AgentRun` rows are filtered to the period's conversation `thread_ts` values. Merged PRs are loaded by referenced number with `merged_at` on or after the earliest in-period origin — not the client's full PR history.

Shipped fraction = merged conversations / all client-originated conversations in the period. Unshipped threads are visible in `M` and are not included in the median/average.

## Period window

Origins are attributed with the `$start` / `$end` passed into aggregation. The PDF path uses `RetainerPeriod::windowStart()` / `windowEnd()` (agency local calendar days converted to UTC). A message at 03:00 UTC on the 1st is still the previous local day in Pacific.

Replies and merges may land after the period end. They still count against an in-period origin.

## Setup / ops notes

No new Slack consumer. No deployments table. No production config change is required for v1.

**`SLACK_INTERNAL_USER_IDS` completeness matters.** First-response (and who counts as "client origin") is the union of:

- `users.slack_user_id`
- `services.slack.internal_user_ids` (`SLACK_INTERNAL_USER_IDS`)
- `services.slack.owner_user_id`

If a Zao teammate is missing from that set, their reply is not first-response, and a thread they started may be mis-counted as client-originated.

Bot user ids live on `slack_workspaces.bot_user_id`. Keep them out of the "first reply" clock even if they are also listed as internal.

## Tests

`tests/Feature/RetainerReportPeriodActivityTest.php` covers happy path, bot acks, `user_is_external` ignored, message_ts clock, greedy `#N` ignored, merge-before-origin ignored, unshipped fraction, PDF labels (`Time to merge` / merged, not deployed), and pre-period origins (including an in-period client follow-up that must not become a new origin).
