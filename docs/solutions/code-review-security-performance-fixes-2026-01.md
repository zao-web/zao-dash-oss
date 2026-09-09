---
title: "Systematic Code Review Fixes - January 2026"
category: code-review
tags: [security, performance, data-integrity, architecture, idor, sql-injection, oom, transactions]
module: Multiple
symptom: "Issues discovered via automated multi-agent code review"
root_cause: "Accumulated technical debt across security, performance, and architecture domains"
discovered_by: "8 parallel review agents (security-sentinel, performance-oracle, architecture-strategist, data-integrity-guardian, code-simplicity-reviewer, agent-native-reviewer, pattern-recognition-specialist, git-history-analyzer)"
fix_commit: "e727243"
branch: "feature/interactive-agent-sessions"
date: "2026-01-19"
---

# Systematic Code Review Fixes - January 2026

## Overview

A comprehensive code review was conducted on the `feature/interactive-agent-sessions` branch using 8 parallel specialized agents. The review identified 20+ issues across security, performance, data integrity, and architecture domains.

**Review Scope:** 44 files, +6,641/-373 lines

**Methodology:** Each specialized agent reviewed the codebase for their domain:
- **security-sentinel**: IDOR, injection, authentication vulnerabilities
- **performance-oracle**: N+1 queries, OOM risks, memory efficiency
- **data-integrity-guardian**: Transactions, FK constraints, race conditions
- **architecture-strategist**: God classes, orphaned code, coupling
- **code-simplicity-reviewer**: Unnecessary complexity, over-engineering
- **agent-native-reviewer**: Agent accessibility and tool parity
- **pattern-recognition-specialist**: Anti-patterns, naming conventions
- **git-history-analyzer**: Historical context and patterns

---

## P1 Critical Fixes Applied

### 1. SQL LIKE Wildcard Injection (Issue #014)

**Problem:** User-controlled URL paths concatenated into LIKE patterns without escaping SQL wildcards.

**Location:** `app/Services/Seo/LeadAttributionService.php:61-71`

**Before:**
```php
$path = parse_url($cleanUrl, PHP_URL_PATH) ?? '';
return SeoPage::where('page_url', 'like', '%'.$path)->first();
```

**After:**
```php
// Escape LIKE wildcards to prevent injection
$path = str_replace(['%', '_'], ['\%', '\_'], parse_url($cleanUrl, PHP_URL_PATH) ?? '');
return SeoPage::where('page_url', 'like', '%'.$path)->first();
```

**Why:** Prevents attackers from using URLs like `/test%25%25` to match unintended records.

---

### 2. IDOR on Interaction API Endpoints (Issue #015)

**Problem:** Any authenticated user could view/respond to any agent interaction request.

**Location:** `routes/web.php:1147-1153` and `InteractionResponseController.php`

**Fix Applied:**
```php
// routes/web.php - Added EnsureInternalUser middleware
Route::middleware([\App\Http\Middleware\EnsureInternalUser::class])->group(function () {
    Route::get('/api/interactions/pending', ...);
    Route::get('/api/interactions/{interaction}', ...);
    Route::post('/api/interactions/{interaction}/respond', ...);
});
```

**Why:** Restricts access to internal team members only. Client portal users are denied with 403.

---

### 3. Race Condition in Lead Attribution (Issue #016)

**Problem:** Two separate DB operations without transaction; counter drift and double attribution possible.

**Location:** `app/Services/Seo/LeadAttributionService.php:33-59`

**Fix Applied:**
```php
// Prevent double attribution
if ($lead->seo_page_id !== null) {
    return false;
}

// Wrap in transaction to prevent race conditions
return DB::transaction(function () use ($lead, $seoPage) {
    $lead->update(['seo_page_id' => $seoPage->id]);
    $seoPage->increment('total_leads');
    event(new LeadAttributedToSeoPage($lead, $seoPage));
    return true;
});
```

---

### 4. Migration FK Rollback Failure (Issue #017)

**Problem:** `down()` method tried to drop column without dropping FK constraint first.

**Location:** `database/migrations/2026_01_20_053700_add_enhanced_attribution_to_leads.php`

**Fix Applied:**
```php
public function down(): void
{
    // Must drop foreign key BEFORE dropping column
    Schema::table('leads', function (Blueprint $table) {
        if (Schema::hasColumn('leads', 'seo_page_id')) {
            $table->dropForeign(['seo_page_id']);
        }
    });

    Schema::table('leads', function (Blueprint $table) {
        // Now safe to drop columns...
    });
}
```

---

### 5. SyncSeoPerformanceJob OOM Risk (Issue #018)

**Problem:** Loading all SEO pages with `->get()` risked OOM at scale (10,000+ pages = 500MB+).

**Location:** `app/Jobs/SyncSeoPerformanceJob.php:60`

**Fix Applied:**
```php
// Use cursor() for memory-efficient iteration
foreach (SeoPage::active()->cursor() as $page) {
    // Only one model in memory at a time
}
```

**Also fixed:** `LeadAttributionService::getAttributionSummary()` now uses DB aggregates:
```php
$stats = $seoPage->leads()
    ->selectRaw('COUNT(*) as total_leads, SUM(deal_value) as total_value, ...')
    ->first();
```

---

## P2 Important Fixes Applied

### 6. Slack Signature Verification Fail-Open (Issue #006)

**Problem:** Missing signing secret caused verification to silently return `true`.

**Location:** `app/Http/Controllers/SlackWebhookController.php:74-87`

**Fix Applied:**
```php
if (! $signingSecret) {
    if (app()->environment('local', 'testing')) {
        Log::warning('Slack signing secret not configured - bypassing in dev');
        return true;
    }
    Log::critical('Slack signing secret not configured - rejecting in production');
    return false;
}
```

---

### 7. Orphaned Event with No Listeners (Issue #020)

**Problem:** `LeadAttributedToSeoPage` event dispatched but never handled.

**Fix Applied:**
- Created `app/Listeners/LogLeadAttribution.php`
- Registered in `AppServiceProvider::boot()`

```php
class LogLeadAttribution
{
    public function handle(LeadAttributedToSeoPage $event): void
    {
        Log::info('Lead attributed to SEO page', [
            'lead_id' => $event->lead->id,
            'seo_page_id' => $event->seoPage->id,
            'seo_page_url' => $event->seoPage->page_url,
        ]);
    }
}
```

---

## Issues Already Fixed (Found During Review)

| Issue | Status | Notes |
|-------|--------|-------|
| 001 - CreateInvoiceTool transaction | Already implemented | DB::transaction() present |
| 002 - MCP tools authorization | Already implemented | EnsureInternalUser on MCP routes |
| 003 - CreateClientNoteTool null user | Already implemented | Null validation present |
| 005 - N+1 query | Already fixed | Uses withCount() |
| 009 - Missing database indexes | Already exist | All recommended indexes present |

---

## Files Changed

```
app/Http/Controllers/Api/InteractionResponseController.php  +22 lines
app/Http/Controllers/SlackWebhookController.php             +8 lines
app/Jobs/SyncSeoPerformanceJob.php                          +1 line
app/Listeners/LogLeadAttribution.php                        NEW +28 lines
app/Providers/AppServiceProvider.php                        +3 lines
app/Services/Seo/LeadAttributionService.php                 +35 lines
database/migrations/..._add_enhanced_attribution...php      +7 lines
routes/web.php                                              +5 lines
```

---

## Prevention Strategies

### SQL Injection (LIKE Wildcards)
```php
// Always escape before LIKE queries
$safeInput = str_replace(['%', '_'], ['\%', '\_'], $userInput);
```

### IDOR Prevention
```php
// Route-level: Use EnsureInternalUser middleware
Route::middleware([EnsureInternalUser::class])->group(...);

// Controller-level: Verify ownership
if ($resource->user_id !== auth()->id()) {
    abort(403);
}
```

### Data Integrity
```php
// Multi-table operations need transactions
DB::transaction(function () use (...) {
    $parent->update([...]);
    $child->create([...]);
    $parent->increment('counter');
});
```

### Memory Efficiency
```php
// Large datasets: Use cursor() not get()
foreach (Model::query()->cursor() as $item) { ... }

// Aggregates: Use DB, not collections
$total = Model::query()->sum('amount');  // Good
$total = Model::all()->sum('amount');    // Bad - loads all into memory
```

### Security Configuration
```php
// Always fail closed in production
if (! $configValue) {
    if (app()->environment('local', 'testing')) {
        return true;  // Dev bypass
    }
    Log::critical('Missing required config');
    return false;  // Production denial
}
```

---

## Code Review Checklist

Use this checklist for future reviews:

### Security
- [ ] LIKE queries escape `%` and `_` from user input
- [ ] All resource endpoints have authorization checks
- [ ] Token validation denies on missing config (fail closed)
- [ ] `hash_equals()` used for signature comparison

### Data Integrity
- [ ] Multi-table operations wrapped in transactions
- [ ] Counter operations inside transactions with duplicate checks
- [ ] Migration `down()` drops FKs before columns
- [ ] Events have registered listeners

### Performance
- [ ] Large iterations use `cursor()` or `chunk()`
- [ ] Aggregates use query builder, not collections
- [ ] Eager loading prevents N+1 queries
- [ ] Database has indexes for frequently-queried columns

### Architecture
- [ ] No orphaned events or dead code
- [ ] Controllers under 500 lines
- [ ] Services handle business logic, controllers handle HTTP

---

## Remaining P2 Issues (Deferred)

| Issue | Description | Effort |
|-------|-------------|--------|
| 019 | SlackWebhookController refactoring (1710 lines) | Large |
| 004 | Add MCP tool tests | Medium |
| 007 | Fix duplicate location data | Medium |
| 008 | Fix unused dependency in test | Small |
| 010 | Extract intent detection service | Medium |
| 011 | Expose SEO services via MCP | Medium |

---

## Lessons Learned

1. **Automated review agents find issues humans miss**: 8 specialized agents found 20+ issues that manual review might overlook.

2. **Many issues were already fixed**: 5 of the identified P1 issues were already addressed, showing good defensive coding practices.

3. **Quick wins compound**: Most P1 fixes took 15-30 minutes each. Addressing them immediately prevents technical debt accumulation.

4. **Fail-closed is non-negotiable**: Security configuration that "fails open" creates invisible vulnerabilities.

5. **Memory efficiency matters at scale**: Operations that work fine with 100 records can OOM with 10,000.
