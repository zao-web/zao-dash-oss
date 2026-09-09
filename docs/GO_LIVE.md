# Go-Live Procedure

Complete guide for transitioning Zao Dash from development/demo mode to production.

## Overview

The go-live process involves:
1. Cleaning seeded/demo data
2. Connecting real service integrations
3. Verifying sync completion
4. Running pre-flight checks

## Pre-requisites

Ensure all environment variables are configured:

### Required Environment Variables

```bash
# Database
DB_CONNECTION=mysql
DB_HOST=your-host
DB_DATABASE=zao_dash
DB_USERNAME=your-user
DB_PASSWORD=your-password

# QuickBooks
QUICKBOOKS_CLIENT_ID=xxx
QUICKBOOKS_CLIENT_SECRET=xxx
QUICKBOOKS_REDIRECT_URI=https://your-app.com/integrations/quickbooks/callback

# Google (Gmail, Calendar, Drive)
GOOGLE_CLIENT_ID=xxx
GOOGLE_CLIENT_SECRET=xxx
GOOGLE_REDIRECT_URI=https://your-app.com/integrations/google/callback

# Slack
SLACK_CLIENT_ID=xxx
SLACK_CLIENT_SECRET=xxx
SLACK_SIGNING_SECRET=xxx

# GitHub
GITHUB_APP_ID=xxx
GITHUB_PRIVATE_KEY_PATH=/path/to/key.pem
GITHUB_WEBHOOK_SECRET=xxx

# Harvest
HARVEST_CLIENT_ID=xxx
HARVEST_CLIENT_SECRET=xxx

# Notion
NOTION_CLIENT_ID=xxx
NOTION_CLIENT_SECRET=xxx

# Wise (payments)
WISE_API_KEY=xxx
WISE_PROFILE_ID=xxx

# Claude AI (for agents)
ANTHROPIC_API_KEY=xxx

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=xxx
MAIL_PASSWORD=xxx
```

### Infrastructure Requirements

- [ ] Queue worker running (`php artisan queue:work --queue=sync,default`)
- [ ] Redis configured (for caching and rate limiting)
- [ ] Scheduler running (`php artisan schedule:work`)
- [ ] SSL certificate installed

---

## Step 1: Clean Demo Data

View what seeded data exists:

```bash
php artisan tinker
>>> App\Models\Client::whereNotNull('seeded_at')->pluck('name');
>>> App\Models\Task::whereNotNull('seeded_at')->count();
>>> App\Models\Lead::whereNotNull('seeded_at')->count();
```

Delete all seeded data:

```bash
# Preview what will be deleted
php artisan db:clean-seeded

# Confirm and delete
php artisan db:clean-seeded --force
```

**Tables cleaned:**
- clients, client_contacts
- projects, milestones, tasks
- agents, agent_runs
- vault_secrets
- leads
- contractors, contractor_invoices

---

## Step 2: Connect Integrations

Connect each service via **Settings > Integrations**. Each connection automatically triggers a full sync.

| Order | Service | What Syncs | Est. Time |
|-------|---------|------------|-----------|
| 1 | QuickBooks | Customers, invoices, accounts, transactions | 2-5 min |
| 2 | Google | Emails (7d), calendar events, documents | 3-10 min |
| 3 | Slack | Channels, recent messages | 1-3 min |
| 4 | GitHub | Repos, issues (30d), PRs (30d) | 1-5 min |
| 5 | Harvest | Projects, time entries (30d), invoices (6mo) | 1-3 min |
| 6 | Notion | Pages, database items | 1-2 min |
| 7 | WordPress | Posts, pages | 1-2 min |
| 8 | ClickUp | Tasks from workspaces | 1-3 min |

### Sync Progress

After connecting, sync progress is shown on the Integrations page. You can also check via API:

```bash
# Get all sync statuses
curl -H "Authorization: Bearer $TOKEN" \
  https://your-app.com/api/integrations/sync-status

# Check if any service is currently syncing
curl -H "Authorization: Bearer $TOKEN" \
  https://your-app.com/api/integrations/syncing
```

Or via Tinker:

```php
>>> App\Models\QuickBooksConnection::first()->only('sync_status', 'sync_progress', 'sync_error');
>>> App\Models\GoogleCredential::first()->only('sync_status', 'sync_progress');
```

---

## Step 3: Verify Sync Completion

Wait for all syncs to complete. Check status:

```bash
php artisan tinker
```

```php
// Check all connections
$connections = [
    'QuickBooks' => App\Models\QuickBooksConnection::first(),
    'Google' => App\Models\GoogleCredential::first(),
    'Slack' => App\Models\SlackWorkspace::first(),
    'GitHub' => App\Models\GitHubInstallation::first(),
    'Harvest' => App\Models\HarvestCredential::first(),
    'Notion' => App\Models\NotionConnection::first(),
];

foreach ($connections as $name => $conn) {
    if ($conn) {
        echo "$name: {$conn->sync_status} ({$conn->sync_progress}%)\n";
    } else {
        echo "$name: Not connected\n";
    }
}
```

All should show `completed` with `100%` progress.

---

## Step 4: Run Pre-Flight Check

```bash
php artisan app:go-live-check
```

Expected output:
```
Running Go-Live Pre-Flight Check...

  ✓ Seeded data cleaned
  ✓ Owner user exists
  ✓ QuickBooks connected
  ✓ Google connected
  ✓ Slack connected
  ✓ GitHub connected
  ✓ Harvest connected
  ✓ All syncs completed
  ✓ Wise configured
  ✓ Mail configured
  ✓ Queue worker ready

All checks passed! Ready for production.
```

**Required checks** (must pass):
- Seeded data cleaned
- Owner user exists
- QuickBooks connected
- All syncs completed

**Optional checks** (warnings only):
- GitHub, Harvest, Notion, WordPress, ClickUp connected
- Wise configured
- Mail configured

---

## Step 5: Final Verification

### Dashboard
- [ ] Dashboard loads with real data
- [ ] Quick stats show actual numbers

### Clients
- [ ] Clients list shows QuickBooks customers
- [ ] Client health scores are calculated

### Tasks
- [ ] Tasks sync from ClickUp (if connected)
- [ ] GitHub issues appear as tasks (for tracked repos)

### Emails
- [ ] Emails visible from Gmail
- [ ] Client emails auto-linked

### Time Tracking
- [ ] Time entries from Harvest showing
- [ ] Profitability calculations work

### Agents
- [ ] Test agent runs successfully
- [ ] Agent costs tracked correctly

---

## Troubleshooting

### Sync Failed

Check job failures:

```bash
php artisan queue:failed
```

Retry a failed job:

```bash
php artisan queue:retry {id}
```

Or manually trigger a sync:

```bash
php artisan tinker
>>> App\Jobs\SyncQuickBooksJob::dispatch(1)->onQueue('sync');
>>> App\Jobs\SyncGSuiteJob::dispatch(1)->onQueue('sync');
```

### Connection Token Expired

Re-authenticate via **Settings > Integrations > Reconnect**

### Missing Data

Check sync time windows. Default sync periods:
- Emails: 7 days
- GitHub issues/PRs: 30 days
- Harvest time: 30 days
- QB transactions: 3 months

For older data, manually trigger extended sync:

```php
// Sync QB data from 12 months ago
App\Jobs\SyncQuickBooksJob::dispatch(
    connectionId: 1,
    fromDate: now()->subMonths(12)->toDateString()
)->onQueue('sync');
```

### Sync Stuck

If a sync is stuck in "syncing" status:

```php
// Reset sync status
$connection = App\Models\QuickBooksConnection::find(1);
$connection->update([
    'sync_status' => 'pending',
    'sync_progress' => 0,
    'sync_error' => null,
]);

// Re-trigger sync
App\Jobs\SyncQuickBooksJob::dispatch(1)->onQueue('sync');
```

---

## Command Reference

| Command | Description |
|---------|-------------|
| `php artisan db:clean-seeded` | Remove all seeded/demo data |
| `php artisan db:clean-seeded --force` | Skip confirmation prompt |
| `php artisan app:go-live-check` | Run pre-flight checks |
| `php artisan queue:work --queue=sync,default` | Start queue worker |
| `php artisan schedule:work` | Start scheduler |

---

## Post Go-Live

After going live:

1. **Monitor queue** - Watch for job failures
2. **Check agent runs** - Verify agents execute successfully
3. **Review costs** - Check AI usage in the Agents dashboard
4. **Set up alerts** - Configure health alert escalations

### Scheduled Tasks

These jobs run automatically after go-live:

| Job | Schedule | Purpose |
|-----|----------|---------|
| `agents:run-scheduled` | Every minute | Execute scheduled agents |
| `sync-quickbooks` | Daily 6am | Sync financial data |
| `sync-gsuite` | Every 4 hours | Sync emails & calendar |
| `sync-slack` | Every 30 min | Sync Slack messages |
| `sync-github` | Every 4 hours | Sync repos, issues, PRs |
| `sync-harvest` | Every 2 hours | Sync time entries |
| `detect-contractor-invoices` | Daily 7am | Scan for contractor invoices |
