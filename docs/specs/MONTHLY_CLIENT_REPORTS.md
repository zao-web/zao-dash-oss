# Monthly Client Reports ("Spotify Wrapped" for Agency Work)

## Overview

Automated monthly PDF reports sent to clients showcasing the work done during the period. Reports aggregate data from multiple integrations (Harvest, GitHub, Tasks) and use AI to generate executive summaries and highlights.

## Features

- **Cross-Integration Data Aggregation**: Pulls from Harvest time entries, GitHub PRs/Issues, and completed Tasks
- **AI-Generated Content**: Uses Claude to create executive summaries and highlight key achievements
- **Beautiful PDF Design**: Dark-themed, modern design inspired by Spotify Wrapped
- **Automated Delivery**: Scheduled to run daily, checking each client's configured send day
- **Per-Client Configuration**: Customizable branding, frequency, and content sections
- **Email Delivery**: PDF attached to branded email with summary preview

---

## Architecture

### Models

#### `ClientReportSettings`
Per-client configuration for reports.

| Field | Type | Description |
|-------|------|-------------|
| `client_id` | FK | Parent client |
| `is_enabled` | bool | Whether reports are active |
| `frequency` | enum | monthly, quarterly, weekly |
| `send_day` | int | Day of month/week to send |
| `recipients` | json | Email addresses |
| `include_time_breakdown` | bool | Show hours by category |
| `include_github_activity` | bool | Show PRs/issues |
| `include_tasks_completed` | bool | Show task completion |
| `include_financials` | bool | Show revenue/billing |
| `include_upcoming` | bool | Show upcoming work |
| `custom_branding` | json | `{logo_url, primary_color, accent_color}` |

#### `ClientReport`
Generated report instances.

| Field | Type | Description |
|-------|------|-------------|
| `client_id` | FK | Parent client |
| `period_start` | date | Report period start |
| `period_end` | date | Report period end |
| `report_type` | enum | monthly, quarterly, weekly, custom |
| `data_snapshot` | json | Raw aggregated data for historical reference |
| `executive_summary` | text | AI-generated summary |
| `highlights` | json | Key achievements array |
| `metrics` | json | Hero stats array |
| `total_hours` | decimal | Total hours worked |
| `hours_by_category` | json | Breakdown by task category |
| `hours_by_project` | json | Breakdown by project |
| `tasks_completed` | int | Count of completed tasks |
| `prs_merged` | int | Count of merged PRs |
| `issues_closed` | int | Count of closed issues |
| `meetings_held` | int | Estimated meeting count |
| `pdf_path` | string | Storage path to PDF |
| `pdf_disk` | string | Storage disk |
| `sent_to` | json | Email recipients sent to |
| `sent_at` | timestamp | When email was sent |
| `opened_at` | timestamp | First email open |
| `opens_count` | int | Total email opens |
| `status` | enum | draft, generated, sent, failed |

---

## Services

### `ClientReportService`

Main service for report generation.

```php
use App\Services\Reports\ClientReportService;

$service = app(ClientReportService::class);

// Generate a report
$report = $service->generateReport(
    client: $client,
    periodStart: now()->subMonth()->startOfMonth(),
    periodEnd: now()->subMonth()->endOfMonth(),
    reportType: 'monthly',
    generatedBy: auth()->id()
);

// Get clients due for reports today
$clients = $service->getClientsDueForReports();

// Get or create settings for a client
$settings = $service->getOrCreateSettings($client);
```

---

## Jobs

### `GenerateClientReportJob`

Background job for report generation.

```php
use App\Jobs\GenerateClientReportJob;

// Generate for previous month
GenerateClientReportJob::forPreviousMonth($client, auth()->id(), sendEmail: true)
    ->dispatch();

// Generate for previous quarter
GenerateClientReportJob::forPreviousQuarter($client)
    ->dispatch();

// Custom period
GenerateClientReportJob::dispatch(
    client: $client,
    periodStart: Carbon::parse('2024-01-01'),
    periodEnd: Carbon::parse('2024-01-31'),
    reportType: 'monthly',
    generatedBy: auth()->id(),
    sendEmail: true
);
```

---

## Artisan Commands

### Generate Reports

```bash
# Generate for all clients due today (based on settings)
php artisan reports:generate

# Generate for specific client
php artisan reports:generate --client=1

# Generate for specific month
php artisan reports:generate --month=2024-11

# Generate and send via email
php artisan reports:generate --client=1 --send

# Queue the job instead of running immediately
php artisan reports:generate --client=1 --queue

# Generate quarterly reports
php artisan reports:generate --type=quarterly --month=2024-10
```

---

## Scheduling

Reports are automatically checked daily at 8 AM. Each client's settings determine when their report is sent:

- **Monthly**: Sent on configured `send_day` (1-31) of the month
- **Weekly**: Sent on configured `send_day` (0=Sunday through 6=Saturday)
- **Quarterly**: Sent on configured `send_day` in January, April, July, October

The scheduler entry in `routes/console.php`:

```php
Schedule::command('reports:generate --send --queue')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->name('generate-client-reports');
```

---

## PDF Rendering

Reports use Spatie Browsershot for PDF generation. **Requires Chrome/Puppeteer on the server.**

### Installation

```bash
# Install browsershot
composer require spatie/browsershot

# Install Puppeteer (on server)
npm install puppeteer

# For Laravel Cloud / serverless, use chromium binary
# See: https://spatie.be/docs/browsershot/v4/requirements
```

### Fallback

If Browsershot is not available, the system will save HTML instead of PDF. Check logs for warnings.

---

## PDF Template

The report template (`resources/views/reports/client-monthly.blade.php`) is a self-contained HTML document with embedded CSS for PDF rendering.

### Customization

Custom branding is applied via CSS variables:

```php
// In ClientReportSettings custom_branding JSON:
{
    "logo_url": "https://example.com/logo.png",
    "primary_color": "#1a1a2e",
    "accent_color": "#4f46e5"
}
```

### Pages

- **Page 1**: Hero section, executive summary, metrics, highlights
- **Page 2**: Time breakdown chart, GitHub activity, tasks completed

---

## Data Aggregation

### Time Data (Harvest)
- Total hours, billable vs non-billable
- Hours by category (Harvest task types)
- Hours by project
- Daily breakdown for charts
- Meeting/call count estimation

### GitHub Data
- PRs merged in period
- Issues closed in period
- Lines added/removed
- Top contributors

### Task Data
- Completed tasks count
- Breakdown by priority
- Task details for highlights

---

## AI Content Generation

The service uses Claude (via `AnthropicService`) to generate:

1. **Executive Summary**: 2-3 sentence professional summary
2. **Highlights**: 3-4 key achievements with titles and descriptions
3. **Hero Metrics**: 3 impressive stats to highlight

If AI generation fails, fallback content is generated from the raw data.

---

## Email Delivery

Reports are sent via the `ClientReportMail` mailable:

- Subject: "{Client Name} - {Period} Report"
- Body: Markdown email with summary stats
- Attachment: PDF report

Recipients are determined by:
1. `ClientReportSettings.recipients` array (if set)
2. Client contacts marked with `receives_reports = true`

---

## Configuring a Client

### Via Database/Tinker

```php
use App\Models\Client;
use App\Services\Reports\ClientReportService;

$client = Client::find(1);
$service = app(ClientReportService::class);

$settings = $service->getOrCreateSettings($client);
$settings->update([
    'is_enabled' => true,
    'frequency' => 'monthly',
    'send_day' => 1,
    'recipients' => ['client@example.com', 'stakeholder@example.com'],
    'custom_branding' => [
        'logo_url' => 'https://example.com/logo.png',
        'primary_color' => '#1a1a2e',
        'accent_color' => '#4f46e5',
    ],
]);
```

### Content Sections

Toggle sections based on client needs:

```php
$settings->update([
    'include_time_breakdown' => true,
    'include_github_activity' => true,  // Only if client has GitHub repos linked
    'include_tasks_completed' => true,
    'include_financials' => false,      // For sensitive clients
    'include_upcoming' => true,
]);
```

---

## Testing

### Generate Test Report

```bash
# Generate for specific client without sending
php artisan reports:generate --client=1 --month=2024-11

# Check the generated report
php artisan tinker
>>> App\Models\ClientReport::latest()->first()
```

### Preview PDF

Generated PDFs are stored in `storage/app/reports/{client_id}/`.

---

## Future Enhancements

- [ ] Web preview before sending
- [ ] Report approval workflow
- [ ] Custom template selection
- [ ] Client portal for viewing past reports
- [ ] Click tracking for email opens
- [ ] Comparison with previous periods
- [ ] Interactive charts in email (AMP email)
