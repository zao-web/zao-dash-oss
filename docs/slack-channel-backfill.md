# Slack Channel Linking & History Backfill

How Slack channels get attached to clients, why a channel's messages can be
missing from a retainer report, and how to backfill them.

## How channels link to clients

`SlackChannelMatcherService` auto-links channels to clients using three signals:

- **Channel name match** — slug comparison against client and project names.
  Matching uses *distinctive root tokens*: generic corporate words (LLC, Inc,
  Media, Group, Agency, …) are stripped before comparison, so a channel named
  `example-client` matches client `Example Client LLC` on the shared root `locum`.
  Plain slug containment alone misses this.
- **Shared-channel signal** — Slack Connect channels are strong client signals.
- **Participant domains** — external participant emails matched to client
  contact domains.

Channels scoring ≥70 confidence are auto-linked (this runs inside
`ProcessSlackMessagesJob`); lower scores surface as suggestions.

**Linking also enables monitoring.** When a channel is auto-linked it is set to
`is_monitored = true` and `monitoring_enabled = true`. This matters because
`SyncSlackJob` only pulls history for monitored channels — a channel linked to a
client but left unmonitored would never ingest its messages, and would silently
stay empty on retainer reports.

## Why messages can be missing

A retainer report's narrative is built from `slack_messages` rows carrying the
client's `client_id`. Messages get that `client_id` from their channel at sync
time, so a message is only on the report if **all** of these held when it synced:

1. its channel is linked to the client (`slack_channels.client_id`), and
2. the channel is monitored (`is_monitored = true`), and
3. the sync window reached back far enough to include the message.

Routine `SyncSlackJob` is incremental: it fetches from the channel's last sync,
or — for a channel that has never synced — a 30-day cold-start window. So a
channel that was just linked won't have its older history until you backfill it.

## Backfilling history

```bash
# Link to a client and backfill the last 60 days
php artisan slack:backfill-channel C00EXAMPLE02 --client="Example Client LLC" --since="60 days"

# Backfill an already-linked channel from an absolute date
php artisan slack:backfill-channel 4325 --since=2026-05-01
```

- `channel` accepts the `slack_channels.id` (numeric) or the Slack channel/DM id
  (e.g. `C00EXAMPLE02`).
- `--since` accepts a relative phrase (`"30 days"`, `"2 weeks"`) or an absolute
  date (`2026-05-01`); defaults to 30 days.
- `--client` links the channel (and enables monitoring) before syncing; omit it
  if the channel is already linked.
- Runs synchronously and reports the before/after message count.
- On a zero-message result the command probes Slack directly and prints the real
  API outcome — either the error (`not_in_channel`, `missing_scope`, …) or a
  confirmation that the conversation is genuinely empty in range.

### Picking the right conversation

A client often has more than one Slack conversation, and they're easy to confuse:

- A **regular channel** (e.g. `example-client`, `C00EXAMPLE02`) — `is_dm = false`.
- A **group DM / MPIM** (e.g. `Example group DM`, `C00EXAMPLE01`) — `is_dm = true`,
  the "DM with just these people" conversation.

If a backfill reports `0 → 0`, first confirm you targeted the conversation that
actually holds the discussion. Reading group DMs the bot isn't a member of
requires a workspace **user OAuth token** with `mpim:history` — the fetch falls
back to that token automatically when the bot can't see the conversation.

> Note: the scheduled `SyncSlackJob` only syncs workspaces with `is_active = true`.
> A manual `slack:backfill-channel` bypasses that (it targets the workspace by id),
> but if a workspace is inactive its channels won't stay current on the normal
> 30-minute cadence — flip `slack_workspaces.is_active` to keep them syncing.

Manual one-off linking without a backfill is still available via
`php artisan slack:track-channel {slack_id} {client}`; the next scheduled sync
then pulls history within the normal window.

## Narrative shows "No LLM provider available"

The retainer report narrative needs an LLM. If you see:

> No LLM provider available (configure CLOUDFLARE_ACCOUNT_ID + CLOUDFLARE_API_TOKEN, or fund Anthropic credits).

…then **both** providers were unavailable when the narrative ran. The service
tries Cloudflare Workers AI first (free tier), then Anthropic. Fix by setting
`CLOUDFLARE_ACCOUNT_ID` + `CLOUDFLARE_API_TOKEN` in the Laravel Cloud
environment, or by funding Anthropic credits for `ANTHROPIC_API_KEY`, then hit
**Refresh** on the report to regenerate. This is independent of Slack ingestion:
even with a provider configured, the narrative can only summarize evidence that
has actually synced (see above).

## When the work never synced anywhere: narrative from a pasted transcript

Sometimes a period's work happened in a conversation the sync can't reach — a
Slack channel/DM the bot and user token aren't in, a workspace that was
`is_active = false`, or a thread that lived in email. `slack:backfill-channel`
can't help there: there's nothing for it to pull. The report would otherwise
show an empty period even though real work happened.

For those cases, build the narrative directly from a pasted transcript:

```bash
# From a heredoc paste (Laravel Cloud "Run command" box supports this):
php artisan retainer:narrative-from-text --period=5 <<'TRANSCRIPT'
[paste the full month of conversation here]
TRANSCRIPT

# Or from a file in storage/app:
php artisan retainer:narrative-from-text --period=5 --file=locum-may.txt

# Preview without saving:
php artisan retainer:narrative-from-text --period=5 --dry-run <<'TRANSCRIPT'
[paste]
TRANSCRIPT
```

- `--period` is `retainer_periods.id` (the number in the report URL, e.g.
  `/retainers/5/report` → `--period=5`).
- The transcript runs through the **same** system prompt, LLM provider chain
  (Cloudflare → Anthropic), parser, and persistence as a normal report — only
  the evidence source differs — so hour estimates stay consistent with every
  other period. The model is told to estimate hours directly from the
  conversation and to keep topic dates within the period window.
- On success it writes `ai_estimated` time entries and caches the narrative, so
  the report reflects it on the next load (no separate Refresh needed).
- `--dry-run` prints the topic/hours table and value summary without saving.

### From here: the MCP tool

The same capability is exposed as a zao-dash MCP tool,
`build-retainer-narrative-from-text`, so you can run it without shell access:

- `period_id` — `retainer_periods.id` (the number in the report URL).
- `transcript` — paste the full month of conversation.
- `dry_run` — set true to preview topics/hours/summary without saving.

It runs the identical pipeline as the artisan command and persists the same
`ai_estimated` time entries + narrative cache, so the report reflects it on next
load.
