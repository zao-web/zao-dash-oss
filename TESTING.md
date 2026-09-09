# Zao Dash - Test Suite Documentation

Comprehensive testing for all services in the Zao Dash application.

## Overview

**Total Test Files**: 10
**Total Tests**: 157+
**Coverage Target**: 90%
**Framework**: PHPUnit with Laravel Testing Utilities

## Quick Start

```bash
# Run all tests
php artisan test

# Run only service tests
php artisan test tests/Unit/Services

# Run with coverage report
php artisan test --coverage

# Run specific test file
php artisan test tests/Unit/Services/Approval/ApprovalServiceTest.php

# Run in parallel
php artisan test --parallel
```

## Test Coverage Summary

### Core Services

| Service | Tests | Coverage Areas |
|---------|-------|----------------|
| **ApprovalService** | 17 | Request creation, approval/rejection, auto-approval, escalation, 2FA, notifications |
| **AgentSandbox** | 20 | Sandbox creation, path validation, file operations, domain allowlisting, cleanup |
| **KpiCalculator** | 23 | Revenue, projects, clients, pipeline, team utilization, agents, goals, caching |
| **VaultService** | 24 | Secret storage, encryption, access control, rotation, deletion, logging |
| **BusinessIntelligenceService** | 19 | Funnel metrics, conversions, forecasting, goal tracking, levers, capacity |

### Integration Services (with HTTP mocking)

| Service | Tests | Coverage Areas |
|---------|-------|----------------|
| **SlackApiService** | 14 | Channels, messages, threads, users, webhooks, pagination |
| **GitHubApiService** | 18 | Issues, PRs, comments, merging, secrets, workflows, webhooks |
| **AnthropicService** | 16 | Messages, tools, streaming, context, error handling |
| **CalendarService** | 3 | Calendar listing, event management, auth |
| **HarvestApiService** | 3 | Projects, time entries, invoices, auth |

## Test Structure

```
tests/
├── Unit/
│   └── Services/
│       ├── Approval/
│       │   └── ApprovalServiceTest.php
│       ├── Analytics/
│       │   └── KpiCalculatorTest.php
│       ├── Vault/
│       │   └── VaultServiceTest.php
│       ├── AI/
│       │   └── AnthropicServiceTest.php
│       ├── Slack/
│       │   └── SlackApiServiceTest.php
│       ├── GitHub/
│       │   └── GitHubApiServiceTest.php
│       ├── Google/
│       │   └── CalendarServiceTest.php
│       ├── Harvest/
│       │   └── HarvestApiServiceTest.php
│       ├── AgentSandboxTest.php
│       ├── BusinessIntelligenceServiceTest.php
│       └── README.md
└── Feature/
    ├── AgentExecutionTest.php
    ├── AgentTemplatesTest.php
    ├── CostTrackingTest.php
    └── WebhookTest.php
```

## Testing Patterns

### 1. Mocked HTTP Requests

External API calls are mocked using Laravel's HTTP fake:

```php
Http::fake([
    'api.example.com/*' => Http::response(['data' => 'test'], 200),
]);

$result = $service->apiCall();

Http::assertSent(function ($request) {
    return $request->hasHeader('Authorization', 'Bearer token');
});
```

### 2. Database Testing

```php
use RefreshDatabase;

$model = Model::factory()->create();
$service->update($model);

$this->assertDatabaseHas('models', [
    'status' => 'updated',
]);
```

### 3. Event Testing

```php
Event::fake();

$service->performAction();

Event::assertDispatched(NotificationCreated::class, function ($event) {
    return $event->type === 'success';
});
```

### 4. Exception Testing

```php
$this->expectException(ValidationException::class);
$this->expectExceptionMessage('Invalid input');

$service->validate($invalidData);
```

## Key Test Examples

### ApprovalService Tests

```php
/** @test */
public function it_auto_approves_when_conditions_met()
{
    config(['approval_policies.categories.test' => [
        'risk_level' => 'low',
        'auto_approve_conditions' => ['amount_under' => 1000],
    ]]);

    $approval = $service->createRequest($run, 'test', 'Small expense', ['amount' => 500]);

    $this->assertEquals('approved', $approval->status);
}

/** @test */
public function it_requires_2fa_for_critical_approvals()
{
    $approval = ApprovalRequest::factory()->create([
        'category' => 'critical',
        'risk_level' => 'critical',
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('2FA verification required');

    $service->approve($approval, $userWithout2FA);
}
```

### AgentSandbox Tests

```php
/** @test */
public function it_blocks_sensitive_file_patterns()
{
    $blockedFiles = ['.env', 'credentials.json', 'secret.txt', 'key.pem'];

    foreach ($blockedFiles as $file) {
        $this->assertFalse(
            $sandbox->isPathAllowed($sandboxPath, $sandboxPath . '/' . $file)
        );
    }
}

/** @test */
public function it_validates_allowed_urls()
{
    config(['agents.domains._default' => ['api.example.com']]);

    $this->assertTrue($sandbox->isUrlAllowed('https://api.example.com/endpoint', $agent));
    $this->assertFalse($sandbox->isUrlAllowed('https://evil.com/endpoint', $agent));
}
```

### VaultService Tests

```php
/** @test */
public function it_logs_access_attempts()
{
    $secret = VaultSecret::factory()->create(['key' => 'test.key']);

    $vault->get('test.key', $user);

    $this->assertDatabaseHas('vault_access_logs', [
        'secret_id' => $secret->id,
        'action' => 'read',
        'accessor_id' => $user->id,
        'success' => true,
    ]);
}

/** @test */
public function it_prevents_unauthorized_updates()
{
    $secret = VaultSecret::factory()->create([
        'allowed_users' => [999], // Different user
    ]);

    $result = $vault->update($secret, 'new-value', $unauthorizedUser);

    $this->assertFalse($result);
}
```

### Integration Service Tests (HTTP Mocking)

```php
/** @test */
public function it_syncs_github_issues()
{
    Http::fake([
        'api.github.com/repos/*/issues' => Http::response([
            [
                'number' => 1,
                'title' => 'Bug fix',
                'state' => 'open',
                'labels' => [['name' => 'bug']],
            ],
        ], 200),
    ]);

    $service->syncIssues($repo);

    $this->assertDatabaseHas('git_hub_issues', [
        'issue_number' => 1,
        'title' => 'Bug fix',
    ]);
}

/** @test */
public function it_posts_slack_message()
{
    Http::fake([
        'slack.com/api/chat.postMessage*' => Http::response([
            'ok' => true,
            'ts' => '1234.5678',
        ], 200),
    ]);

    $result = $service->postMessage($workspace, 'C1234', 'Hello!');

    Http::assertSent(function ($request) {
        return $request['text'] === 'Hello!';
    });
}
```

## Running Specific Test Suites

```bash
# Core services
php artisan test tests/Unit/Services/Approval
php artisan test tests/Unit/Services/Analytics
php artisan test tests/Unit/Services/Vault

# Integration services
php artisan test tests/Unit/Services/Slack
php artisan test tests/Unit/Services/GitHub
php artisan test tests/Unit/Services/AI

# Business intelligence
php artisan test tests/Unit/Services/BusinessIntelligenceServiceTest.php

# Agent sandbox
php artisan test tests/Unit/Services/AgentSandboxTest.php
```

## Test Data Setup

All tests use model factories for consistent data:

```php
// Users
$admin = User::factory()->create(['role' => 'admin']);
$user = User::factory()->create();

// Approvals
$pending = ApprovalRequest::factory()->pending()->create();
$approved = ApprovalRequest::factory()->approved()->create();

// Vault
$secret = VaultSecret::factory()->create(['is_active' => true]);

// Integrations
$workspace = SlackWorkspace::factory()->create();
$repo = GitHubRepo::factory()->create();
```

## Continuous Integration

Tests run automatically:
- On every commit (pre-commit hook)
- On pull requests (GitHub Actions)
- Before deployment (CI/CD pipeline)

### CI Configuration

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Run tests
        run: php artisan test --parallel
      - name: Coverage
        run: php artisan test --coverage --min=80
```

## Coverage Requirements

| Service Type | Minimum | Target |
|--------------|---------|--------|
| Critical Services (Approval, Vault, Sandbox) | 95% | 98% |
| Core Services (KPI, BI) | 90% | 95% |
| Integration Services | 80% | 90% |
| Overall | 80% | 90% |

## Best Practices

1. **Test Isolation**: Each test is independent
2. **Clear Naming**: Test names describe what is tested
3. **Mock External Deps**: HTTP, APIs, events are mocked
4. **Multiple Assertions**: Verify all aspects of behavior
5. **Success & Failure**: Test both happy and error paths
6. **Edge Cases**: Boundary conditions are covered
7. **Fast Execution**: Tests run in under 30 seconds

## Adding New Tests

When creating tests for a new service:

1. Create test file in `tests/Unit/Services/[Category]/`
2. Extend `TestCase` and use `RefreshDatabase`
3. Mock external dependencies (HTTP, Events, Queue)
4. Test all public methods
5. Cover success, failure, edge cases
6. Add PHPDoc `@test` annotation
7. Update documentation

Example:

```php
<?php

namespace Tests\Unit\Services\MyService;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class MyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyService();
    }

    /** @test */
    public function it_performs_action()
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $result = $this->service->doSomething();

        $this->assertTrue($result);
    }
}
```

## Troubleshooting

### Tests Fail Locally But Pass in CI
- Check database state (run `php artisan migrate:fresh`)
- Clear cache (`php artisan config:clear`)
- Check environment variables

### Slow Test Execution
- Use `--parallel` flag
- Check for missing HTTP fakes
- Reduce database factory usage

### Flaky Tests
- Check for timezone issues
- Look for uncleared static state
- Verify proper test isolation

## Resources

- [Laravel Testing Docs](https://laravel.com/docs/testing)
- [PHPUnit Docs](https://phpunit.de/documentation.html)
- [HTTP Testing](https://laravel.com/docs/http-tests)
- [Database Testing](https://laravel.com/docs/database-testing)
