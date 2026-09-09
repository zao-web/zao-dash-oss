# Prevention Strategies

This document outlines prevention strategies developed from issues discovered during the Slack MCP integration and SEO services exposure work. These strategies help prevent similar issues from recurring.

## Issues Fixed (Reference)

| Issue | Root Cause | Fix Applied |
|-------|------------|-------------|
| MCP tools missing authorization | No middleware on web routes | Added `['auth:sanctum', EnsureInternalUser::class]` middleware |
| Missing test coverage | Tests not written alongside features | Created 44+ tests for MCP and Slack services |
| Coupled intent detection | Logic embedded in response service | Extracted `SlackIntentDetectionService` |
| SEO services not exposed | Services existed but no MCP interface | Created 4 new MCP tools |

---

## 1. Code Review Checklist

### MCP Tool Reviews

When reviewing PRs that add or modify MCP tools:

- [ ] **Authorization Check**: Verify the tool is registered in a server that has appropriate middleware in `routes/ai.php`
- [ ] **Test Coverage**: Confirm tests exist in `tests/Feature/Mcp/` that cover:
  - [ ] Happy path execution
  - [ ] Authorization denial for non-internal users (if applicable)
  - [ ] Validation error handling
  - [ ] Edge cases (empty data, invalid IDs, etc.)
- [ ] **Server Registration**: Verify the tool is added to `$tools` array in `ZaoDashServer.php`
- [ ] **Instructions Updated**: Confirm server `$instructions` property includes the new tool
- [ ] **Documentation**: Check that `docs/MCP_SERVER.md` is updated with the new tool

### Service Class Reviews

When reviewing PRs that add or modify service classes:

- [ ] **Single Responsibility**: Does the service do one thing well, or is it growing into a "god class"?
  - Services over 300 lines should be reviewed for extraction opportunities
  - Services with more than 10 public methods may need splitting
- [ ] **MCP Exposure Review**: Should this service be accessible via MCP?
  - Consider: Would LLMs benefit from accessing this functionality?
  - If yes, create corresponding MCP tool or add to backlog
- [ ] **Test Coverage**: Feature/Unit tests exist for all public methods
- [ ] **Dependency Injection**: Uses constructor injection, not facades or static calls

### Slack Integration Reviews

When reviewing Slack-related changes:

- [ ] **Intent Detection**: New patterns added to `SlackIntentDetectionService`, not embedded in response handlers
- [ ] **Action Types**: New action types added to `SlackActionType` enum
- [ ] **Confirmation Requirements**: Actions requiring confirmation are properly flagged in `requiresConfirmation()`
- [ ] **Test Coverage**: Tests exist in `tests/Feature/Services/Slack/`

---

## 2. Architecture Guidelines

### MCP Tool Architecture

```
When to create an MCP tool:
1. A service exists that LLMs could benefit from
2. The functionality is CRUD-like or query-based
3. The service returns structured data

When NOT to create an MCP tool:
1. The service requires real-time interaction
2. The functionality is purely internal/infrastructure
3. The data is highly sensitive (even for internal users)
```

#### Standard MCP Tool Structure

```php
// app/Mcp/Tools/ExampleTool.php
class ExampleTool extends Tool
{
    protected string $name = 'example-action';      // kebab-case
    protected string $title = 'Example Action';     // Human readable
    protected string $description = '...';          // Clear description for LLMs

    public function __construct(
        private ExampleService $service,            // Inject service
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([...]);     // Always validate

        // Delegate to service (keep tool thin)
        $result = $this->service->doThing($validated);

        return Response::structured([...]);         // Return structured data
    }

    public function schema(JsonSchema $schema): array
    {
        return [...];                               // Define input schema
    }
}
```

### Service Class Guidelines

```
Signs a service needs refactoring:
- More than 300 lines of code
- More than 10 public methods
- Multiple unrelated responsibilities
- "And" in the class name (e.g., "ParsingAndValidationService")
```

#### Extraction Pattern

When a service grows too large, extract focused services:

```php
// Before: SlackBotResponseService (500+ lines)
// - Intent detection logic
// - Response formatting
// - Action execution
// - Analytics tracking

// After: Extracted services
SlackIntentDetectionService::class    // Just pattern matching
SlackBotResponseService::class        // Response orchestration
SlackAnalyticsService::class          // Analytics tracking
```

### Authorization Architecture

All web-accessible MCP endpoints must include authorization:

```php
// routes/ai.php
Mcp::web('/mcp/zao-dash', ZaoDashServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);
```

For local/CLI access, authorization may be omitted:

```php
Mcp::local('zao-dash', ZaoDashServer::class);
```

---

## 3. Test Requirements

### MCP Tool Test Template

Every MCP tool should have at minimum:

```php
// tests/Feature/Mcp/{ToolName}Test.php
use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\{ToolName}Tool;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

// 1. Happy path test
test('it performs the action successfully', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool({ToolName}Tool::class, [
        // valid parameters
    ]);

    $response->assertOk();
});

// 2. Validation test
test('it validates required parameters', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool({ToolName}Tool::class, []);

    $response->assertError();
});

// 3. Authorization test (if applicable)
test('it denies access to non-internal users', function () {
    $clientUser = User::factory()->clientPortal()->create();

    // This would fail at the middleware level for web routes
    $this->actingAs($clientUser)
        ->postJson('/mcp/zao-dash', [...])
        ->assertForbidden();
});
```

### Service Test Requirements

Services should have tests covering:

1. **Unit tests** for pure logic methods
2. **Feature tests** for methods with side effects
3. **Mock external dependencies** (APIs, file systems)

```php
// tests/Feature/Services/Example/ExampleServiceTest.php
test('it processes data correctly', function () {
    $service = app(ExampleService::class);

    $result = $service->process($input);

    expect($result)->toBeArray()
        ->and($result['status'])->toBe('success');
});
```

---

## 4. Automated Checks

### Potential PHPStan Rules

Add to `phpstan.neon` to catch common issues:

```neon
# Ensure MCP tools extend the correct base class
rules:
    - App\Rules\McpToolsMustExtendTool

# Warn about large service classes
parameters:
    maxClassLines: 300
```

### Custom Pint Rules

```php
// Consider adding to pint.json for naming conventions
{
    "rules": {
        // MCP tools should end with "Tool"
        // Services should end with "Service"
    }
}
```

### Proposed Automated Tests

#### 1. MCP Authorization Coverage Test

```php
// tests/Feature/Mcp/McpAuthorizationTest.php
test('all web MCP routes have authorization middleware', function () {
    $routes = Route::getRoutes()->getRoutesByName();

    $mcpRoutes = collect($routes)->filter(fn ($route) =>
        str_contains($route->uri(), '/mcp/')
    );

    foreach ($mcpRoutes as $route) {
        expect($route->middleware())->toContain('auth:sanctum')
            ->and($route->middleware())->toContain(EnsureInternalUser::class);
    }
});
```

#### 2. MCP Tool Registration Completeness Test

```php
// tests/Feature/Mcp/McpToolRegistrationTest.php
test('all tool classes are registered in a server', function () {
    $toolFiles = glob(app_path('Mcp/Tools/*.php'));
    $toolClasses = array_map(fn ($file) =>
        'App\\Mcp\\Tools\\' . basename($file, '.php'),
        $toolFiles
    );

    $server = new ZaoDashServer();
    $reflection = new ReflectionClass($server);
    $registeredTools = $reflection->getProperty('tools')->getValue($server);

    foreach ($toolClasses as $toolClass) {
        expect($registeredTools)->toContain($toolClass);
    }
});
```

#### 3. Service Size Linting Test

```php
// tests/Unit/Architecture/ServiceSizeTest.php
test('services do not exceed 300 lines', function () {
    $serviceFiles = glob(app_path('Services/**/*.php'));

    foreach ($serviceFiles as $file) {
        $lines = count(file($file));
        expect($lines)->toBeLessThan(300,
            basename($file) . " has {$lines} lines and may need refactoring"
        );
    }
})->skip('Enable when ready to enforce');
```

#### 4. MCP Tool Test Coverage Check

```php
// tests/Feature/Mcp/McpTestCoverageTest.php
test('all MCP tools have corresponding test files', function () {
    $toolFiles = glob(app_path('Mcp/Tools/*.php'));

    foreach ($toolFiles as $toolFile) {
        $toolName = basename($toolFile, '.php');
        $testPath = base_path("tests/Feature/Mcp/{$toolName}Test.php");

        // This is an informational test - doesn't fail but reports gaps
        if (!file_exists($testPath)) {
            $this->markTestIncomplete("Missing test for {$toolName}");
        }
    }
});
```

---

## 5. CI/CD Integration

### GitHub Actions Workflow Addition

```yaml
# .github/workflows/quality-checks.yml
- name: Check MCP Authorization
  run: php artisan test --filter=McpAuthorizationTest

- name: Check MCP Tool Registration
  run: php artisan test --filter=McpToolRegistrationTest

- name: Service Architecture Lint
  run: php artisan test --filter=ServiceSizeTest
```

### Pre-commit Hook Suggestion

```bash
#!/bin/sh
# .git/hooks/pre-commit

# Check if new MCP tool added without test
NEW_TOOLS=$(git diff --cached --name-only | grep 'app/Mcp/Tools/.*\.php$')
for tool in $NEW_TOOLS; do
    tool_name=$(basename "$tool" .php)
    test_file="tests/Feature/Mcp/${tool_name}Test.php"
    if [ ! -f "$test_file" ]; then
        echo "WARNING: New MCP tool $tool_name added without test file"
        echo "Consider creating: $test_file"
    fi
done
```

---

## 6. Service Exposure Decision Matrix

Use this matrix to decide if a service should have MCP exposure:

| Criteria | Weight | Questions to Ask |
|----------|--------|------------------|
| LLM Utility | High | Would an LLM find this useful for common tasks? |
| Data Sensitivity | High | Is the data appropriate for API exposure? |
| Structured Output | Medium | Does the service return structured, serializable data? |
| Idempotency | Medium | Are the operations safe to retry? |
| Existing Tests | Low | Does the service have good test coverage already? |

**Scoring:**
- High weight = Must be "Yes" or appropriate
- Medium weight = Should be "Yes" for ideal exposure
- Low weight = Nice to have

**Services currently exposed via MCP:**
- Client/Project/Task/Lead management
- Agent triggering and monitoring
- Invoice management
- Website builder operations
- SEO content operations

**Services that may warrant future MCP exposure:**
- `BusinessIntelligenceService` - analytics queries
- `TimeEstimationService` - project estimation
- `ProactiveInsightsService` - trend analysis

---

## 7. Quick Reference

### Adding a New MCP Tool Checklist

1. [ ] Create tool class: `php artisan make:mcp-tool MyTool`
2. [ ] Implement `handle()` with validation
3. [ ] Implement `schema()` for input definition
4. [ ] Register in `ZaoDashServer::$tools`
5. [ ] Update `ZaoDashServer::$instructions`
6. [ ] Create test file: `tests/Feature/Mcp/MyToolTest.php`
7. [ ] Update `docs/MCP_SERVER.md`
8. [ ] Run tests: `php artisan test --filter=MyToolTest`

### Extracting a Service Checklist

1. [ ] Identify the responsibility to extract
2. [ ] Create new service class with focused responsibility
3. [ ] Move relevant methods and properties
4. [ ] Update original service to delegate to new service
5. [ ] Inject new service via constructor
6. [ ] Create/update tests for both services
7. [ ] Update any MCP tools that use the original service
