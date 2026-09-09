# Google Sheet Task Sync

Bidirectional sync between a client-owned Google Sheet tracker and Zao's
internal Task table. Each row in the sheet becomes a Task; status changes
flow both ways. Designed for clients who prefer maintaining their issue
list as a spreadsheet rather than learning a new tool.

## Status semantics (the rule that matters most)

The sheet's `Status` column and our internal `Task.status` enum map like
this, with **asymmetric** writeback:

| Trigger                         | Sheet shows | Internal status |
|---------------------------------|-------------|-----------------|
| Client adds a row               | Pending     | pending (we create the Task) |
| We pick it up                   | **Underway** ← we write | in_progress |
| We ship for review              | **Reviewing** ← we write | review |
| Client verifies                 | Done ← only client writes | completed (on next sync) |

We **never** push `Done` to the sheet — the client owns that final verdict.
We **never** push `pending` back — that would overwrite their initial
`Pending` with our own pending.

## How dedup works

Every row we touch gets a `Zao ID` column appended (we create it on first
sync if absent) containing a `zao_*` ULID. Subsequent syncs match rows by
that ID, so reorders / reworded descriptions don't create duplicates.
The client should leave the `Zao ID` column alone.

In the `external_task_mappings` table, each row is keyed
`gs:{spreadsheet_id}:{zao_id}` on the `google_sheet` source.

## Conflict resolution

Snapshots of the sheet status and the task status are stored in
`external_task_mappings.external_data` on every sync. On the next pass:

- If only the sheet status changed since last sync → update the Task
- If only the task status changed since last sync → queue a sheet write
- If both changed → sheet wins (logged for review); we can't get
  per-cell Sheets API timestamps to do real last-writer-wins

The TaskObserver also fires `PushTaskStatusToSheetJob` immediately when a
Task's status changes inside Zao Dash, so the sheet reflects our updates
in seconds rather than waiting for the next 15-minute sync tick.

## Setup checklist for a new client sheet

1. **Re-authorize Google** in Settings → Google Connection. The
   `spreadsheets` scope was added in May 2026; existing tokens predate it
   and need a fresh consent.

2. **Share the sheet** with the same Google account whose credentials
   Zao uses (the User's `google_credentials` row).

3. **Create the ClientSheetSync row** for this client + sheet:

   ```bash
   php artisan tinker --execute='
   \App\Models\ClientSheetSync::create([
       "client_id"      => YOUR_CLIENT_ID,
       "spreadsheet_id" => "GOOGLE_SHEET_ID_FROM_URL",
       "sheet_title"    => null,   // will resolve to the first tab on first sync
       "sheet_gid"      => null,
       "header_row"     => 1,
       "column_map"     => \App\Models\ClientSheetSync::defaultColumnMap(),
       "active"         => true,
   ]);'
   ```

4. **Run the first sync manually** to surface any column-map issues
   before the cron picks it up:

   ```bash
   php artisan sheets:sync --client=CLIENT_SLUG --force
   ```

   On the first sync the service:
   - Resolves the sheet title (first tab if `sheet_title` was null)
   - Reads header row
   - Appends a `Zao ID` column if absent
   - Assigns `zao_*` ULIDs to every existing row
   - Creates Tasks for every row whose Status isn't empty

## The column map

`column_map` is a `logical_key => sheet_header_label` mapping. Header
labels match case-insensitively against the header row. Defaults
(`ClientSheetSync::defaultColumnMap()`) line up with the Locumpedia
tracker layout:

| Logical key   | Default header label         |
|---------------|------------------------------|
| title         | Task / Issue Description     |
| asset         | Asset                        |
| task_type     | Task Type                    |
| user_type     | User Type                    |
| priority      | Priority                     |
| notes         | Notes                        |
| website_link  | Website Link                 |
| status        | Status                       |
| zao_id        | Zao ID                       |

If a client uses different headers, override individual keys in
`column_map` for their `ClientSheetSync` row.

## Files

| Layer | Path |
|---|---|
| OAuth scope | `app/Services/Google/GoogleOAuthService.php` (`spreadsheets`) |
| HTTP wrapper | `app/Services/Google/SheetsService.php` |
| Config model | `app/Models/ClientSheetSync.php` |
| Migration | `database/migrations/2026_05_20_203858_create_client_sheet_syncs_table.php` |
| Sync engine | `app/Services/GoogleSheets/SheetTaskSyncService.php` |
| Writeback observer | `app/Observers/TaskObserver.php` |
| Writeback job | `app/Jobs/PushTaskStatusToSheetJob.php` |
| Console command | `app/Console/Commands/SyncSheetTasks.php` |
| Schedule | `routes/console.php` (search `sync-sheet-tasks`) |
| Tests | `tests/Feature/SheetTaskSyncServiceTest.php` |

## Operational commands

```bash
# Sync all configured client sheets (default schedule: every 15 min, business hours)
php artisan sheets:sync

# Sync one client only
php artisan sheets:sync --client=example-client

# Bypass the 10-minute per-sheet debounce
php artisan sheets:sync --client=example-client --force
```

## Troubleshooting

**"Zao ID column already exists but rows have no IDs."**
That's expected — the service assigns IDs lazily as it sees rows for the
first time. The next `sheets:sync` run will fill them in.

**"My sheet has a different column for Status."**
Update `column_map['status']` on the relevant `ClientSheetSync` row.
After saving, run `--force` once so the cached `status_column_letter`
re-resolves.

**"The sheet shows Underway but the Task is back at pending."**
The conflict-resolution heuristic logged a `both sides changed` event and
trusted the sheet. Check `storage/logs/laravel.log` for
`SheetTaskSyncService: both sides changed since last sync`. If this is
happening regularly, we'll need a more sophisticated heuristic (e.g.,
preferring the more recent of our `updated_at` vs. a stored sheet write
timestamp).

**"OAuth scope not granted error from Sheets API."**
Re-authorize Google in Settings — the `spreadsheets` scope was added
recently and old tokens lack it.
