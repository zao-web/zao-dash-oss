# Demo Mode

Owner-only toggle that anonymizes **every** value rendered in the dashboard so you
can screen-share or record a demo without exposing real client data. It produces
**believable fake data** — "Brightwave Labs", "$3,840", "harper.quinn@northwind.io" —
never asterisks or redaction boxes, so the dashboard still looks like a real account.

## How it works

All sensitive data reaches the browser as **Inertia props**. Every page — the
initial HTML load and every subsequent XHR navigation — serializes those props at a
single point. Demo mode masks there, in one middleware, so it covers the entire
dashboard (all controllers, pages, and partial reloads) without per-page changes.

```
Controller → Inertia props ──▶ HandleInertiaRequests ──▶ MaskDemoData ──▶ browser
                                                          (anonymizes when ON)
```

- **Server-side only.** Nothing sensitive leaves the server when demo mode is on:
  the network tab, page source, and screen all show fake data.
- **Deterministic & coherent.** A per-session seed drives the fakes, so the same
  real client always maps to the same fake name across every page during a demo.
- **Money reconciles.** All monetary values scale by a single hidden linear factor,
  so invoice line items still sum to their totals and charts stay believable.
- **Layout-safe.** Identifiers, foreign keys, slugs, dates, enums (status/type/role),
  booleans, percentages, and paginator counts are never touched, so routing,
  pagination, and conditional UI keep working.

## Using it

1. Click your avatar (top-right) → **Demo Mode** → toggles On/Off.
2. While on, a pulsing **DEMO** badge appears in the header. Click it to turn off.
3. Each time you turn it on, a fresh seed is generated, so a new demo uses a
   different (but internally consistent) set of fake values.

Demo mode is **session-scoped** — it turns off when you log out or the session ends,
and it is only available to users with the `owner` role.

## Demo mode is read-only

Because the values on screen are anonymized, **writes are blocked while demo mode is
on**. This protects production data: without the block, saving an edit form would
persist the *fake* values back over the real record, and deletes (which act on the
unmasked `id`) would hit the real row behind the fake label.

Any `POST`/`PUT`/`PATCH`/`DELETE` is intercepted before it reaches a controller and
redirected back with a toast: *"Demo mode is read-only — turn it off to make
changes."* Turning demo mode off and signing out are always allowed. Reads (`GET`,
including Inertia partial reloads) are unaffected.

This also prevents side-effecting actions during a demo — sending an invoice,
emailing a contact, syncing Harvest, or triggering an agent — from firing for real
against real records.

## What gets anonymized

| Category | Example keys | Behavior |
|----------|--------------|----------|
| Company / project names | `name`, `client_name`, `project_name`, `company` | Replaced with fake company names |
| People | `first_name`, `last_name`, `full_name`, `contact`, `assignee` | Replaced with fake person names |
| Emails | `email`, `contact_email`, any value that is a valid email | Replaced with fake addresses |
| Phones / addresses | `phone`, `mobile`, `address`, `street` | Replaced with fake equivalents |
| Money | `amount`, `total`, `mrr`, `revenue`, `rate`, `budget`, `balance`, … | Scaled by one hidden factor (sums reconcile) |
| Hours / effort | `hours`, `estimated_hours`, `minutes`, `duration` | Scaled by one hidden factor |
| Free text | `title`, `subject`, `description`, `notes`, `narrative` | Replaced with believable filler |

### What is intentionally left untouched

`id` and `*_id`, `slug`, `*_at`/dates, `status`, `type`, `role`, `stage`, booleans,
`percent`/`ratio`/`margin`, and Laravel paginator meta (`total`, `per_page`,
`current_page`). These drive routing and layout — masking them would break the UI.

## Known limitation: URL slugs

Routing uses real client slugs (e.g. `/clients/acme-corp`). On-screen text is fully
anonymized, but if you click into a record the **address bar** may still show a real
slug. For a clean demo, present from list/overview screens, or hide the address bar.
Slugs are left intact deliberately so deep links keep resolving.

## Extending the rules

Classification lives in `app/Support/Demo/DemoDataMasker.php` as a set of key
allow/deny lists (`KEY_COMPANY`, `KEY_MONEY`, `DENY_EXACT`, …). To anonymize a new
field, add its prop key to the appropriate list. When a key is unrecognized the
value is left alone, so the safe default is "not masked" — prefer adding keys over
broadening matches.

## Files

| File | Role |
|------|------|
| `app/Support/Demo/DemoDataMasker.php` | The masker — deterministic, key-aware anonymization of a prop tree |
| `app/Http/Middleware/MaskDemoData.php` | The seam — rewrites Inertia JSON and initial-HTML props when demo mode is on |
| `app/Http/Controllers/DemoModeController.php` | Owner-only session toggle (`POST /demo-mode/toggle`) |
| `app/Http/Middleware/HandleInertiaRequests.php` | Shares the `demoMode` flag to the frontend |
| `resources/js/Layouts/AppLayout.vue` | Toggle button (user menu) + pulsing DEMO badge |

## Tests

- `tests/Unit/Support/DemoDataMaskerTest.php` — masking rules: determinism,
  type preservation, money reconciliation, deny-list, paginator safety.
- `tests/Feature/DemoModeTest.php` — middleware integration and owner-only toggle.
