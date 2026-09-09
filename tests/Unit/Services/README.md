# Service Tests

Comprehensive test coverage for all services in `app/Services`.

## Test Structure

```
tests/Unit/Services/
├── Approval/
│   └── ApprovalServiceTest.php          (17 tests)
├── Analytics/
│   └── KpiCalculatorTest.php            (23 tests)
├── Vault/
│   └── VaultServiceTest.php             (24 tests)
├── Slack/
│   └── SlackApiServiceTest.php          (14 tests)
├── GitHub/
│   └── GitHubApiServiceTest.php         (18 tests)
├── AI/
│   └── AnthropicServiceTest.php         (16 tests)
├── Google/
│   └── CalendarServiceTest.php          (3 tests)
├── Harvest/
│   └── HarvestApiServiceTest.php        (3 tests)
├── AgentSandboxTest.php                 (20 tests)
└── BusinessIntelligenceServiceTest.php  (19 tests)
```

**Total: 157+ comprehensive tests**

## Running Tests

### All Service Tests
```bash
php artisan test tests/Unit/Services
```

### Specific Service
```bash
php artisan test tests/Unit/Services/Approval/ApprovalServiceTest.php
php artisan test tests/Unit/Services/Analytics/KpiCalculatorTest.php
php artisan test tests/Unit/Services/Vault/VaultServiceTest.php
```

### With Coverage
```bash
php artisan test --coverage --min=80
```

### Parallel Execution
```bash
php artisan test --parallel
```

## Test Coverage by Service

### ApprovalService (17 tests)
- ✓ Creating approval requests
- ✓ Auto-approval conditions
- ✓ Approving/rejecting requests
- ✓ 2FA requirements for critical actions
- ✓ Canceling requests
- ✓ Getting pending approvals with filtering
- ✓ Escalating stale approvals
- ✓ Expiring old approvals
- ✓ Emergency cancellation of all pending
- ✓ Evaluating auto-approve conditions
- ✓ Approval statistics
- ✓ Role-based notifications

### AgentSandbox (20 tests)
- ✓ Creating sandboxes with proper structure
- ✓ Manifest generation
- ✓ Path validation (within sandbox)
- ✓ Blocking sensitive file patterns
- ✓ Write operation validation
- ✓ File size limit enforcement
- ✓ Domain allowlisting
- ✓ URL validation
- ✓ HTTP request proxying
- ✓ Environment variable setup
- ✓ File copying with validation
- ✓ Output file retrieval
- ✓ Log retrieval
- ✓ Cleanup with/without preserving output
- ✓ Output archiving
- ✓ Old sandbox cleanup
- ✓ Sandbox statistics

### KpiCalculator (23 tests)
- ✓ Dashboard KPI aggregation
- ✓ Revenue calculations (invoiced, paid, outstanding)
- ✓ Fallback to Harvest when QuickBooks unavailable
- ✓ Project statistics
- ✓ Client health distribution
- ✓ Pipeline metrics
- ✓ Win rate calculations
- ✓ Team utilization rates
- ✓ Agent success rates and costs
- ✓ Goal progress tracking
- ✓ On-track determination
- ✓ Percent change calculations
- ✓ Hours by project
- ✓ Top performing agents
- ✓ Average sales cycle
- ✓ KPI caching
- ✓ Cache clearing

### VaultService (24 tests)
- ✓ Storing secrets with encryption
- ✓ Retrieving secrets
- ✓ Null returns for nonexistent/inactive/expired secrets
- ✓ User access control
- ✓ Agent access control
- ✓ Access logging (success and denied)
- ✓ Access statistics tracking
- ✓ Retrieving multiple secrets
- ✓ Updating secret values
- ✓ Unauthorized update prevention
- ✓ Secret rotation with logging
- ✓ Deletion (admin-only)
- ✓ Getting agent-scoped secrets
- ✓ Secret existence checks
- ✓ Listing accessible secrets
- ✓ Admin can list all secrets
- ✓ Filtering by category
- ✓ Metadata-only returns (no values)

### BusinessIntelligenceService (19 tests)
- ✓ Funnel metrics calculation
- ✓ Conversion rate calculations
- ✓ Win rate calculations
- ✓ Weighted pipeline calculations
- ✓ Funnel snapshot storage
- ✓ Required leads per week calculation
- ✓ Fallback assumptions when no history
- ✓ Goal progress tracking
- ✓ On-track/behind status detection
- ✓ Identifying growth levers when behind
- ✓ Lever suggestions (leads, win rate, deal size, etc.)
- ✓ Capacity analysis
- ✓ Capacity overload detection
- ✓ Revenue forecasting (30/60/90 day)
- ✓ Goal actuals updating
- ✓ No levers when on track

### SlackApiService (14 tests with mocked HTTP)
- ✓ Listing channels
- ✓ Pagination handling
- ✓ Syncing channels to database
- ✓ Getting channel history
- ✓ Error handling on failed requests
- ✓ Getting thread replies
- ✓ Getting user info
- ✓ Handling failed user lookups
- ✓ Storing messages
- ✓ Syncing threads
- ✓ Posting messages
- ✓ Getting permalinks
- ✓ Authorization header verification

### GitHubApiService (18 tests with mocked HTTP)
- ✓ Listing issues
- ✓ Query parameter passing
- ✓ Error handling
- ✓ Getting single issue
- ✓ Creating issues
- ✓ Storing issues to database
- ✓ Listing pull requests
- ✓ Getting single PR
- ✓ Storing PRs to database
- ✓ Merged PR detection
- ✓ Merging PRs
- ✓ Adding PR comments
- ✓ Setting repository secrets
- ✓ Creating workflow files
- ✓ Updating existing workflows
- ✓ GitHub auth header verification

### AnthropicService (16 tests with mocked HTTP)
- ✓ Configuration check
- ✓ Sending messages
- ✓ Correct header sending
- ✓ System prompt inclusion
- ✓ Default system prompt usage
- ✓ Context message handling
- ✓ Tool sending
- ✓ API error handling
- ✓ Message with tools (full flow)
- ✓ Stopping when no tool calls
- ✓ Max iterations respect
- ✓ Custom model usage
- ✓ Message building
- ✓ Tool executor error handling

### CalendarService (3 tests with mocked HTTP)
- ✓ Listing calendars
- ✓ Listing events
- ✓ Auth token verification

### HarvestApiService (3 tests with mocked HTTP)
- ✓ Listing projects
- ✓ Listing time entries
- ✓ Auth header verification

## Test Patterns

### Mocked HTTP Requests
Integration services use `Http::fake()` to mock external API calls:

```php
Http::fake([
    'api.example.com/*' => Http::response(['data' => 'test'], 200),
]);

$result = $service->apiCall();

Http::assertSent(function ($request) {
    return $request->hasHeader('Authorization', 'Bearer token');
});
```

### Database Testing
Services that interact with models use `RefreshDatabase`:

```php
use RefreshDatabase;

$approval = ApprovalRequest::factory()->create();
$result = $service->approve($approval, $user);

$this->assertDatabaseHas('approval_requests', [
    'status' => 'approved',
]);
```

### Event Testing
Services that dispatch events use `Event::fake()`:

```php
Event::fake();

$service->createRequest(...);

Event::assertDispatched(NotificationCreated::class);
```

### Exception Testing
Error conditions are tested with exception expectations:

```php
$this->expectException(\Exception::class);
$this->expectExceptionMessage('2FA verification required');

$service->approve($criticalApproval, $userWithout2FA);
```

## Test Data Factories

All tests use model factories for consistent test data:

```php
$user = User::factory()->create(['role' => 'admin']);
$approval = ApprovalRequest::factory()->pending()->create();
$secret = VaultSecret::factory()->create(['is_active' => true]);
```

## Best Practices

1. **Isolation**: Each test is independent and doesn't rely on others
2. **Clarity**: Test names clearly describe what is being tested
3. **Mocking**: External dependencies are mocked, not called
4. **Assertions**: Multiple assertions verify expected behavior
5. **Coverage**: Both success and failure paths are tested
6. **Edge Cases**: Boundary conditions and edge cases are covered

## Adding New Tests

When adding a new service:

1. Create test file in appropriate subdirectory
2. Extend `TestCase` and use `RefreshDatabase`
3. Mock external dependencies (HTTP, Events, etc.)
4. Test all public methods
5. Cover success, failure, and edge cases
6. Update this README with test count

## Continuous Integration

Tests are run automatically on:
- Every commit (via pre-commit hook)
- Every pull request
- Before deployment

## Coverage Goals

- **Minimum**: 80% line coverage
- **Target**: 90% line coverage
- **Critical Services**: 95%+ coverage (Approval, Vault, AgentSandbox)
