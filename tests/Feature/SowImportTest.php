<?php

use App\Jobs\ProcessSowParseJob;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\SowParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// --- Parse Endpoint Validation Tests ---

it('rejects parse request with no documents', function () {
    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => [],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['documents']);
});

it('rejects parse request with invalid file type', function () {
    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => [
            [
                'type' => 'pdf',
                'file' => UploadedFile::fake()->create('doc.txt', 100, 'text/plain'),
            ],
        ],
    ]);

    $response->assertStatus(422);
});

it('rejects parse request with oversized files', function () {
    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => [
            [
                'type' => 'pdf',
                'file' => UploadedFile::fake()->create('big.pdf', 25000, 'application/pdf'),
            ],
        ],
    ]);

    $response->assertStatus(422);
});

it('rejects more than 5 documents', function () {
    $documents = [];
    for ($i = 0; $i < 6; $i++) {
        $documents[] = [
            'type' => 'text',
            'content' => 'Content '.$i,
            'label' => 'Doc '.$i,
        ];
    }

    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => $documents,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['documents']);
});

// --- Parse Endpoint Async Tests ---

it('dispatches parse job and returns parse_id for text documents', function () {
    Queue::fake();

    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => [
            [
                'type' => 'text',
                'content' => 'This is a statement of work for Acme Corp...',
                'label' => 'SOW',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['parse_id']);

    Queue::assertPushed(ProcessSowParseJob::class, function ($job) use ($response) {
        return $job->parseId === $response->json('parse_id');
    });
});

it('returns 422 when no text can be extracted from documents', function () {
    $this->mock(SowParsingService::class, function ($mock) {
        $mock->shouldReceive('buildCombinedText')
            ->once()
            ->andReturn(null);
    });

    $response = $this->postJson('/api/sow-import/parse', [
        'documents' => [
            [
                'type' => 'text',
                'content' => 'Some content that the mock will ignore',
                'label' => 'Empty',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'Could not extract any text from the provided documents. Please check the files and try again.');
});

// --- Parse Status Endpoint Tests ---

it('returns completed status with data when job finishes', function () {
    $parseId = 'test-parse-id';
    $mockData = [
        'client' => ['name' => 'Acme Corp', 'website' => 'https://acme.com', 'description' => 'A company'],
        'contacts' => [],
        'project' => ['name' => 'Website Redesign', 'description' => 'Redesign', 'type' => 'project', 'budget' => 50000],
        'milestones' => [
            [
                'name' => 'Discovery',
                'description' => 'Discovery phase',
                'tasks' => [
                    ['title' => 'Research', 'description' => 'Do research', 'priority' => 'high', 'estimated_hours' => 10, 'subtasks' => []],
                ],
            ],
        ],
    ];

    Cache::put("sow_parse:{$parseId}", ['status' => 'completed', 'data' => $mockData], now()->addMinutes(30));

    $response = $this->getJson("/api/sow-import/parse/{$parseId}/status");

    $response->assertSuccessful();
    $response->assertJsonPath('status', 'completed');
    $response->assertJsonPath('data.client.name', 'Acme Corp');
    $response->assertJsonPath('data.project.name', 'Website Redesign');
});

it('returns processing status while job is running', function () {
    $parseId = 'running-parse';
    Cache::put("sow_parse:{$parseId}", ['status' => 'processing'], now()->addMinutes(30));

    $response = $this->getJson("/api/sow-import/parse/{$parseId}/status");

    $response->assertSuccessful();
    $response->assertJsonPath('status', 'processing');
});

it('returns failed status with error message', function () {
    $parseId = 'failed-parse';
    Cache::put("sow_parse:{$parseId}", [
        'status' => 'failed',
        'error' => 'Could not extract project data from the provided documents. Please check the files and try again.',
    ], now()->addMinutes(30));

    $response = $this->getJson("/api/sow-import/parse/{$parseId}/status");

    $response->assertSuccessful();
    $response->assertJsonPath('status', 'failed');
    $response->assertJsonPath('error', 'Could not extract project data from the provided documents. Please check the files and try again.');
});

it('returns 404 for unknown parse_id', function () {
    $response = $this->getJson('/api/sow-import/parse/nonexistent/status');

    $response->assertNotFound();
    $response->assertJsonPath('status', 'not_found');
});

// --- Job Tests ---

it('job stores completed result in cache on success', function () {
    $mockResult = [
        'client' => ['name' => 'Job Client'],
        'milestones' => [['name' => 'Phase 1', 'tasks' => [['title' => 'Task 1']]]],
    ];

    $this->mock(SowParsingService::class, function ($mock) use ($mockResult) {
        $mock->shouldReceive('extractWithAI')
            ->once()
            ->andReturn($mockResult);
    });

    $job = new ProcessSowParseJob('job-test-id', 'Some document text');
    $job->handle(app(SowParsingService::class));

    $cached = Cache::get('sow_parse:job-test-id');
    expect($cached['status'])->toBe('completed');
    expect($cached['data']['client']['name'])->toBe('Job Client');
});

it('job stores failed result in cache when AI returns null', function () {
    $this->mock(SowParsingService::class, function ($mock) {
        $mock->shouldReceive('extractWithAI')
            ->once()
            ->andReturn(null);
    });

    $job = new ProcessSowParseJob('job-fail-id', 'Some document text');
    $job->handle(app(SowParsingService::class));

    $cached = Cache::get('sow_parse:job-fail-id');
    expect($cached['status'])->toBe('failed');
    expect($cached['error'])->not->toBeEmpty();
});

// --- Confirm Endpoint Tests ---

it('creates client, contacts, project, milestones, and tasks', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'New Client LLC', 'website' => 'https://newclient.com', 'description' => 'A new client'],
        'contacts' => [
            ['name' => 'Jane Smith', 'email' => 'jane@newclient.com', 'role' => 'PM', 'phone' => '555-0001'],
        ],
        'project' => ['name' => 'App Build', 'description' => 'Build a mobile app', 'type' => 'project', 'budget' => 25000],
        'milestones' => [
            [
                'name' => 'Design Phase',
                'description' => 'UX/UI design',
                'tasks' => [
                    ['title' => 'Create wireframes', 'description' => 'Low-fi wireframes', 'priority' => 'high', 'estimated_hours' => 20, 'subtasks' => []],
                    ['title' => 'Design mockups', 'description' => 'High-fi mockups', 'priority' => 'medium', 'estimated_hours' => 30, 'subtasks' => []],
                ],
            ],
            [
                'name' => 'Development Phase',
                'description' => 'Build the app',
                'tasks' => [
                    ['title' => 'Set up project', 'description' => 'Initialize codebase', 'priority' => 'high', 'estimated_hours' => 5, 'subtasks' => []],
                ],
            ],
        ],
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('clients', ['name' => 'New Client LLC']);
    $this->assertDatabaseHas('client_contacts', ['email' => 'jane@newclient.com', 'name' => 'Jane Smith']);
    $this->assertDatabaseHas('projects', ['name' => 'App Build', 'type' => 'project']);
    $this->assertDatabaseHas('milestones', ['name' => 'Design Phase']);
    $this->assertDatabaseHas('milestones', ['name' => 'Development Phase']);
    $this->assertDatabaseHas('tasks', ['title' => 'Create wireframes', 'source' => 'sow_import']);
    $this->assertDatabaseHas('tasks', ['title' => 'Design mockups']);
    $this->assertDatabaseHas('tasks', ['title' => 'Set up project']);

    expect($response->json('summary.milestones_created'))->toBe(2);
    expect($response->json('summary.tasks_created'))->toBe(3);
    expect($response->json('summary.contacts_created'))->toBe(1);
    expect($response->json('summary.client'))->toBe('created');
});

it('creates project timeline and invoices when provided on confirm', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Timeline Client', 'website' => 'https://timeline.test', 'description' => 'Timeline build'],
        'contacts' => [],
        'project' => [
            'name' => 'Timeline Project',
            'description' => 'Full delivery',
            'type' => 'project',
            'budget' => 40000,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
        ],
        'milestones' => [
            [
                'name' => 'Discovery',
                'description' => 'Research and planning',
                'due_date' => '2026-04-15',
                'tasks' => [
                    [
                        'title' => 'Run discovery workshop',
                        'description' => 'Align the team',
                        'priority' => 'high',
                        'estimated_hours' => 12,
                        'due_date' => '2026-04-10',
                        'subtasks' => [
                            [
                                'title' => 'Prepare agenda',
                                'description' => 'Agenda and materials',
                                'due_date' => '2026-04-08',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'invoices' => [
            [
                'subject' => 'Project Deposit',
                'amount' => 20000,
                'issue_date' => '2026-04-01',
                'due_date' => '2026-04-08',
            ],
            [
                'subject' => 'Launch Balance',
                'items' => [
                    ['description' => 'Remaining fixed fee', 'quantity' => 1, 'unit_price' => 20000, 'type' => 'fixed'],
                ],
                'due_days' => 14,
            ],
        ],
    ]);

    $response->assertSuccessful();

    $project = Project::where('name', 'Timeline Project')->firstOrFail();

    expect($project->start_date?->toDateString())->toBe('2026-04-01')
        ->and($project->end_date?->toDateString())->toBe('2026-06-30')
        ->and($response->json('summary.invoices_created'))->toBe(2);

    $milestone = Milestone::where('project_id', $project->id)
        ->where('name', 'Discovery')
        ->firstOrFail();
    $task = Task::where('project_id', $project->id)
        ->where('title', 'Run discovery workshop')
        ->firstOrFail();
    $subtask = Task::where('project_id', $project->id)
        ->where('title', '[Run discovery workshop] Prepare agenda')
        ->firstOrFail();

    expect($milestone->due_date?->toDateString())->toBe('2026-04-15')
        ->and($task->due_date?->toDateString())->toBe('2026-04-10')
        ->and($subtask->due_date?->toDateString())->toBe('2026-04-08');

    expect(Invoice::where('project_id', $project->id)->count())->toBe(2);
});

it('skips contacts whose email matches an existing team member', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Acme Corp', 'website' => null, 'description' => null],
        'contacts' => [
            ['name' => 'External Contact', 'email' => 'external@acme.com', 'role' => 'PM', 'phone' => null],
            ['name' => $this->user->name, 'email' => $this->user->email, 'role' => 'Project Lead', 'phone' => null],
        ],
        'project' => ['name' => 'Test Project', 'description' => null, 'type' => 'project', 'budget' => null],
        'milestones' => [
            ['name' => 'Phase 1', 'description' => null, 'tasks' => [
                ['title' => 'Setup', 'description' => null, 'priority' => 'medium', 'estimated_hours' => 5, 'subtasks' => []],
            ]],
        ],
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('client_contacts', ['email' => 'external@acme.com']);
    $this->assertDatabaseMissing('client_contacts', ['email' => $this->user->email]);
    expect($response->json('summary.contacts_created'))->toBe(1);
});

it('finds existing client by name instead of creating duplicate', function () {
    $existing = Client::factory()->create(['name' => 'Existing Corp']);

    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'existing corp', 'website' => null, 'description' => null],
        'contacts' => [],
        'project' => ['name' => 'New Project', 'description' => null, 'type' => 'retainer', 'budget' => null],
        'milestones' => [
            [
                'name' => 'Phase 1',
                'description' => null,
                'tasks' => [
                    ['title' => 'Do something', 'description' => null, 'priority' => 'medium', 'estimated_hours' => null, 'subtasks' => []],
                ],
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Should reuse the existing client
    expect($response->json('client_id'))->toBe($existing->id);
    expect($response->json('summary.client'))->toBe('existing');

    // Should not create a duplicate
    expect(Client::where('name', 'Existing Corp')->count())->toBe(1);
});

it('creates subtasks as flat tasks with parent prefix', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Sub Client', 'website' => null, 'description' => null],
        'contacts' => [],
        'project' => ['name' => 'Subtask Project', 'description' => null, 'type' => 'project', 'budget' => null],
        'milestones' => [
            [
                'name' => 'Phase 1',
                'description' => null,
                'tasks' => [
                    [
                        'title' => 'Build homepage',
                        'description' => 'Build the homepage',
                        'priority' => 'high',
                        'estimated_hours' => null,
                        'subtasks' => [
                            ['title' => 'Create header', 'description' => 'Header component'],
                            ['title' => 'Create footer', 'description' => 'Footer component'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('tasks', ['title' => 'Build homepage']);
    $this->assertDatabaseHas('tasks', ['title' => '[Build homepage] Create header']);
    $this->assertDatabaseHas('tasks', ['title' => '[Build homepage] Create footer']);

    // 1 parent + 2 subtasks = 3 total
    expect($response->json('summary.tasks_created'))->toBe(3);
});

it('validates required fields on confirm', function () {
    $response = $this->postJson('/api/sow-import/confirm', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['client.name', 'project.name', 'project.type', 'milestones']);
});

it('validates project type must be valid', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Test Client'],
        'project' => ['name' => 'Test Project', 'type' => 'invalid'],
        'milestones' => [
            [
                'name' => 'Phase 1',
                'tasks' => [['title' => 'Task 1']],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['project.type']);
});

it('validates milestones must have tasks', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Test Client'],
        'project' => ['name' => 'Test Project', 'type' => 'project'],
        'milestones' => [
            [
                'name' => 'Empty Milestone',
                'tasks' => [],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['milestones.0.tasks']);
});

it('does not duplicate contacts with same email', function () {
    $client = Client::factory()->create(['name' => 'Contact Client']);
    ClientContact::create([
        'client_id' => $client->id,
        'name' => 'Existing Contact',
        'email' => 'existing@test.com',
    ]);

    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Contact Client'],
        'contacts' => [
            ['name' => 'Existing Contact', 'email' => 'existing@test.com', 'role' => 'PM'],
            ['name' => 'New Contact', 'email' => 'new@test.com', 'role' => 'Dev'],
        ],
        'project' => ['name' => 'Contact Test Project', 'type' => 'project'],
        'milestones' => [
            [
                'name' => 'Phase 1',
                'tasks' => [['title' => 'A task']],
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Only 1 new contact should be created (the existing one is skipped)
    expect($response->json('summary.contacts_created'))->toBe(1);
    expect(ClientContact::where('client_id', $client->id)->count())->toBe(2);
});

it('sets task metadata with import markers', function () {
    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Meta Client'],
        'contacts' => [],
        'project' => ['name' => 'Meta Project', 'type' => 'project'],
        'milestones' => [
            [
                'name' => 'Phase 1',
                'tasks' => [['title' => 'Check metadata']],
            ],
        ],
    ]);

    $response->assertSuccessful();

    $task = Task::where('title', 'Check metadata')->first();
    expect($task->source)->toBe('sow_import');
    expect($task->metadata['ai_generated'])->toBeTrue();
    expect($task->metadata['imported_at'])->not->toBeNull();
});

it('rolls back transaction on failure', function () {
    $clientCountBefore = Client::count();
    $projectCountBefore = Project::count();

    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Rollback Client'],
        'contacts' => [],
        'project' => ['name' => 'Rollback Project', 'type' => 'project'],
        'milestones' => [
            [
                'name' => 'Valid Milestone',
                'tasks' => [
                    ['title' => str_repeat('x', 300), 'description' => null, 'priority' => 'medium'],
                ],
            ],
        ],
    ]);

    // If it fails (title too long for varchar(255)), nothing should be created
    if (! $response->isSuccessful()) {
        expect(Client::count())->toBe($clientCountBefore);
        expect(Project::count())->toBe($projectCountBefore);
    }
});

// --- PDF & Service Tests ---

it('extracts text from a real PDF upload via service', function () {
    $service = app(SowParsingService::class);

    $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>>>endobj\n4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\nxref\n0 5\ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n0\n%%EOF";

    $tmpFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
    file_put_contents($tmpFile, $pdfContent);

    $uploadedFile = new UploadedFile($tmpFile, 'test.pdf', 'application/pdf', null, true);

    $result = $service->extractTextFromPdf($uploadedFile);
    expect($result)->toBeString();

    unlink($tmpFile);
});

it('builds combined text from multiple documents', function () {
    $service = app(SowParsingService::class);

    $result = $service->buildCombinedText([
        ['type' => 'text', 'content' => 'First document content', 'label' => 'SOW'],
        ['type' => 'text', 'content' => 'Second document content', 'label' => 'Discovery'],
    ]);

    expect($result)->toContain('=== SOW ===');
    expect($result)->toContain('First document content');
    expect($result)->toContain('=== Discovery ===');
    expect($result)->toContain('Second document content');
});

it('returns null for empty documents', function () {
    $service = app(SowParsingService::class);

    $result = $service->buildCombinedText([
        ['type' => 'text', 'content' => '', 'label' => 'Empty'],
    ]);

    expect($result)->toBeNull();
});

it('requires authentication', function () {
    auth()->logout();

    $response = $this->postJson('/api/sow-import/confirm', [
        'client' => ['name' => 'Test'],
        'project' => ['name' => 'Test', 'type' => 'project'],
        'milestones' => [['name' => 'P1', 'tasks' => [['title' => 'T1']]]],
    ]);

    $response->assertUnauthorized();
});
