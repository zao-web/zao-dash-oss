# Agent Tools Test Suite

Comprehensive Pest test suite for all agent tools in `/app/Agents/Tools`.

## Overview

This directory contains unit tests for every agent tool, covering:
- getName() and getDescription() methods
- getParameters() schema validation
- execute() method with mocked dependencies
- Parameter validation (required fields, type validation)
- Error handling

## Test Structure

Each tool test file follows this pattern:

### Basic Tests (All Tools)
- ✅ `getName returns correct name`
- ✅ `getDescription returns correct description`
- ✅ `getParameters returns valid schema`
- ✅ `id returns correct tool ID`
- ✅ `requiresApproval returns boolean`
- ✅ `riskLevel returns valid level`
- ✅ `toArray returns complete metadata`
- ✅ `toAnthropicTool returns Anthropic format`

### Execute Method Tests (Comprehensive Tools)
- ✅ Test execute with valid parameters
- ✅ Test execute with minimal parameters
- ✅ Test execute with all parameters
- ✅ Test filtering and searching functionality
- ✅ Test limit/pagination parameters
- ✅ Test default values

### Validation Tests
- ✅ Test required parameter validation
- ✅ Test invalid value rejection
- ✅ Test valid parameter acceptance
- ✅ Test edge cases (negative values, out of range, etc.)

### Error Handling Tests
- ✅ Test API failures (for external service tools)
- ✅ Test missing dependencies
- ✅ Test exception handling

## Running Tests

### Run all tool tests:
```bash
php artisan test --filter=Tools
```

### Run specific tool test:
```bash
php artisan test tests/Unit/Agents/Tools/SearchTasksToolTest.php
```

### Run with coverage:
```bash
php artisan test --coverage --min=80
```

## Test Coverage

### Comprehensive Tests (Full Coverage)
These tools have complete test coverage including mocked dependencies:

- ✅ **BaseToolTest** - Base class functionality
- ✅ **SearchTasksToolTest** - Task search with filters
- ✅ **CreateTaskToolTest** - Task creation with approval
- ✅ **UpdateTaskToolTest** - Task updates with validation
- ✅ **SearchProjectsToolTest** - Project search and filters
- ✅ **SearchClientsToolTest** - Client search
- ✅ **CreateProjectToolTest** - Project creation
- ✅ **CreateClientToolTest** - Client creation
- ✅ **GetFocusToolTest** - Focus recommendations (mocked service)
- ✅ **GetStatsToolTest** - System statistics
- ✅ **GetApprovalsToolTest** - Approval requests
- ✅ **SearchLeadsToolTest** - Lead pipeline search
- ✅ **SearchContentToolTest** - Content search
- ✅ **WebSearchToolTest** - Web search with mocked HTTP
- ✅ **NavigateToolTest** - Navigation suggestions
- ✅ **TriggerAgentToolTest** - Agent execution (mocked executor)
- ✅ **QboGetExpensesToolTest** - QuickBooks expenses (mocked service)

### Basic Tests (Template Coverage)
These tools have basic schema/metadata tests with TODOs for execute tests:

- ⚠️ **AnalyzeGoalProgressToolTest**
- ⚠️ **AssignAgentTaskToolTest**
- ⚠️ **CreateOutreachCampaignToolTest**
- ⚠️ **CreateProspectToolTest**
- ⚠️ **CreateWeeklyPlanToolTest**
- ⚠️ **DraftOutreachMessageToolTest**
- ⚠️ **ForecastRevenueToolTest**
- ⚠️ **GetFunnelMetricsToolTest**
- ⚠️ **GetQuarterlyPatternsToolTest**
- ⚠️ **GetXTrendsToolTest**
- ⚠️ **MatchIcpToolTest**
- ⚠️ **PostToLinkedInToolTest**
- ⚠️ **PostToXToolTest**
- ⚠️ **QboCategorizeExpenseToolTest**
- ⚠️ **QboGetCategoriesToolTest**
- ⚠️ **QboSuggestCategoryToolTest**
- ⚠️ **ScheduleFollowUpToolTest**
- ⚠️ **SearchProspectsToolTest**
- ⚠️ **SeoAnalyzeSerpToolTest**
- ⚠️ **SeoCompetitorGapsToolTest**
- ⚠️ **SeoGenerateBlogToolTest**
- ⚠️ **SeoGenerateLandingToolTest**
- ⚠️ **SeoGetConversionsToolTest**
- ⚠️ **SeoGetPseoPerformanceToolTest**
- ⚠️ **SeoGetRankingsToolTest**
- ⚠️ **SeoKeywordResearchToolTest**
- ⚠️ **SeoOptimizeContentToolTest**
- ⚠️ **SeoSearchVolumeToolTest**
- ⚠️ **SeoTrackPageToolTest**

## Enhancing Tests

To enhance basic tests to comprehensive coverage:

1. **Add mocked dependencies** for services/APIs
2. **Add execute tests** with various parameter combinations
3. **Add edge case tests** for error conditions
4. **Add integration tests** where appropriate

Example enhancement:
```php
// Basic (template) test
test('execute method exists', function () {
    $tool = new SomeToolTool();
    expect(method_exists($tool, 'execute'))->toBeTrue();
});

// Enhanced (comprehensive) test
test('execute fetches data successfully', function () {
    $mockService = Mockery::mock(SomeService::class);
    $mockService->shouldReceive('getData')
        ->once()
        ->andReturn(['data' => 'value']);

    $tool = new SomeToolTool($mockService);
    $result = $tool->execute(['param' => 'value']);

    expect($result)->toHaveKey('data')
        ->and($result['data'])->toBe('value');
});
```

## Pest Configuration

Tests use Pest PHP with:
- **RefreshDatabase** - Fresh database for each test
- **Mockery** - For mocking dependencies
- **Laravel Factories** - For creating test data
- **Expectations API** - For fluent assertions

## Continuous Integration

These tests are designed to run in CI/CD pipelines:
- All tests should be fast (< 1s each)
- Database is reset between tests
- No external API calls (all mocked)
- No test interdependencies

## Contributing

When adding a new tool:
1. Create test file: `tests/Unit/Agents/Tools/[ToolName]Test.php`
2. Follow the comprehensive test pattern (see SearchTasksToolTest)
3. Mock all external dependencies
4. Test all public methods
5. Ensure 100% code coverage for the tool

## Notes

- Tests use **Pest PHP syntax** (not PHPUnit)
- All tools extend **BaseTool** (tested separately)
- **Mocking is essential** for tools with dependencies
- Tests should be **deterministic** (no random data, fixed timestamps)
