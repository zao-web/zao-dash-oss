# Self-Healing System Technical Spec

## Overview

Zao Dashboard monitors its own error logs via Laravel Nightwatch → Slack, automatically spawns Dev Agent to fix issues, commits fixes to GitHub, and triggers deployment. **The system that fixes itself.**

---

## Architecture

```
Laravel Nightwatch (error monitoring)
    ↓
Slack (#ops-logs channel)
    ↓
Slack Events API → SlackWebhookController
    ↓
NightwatchErrorDetector (parser)
    ↓
SelfHealingJob (dispatched)
    ├── Deduplication check
    ├── Safety rails check
    └── Spawn DevAgent
        ↓
    DevAgent analyzes + fixes
        ↓
    Commit + Push to GitHub
        ↓
    Laravel Cloud auto-deploy
        ↓
    Error resolved (or escalate to human)
```

---

## 1. Nightwatch Message Parser

### Nightwatch Slack Format
Nightwatch posts errors in a structured format. Extract:

```
┌─────────────────────────────────────────┐
│ 🔴 Exception: App\Jobs\SyncHarvestJob   │
│                                         │
│ Target class [HarvestService] does not  │
│ exist                                   │
│                                         │
│ File: Container.php:1124                │
│ Stack trace: [View in Nightwatch]       │
│                                         │
│ Environment: production                 │
│ Occurred: 3 times in last hour          │
└─────────────────────────────────────────┘
```

### Parsed Data Structure

```php
class NightwatchError
{
    public string $exceptionClass;     // "Illuminate\Container\BindingResolutionException"
    public string $message;            // "Target class [HarvestService] does not exist"
    public ?string $file;              // "Container.php"
    public ?int $line;                 // 1124
    public string $sourceJob;          // "App\Jobs\SyncHarvestJob"
    public string $environment;        // "production"
    public int $occurrenceCount;       // 3
    public string $nightwatchUrl;      // Link to full trace
    public string $slackMessageTs;     // For threading responses
    public string $slackChannelId;
}
```

### Detection Logic

```php
class NightwatchErrorDetector
{
    public function isNightwatchError(SlackMessage $message): bool
    {
        // Check sender (Nightwatch bot)
        // Check channel (#ops-logs)
        // Check message format (contains exception markers)
    }

    public function parse(SlackMessage $message): ?NightwatchError
    {
        // Extract structured data from Nightwatch message
        // Handle attachments, blocks, and plain text formats
    }
}
```

---

## 2. Self-Healing Job

### Job Dispatch

```php
// In SlackWebhookController or SlackMessageProcessor
if ($nightwatchDetector->isNightwatchError($message)) {
    $error = $nightwatchDetector->parse($message);

    if ($error && $this->shouldAttemptFix($error)) {
        SelfHealingJob::dispatch($error);
    }
}
```

### SelfHealingJob

```php
class SelfHealingJob implements ShouldQueue
{
    public function __construct(
        public NightwatchError $error
    ) {}

    public function handle(
        SelfHealingService $service,
        DevAgentRunner $devAgent
    ): void {
        // 1. Deduplication - already being fixed?
        if ($service->isAlreadyBeingFixed($this->error)) {
            return;
        }

        // 2. Rate limit check
        if ($service->hasExceededRateLimit()) {
            $service->notifyRateLimitReached();
            return;
        }

        // 3. Circuit breaker check
        if ($service->circuitBreakerOpen()) {
            $service->notifyCircuitBreakerOpen();
            return;
        }

        // 4. Create healing attempt record
        $attempt = $service->createAttempt($this->error);

        try {
            // 5. Spawn Dev Agent with error context
            $result = $devAgent->fixError($this->error, $attempt);

            if ($result->success) {
                $service->markSuccess($attempt, $result);
                $this->replyToSlack("✅ Fixed and deployed: {$result->commitUrl}");
            } else {
                $service->markFailure($attempt, $result);
                $this->replyToSlack("⚠️ Couldn't auto-fix. Escalating to human.");
                $service->escalateToHuman($this->error, $result);
            }

        } catch (\Exception $e) {
            $service->markFailure($attempt, $e);
            $service->checkCircuitBreaker();
        }
    }

    protected function replyToSlack(string $message): void
    {
        // Reply in thread to original Nightwatch message
    }
}
```

---

## 3. Safety Rails

### 3.1 Scope Restriction

**Only fix errors in the zao-dash repository itself.**

```php
class SelfHealingService
{
    protected array $allowedRepos = [
        'zao-web/zao-dash',
    ];

    public function canFix(NightwatchError $error): bool
    {
        // Only fix if error is in allowed repo
        // Determined by file path or source analysis
    }
}
```

### 3.2 Deduplication

```php
// Don't fix the same error simultaneously
// Don't fix an error that was already fixed recently

public function isAlreadyBeingFixed(NightwatchError $error): bool
{
    return SelfHealingAttempt::query()
        ->where('error_signature', $this->getSignature($error))
        ->where('status', 'in_progress')
        ->exists();
}

public function wasRecentlyFixed(NightwatchError $error): bool
{
    return SelfHealingAttempt::query()
        ->where('error_signature', $this->getSignature($error))
        ->where('status', 'success')
        ->where('created_at', '>', now()->subHours(6))
        ->exists();
}

protected function getSignature(NightwatchError $error): string
{
    // Hash of exception class + message + source
    return md5($error->exceptionClass . $error->message . $error->sourceJob);
}
```

### 3.3 Rate Limiting

```php
// Max 5 fixes per hour
// Max 10 fixes per day

public function hasExceededRateLimit(): bool
{
    $hourlyCount = SelfHealingAttempt::where('created_at', '>', now()->subHour())->count();
    $dailyCount = SelfHealingAttempt::where('created_at', '>', now()->subDay())->count();

    return $hourlyCount >= 5 || $dailyCount >= 10;
}
```

### 3.4 Circuit Breaker

```php
// If 3 consecutive failures, stop attempting for 1 hour

public function circuitBreakerOpen(): bool
{
    $recentAttempts = SelfHealingAttempt::query()
        ->latest()
        ->take(3)
        ->get();

    if ($recentAttempts->count() < 3) {
        return false;
    }

    $allFailed = $recentAttempts->every(fn($a) => $a->status === 'failed');
    $lastAttempt = $recentAttempts->first();

    return $allFailed && $lastAttempt->created_at->gt(now()->subHour());
}
```

### 3.5 Error Type Filtering

```php
// Some errors shouldn't be auto-fixed

protected array $skipPatterns = [
    'Class .* does not exist',        // Missing class - needs human decision
    'SQLSTATE.*does not exist',       // Missing column - migration needed (CAN fix)
    'Connection refused',              // Infrastructure issue
    'Too many connections',            // Database overload
    'Out of memory',                   // Resource issue
    'Maximum execution time',          // Timeout
];

protected array $autoFixPatterns = [
    'Undefined column',               // Missing migration - CREATE migration
    'Undefined property',             // Missing property on model
    'Target class .* does not exist', // Missing service - CREATE or fix import
    'Call to undefined method',       // Method doesn't exist
    'Type error: Argument',           // Type mismatch
];

public function shouldAttemptFix(NightwatchError $error): bool
{
    foreach ($this->skipPatterns as $pattern) {
        if (preg_match("/$pattern/i", $error->message)) {
            return false;
        }
    }

    foreach ($this->autoFixPatterns as $pattern) {
        if (preg_match("/$pattern/i", $error->message)) {
            return true;
        }
    }

    // Default: attempt fix for unknown error types
    return true;
}
```

---

## 4. Dev Agent Integration

### Error Context for Agent

```php
class DevAgentRunner
{
    public function fixError(NightwatchError $error, SelfHealingAttempt $attempt): AgentResult
    {
        $context = $this->buildContext($error);

        return $this->runner->execute(
            agent: 'dev-agent',
            task: $this->buildTask($error),
            context: $context,
            options: [
                'auto_commit' => true,
                'auto_push' => true,
                'branch' => 'main', // Direct to main for hotfixes
                'commit_prefix' => '[auto-fix]',
            ]
        );
    }

    protected function buildTask(NightwatchError $error): string
    {
        return <<<TASK
        Fix the following production error in zao-dash:

        **Exception**: {$error->exceptionClass}
        **Message**: {$error->message}
        **Source**: {$error->sourceJob}
        **File**: {$error->file}:{$error->line}
        **Occurrences**: {$error->occurrenceCount} times

        Instructions:
        1. Analyze the error and identify root cause
        2. Implement the fix (create migration if needed, add missing class, fix import, etc.)
        3. Ensure the fix doesn't break existing functionality
        4. Run `php artisan test` to verify no regressions
        5. Commit with message: "[auto-fix] {brief description}"
        6. Push to main branch

        Important:
        - This is a production hotfix - be conservative
        - If unsure, document findings and escalate
        - Do not make unrelated changes
        TASK;
    }
}
```

### Agent SKILL.md Addition

Add to `storage/app/skills/dev-agent/SKILL.md`:

```markdown
## Self-Healing Mode

When fixing production errors (task contains "[auto-fix]"):

1. **Be Conservative**: Only fix the specific error, nothing else
2. **Verify First**: Read the full stack trace context
3. **Create Migrations**: If missing columns, create proper migration
4. **Create Classes**: If missing class, check if it should exist or if import is wrong
5. **Test Locally**: Run `php artisan test` before committing
6. **Clear Commit**: Prefix with "[auto-fix]" and describe what was fixed
7. **Escalate If Unsure**: Return failure if fix isn't obvious

### Common Auto-Fix Patterns

| Error Pattern | Fix Strategy |
|---------------|--------------|
| Column does not exist | Create migration adding column |
| Class does not exist | Create class OR fix import statement |
| Undefined method | Add method OR fix method call |
| Type error | Add proper type casting |
```

---

## 5. Database Schema

### `self_healing_attempts`

```php
Schema::create('self_healing_attempts', function (Blueprint $table) {
    $table->id();
    $table->string('error_signature');  // For deduplication
    $table->string('exception_class');
    $table->text('error_message');
    $table->string('source_job')->nullable();
    $table->string('source_file')->nullable();
    $table->integer('source_line')->nullable();
    $table->string('nightwatch_url')->nullable();
    $table->string('slack_message_ts');
    $table->string('slack_channel_id');

    $table->enum('status', ['pending', 'in_progress', 'success', 'failed', 'escalated']);
    $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
    $table->string('commit_sha')->nullable();
    $table->string('commit_url')->nullable();
    $table->text('failure_reason')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();

    $table->timestamps();

    $table->index('error_signature');
    $table->index('status');
    $table->index('created_at');
});
```

---

## 6. Slack Integration

### Channel Configuration

```php
// config/self-healing.php
return [
    'enabled' => env('SELF_HEALING_ENABLED', false),
    'slack_channel' => env('SELF_HEALING_SLACK_CHANNEL', '#ops-logs'),
    'nightwatch_bot_id' => env('NIGHTWATCH_BOT_ID'), // Slack bot user ID

    'rate_limits' => [
        'per_hour' => 5,
        'per_day' => 10,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 3,
        'cooldown_minutes' => 60,
    ],

    'allowed_repos' => [
        'zao-web/zao-dash',
    ],
];
```

### Slack Responses

Thread replies to the original Nightwatch message:

```
🤖 Self-healing triggered. Analyzing error...

---

🔧 Fix identified: Missing `issues_synced_at` column in `github_repos` table

Creating migration...
Running tests...
Committing and pushing...

---

✅ Fixed and deployed!
Commit: [abc123](https://github.com/zao-web/zao-dash/commit/abc123)
Migration: `2025_12_18_084027_add_sync_timestamps_to_github_repos_table.php`

The fix will be live in ~2 minutes after deployment completes.
```

Or if escalated:

```
⚠️ Couldn't auto-fix this error.

Analysis: The error suggests a missing service class, but I'm unsure if it should be created or if there's an import issue. This requires human review.

Escalating to @justin for manual fix.
```

---

## 7. Dashboard Integration

### Self-Healing Status Widget

```
┌─────────────────────────────────────────┐
│ 🩺 Self-Healing Status                  │
│ ═══════════════════════════════════════ │
│                                         │
│ Last 24 hours:                          │
│ ✅ 3 errors auto-fixed                  │
│ ⚠️ 1 escalated to human                 │
│ 🔄 0 in progress                        │
│                                         │
│ Circuit breaker: 🟢 Closed              │
│ Rate limit: 7/10 daily attempts used    │
│                                         │
│ [View History]                          │
└─────────────────────────────────────────┘
```

### Self-Healing History Page

- List all attempts with status
- Filter by: success, failed, escalated
- Click to see: error details, agent run, commit diff
- Manual retry button for escalated issues

---

## 8. Implementation Order

1. **NightwatchErrorDetector** - Parse Slack messages from Nightwatch
2. **Database migration** - `self_healing_attempts` table
3. **SelfHealingService** - Safety rails, deduplication, circuit breaker
4. **SelfHealingJob** - Orchestrate the fix attempt
5. **Dev Agent integration** - Context building, commit/push handling
6. **Slack webhook update** - Detect and dispatch
7. **Slack thread responses** - Status updates in thread
8. **Dashboard widget** - Status overview
9. **Config & toggle** - Enable/disable via env var

---

## 9. Testing Strategy

### Unit Tests
- `NightwatchErrorDetectorTest` - Parsing various message formats
- `SelfHealingServiceTest` - Safety rails logic
- `ErrorSignatureTest` - Deduplication key generation

### Integration Tests
- Mock Slack webhook → Job dispatched
- Mock Dev Agent → Commit created
- Circuit breaker activation after failures

### Staging Testing
- Send fake Nightwatch messages to staging Slack
- Verify fixes are attempted
- Verify safety rails work

---

## 10. Monitoring & Alerts

### Success Metrics
- Fix success rate (target: >70%)
- Mean time to fix (target: <10 minutes)
- Escalation rate (target: <30%)

### Alerts
- Circuit breaker opened → Slack alert to #eng-alerts
- 3+ escalations in 1 hour → Something systemic is wrong
- Fix created regression → Immediate human review

---

## 11. Future Enhancements

1. **Multi-repo support** - Fix errors in client repos (with approval)
2. **Pre-production catch** - Fix errors in staging before they hit prod
3. **Predictive healing** - Detect patterns that will cause errors
4. **Fix templates** - Pre-built fixes for common error types
5. **Learning loop** - Track which fixes worked, improve over time
