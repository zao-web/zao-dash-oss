<?php

use App\Models\ApprovalRequest;

test('has guarded attributes empty', function () {
    expect((new ApprovalRequest)->getGuarded())->toBe(['*']);
});

test('casts payload to array', function () {
    $approval = ApprovalRequest::factory()->create(['payload' => ['action' => 'send_email']]);

    expect($approval->payload)->toBeArray()
        ->and($approval->payload)->toBe(['action' => 'send_email']);
});

test('casts decided_at to datetime', function () {
    $approval = ApprovalRequest::factory()->create(['decided_at' => now()]);

    expect($approval->decided_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts expires_at to datetime', function () {
    $approval = ApprovalRequest::factory()->create(['expires_at' => now()->addDay()]);

    expect($approval->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to agent run relationship', function () {
    $approval = ApprovalRequest::factory()->create();

    expect($approval->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to decided by user relationship', function () {
    $approval = ApprovalRequest::factory()->create();

    expect($approval->decidedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be created via factory', function () {
    $approval = ApprovalRequest::factory()->create();

    expect($approval)->toBeInstanceOf(ApprovalRequest::class)
        ->and($approval->exists)->toBeTrue();
});
