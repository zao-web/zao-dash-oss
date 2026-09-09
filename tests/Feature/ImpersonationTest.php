<?php

use App\Models\Agent;
use App\Models\Client;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows owner to start impersonation', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)
        ->post("/impersonate/client/{$client->id}");

    $response->assertRedirect(route('portal.dashboard'));
    expect(session('impersonating_client_id'))->toBe($client->id);
    expect(session('impersonating_client_name'))->toBe($client->name);
});

it('allows admin to start impersonation', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)
        ->post("/impersonate/client/{$client->id}");

    $response->assertRedirect(route('portal.dashboard'));
    expect(session('impersonating_client_id'))->toBe($client->id);
});

it('allows staff to start impersonation', function () {
    $user = User::factory()->create(['role' => 'staff']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)
        ->post("/impersonate/client/{$client->id}");

    $response->assertRedirect(route('portal.dashboard'));
});

it('blocks client users from starting impersonation', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

    $response = $this->actingAs($user)
        ->post("/impersonate/client/{$client->id}");

    $response->assertForbidden();
});

it('stops impersonation and redirects to client page', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->post('/impersonate/stop')
        ->assertRedirect(route('clients.show', $client->slug));

    expect(session('impersonating_client_id'))->toBeNull();
});

it('allows owner to access portal when impersonating', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->get('/portal');

    $response->assertOk();
});

it('blocks non-client non-internal users from portal', function () {
    $user = User::factory()->create(['role' => 'client']);

    $response = $this->actingAs($user)
        ->get('/portal');

    // Client user without client_id should get 403
    $response->assertForbidden();
});

it('allows client user with client_id to access portal', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

    $response = $this->actingAs($user)
        ->get('/portal');

    $response->assertOk();
});

it('loads portal projects page without error when impersonating', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->get('/portal/projects');

    $response->assertOk();
});

it('loads portal project show with kanban data when impersonating', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'in_progress',
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->get("/portal/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/ProjectShow')
        ->has('project.tasks', 2)
        ->has('stats')
        ->where('stats.total_tasks', 2)
        ->where('stats.completed_tasks', 1)
        ->where('stats.in_progress_tasks', 1)
    );
});

it('portal task show returns comments', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    \App\Models\TaskComment::createComment($task, $user->id, 'Test comment from team');

    $response = $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->getJson("/portal/api/tasks/{$task->id}");

    $response->assertOk();
    $response->assertJsonPath('task.id', $task->id);
    $response->assertJsonCount(1, 'task.comments');
});

it('portal allows posting comments on client tasks', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($clientUser)
        ->postJson("/portal/api/tasks/{$task->id}/comments", [
            'content' => 'Client feedback on this task',
        ]);

    $response->assertCreated();
    $response->assertJsonPath('comment.content', 'Client feedback on this task');
    $response->assertJsonPath('comment.user.name', $clientUser->name);
});

it('portal blocks access to tasks from other clients', function () {
    $client1 = Client::factory()->create();
    $client2 = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client1->id]);
    $project = Project::factory()->create(['client_id' => $client2->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($clientUser)
        ->getJson("/portal/api/tasks/{$task->id}");

    $response->assertForbidden();
});

it('portal allows reassigning to client contacts', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
    $contact = \App\Models\ClientContact::factory()->create(['client_id' => $client->id]);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($clientUser)
        ->postJson("/portal/api/tasks/{$task->id}/assign", [
            'assigned_to' => $contact->id,
            'assignee_type' => 'client_contact',
        ]);

    $response->assertOk();
    $response->assertJsonPath('assignee.name', $contact->name);
    $response->assertJsonPath('assignee.type', 'client_contact');
});

it('portal blocks assigning to agents', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
    $agent = Agent::factory()->create(['status' => 'active']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($clientUser)
        ->postJson("/portal/api/tasks/{$task->id}/assign", [
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
        ]);

    $response->assertUnprocessable();
});

it('portal mention search returns team and client contacts only', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
    $teamMember = User::factory()->create(['role' => 'admin', 'name' => 'Justin Smith']);
    \App\Models\ClientContact::factory()->create([
        'client_id' => $client->id,
        'name' => 'Justin Client',
        'email' => 'justinclient@example.com',
    ]);

    $response = $this->actingAs($clientUser)
        ->getJson('/portal/api/mentions/search?q=Justin');

    $response->assertOk();
    $items = $response->json('items');
    $names = collect($items)->pluck('label')->toArray();
    expect($names)->toContain('Justin Smith');
    expect($names)->toContain('Justin Client');
});

it('portal mention search excludes agents', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
    Agent::factory()->create(['name' => 'Search Agent', 'status' => 'active']);

    $response = $this->actingAs($clientUser)
        ->getJson('/portal/api/mentions/search?q=Search');

    $response->assertOk();
    $items = $response->json('items');
    $names = collect($items)->pluck('label')->toArray();
    expect($names)->not->toContain('Search Agent');
});

it('masks agent assignees as Zao Team in portal', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $agent = Agent::factory()->create(['status' => 'active']);

    Task::factory()->create([
        'project_id' => $project->id,
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
        'status' => 'in_progress',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'impersonating_client_id' => $client->id,
            'impersonating_client_name' => $client->name,
            'original_user_id' => $user->id,
        ])
        ->get("/portal/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('project.tasks.0.assignee.name', 'Zao Team')
        ->where('project.tasks.0.assignee.type', 'user')
    );
});
