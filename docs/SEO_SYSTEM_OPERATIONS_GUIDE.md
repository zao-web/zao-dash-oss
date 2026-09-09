# Autonomous SEO Content Generation System - Operations Guide

This guide documents prevention strategies, best practices, monitoring recommendations, and test coverage for the autonomous programmatic SEO content generation system.

## System Overview

The SEO content generation system consists of:

| Component | File | Purpose |
|-----------|------|---------|
| `ProcessSeoContentQueueJob` | `app/Jobs/ProcessSeoContentQueueJob.php` | Orchestrator - processes queued pages |
| `GenerateSeoContentJob` | `app/Jobs/GenerateSeoContentJob.php` | Worker - generates individual content pieces |
| `SyncSeoPerformanceJob` | `app/Jobs/SyncSeoPerformanceJob.php` | Syncs Search Console/GA4 metrics daily |
| `AlertStuckSeoGenerationsJob` | `app/Jobs/AlertStuckSeoGenerationsJob.php` | Monitors and alerts on stuck pages |
| `SeoQualityGateService` | `app/Services/Seo/SeoQualityGateService.php` | Validates content before publishing |
| `SearchConsoleService` | `app/Services/Google/SearchConsoleService.php` | Google API integration |

### Schedule Configuration

```
routes/console.php:
- SyncSeoPerformanceJob: Daily at 05:30
- ProcessSeoContentQueueJob: Every 15 minutes
- AlertStuckSeoGenerationsJob: Hourly
```

---

## Prevention Strategies

### 1. API Rate Limiting and Quota Management

**Problem:** Google Search Console and GA4 APIs have rate limits. Exceeding them causes cascading failures.

**Prevention:**

```php
// Already implemented in SyncSeoPerformanceJob.php
- Laravel RateLimiter: 60 requests/minute (configurable via GOOGLE_API_RATE_LIMIT_PER_MINUTE)
- Exponential backoff: 1s, 2s, 4s delays on transient failures
- Consecutive failure circuit breaker: Stops after 5 consecutive failures
- 100ms delay between page syncs
```

**Best Practices:**
- Never increase rate limit above 60/min without verifying Google quota
- Monitor Horizon for jobs stuck in rate limit retry loops
- Configure `services.google.api_rate_limit` in `.env` for different environments

**Configuration:**
```bash
# .env
GOOGLE_API_RATE_LIMIT_PER_MINUTE=60  # Production
GOOGLE_API_RATE_LIMIT_PER_MINUTE=30  # Staging (shared quota)
```

### 2. Stuck Generation Prevention

**Problem:** Pages can get stuck in 'generating' status when agents fail silently.

**Prevention:**
- `AlertStuckSeoGenerationsJob` runs hourly to detect pages stuck > 2 hours
- Slack alerts sent when stuck pages detected
- Manual cleanup available via artisan

**Best Practices:**
- Review Slack alerts promptly - stuck pages indicate agent failures
- Check Laravel Horizon for failed `RunAgentJob` when alerts fire
- Consider reducing stuck threshold from 2 hours to 1 hour in high-volume scenarios

**Manual Cleanup:**
```bash
# View stuck pages
php artisan tinker
>>> \App\Models\SeoPage::where('status', 'generating')
    ->where('generation_started_at', '<', now()->subHours(2))
    ->get(['id', 'target_keyword', 'generation_started_at']);

# Reset to queued for retry
>>> \App\Models\SeoPage::where('status', 'generating')
    ->where('generation_started_at', '<', now()->subHours(2))
    ->update(['status' => 'queued', 'generation_started_at' => null]);
```

### 3. Concurrent Generation Limits

**Problem:** Unlimited concurrent generations can exhaust system resources (AI API costs, memory, CPU).

**Prevention (Already Implemented):**
```php
// ProcessSeoContentQueueJob.php
private const MAX_CONCURRENT_GENERATIONS = 3;
private const MAX_PAGES_PER_RUN = 5;
```

**Best Practices:**
- Monitor generation costs via AI provider dashboard
- Increase `MAX_CONCURRENT_GENERATIONS` only with corresponding infrastructure scaling
- During peak periods, consider temporarily reducing to 1-2 concurrent

### 4. Quality Gate Enforcement

**Problem:** Low-quality content can be published, harming SEO performance.

**Prevention:**
- `SeoQualityGateService` validates 6 criteria before publishing
- Minimum thresholds: 800 words, 90 humanization score, 3 internal links, valid schema
- Pages failing quality gates stay in 'draft' status

**Quality Gate Thresholds:**
| Check | Minimum | Weight |
|-------|---------|--------|
| Word Count | 800 | 20% |
| Humanization Score | 90 | 25% |
| Internal Links | 3 | 15% |
| Schema Valid | true | 15% |
| Proprietary Data Points | 2 | 15% |
| Meta Complete | 30/100 chars | 10% |

**Best Practices:**
- Never bypass quality gates for urgent content
- Review pages in 'draft' status weekly to identify generation issues
- Adjust thresholds gradually based on performance data

### 5. Duplicate Content Prevention

**Problem:** Creating multiple pages targeting the same keyword cannibalizes rankings.

**Prevention:**
- Unique constraint on `target_keyword` column (recommended)
- `SeoCheckDuplicateTool` available for agents to check before creating

**Best Practices:**
- Before seeding queue, audit existing pages for similar keywords
- Use keyword clustering to identify related keywords that should go to same page
- Implement canonicalization for intentionally similar pages

### 6. Job Retry and Timeout Configuration

**Current Configuration:**
```php
// GenerateSeoContentJob
public int $tries = 2;
public array $backoff = [60, 300];  // 1 min, 5 min
public int $timeout = 900;  // 15 minutes

// SyncSeoPerformanceJob
// Uses internal retry logic with exponential backoff
// Max 3 retries per page, 5 consecutive failures stops job
```

**Best Practices:**
- Keep `tries` low (2-3) to avoid cost explosion on persistent failures
- Backoff should increase exponentially (current: 60s, 300s)
- Timeout should exceed typical generation time by 2x margin
- Monitor timeout failures in Horizon - may indicate need for model adjustment

---

## Best Practices for Operating the System

### Daily Operations

1. **Morning Check (09:00):**
   - Verify `SyncSeoPerformanceJob` ran successfully at 05:30
   - Review overnight alerts in Slack
   - Check queue depth in Horizon

2. **Queue Monitoring:**
   - Normal: 0-10 pages in `queued` status
   - Warning: 11-25 pages queued (generation may be slow)
   - Critical: 26+ pages queued (investigate blockers)

3. **Generation Health:**
   - Healthy: 0-2 pages in `generating` status
   - Warning: 3+ pages generating for < 1 hour
   - Critical: Any page generating > 2 hours

### Weekly Operations

1. **Monday Morning Review:**
   - Check weekly performance snapshot creation
   - Review pages published in past week
   - Audit pages that failed quality gates

2. **Content Pipeline:**
   - Verify priority order of queued pages
   - Review and potentially re-queue failed pages
   - Assess if new content types needed

3. **Performance Analysis:**
   - Compare week-over-week impressions/clicks
   - Identify pages with declining metrics
   - Plan optimization for underperforming pages

### Monthly Operations

1. **System Health Audit:**
   ```bash
   php artisan tinker
   >>> app(\App\Services\Seo\SeoPerformanceService::class)->getSystemHealth();
   ```

2. **Quality Gate Review:**
   - Analyze pages that consistently fail specific gates
   - Consider adjusting thresholds based on performance data
   - Review humanization score distribution

3. **API Quota Check:**
   - Review Google Cloud Console for quota usage
   - Adjust rate limits if approaching limits
   - Plan for quota increases if scaling content

### Emergency Procedures

**Scenario: Mass Generation Failures**
```bash
# 1. Pause queue processing
# In routes/console.php, comment out ProcessSeoContentQueueJob schedule

# 2. Diagnose cause
php artisan queue:failed  # Check failed jobs
php artisan tinker
>>> \App\Models\SeoPage::where('status', 'failed')
    ->where('updated_at', '>', now()->subHours(6))
    ->pluck('generation_error');

# 3. Fix root cause (API keys, service outage, etc.)

# 4. Reset failed pages
>>> \App\Models\SeoPage::where('status', 'failed')
    ->where('updated_at', '>', now()->subHours(6))
    ->update(['status' => 'queued']);

# 5. Re-enable queue processing
```

**Scenario: Google API Quota Exhausted**
```bash
# 1. SyncSeoPerformanceJob will auto-stop after 5 consecutive failures
# 2. Wait for quota reset (usually midnight Pacific)
# 3. Consider reducing GOOGLE_API_RATE_LIMIT_PER_MINUTE
# 4. Check if other services share the same Google project
```

---

## Monitoring Recommendations

### Horizon Dashboard Metrics

Monitor these queues in Laravel Horizon:

| Queue | Normal Wait | Warning | Critical |
|-------|-------------|---------|----------|
| default | < 5 min | 5-15 min | > 15 min |
| seo-content | < 30 min | 30-60 min | > 60 min |
| agents | < 2 min | 2-5 min | > 5 min |

### Key Metrics to Track

1. **Generation Throughput:**
   - Pages generated per day (target: 5-10)
   - Average generation time (target: < 10 min)
   - Generation success rate (target: > 90%)

2. **Quality Metrics:**
   - Average humanization score (target: > 95)
   - Quality gate pass rate (target: > 80%)
   - Average word count (target: > 1000)

3. **Performance Metrics:**
   - Pages with 0 impressions after 30 days (target: < 10%)
   - Average CTR for PSEO pages (target: > 2%)
   - Lead attribution rate (target: > 0.5%)

### Recommended Alerts

**Critical (Immediate Response):**
- Any page stuck generating > 3 hours
- Generation success rate < 50% over 24 hours
- SyncSeoPerformanceJob fails completely

**Warning (Same Day Response):**
- Queue depth > 20 pages
- More than 5 pages failed in 24 hours
- Quality gate pass rate < 70%

**Info (Weekly Review):**
- Average humanization score < 95
- Word count below 800 on any published page
- Pages with declining impressions week-over-week

### Slack Alert Configuration

Already configured in `AlertStuckSeoGenerationsJob`:
```php
// Config: services.slack.alerts_webhook
// Fires: When pages stuck > 2 hours
// Format: Emoji warning, count, page list, action link
```

**Recommended Additional Alerts:**

```php
// Add to routes/console.php

// Daily generation summary
Schedule::call(function () {
    $yesterday = now()->subDay();
    $stats = [
        'generated' => SeoPage::where('generation_completed_at', '>', $yesterday)->count(),
        'failed' => SeoPage::where('status', 'failed')
            ->where('updated_at', '>', $yesterday)->count(),
        'queued' => SeoPage::where('status', 'queued')->count(),
    ];
    // Send to Slack
})->dailyAt('08:00')->name('seo-daily-summary');
```

---

## Test Cases That Should Exist

### Existing Test Coverage

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/SyncSeoPerformanceJobTest.php` | API sync, rate limiting, backoff, weekly snapshots |
| `tests/Feature/SeoContentGenerationWorkflowTest.php` | Content humanization, quality gates |
| `tests/Unit/SeoPageTest.php` | Model scopes, relationships |
| `tests/Feature/SeoAttributionTest.php` | Lead attribution |

### Missing Test Cases (Should Add)

#### 1. ProcessSeoContentQueueJob Tests

```php
// tests/Feature/ProcessSeoContentQueueJobTest.php

it('respects MAX_CONCURRENT_GENERATIONS limit', function () {
    // Create 3 pages already generating
    SeoPage::factory()->count(3)->create([
        'status' => SeoPageStatus::Generating,
    ]);

    // Create queued pages
    SeoPage::factory()->count(5)->create([
        'status' => SeoPageStatus::Queued,
    ]);

    (new ProcessSeoContentQueueJob())->handle();

    // Should not dispatch any new generations
    expect(SeoPage::where('status', SeoPageStatus::Generating)->count())->toBe(3);
});

it('processes pages in priority order', function () {
    $lowPriority = SeoPage::factory()->create([
        'status' => SeoPageStatus::Queued,
        'priority' => 10,
    ]);
    $highPriority = SeoPage::factory()->create([
        'status' => SeoPageStatus::Queued,
        'priority' => 1,
    ]);

    (new ProcessSeoContentQueueJob(maxPages: 1))->handle();

    expect($highPriority->fresh()->status)->toBe(SeoPageStatus::Generating);
    expect($lowPriority->fresh()->status)->toBe(SeoPageStatus::Queued);
});

it('marks page as generating with timestamp before dispatching', function () {
    $page = SeoPage::factory()->create([
        'status' => SeoPageStatus::Queued,
    ]);

    Bus::fake([GenerateSeoContentJob::class]);

    (new ProcessSeoContentQueueJob())->handle();

    expect($page->fresh()->status)->toBe(SeoPageStatus::Generating);
    expect($page->fresh()->generation_started_at)->not->toBeNull();
});
```

#### 2. GenerateSeoContentJob Tests

```php
// tests/Feature/GenerateSeoContentJobTest.php

it('creates SeoPage record with generating status', function () {
    $job = new GenerateSeoContentJob(
        title: 'Laravel Development Healthcare',
        keyword: 'laravel healthcare',
        playbook: 'Vertical',
        urlSlug: 'laravel-healthcare',
        proprietaryData: ['projects_count' => 10],
    );

    $job->handle();

    $page = SeoPage::where('target_keyword', 'laravel healthcare')->first();
    expect($page)->not->toBeNull();
    expect($page->status)->toBe(SeoPageStatus::Generating);
});

it('maps playbook to correct page type', function () {
    $mappings = [
        'Comparisons' => 'comparison',
        'Location' => 'location',
        'Vertical' => 'service',
        'Glossary' => 'guide',
        'Case Study' => 'case-study',
    ];

    foreach ($mappings as $playbook => $expectedType) {
        $job = new GenerateSeoContentJob(
            title: "Test {$playbook}",
            keyword: "test-{$playbook}",
            playbook: $playbook,
            urlSlug: "test-{$playbook}",
            proprietaryData: [],
        );

        $job->handle();

        $page = SeoPage::where('target_keyword', "test-{$playbook}")->first();
        expect($page->page_type)->toBe($expectedType);
    }
});

it('handles missing agent gracefully', function () {
    // Delete or deactivate the agent
    Agent::where('slug', 'seo-content-writer')->delete();

    $job = new GenerateSeoContentJob(
        title: 'Test Page',
        keyword: 'test-keyword',
        playbook: 'Vertical',
        urlSlug: 'test-page',
        proprietaryData: [],
    );

    $job->handle();

    $page = SeoPage::where('target_keyword', 'test-keyword')->first();
    expect($page->status)->toBe(SeoPageStatus::Draft);
});
```

#### 3. AlertStuckSeoGenerationsJob Tests

```php
// tests/Feature/AlertStuckSeoGenerationsJobTest.php

it('sends alert when pages are stuck', function () {
    Http::fake();
    config(['services.slack.alerts_webhook' => 'https://hooks.slack.com/test']);

    SeoPage::factory()->create([
        'status' => SeoPageStatus::Generating,
        'generation_started_at' => now()->subHours(3),
        'target_keyword' => 'stuck keyword',
    ]);

    (new AlertStuckSeoGenerationsJob())->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'stuck keyword');
    });
});

it('does not alert for recently started generations', function () {
    Http::fake();
    config(['services.slack.alerts_webhook' => 'https://hooks.slack.com/test']);

    SeoPage::factory()->create([
        'status' => SeoPageStatus::Generating,
        'generation_started_at' => now()->subMinutes(30),
    ]);

    (new AlertStuckSeoGenerationsJob())->handle();

    Http::assertNothingSent();
});
```

#### 4. SeoQualityGateService Tests

```php
// tests/Unit/Services/Seo/SeoQualityGateServiceTest.php

it('fails pages below minimum word count', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 500,
        'humanization_score' => 100,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'A title that is long enough for SEO',
        'meta_description' => str_repeat('x', 100),
    ]);

    $service = app(SeoQualityGateService::class);

    expect($service->canPublish($page))->toBeFalse();
});

it('passes pages meeting all requirements', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 1200,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'A title that is long enough for SEO',
        'meta_description' => str_repeat('x', 100),
    ]);

    $service = app(SeoQualityGateService::class);

    expect($service->canPublish($page))->toBeTrue();
});

it('calculates quality score correctly', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 1200,        // Pass: 20 points
        'humanization_score' => 95,   // Pass: 25 points
        'internal_links_count' => 5,  // Pass: 15 points
        'schema_valid' => false,      // Fail: 0 points
        'proprietary_data_count' => 3, // Pass: 15 points
        'meta_title' => 'A title that is long enough for SEO',
        'meta_description' => str_repeat('x', 100), // Pass: 10 points
    ]);

    $service = app(SeoQualityGateService::class);
    $result = $service->validate($page);

    expect($result['score'])->toBe(85); // Total - schema (15)
});

it('auto-publishes pages passing all gates', function () {
    $pages = SeoPage::factory()->count(3)->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1200,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'A title that is long enough for SEO',
        'meta_description' => str_repeat('x', 100),
    ]);

    $service = app(SeoQualityGateService::class);
    $published = $service->autoPublishReady();

    expect($published)->toBe(3);
    expect(SeoPage::where('status', SeoPageStatus::Published)->count())->toBe(3);
});
```

#### 5. SearchConsoleService Rate Limiting Tests

```php
// tests/Unit/Services/Google/SearchConsoleServiceTest.php

it('respects rate limits during page sync', function () {
    // This is covered but should add:
    // - Test exponential backoff timing
    // - Test quota error detection
    // - Test circuit breaker threshold
});

it('detects quota errors correctly', function () {
    $job = new SyncSeoPerformanceJob();
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('isQuotaError');
    $method->setAccessible(true);

    expect($method->invoke($job, new Exception('quota exceeded')))->toBeTrue();
    expect($method->invoke($job, new Exception('429 Too Many Requests')))->toBeTrue();
    expect($method->invoke($job, new Exception('rate limit exceeded')))->toBeTrue();
    expect($method->invoke($job, new Exception('not found')))->toBeFalse();
});
```

#### 6. Integration Tests

```php
// tests/Feature/SeoFullPipelineTest.php

it('completes full generation pipeline end-to-end', function () {
    // 1. Seed queue
    $page = SeoPage::factory()->create([
        'status' => SeoPageStatus::Queued,
        'priority' => 1,
    ]);

    // 2. Process queue
    (new ProcessSeoContentQueueJob())->handle();

    // 3. Verify generation started
    expect($page->fresh()->status)->toBe(SeoPageStatus::Generating);

    // 4. Simulate agent completion (would be done by agent)
    $page->update([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1200,
        'humanization_score' => 100,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'generation_completed_at' => now(),
    ]);

    // 5. Verify quality gates
    $service = app(SeoQualityGateService::class);
    expect($service->canPublish($page->fresh()))->toBeTrue();
});
```

---

## Configuration Reference

### Environment Variables

```bash
# Google API Integration
GOOGLE_SEARCH_CONSOLE_SITE_URL=sc-domain:example.com
GOOGLE_GA4_PROPERTY_ID=123456789
GOOGLE_API_RATE_LIMIT_PER_MINUTE=60

# Content Generation
GROK_API_KEY=xai-xxx
GROK_MODEL=grok-4-1-fast

# Alerting
SLACK_ALERTS_WEBHOOK=https://hooks.slack.com/services/xxx

# Optional - Enhanced keyword data
SEMRUSH_API_KEY=
AHREFS_API_KEY=
```

### Config Files

- `config/seo.php` - Location data, default settings
- `config/services.php` - Google API configuration
- `routes/console.php` - Job schedules

### Queue Configuration

Ensure Horizon is configured with appropriate worker counts:

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-seo' => [
            'connection' => 'redis',
            'queue' => ['seo-content', 'agents'],
            'processes' => 3,  // Match MAX_CONCURRENT_GENERATIONS
            'timeout' => 1800, // 30 minutes for long generations
        ],
    ],
],
```

---

## Troubleshooting Guide

### Common Issues

**Issue: Pages stuck in 'queued' status**
- Check if `ProcessSeoContentQueueJob` is running (Horizon)
- Verify MAX_CONCURRENT_GENERATIONS not reached
- Check for failed jobs in queue

**Issue: High API error rate**
- Check Google Cloud Console for quota
- Verify OAuth tokens are valid
- Review rate limit configuration

**Issue: Low humanization scores**
- Review AI prompt in `SeoContentWriterAgent`
- Check Grok API status
- Consider prompt engineering improvements

**Issue: Quality gates failing frequently**
- Review generation logs for patterns
- Check if agent is receiving proprietary data
- Verify internal link tool is available

---

## Appendix: Status State Machine

```
queued ──────► generating ──────► draft ──────► published
   │                │               │               │
   │                │               │               ▼
   │                ▼               ▼           archived
   │             failed ◄───────────┘
   │                ▲
   └────────────────┘ (retry)
```

Valid transitions:
- `queued` -> `generating`: When ProcessSeoContentQueueJob dispatches
- `generating` -> `draft`: When agent completes successfully
- `generating` -> `failed`: When agent fails or timeout
- `draft` -> `published`: When quality gates pass
- `draft` -> `failed`: When manually rejected
- `published` -> `archived`: When content deprecated
- `failed` -> `queued`: Manual retry

---

*Last updated: 2026-01-19*
*System version: Phase 5 complete*
