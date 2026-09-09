# Client Activity Feed

Synthesises raw signals from Slack, email, GitHub, and existing internal tasks
into a client-facing "currently active" view. Designed to answer the question
*"what's in flight for this client right now?"* without anyone having to keep
a task list by hand.

## What it answers

For each active-retainer client the system continuously surfaces:

- **Open** — they raised it, we haven't substantively moved on it
- **In progress** — we're actively working (recent commits, replies, internal task in `in_progress`/`review`)
- **Waiting on you** — our last reply ended with a question or asked for input; ball is in their court
- **Recently completed** — shipped or merged in the last 14 days

These appear in three surfaces:

1. **Project Tasks tab** — auto-created Task rows with `source='activity-feed'`, plus a "from Slack" / "from PR #234" badge linking to the original.
2. **Activity view** — `/clients/{slug}/activity` and `/clients/{slug}/projects/{slug}/activity`. Grouped by client-facing status, written for the client.
3. **Retainer report PDF** — a "Currently active" section above the closed-period billing narrative.

## How the pipeline works

```
SignalCollector ──▶ ClientActivityService (LLM) ──▶ PersistActivityAsTasks
   │                       │                              │
   ├─ Slack (60d)          ├─ Cluster into items          ├─ Lookup ExternalTaskMapping by external_id
   ├─ Email (60d)          ├─ Classify status             ├─ updateOrCreate Task
   ├─ GitHub (open)        ├─ Filter noise                ├─ Skip if manually edited
   └─ Internal tasks       └─ Emit external_ids           └─ Upsert mappings with client-facing status
```

- **Rolling window**: 60 days. Tuned in `SignalCollector::WINDOW_DAYS`.
- **LLM**: Cloudflare Workers AI (Kimi K2 by default) → Anthropic Claude Sonnet fallback. Same providers as the retainer narrative service.
- **Cache TTL**: 30 minutes (`client.activity.{client_id}`). Shorter than the 6h retainer-narrative cache because "current" should feel current.
- **Dedup**: every synthesised item carries `external_ids` (e.g. `slack:C0123:1715000000.123`, `github_pr:zao/repo:42`). The persistence layer looks these up against `ExternalTaskMapping` rows and updates the existing Task rather than creating a duplicate.
- **Conflict protection**: if a Task's `updated_at` is newer than the mapping's `last_synced_at`, we skip the field update (someone edited it manually) and just refresh the mapping payload.

## Detection rules (what the LLM is told)

Located in `app/Services/Activity/ClientActivityService::systemPrompt()`. Be aggressive about marking things `noise`:

- Pure FYIs and forwarded notifications with no explicit ask
- Status check-ins ("any update on X?") — merge into the existing item, never become their own
- Acknowledgments, thanks, emoji-only reactions
- Social or contextual chatter
- Venting about vendors without a request to us
- Commentary on something we already shipped, without a new ask

`waiting_on_client` is **strict**: the most recent message must be from us AND contain an explicit question or ask for input. An FYI or status update does not qualify.

## Running it

```bash
# Sync all active-retainer clients (production: scheduled hourly during business hours)
php artisan activity:sync

# Sync a specific client by id or slug
php artisan activity:sync --client=locum-media-llc

# Bypass the 30-min synthesis cache and the per-client debounce
php artisan activity:sync --force
```

The scheduled run is defined in `routes/console.php`:

```php
Schedule::command('activity:sync')
    ->cron('0 8-18 * * 1-5')         // top of every business hour, M–F
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->name('sync-client-activity');
```

## File map

| Layer | File |
|---|---|
| Migration: tasks enum | `database/migrations/2026_05_18_230220_add_activity_feed_to_tasks_source_enum.php` |
| Migration: external_task_sources | `database/migrations/2026_05_18_230221_make_pm_connection_nullable_and_add_client_to_external_task_sources.php` |
| Signal orchestrator | `app/Services/Activity/SignalCollector.php` |
| Slack collector | `app/Services/Activity/Signals/SlackSignalCollector.php` |
| Email collector | `app/Services/Activity/Signals/EmailSignalCollector.php` |
| GitHub collector | `app/Services/Activity/Signals/GitHubSignalCollector.php` |
| Internal task collector | `app/Services/Activity/Signals/InternalTaskSignalCollector.php` |
| LLM synthesis | `app/Services/Activity/ClientActivityService.php` |
| Task persistence | `app/Services/Activity/PersistActivityAsTasks.php` |
| Console command | `app/Console/Commands/SyncClientActivity.php` |
| Schedule entry | `routes/console.php` (search `sync-client-activity`) |
| Web routes | `routes/web.php` (search `clients.activity`) |
| Controller | `app/Http/Controllers/ActivityController.php` |
| Inertia page | `resources/js/Pages/Activity/Show.vue` |
| PDF section | `resources/views/retainer-reports/show.blade.php` (search `Currently active`) |
| Tests | `tests/Feature/Activity/*` |

## Troubleshooting

**The Activity view shows "Nothing currently in flight" but Slack/email activity exists.**
Run `php artisan activity:sync --client={slug} --force`. If still empty, check `storage/logs/laravel.log` for `ClientActivityService:` entries — likely an LLM provider misconfig.

**Tasks tab shows duplicates.**
A Task with `source='activity-feed'` should NEVER duplicate. If it does, one of two things happened:
1. The same logical item produced two different `external_ids` across runs (LLM hallucinated a different ID). The fix is to merge the mappings and delete the duplicate Task by hand; the next sync will recognise the surviving mapping.
2. A manual task and an auto-task describe the same thing but no shared `external_id` links them. Add an `ExternalTaskMapping` pointing the manual Task at the auto-detected external_id and the next run will merge.

**LLM keeps overriding a Task title I edit by hand.**
That shouldn't happen — `hasManualEditSinceLastSync()` should detect it. Confirm the mapping's `last_synced_at` is older than the Task's `updated_at`. If sync_at is being updated unconditionally, that's the bug.

**`pm_connection_id` constraint error.**
The migration relaxed this column to nullable. If a fresh deploy fails, confirm `2026_05_18_230221_*` ran. On Postgres the column should now be NULLable; on SQLite the table is recreated.

## Cost / rate-limiting notes

Each `activity:sync` run makes 1 LLM call per active client. Cloudflare Workers AI has a generous free tier; Anthropic fallback bills at Sonnet rates. With ~10 active retainer clients and 11 hourly runs per business day, the upper bound is ~110 calls/day. The 30-min per-client debounce + 30-min synthesis cache prevent runaway invocation when surfaces request fresh data simultaneously.
