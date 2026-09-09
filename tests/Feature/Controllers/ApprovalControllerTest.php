<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('index returns approval requests', function () {
    ApprovalRequest::factory()->count(3)->create(['status' => 'pending']);

    $response = $this->get(route('approvals.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Approvals/Index')
        ->has('approvals.data', 3)
    );
});

test('index filters by status', function () {
    ApprovalRequest::factory()->count(2)->create(['status' => 'pending']);
    ApprovalRequest::factory()->count(3)->create(['status' => 'approved']);

    $response = $this->get(route('approvals.index', ['status' => 'approved']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('approvals.data', 3)
    );
});

test('index filters by risk level', function () {
    ApprovalRequest::factory()->count(2)->create(['risk_level' => 'high']);
    ApprovalRequest::factory()->count(3)->create(['risk_level' => 'low']);

    $response = $this->get(route('approvals.index', ['risk_level' => 'high']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('approvals.data', 2)
    );
});

test('index filters by agent', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->count(2)->create(['agent_run_id' => $run->id]);
    ApprovalRequest::factory()->count(3)->create();

    $response = $this->get(route('approvals.index', ['agent_id' => $agent->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('approvals.data', 2)
    );
});

test('show returns approval details', function () {
    $approval = ApprovalRequest::factory()->create();

    $response = $this->get(route('approvals.show', $approval));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Approvals/Show')
        ->has('approval')
    );
});

test('can approve pending request', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    $response = $this->post(route('approvals.approve', $approval), [
        'comment' => 'Looks good',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $approval->refresh();
    expect($approval->status)->toBe('approved');
    expect($approval->decision_note)->toBe('Looks good');
    expect($approval->decided_by)->toBe($this->user->id);
});

test('cannot approve already processed request', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'approved']);

    $response = $this->post(route('approvals.approve', $approval));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('can reject pending request with reason', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    $response = $this->post(route('approvals.reject', $approval), [
        'reason' => 'Not appropriate',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $approval->refresh();
    expect($approval->status)->toBe('rejected');
    expect($approval->decision_note)->toBe('Not appropriate');
});

test('reject requires reason', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    $response = $this->post(route('approvals.reject', $approval), [
        'reason' => '',
    ]);

    $response->assertSessionHasErrors(['reason']);
});

test('cannot reject already processed request', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'rejected']);

    $response = $this->post(route('approvals.reject', $approval), [
        'reason' => 'Not good',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('can bulk approve approvals', function () {
    $approvals = ApprovalRequest::factory()->count(3)->create(['status' => 'pending']);

    $response = $this->post(route('approvals.bulkApprove'), [
        'ids' => $approvals->pluck('id')->toArray(),
        'comment' => 'All approved',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $approvals->each(function ($approval) {
        $approval->refresh();
        expect($approval->status)->toBe('approved');
    });
});

test('bulk approve requires ids array', function () {
    $response = $this->post(route('approvals.bulkApprove'), [
        'ids' => 'not-an-array',
    ]);

    $response->assertSessionHasErrors(['ids']);
});

test('bulk approve validates ids exist', function () {
    $response = $this->post(route('approvals.bulkApprove'), [
        'ids' => [9999, 9998],
    ]);

    $response->assertSessionHasErrors(['ids.0', 'ids.1']);
});

test('can bulk reject approvals', function () {
    $approvals = ApprovalRequest::factory()->count(3)->create(['status' => 'pending']);

    $response = $this->post(route('approvals.bulkReject'), [
        'ids' => $approvals->pluck('id')->toArray(),
        'reason' => 'All rejected',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $approvals->each(function ($approval) {
        $approval->refresh();
        expect($approval->status)->toBe('rejected');
    });
});

test('bulk reject requires reason', function () {
    $approvals = ApprovalRequest::factory()->count(2)->create(['status' => 'pending']);

    $response = $this->post(route('approvals.bulkReject'), [
        'ids' => $approvals->pluck('id')->toArray(),
        'reason' => '',
    ]);

    $response->assertSessionHasErrors(['reason']);
});

test('approvals require authentication', function () {
    auth()->logout();

    $response = $this->get(route('approvals.index'));

    $response->assertRedirect(route('login'));
});
