---
title: "MCP Route Authorization and Service Extraction Patterns"
category: architecture-patterns
tags:
  - mcp
  - middleware
  - authorization
  - single-responsibility-principle
  - service-extraction
  - slack-integration
  - seo-tools
  - code-review
module: mcp-tools, slack-integration
symptom: "Code review identified security gap (client portal users could access MCP tools), coupled logic (intent detection embedded in orchestrator), and missing MCP exposure for SEO services"
root_cause: "Architectural oversights during rapid feature development: MCP routes lacked role-based access control, SlackMentionOrchestrator grew to handle multiple concerns, useful SEO services weren't exposed via MCP interface"
created: 2026-01-19
---

# MCP Route Authorization and Service Extraction Patterns

## Overview

This documents patterns established while fixing 13 code review issues on the `feature/interactive-agent-sessions` branch. The fixes address:

1. **P1-002**: MCP tools lacking authorization
2. **P1-004**: Missing MCP tool tests
3. **P2-010**: Intent detection coupled in orchestrator
4. **P2-011**: SEO services not exposed via MCP

## Problem 1: MCP Routes Without Authorization

### Symptom

Client portal users (role: `client`) could access internal MCP tools via the web endpoint at `/mcp/zao-dash`.

### Root Cause

The MCP web route only had `auth:sanctum` middleware, which verifies the user is logged in but doesn't check their role.

### Solution: EnsureInternalUser Middleware

```php
// app/Http/Middleware/EnsureInternalUser.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isInternalUser()) {
            abort(403, 'Access denied. This resource is restricted to internal team members.');
        }

        return $next($request);
    }
}
```

Applied to routes:

```php
// routes/ai.php
use App\Http\Middleware\EnsureInternalUser;

Mcp::web('/mcp/zao-dash', ZaoDashServer::class)
    ->middleware(['auth:sanctum', EnsureInternalUser::class]);

// Local MCP server remains unrestricted (used by internal agents)
Mcp::local('zao-dash', ZaoDashServer::class);
```

### Why Middleware Over Per-Tool Checks

- **Separation of concerns**: Authentication (`auth:sanctum`) vs authorization (`EnsureInternalUser`)
- **Single enforcement point**: All web MCP routes protected by default
- **Testable**: Middleware can be unit tested in isolation
- **Reusable**: Same middleware can protect other internal routes

## Problem 2: Missing MCP Tool Tests

### Symptom

Critical tools like `CreateInvoiceTool` and `CreateClientNoteTool` had no test coverage.

### Solution: MCP Tool Test Pattern

```php
// tests/Feature/Mcp/CreateInvoiceToolTest.php
use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\CreateInvoiceTool;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create();
});

test('creates invoice with line items', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'subject' => 'Development Services',
        'items' => [
            ['description' => 'Feature Development', 'quantity' => 10, 'unit_price' => 150],
        ],
    ]);

    $response->assertOk();

    $invoice = Invoice::where('client_id', $this->client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->lines)->toHaveCount(1);
});

test('validates required client_id', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'subject' => 'Test Invoice',
        'items' => [['description' => 'Test', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $response->assertHasErrors();
});
```

### MCP Tool Test Checklist

Every MCP tool should have tests for:

1. **Happy path**: Valid input produces expected output
2. **Validation errors**: Missing/invalid required fields
3. **Authorization** (web routes): Unauthorized users get 403
4. **Edge cases**: Null handling, empty arrays, transactions

## Problem 3: Coupled Intent Detection

### Symptom

`SlackMentionOrchestrator` contained regex patterns and intent parsing logic mixed with orchestration flow.

### Root Cause

Rapid development led to adding intent detection inline rather than extracting to a service.

### Solution: Extract SlackIntentDetectionService

```php
// app/Services/Slack/SlackIntentDetectionService.php
namespace App\Services\Slack;

use App\Enums\SlackActionType;

class SlackIntentDetectionService
{
    public function detectIntent(string $message): ?array
    {
        $normalizedMessage = strtolower($message);

        // Task creation pattern
        if (preg_match('/create\s+(a\s+)?task\s*(to|for|:)?\s*(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::CreateTask->value,
                'title' => trim($matches[3]),
                'priority' => $this->extractPriority($normalizedMessage),
            ];
        }

        // Note logging pattern
        if (preg_match('/log\s+(a\s+)?(note|that)\s*:?\s*(.+)/i', $message, $matches)) {
            return [
                'type' => SlackActionType::LogNote->value,
                'content' => trim($matches[3]),
            ];
        }

        return null;
    }

    public function extractPriority(string $message): string
    {
        if (preg_match('/\b(urgent|critical|asap)\b/i', $message)) {
            return 'urgent';
        }
        if (preg_match('/\bhigh\s*(priority)?\b/i', $message)) {
            return 'high';
        }
        return 'medium';
    }

    public function requiresConfirmation(array $action): bool
    {
        return in_array($action['type'] ?? null, [
            SlackActionType::TriggerAgent->value,
            SlackActionType::CompoundEngineering->value,
        ], true);
    }
}
```

### Service Extraction Signals

Extract to a service when you see:

- **300+ lines** in a single class
- **10+ public methods** with different concerns
- **Multiple regex patterns** for parsing
- **"And" in the class name** (e.g., "OrchestrationAndDetection")
- **Hard to test** without full integration setup

## Problem 4: Services Not Exposed via MCP

### Symptom

SEO services (validation, schema generation, attribution) were only accessible internally.

### Solution: MCP Tool Pattern for Service Exposure

```php
// app/Mcp/Tools/ValidateSeoContentTool.php
namespace App\Mcp\Tools;

use App\Services\Seo\ContentValidatorService;
use Laravel\Mcp\Server\Tool;

class ValidateSeoContentTool extends Tool
{
    protected string $name = 'validate-seo-content';
    protected string $description = 'Validate SEO content against playbook rules.';

    public function __construct(
        private ContentValidatorService $validator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page_id' => 'nullable|exists:seo_pages,id',
            'content' => 'nullable|string',
            'playbook' => 'nullable|string',
        ]);

        // Delegate to service
        $result = $this->validator->validate($content, $playbook);

        return Response::structured([
            'valid' => $result['valid'],
            'score' => $result['score'],
            'errors' => $result['errors'],
            'message' => $result['valid']
                ? "Passed with score {$result['score']}/100"
                : 'Failed with '.count($result['errors']).' errors',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->description('SEO page ID'),
            'content' => $schema->string()->description('HTML content'),
            'playbook' => $schema->string()->enum([...])->description('Playbook type'),
        ];
    }
}
```

### When to Expose Services via MCP

Ask these questions:

| Question | Weight |
|----------|--------|
| Would an LLM find this useful for common tasks? | High |
| Is the data appropriate for API exposure? | High |
| Does the service return structured, serializable data? | Medium |
| Are the operations safe to retry? | Medium |

## Prevention Strategies

### Code Review Checklist

**For MCP Tool PRs:**
- [ ] Middleware includes `auth:sanctum` and `EnsureInternalUser` for web routes
- [ ] Tests exist in `tests/Feature/Mcp/`
- [ ] Tool registered in `ZaoDashServer::$tools`
- [ ] Server instructions updated

**For Service Class PRs:**
- [ ] Service under 300 lines
- [ ] Fewer than 10 public methods
- [ ] Single responsibility (no "and" in name)
- [ ] Consider MCP exposure for useful services

### Automated Checks

```php
// tests/Feature/Mcp/McpAuthorizationTest.php
test('all web mcp routes require internal user middleware', function () {
    // Verify EnsureInternalUser is applied to web MCP routes
});

test('all mcp tools have test coverage', function () {
    $tools = ZaoDashServer::tools();
    foreach ($tools as $tool) {
        $testFile = "tests/Feature/Mcp/{$tool->name}Test.php";
        expect(file_exists($testFile))->toBeTrue();
    }
});
```

## Related Documentation

- [MCP Server Documentation](../../MCP_SERVER.md)
- [Services Architecture](../../SERVICES.md)
- [API Authorization](../../API.md#authorization)
- [Compound Engineering](../../COMPOUND_ENGINEERING.md)

## Files Modified

**New Files:**
- `app/Http/Middleware/EnsureInternalUser.php`
- `app/Services/Slack/SlackIntentDetectionService.php`
- `app/Mcp/Tools/ValidateSeoContentTool.php`
- `app/Mcp/Tools/GenerateSeoSchemaTool.php`
- `app/Mcp/Tools/GetSeoAttributionTool.php`
- `app/Mcp/Tools/DeleteSeoPageTool.php`
- `tests/Feature/Mcp/CreateInvoiceToolTest.php`
- `tests/Feature/Mcp/CreateClientNoteToolTest.php`
- `tests/Feature/Middleware/EnsureInternalUserTest.php`
- `tests/Feature/Services/Slack/SlackIntentDetectionServiceTest.php`

**Modified Files:**
- `routes/ai.php` - Added middleware
- `app/Mcp/Servers/ZaoDashServer.php` - Registered SEO tools
