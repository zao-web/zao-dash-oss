# Retainer Offboarding (Delete a Retainer)

## Overview

When a client's retainer relationship ends (e.g. they are no longer a client), the
retainer can be removed from the Retainers dashboard. This is an **offboard of the
whole client retainer**, not a single-period delete.

## How to use

1. Go to **Retainers** (`/retainers`).
2. On the client's card, click the **trash icon** (top-right, next to the health badge).
3. Confirm the dialog. It spells out exactly what is deleted and what is kept.

There is no undo. Historical retainer reports for that client will no longer be
reachable (including previously shared signed report links, which 404 once their
period is gone).

## What it does

`DELETE /retainers/{retainer}` (`RetainerController@destroy`):

| Data | Outcome |
|---|---|
| All `retainer_periods` for the client | Deleted (every period, not just the clicked one) |
| AI-estimated time entries (`source = ai_estimated`) tied to those periods | Deleted — these are synthetic report artifacts |
| Manually tracked time entries | Kept; `retainer_period_id` nulls via FK |
| Invoices referencing a period | Kept; `retainer_period_id` nulls via FK |
| Cached narratives, refresh statuses, stored report PDFs | Cleared per period |
| `clients.recurring_invoice_enabled` | Set to `false` |

## Why recurring invoicing is disabled

Retainer periods are auto-created by the daily `retainers:sync` command (and
on-demand by `RetainerPeriodService::ensureCurrentPeriod`) for every client with
`recurring_invoice_enabled = true` and a positive `recurring_invoice_amount`.
Deleting periods without flipping that flag would just resurrect the current-month
period on the next sync. Disabling it also stops recurring invoice generation for
the offboarded client.

If the client's billing should continue without a retainer dashboard (unusual),
re-enable **Recurring invoice** on the client settings after deleting — the next
`retainers:sync` run will recreate a current period.

## Admin notes

- No artisan command is required; the action is UI-only. To offboard from the
  console instead, replicate the controller logic — do not just
  `RetainerPeriod::delete()`, or sync will recreate the period.
- The client record itself is untouched (status, projects, contacts, history).
  Marking the client inactive is a separate action on the client page.
