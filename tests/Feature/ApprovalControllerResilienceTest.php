<?php

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('renders the approvals index even when a row has a non-array payload', function () {
    $user = User::factory()->create(['role' => 'owner']);

    // Healthy row first — proves baseline renders.
    ApprovalRequest::factory()->create([
        'status' => 'pending',
        'action_type' => 'agent_execution',
        'payload' => ['preview' => 'normal preview'],
    ]);

    // Poisoned row: writing a JSON-encoded bare string into the payload
    // column. Laravel's array cast decodes that into a PHP string rather
    // than an array — exactly the production failure mode that caused the
    // TypeError on getPayloadPreview(?array).
    $poisoned = ApprovalRequest::factory()->create([
        'status' => 'pending',
        'action_type' => 'agent_execution',
    ]);
    DB::table('approval_requests')->where('id', $poisoned->id)->update([
        'payload' => json_encode('this is a bare string, not an object'),
    ]);

    $this->actingAs($user)
        ->get('/approvals')
        ->assertSuccessful();
});

it('renders the approvals index even when a row has utterly invalid json', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $row = ApprovalRequest::factory()->create([
        'status' => 'pending',
        'action_type' => 'agent_execution',
    ]);
    DB::table('approval_requests')->where('id', $row->id)->update([
        'payload' => '{not even valid json',
    ]);

    $this->actingAs($user)
        ->get('/approvals')
        ->assertSuccessful();
});
