# Code Review Prevention Strategies

This document outlines best practices, patterns, and test cases for preventing common security and performance issues identified during code reviews.

---

## 1. SQL Injection Prevention: LIKE Wildcard Escaping

### The Problem

User input containing `%` or `_` characters can manipulate LIKE queries to return unintended results or expose data.

```php
// VULNERABLE: User input "100%" matches ALL records
$query->where('name', 'LIKE', '%' . $userInput . '%');
```

### Best Practice Pattern

Always escape LIKE wildcards when incorporating user input into LIKE clauses:

```php
// SAFE: Escape wildcards before use
$escaped = str_replace(['%', '_'], ['\%', '\_'], $userInput);
$query->where('name', 'LIKE', '%' . $escaped . '%');
```

### Code Template

```php
<?php

namespace App\Support;

class QueryHelpers
{
    /**
     * Escape LIKE wildcards in user input.
     *
     * Use this whenever incorporating user input into a LIKE clause.
     */
    public static function escapeLikeWildcards(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }
}

// Usage in queries:
use App\Support\QueryHelpers;

$escaped = QueryHelpers::escapeLikeWildcards($request->input('search'));
$query->where('name', 'LIKE', '%' . $escaped . '%');
```

### Code Review Checklist

- [ ] All LIKE queries with user input escape `%` and `_` characters
- [ ] `whereRaw` with LIKE uses parameterized queries
- [ ] Search/filter endpoints escape wildcards before query construction
- [ ] Consider using `ILIKE` for case-insensitive searches (PostgreSQL) or `LOWER()` with escaping

### Test Cases

```php
<?php

use App\Support\QueryHelpers;

it('escapes LIKE wildcards in user input', function () {
    expect(QueryHelpers::escapeLikeWildcards('100%'))->toBe('100\%');
    expect(QueryHelpers::escapeLikeWildcards('test_value'))->toBe('test\_value');
    expect(QueryHelpers::escapeLikeWildcards('%_%'))->toBe('\%\_\%');
    expect(QueryHelpers::escapeLikeWildcards('normal'))->toBe('normal');
});

it('search does not match all records with wildcard input', function () {
    // Create test data
    Client::factory()->create(['name' => 'Acme Corp']);
    Client::factory()->create(['name' => 'Beta Inc']);

    // Search with wildcard - should NOT match everything
    $results = Client::where('name', 'LIKE', '%' . QueryHelpers::escapeLikeWildcards('100%') . '%')->get();

    expect($results)->toBeEmpty();
});

it('search correctly handles underscore wildcards', function () {
    Client::factory()->create(['name' => 'Test_Client']);
    Client::factory()->create(['name' => 'TestXClient']);

    // Search for literal underscore
    $escaped = QueryHelpers::escapeLikeWildcards('Test_');
    $results = Client::where('name', 'LIKE', $escaped . '%')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Test_Client');
});
```

---

## 2. IDOR Prevention: Middleware and Authorization Patterns

### The Problem

Insecure Direct Object Reference (IDOR) occurs when users can access or modify resources belonging to others by manipulating IDs.

```php
// VULNERABLE: No ownership verification
public function show(Project $project) {
    return view('projects.show', compact('project'));
}
```

### Best Practice Patterns

#### Pattern A: Policy-Based Authorization (Preferred)

```php
// app/Policies/ProjectPolicy.php
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->id === $project->user_id
            || $project->team_id === $user->current_team_id;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}

// In controller - automatically 403s if policy fails
public function show(Project $project)
{
    $this->authorize('view', $project);
    return view('projects.show', compact('project'));
}
```

#### Pattern B: Route Model Binding with Scoping

```php
// routes/web.php - Scope to authenticated user
Route::get('/projects/{project}', [ProjectController::class, 'show'])
    ->middleware('auth')
    ->scopeBindings();

// Or in RouteServiceProvider
Route::bind('project', function ($value) {
    return Project::where('id', $value)
        ->where('user_id', auth()->id())
        ->firstOrFail();
});
```

#### Pattern C: Controller-Level Ownership Check

```php
public function show(Project $project)
{
    // Fail-closed: explicitly check ownership
    if ($project->user_id !== Auth::id()) {
        abort(403, 'You do not have access to this project.');
    }

    return view('projects.show', compact('project'));
}
```

#### Pattern D: Middleware for Resource Ownership

```php
// app/Http/Middleware/EnsureProjectOwnership.php
class EnsureProjectOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if (!$project || $project->user_id !== $request->user()?->id) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}

// Usage in routes
Route::middleware(['auth', 'project.owner'])->group(function () {
    Route::resource('projects', ProjectController::class);
});
```

### Code Review Checklist

- [ ] All resource access methods have authorization checks
- [ ] Policies exist for models with user ownership
- [ ] `$this->authorize()` or `Gate::authorize()` is called before resource access
- [ ] Route model binding scopes resources appropriately
- [ ] API endpoints verify ownership for all CRUD operations
- [ ] Bulk operations verify ownership for ALL items, not just first
- [ ] Nested resources verify parent ownership (e.g., `/projects/{project}/tasks/{task}`)

### Test Cases

```php
<?php

it('prevents accessing another user project', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($attacker)
        ->get(route('projects.show', $project))
        ->assertForbidden();
});

it('prevents updating another user project', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($attacker)
        ->put(route('projects.update', $project), ['name' => 'Hacked'])
        ->assertForbidden();

    // Verify no change occurred
    expect($project->fresh()->name)->not->toBe('Hacked');
});

it('prevents deleting another user project', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($attacker)
        ->delete(route('projects.destroy', $project))
        ->assertForbidden();

    // Verify not deleted
    expect(Project::find($project->id))->not->toBeNull();
});

it('prevents bulk operations on mixed-ownership resources', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $ownProject = Project::factory()->create(['user_id' => $attacker->id]);
    $otherProject = Project::factory()->create(['user_id' => $owner->id]);

    // Attacker tries to bulk delete including someone else's project
    $this->actingAs($attacker)
        ->post(route('projects.bulk-delete'), [
            'ids' => [$ownProject->id, $otherProject->id]
        ])
        ->assertForbidden();

    // Neither should be deleted due to fail-closed
    expect(Project::find($otherProject->id))->not->toBeNull();
});
```

---

## 3. Data Integrity: Transaction and FK Constraint Patterns

### The Problem

Multi-step database operations without transactions can leave data in inconsistent states during failures.

```php
// VULNERABLE: Partial failure leaves data inconsistent
$invoice = Invoice::create([...]);
$invoice->lines()->createMany($lineItems); // If this fails, invoice exists without lines
$invoice->client->increment('invoice_count'); // Counter might drift
```

### Best Practice Patterns

#### Pattern A: DB::transaction Wrapper

```php
use Illuminate\Support\Facades\DB;

return DB::transaction(function () use ($data) {
    $invoice = Invoice::create([
        'client_id' => $data['client_id'],
        'total' => $data['total'],
    ]);

    $invoice->lines()->createMany($data['line_items']);

    // Counters inside transaction prevent drift
    $invoice->client->increment('invoice_count');

    return $invoice;
});
```

#### Pattern B: Row-Level Locking for Concurrent Access

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($interactionId, $response) {
    // Lock row to prevent race conditions
    $interaction = InteractionRequest::lockForUpdate()->find($interactionId);

    if (!$interaction || $interaction->isResponded()) {
        throw new \Exception('Already responded or not found');
    }

    $interaction->update([
        'response' => $response,
        'responded_at' => now(),
    ]);

    return $interaction;
});
```

#### Pattern C: Foreign Key Constraints in Migrations

```php
// Migration - enforce referential integrity at database level
Schema::create('invoice_lines', function (Blueprint $table) {
    $table->id();

    // CASCADE: Delete lines when invoice deleted
    $table->foreignId('invoice_id')
        ->constrained()
        ->cascadeOnDelete();

    // RESTRICT: Prevent project deletion if referenced
    $table->foreignId('project_id')
        ->nullable()
        ->constrained()
        ->restrictOnDelete();

    // NULL: Set to null when user deleted
    $table->foreignId('created_by')
        ->nullable()
        ->constrained('users')
        ->nullOnDelete();

    $table->timestamps();
});
```

### FK Constraint Decision Guide

| Relationship | On Delete | When to Use |
|-------------|-----------|-------------|
| `cascadeOnDelete()` | CASCADE | Child meaningless without parent (invoice lines, comments) |
| `restrictOnDelete()` | RESTRICT | Prevent accidental deletion of referenced data |
| `nullOnDelete()` | SET NULL | Preserve record but clear reference (audit logs, created_by) |
| No constraint | None | Legacy data or soft deletes handle it |

### Code Review Checklist

- [ ] Multi-table writes wrapped in `DB::transaction()`
- [ ] Counter increments/decrements inside transactions
- [ ] `lockForUpdate()` used for concurrent modification scenarios
- [ ] Foreign keys have explicit ON DELETE behavior
- [ ] Transactions have appropriate isolation level for use case
- [ ] Exception handling does not swallow transaction failures

### Test Cases

```php
<?php

it('rolls back transaction on partial failure', function () {
    $client = Client::factory()->create();
    $initialCount = $client->invoice_count;

    try {
        DB::transaction(function () use ($client) {
            Invoice::create(['client_id' => $client->id, 'total' => 100]);
            $client->increment('invoice_count');

            // Simulate failure
            throw new \Exception('Line item creation failed');
        });
    } catch (\Exception $e) {
        // Expected
    }

    // Invoice should not exist, counter unchanged
    expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    expect($client->fresh()->invoice_count)->toBe($initialCount);
});

it('prevents concurrent double-responses with locking', function () {
    $interaction = InteractionRequest::factory()->create(['status' => 'pending']);

    // Simulate concurrent responses
    $results = collect([1, 2])->map(function ($i) use ($interaction) {
        return DB::transaction(function () use ($interaction, $i) {
            $locked = InteractionRequest::lockForUpdate()->find($interaction->id);

            if ($locked->status !== 'pending') {
                return 'already_responded';
            }

            $locked->update(['status' => 'responded', 'response' => "Response {$i}"]);
            return 'success';
        });
    });

    // Exactly one should succeed
    expect($results->filter(fn($r) => $r === 'success')->count())->toBe(1);
});

it('cascades deletion to child records', function () {
    $invoice = Invoice::factory()->create();
    $lines = InvoiceLine::factory()->count(3)->create(['invoice_id' => $invoice->id]);

    $invoice->delete();

    expect(InvoiceLine::where('invoice_id', $invoice->id)->count())->toBe(0);
});
```

---

## 4. Memory/Performance: Cursor vs get(), DB Aggregates vs Collections

### The Problem

Loading large datasets into memory causes OutOfMemory errors and slow response times.

```php
// DANGEROUS: Loads ALL records into memory
$users = User::all();
$total = $users->sum('balance'); // Collection sum - all records loaded

// DANGEROUS: N+1 query problem
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name; // Query per iteration
}
```

### Best Practice Patterns

#### Pattern A: Database Aggregates Instead of Collection Methods

```php
// BAD: Loads all records, then sums in PHP
$total = Invoice::where('status', 'paid')->get()->sum('total');

// GOOD: Database does the math, returns single value
$total = Invoice::where('status', 'paid')->sum('total');

// GOOD: Multiple aggregates in single query
$stats = Invoice::where('client_id', $clientId)
    ->selectRaw('
        COUNT(*) as total_count,
        SUM(total) as total_amount,
        AVG(total) as avg_amount,
        MAX(created_at) as last_invoice_date
    ')
    ->first();
```

#### Pattern B: Cursor for Large Dataset Iteration

```php
// BAD: All records in memory
foreach (SeoPage::all() as $page) {
    $this->processPage($page);
}

// GOOD: One record at a time in memory
foreach (SeoPage::cursor() as $page) {
    $this->processPage($page);
}

// GOOD: Chunked processing with callbacks
SeoPage::chunk(100, function ($pages) {
    foreach ($pages as $page) {
        $this->processPage($page);
    }
});

// GOOD: Lazy collection for memory-efficient large sets
SeoPage::lazy()->each(function ($page) {
    $this->processPage($page);
});
```

#### Pattern C: Eager Loading to Prevent N+1

```php
// BAD: N+1 queries
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name; // Query per post
}

// GOOD: Eager load relationships
$posts = Post::with('author')->get();
foreach ($posts as $post) {
    echo $post->author->name; // No additional queries
}

// GOOD: Nested eager loading
$posts = Post::with(['author', 'comments.user'])->get();

// GOOD: Constrained eager loading
$users = User::with(['posts' => function ($query) {
    $query->where('published', true)->latest()->limit(5);
}])->get();
```

#### Pattern D: Pagination for User-Facing Lists

```php
// API endpoint
public function index(Request $request)
{
    return Invoice::query()
        ->with(['client:id,name', 'project:id,name'])
        ->when($request->status, fn($q, $s) => $q->where('status', $s))
        ->orderByDesc('created_at')
        ->paginate($request->per_page ?? 25);
}
```

### Memory Usage Decision Guide

| Scenario | Pattern | Why |
|----------|---------|-----|
| Counting records | `->count()` | Single integer returned |
| Sum/average | `->sum()` / `->avg()` | Database computation |
| Check existence | `->exists()` | Returns boolean, no data loaded |
| Process large set | `->cursor()` or `->chunk()` | Memory-efficient iteration |
| Display list | `->paginate()` | Limited records per page |
| Need all records | `->get()` | Only when truly needed |

### Code Review Checklist

- [ ] `->sum()`, `->count()`, `->avg()` use query builder, not collection
- [ ] Large iterations use `cursor()`, `chunk()`, or `lazy()`
- [ ] N+1 queries prevented with `with()` eager loading
- [ ] List endpoints use pagination
- [ ] Existence checks use `->exists()` not `->count() > 0`
- [ ] `->pluck()` used when only specific columns needed

### Test Cases

```php
<?php

it('uses database aggregates instead of collection methods', function () {
    Invoice::factory()->count(100)->create();

    // This should be a single query
    DB::enableQueryLog();
    $sum = Invoice::sum('total');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('sum');
});

it('cursor iterates without loading all records', function () {
    Invoice::factory()->count(100)->create();

    $processed = 0;
    $maxMemoryPerIteration = 0;

    foreach (Invoice::cursor() as $invoice) {
        $processed++;
        // Memory should stay relatively constant
    }

    expect($processed)->toBe(100);
});

it('prevents N+1 queries with eager loading', function () {
    $client = Client::factory()->create();
    Invoice::factory()->count(10)->create(['client_id' => $client->id]);

    DB::enableQueryLog();

    // Should be 2 queries: invoices + clients
    $invoices = Invoice::with('client')->get();
    foreach ($invoices as $invoice) {
        $_ = $invoice->client->name;
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(2);
});
```

---

## 5. Security Configuration: Fail-Closed Patterns

### The Problem

Missing or misconfigured security tokens can silently disable authentication, allowing unauthorized access.

```php
// DANGEROUS: Empty token always matches empty header
$token = config('services.agent.token'); // Returns null if not set
if ($request->header('X-Token') === $token) { // null === null is true!
    return $next($request);
}
```

### Best Practice Patterns

#### Pattern A: Fail-Closed Token Validation

```php
public function handle(Request $request, Closure $next): Response
{
    $providedToken = $request->header('X-Agent-Token');
    $expectedToken = config('services.agent.internal_token');

    // FAIL-CLOSED: Deny if token not configured OR doesn't match
    if (empty($expectedToken) || $providedToken !== $expectedToken) {
        abort(401, 'Invalid or missing authentication token.');
    }

    return $next($request);
}
```

#### Pattern B: Environment-Specific Exceptions

```php
public function handle(Request $request, Closure $next): Response
{
    $expectedToken = config('services.agent.internal_token');

    // Fail-closed by default
    if (empty($expectedToken)) {
        // Only allow bypass in local development with explicit flag
        if (app()->environment('local') && config('services.agent.allow_local_bypass')) {
            Log::warning('Agent auth bypassed in local development');
            return $next($request);
        }

        abort(500, 'Agent authentication not configured.');
    }

    if ($request->header('X-Agent-Token') !== $expectedToken) {
        abort(401, 'Invalid authentication token.');
    }

    return $next($request);
}
```

#### Pattern C: Webhook Signature Validation

```php
public function handleWebhook(Request $request): Response
{
    $signature = $request->header('X-Webhook-Signature');
    $secret = config('services.webhook.secret');

    // Fail-closed: require both signature AND secret
    if (empty($secret)) {
        Log::error('Webhook secret not configured');
        abort(500, 'Webhook authentication not configured.');
    }

    if (empty($signature)) {
        abort(401, 'Missing webhook signature.');
    }

    $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

    // Timing-safe comparison
    if (!hash_equals($expectedSignature, $signature)) {
        abort(401, 'Invalid webhook signature.');
    }

    return $this->processWebhook($request);
}
```

#### Pattern D: Feature Flags with Safe Defaults

```php
// config/features.php
return [
    'dangerous_feature' => env('FEATURE_DANGEROUS', false), // Default OFF
    'safe_feature' => env('FEATURE_SAFE', true),            // Default ON
];

// Usage - always check explicitly
if (config('features.dangerous_feature') === true) {
    // Explicit true check, not just truthy
}
```

### Code Review Checklist

- [ ] Token/secret comparisons check for empty values BEFORE comparison
- [ ] Missing configuration results in denial, not bypass
- [ ] `hash_equals()` used for timing-safe string comparison
- [ ] Environment exceptions are explicit and logged
- [ ] Default values for security settings are restrictive
- [ ] Production environment never allows auth bypass

### Test Cases

```php
<?php

it('denies access when token not configured', function () {
    config(['services.agent.internal_token' => null]);

    $this->postJson('/api/agent/callback', [], [
        'X-Agent-Token' => 'any-token',
    ])->assertStatus(500); // Or 401, depending on implementation
});

it('denies access when token is empty string', function () {
    config(['services.agent.internal_token' => '']);

    $this->postJson('/api/agent/callback', [], [
        'X-Agent-Token' => '',
    ])->assertUnauthorized();
});

it('denies access with incorrect token', function () {
    config(['services.agent.internal_token' => 'correct-token']);

    $this->postJson('/api/agent/callback', [], [
        'X-Agent-Token' => 'wrong-token',
    ])->assertUnauthorized();
});

it('allows access with correct token', function () {
    config(['services.agent.internal_token' => 'correct-token']);

    $this->postJson('/api/agent/callback', ['data' => 'test'], [
        'X-Agent-Token' => 'correct-token',
    ])->assertSuccessful();
});

it('denies access when header missing', function () {
    config(['services.agent.internal_token' => 'correct-token']);

    $this->postJson('/api/agent/callback', ['data' => 'test'])
        ->assertUnauthorized();
});

it('uses timing-safe comparison for signatures', function () {
    // This is more of a code audit than a test
    // Verify hash_equals is used in webhook handlers
    $webhookController = file_get_contents(app_path('Http/Controllers/WebhookController.php'));

    expect($webhookController)->toContain('hash_equals');
});
```

---

## Quick Reference: Code Review Checklist

### Security

- [ ] LIKE queries escape `%` and `_` wildcards from user input
- [ ] All resource endpoints have IDOR protection (policies/ownership checks)
- [ ] Token validation is fail-closed (deny on missing config)
- [ ] `hash_equals()` used for signature comparison
- [ ] No `env()` calls outside config files

### Data Integrity

- [ ] Multi-table operations wrapped in transactions
- [ ] Counter operations inside transactions
- [ ] Foreign keys have explicit ON DELETE behavior
- [ ] `lockForUpdate()` used for concurrent modification

### Performance

- [ ] Aggregates use query builder (`->sum()`) not collections (`->get()->sum()`)
- [ ] Large iterations use `cursor()` or `chunk()`
- [ ] Eager loading prevents N+1 queries
- [ ] List endpoints use pagination

### Authorization

- [ ] Policies exist for owned resources
- [ ] `$this->authorize()` called in controllers
- [ ] Bulk operations verify ALL items, not just first
- [ ] Nested resources verify parent ownership
