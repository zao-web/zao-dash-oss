# Autonomous SEO Content Generation System

> Complete system for generating, validating, and publishing SEO content at scale with rate limiting, failure handling, and monitoring.

---

## Overview

The autonomous SEO content generation system produces 185+ pages across 10 playbook types, with priority-based queue processing, quality gates, and system health monitoring.

**Key Components:**

| Component | Purpose |
|-----------|---------|
| `SyncSeoPerformanceJob` | Daily sync of Search Console/GA4 data with rate limiting |
| `ProcessSeoContentQueueJob` | Orchestrator that picks queued pages every 15 min |
| `SeedSeoContentQueue` | Artisan command to seed 185 planned pages |
| `SeoQualityGateService` | Validates pages before publishing |
| `AlertStuckSeoGenerationsJob` | Hourly Slack alerts for stuck generations |
| `SeoPerformanceService::getSystemHealth()` | Dashboard metrics and monitoring |

---

## Architecture

### State Machine

```
Queued → Generating → Draft → Published
           ↓
         Failed
```

### Data Flow

```
seo:seed-queue command
    ↓ creates SeoPage records with status=Queued
ProcessSeoContentQueueJob (every 15 min)
    ↓ picks highest priority pages, marks as Generating
GenerateSeoContentJob
    ↓ dispatches SeoContentWriterAgent
Agent completes
    ↓ page moves to Draft
SeoQualityGateService.validate()
    ↓ if passed, moves to Published
Published on WordPress
```

---

## Rate Limiting & Failure Handling

### Google API Rate Limiting

```php
$executed = RateLimiter::attempt(
    key: 'google-api-sync',
    maxAttempts: 60,  // per minute, configurable
    callback: fn () => $this->syncPage(...),
    decaySeconds: 60
);
```

### Exponential Backoff

For transient failures (429 Too Many Requests, quota errors):

```php
private const BASE_DELAY_MS = 1000;

for ($retry = 0; $retry <= $maxRetries; $retry++) {
    try {
        // API call
    } catch (\Exception $e) {
        if ($this->isRateLimitError($e) && $retry < $maxRetries) {
            // Delays: 1s, 2s, 4s
            $delayMs = self::BASE_DELAY_MS * pow(2, $retry);
            usleep($delayMs * 1000);
            continue;
        }
        throw $e;
    }
}
```

### Circuit Breaker

Stops processing after 5 consecutive failures to preserve API quota:

```php
if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
    Log::error('Too many consecutive failures, stopping sync');
    break;
}
```

---

## Content Queue System

### Priority Matrix (185 Pages)

| Priority | Playbook | Pages | Conversion Intent |
|----------|----------|-------|-------------------|
| 1 | Comparisons | 30 | Highest - decision stage |
| 2 | Verticals | 25 | High - industry-specific |
| 3 | Glossary | 30 | Medium - educational |
| 4 | Examples | 20 | Medium - social proof |
| 5 | Case Studies | 15 | High - trust building |
| 6 | Integrations | 20 | Medium - technical |
| 7 | Curation | 15 | Low - awareness |
| 8 | Tools | 10 | High - lead capture |
| 9 | Persona | 15 | Medium - targeted |
| 10 | Location | 5 | Low - local SEO |

### Concurrency Control

```php
private const MAX_CONCURRENT_GENERATIONS = 3;
private const MAX_PAGES_PER_RUN = 5;

// Check available slots before processing
$generating = SeoPage::where('status', SeoPageStatus::Generating)->count();
if ($generating >= self::MAX_CONCURRENT_GENERATIONS) {
    return; // Wait for slots
}
```

### Race Condition Prevention

Mark page as `Generating` BEFORE dispatching to prevent duplicate processing:

```php
$page->update([
    'status' => SeoPageStatus::Generating,
    'generation_started_at' => now(),
]);

GenerateSeoContentJob::dispatch(...);
```

---

## Quality Gates

### Validation Criteria

| Check | Minimum | Weight |
|-------|---------|--------|
| Word Count | 800 | 20 |
| Humanization Score | 90 | 25 |
| Internal Links | 3 | 15 |
| Schema Valid | true | 15 |
| Proprietary Data Points | 2 | 15 |
| Meta Complete | title 30+, desc 100+ | 10 |

### Usage

```php
$qualityGate = app(SeoQualityGateService::class);

// Check single page
$result = $qualityGate->validate($page);
if ($result['passed']) {
    $page->update(['status' => SeoPageStatus::Published]);
}

// Get all publishable pages
$ready = $qualityGate->getPublishablePages();

// Auto-publish all ready pages
$count = $qualityGate->autoPublishReady();
```

---

## Monitoring & Alerting

### System Health Metrics

```php
$health = app(SeoPerformanceService::class)->getSystemHealth();

// Returns:
[
    'pages_queued' => 42,
    'pages_generating' => 2,
    'pages_stuck' => 0,        // Generating > 1 hour
    'pages_failed' => 3,       // Last 7 days
    'pages_draft' => 15,
    'pages_published' => 128,
    'pages_no_traffic' => 5,   // Published > 30 days, 0 impressions
    'avg_humanization_score' => 94.2,
    'avg_word_count' => 1247,
    'queue_progress' => [...], // By playbook
    'top_performing_pages' => [...],
    'recent_failures' => [...],
]
```

### Slack Alerts

`AlertStuckSeoGenerationsJob` runs hourly and alerts when pages are stuck in `Generating` status for over 2 hours:

```
:warning: *SEO Generation Alert*

3 pages have been stuck in 'generating' status for over 2 hours:

- laravel vs django (/laravel-vs-django)
- wordpress for healthcare (/wordpress-healthcare)
- what is eloquent orm (/what-is-eloquent-orm)

Check Laravel Horizon for issues.
```

---

## Scheduling

```php
// routes/console.php

// Daily at 5:30 AM - Sync Search Console/GA4 data
Schedule::job(new SyncSeoPerformanceJob)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->name('sync-seo-performance');

// Every 15 minutes - Process content queue
Schedule::job(new ProcessSeoContentQueueJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('process-seo-content-queue');

// Hourly - Check for stuck generations
Schedule::job(new AlertStuckSeoGenerationsJob)
    ->hourly()
    ->name('seo-alert-stuck-pages');
```

---

## Commands

### Seed Content Queue

```bash
# Preview what will be queued
php artisan seo:seed-queue --dry-run

# Seed all 185 pages
php artisan seo:seed-queue

# Seed specific playbook
php artisan seo:seed-queue --playbook=Comparisons

# Seed by priority level
php artisan seo:seed-queue --priority=1

# Limit number of pages
php artisan seo:seed-queue --limit=10
```

---

## Configuration

### Environment Variables

```env
# Google API
GOOGLE_SEARCH_CONSOLE_SITE_URL=sc-domain:example.com
GOOGLE_GA4_PROPERTY_ID=123456789
GOOGLE_API_RATE_LIMIT=60

# Slack Alerts
SLACK_ALERTS_WEBHOOK_URL=https://hooks.slack.com/services/...
```

### Database Columns (seo_pages)

```
priority                 - Queue ordering (1-10, lower = higher priority)
proprietary_data_count   - Tracks unique data points included
generation_started_at    - For stuck detection
playbook                 - Content template type
```

---

## SQLite Compatibility Note

When using `updateOrCreate` with date columns, always use Carbon objects:

```php
// CORRECT - Works with SQLite
SeoPerformanceHistory::updateOrCreate(
    [
        'seo_page_id' => $page->id,
        'snapshot_date' => now()->startOfDay(),
    ],
    [...]
);

// WRONG - May create duplicates in SQLite
'snapshot_date' => '2026-01-19'
```

---

## Files

| Component | Path |
|-----------|------|
| SyncSeoPerformanceJob | `app/Jobs/SyncSeoPerformanceJob.php` |
| ProcessSeoContentQueueJob | `app/Jobs/ProcessSeoContentQueueJob.php` |
| SeedSeoContentQueue | `app/Console/Commands/SeedSeoContentQueue.php` |
| SeoQualityGateService | `app/Services/Seo/SeoQualityGateService.php` |
| AlertStuckSeoGenerationsJob | `app/Jobs/AlertStuckSeoGenerationsJob.php` |
| SeoPerformanceService | `app/Services/Seo/SeoPerformanceService.php` |
| Tests | `tests/Feature/SyncSeoPerformanceJobTest.php` |

---

## Related Documentation

- [Programmatic SEO](./PROGRAMMATIC_SEO.md) - Overall SEO strategy and agent design
- [Blog Publishing](./BLOG_PUBLISHING.md) - Content publishing pipeline
- [Agents](../AGENTS.md) - Agent system overview
- [Infrastructure](../INFRASTRUCTURE.md) - Queue and scheduling configuration
