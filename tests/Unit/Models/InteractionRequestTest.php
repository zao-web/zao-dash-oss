<?php

use App\Models\AgentRun;
use App\Models\InteractionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty for fillable pattern', function () {
    expect((new InteractionRequest)->getGuarded())->toBe([]);
});

test('casts options to array', function () {
    $interaction = InteractionRequest::factory()->select()->create();

    expect($interaction->options)->toBeArray()
        ->and($interaction->options)->toHaveCount(3);
});

test('casts context to array', function () {
    $interaction = InteractionRequest::factory()->create(['context' => ['header' => 'Test']]);

    expect($interaction->context)->toBeArray()
        ->and($interaction->context['header'])->toBe('Test');
});

test('casts responded_at to datetime', function () {
    $interaction = InteractionRequest::factory()->responded()->create();

    expect($interaction->responded_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts expires_at to datetime', function () {
    $interaction = InteractionRequest::factory()->create();

    expect($interaction->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to agent run relationship', function () {
    $interaction = InteractionRequest::factory()->create();

    expect($interaction->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($interaction->agentRun)->toBeInstanceOf(AgentRun::class);
});

test('belongs to responded by user relationship', function () {
    $user = User::factory()->create();
    $interaction = InteractionRequest::factory()->create([
        'responded_by_id' => $user->id,
        'responded_at' => now(),
        'response' => 'test',
    ]);

    expect($interaction->respondedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($interaction->respondedBy)->toBeInstanceOf(User::class)
        ->and($interaction->respondedBy->id)->toBe($user->id);
});

test('isResponded returns true when responded_at is set', function () {
    $responded = InteractionRequest::factory()->responded()->create();
    $pending = InteractionRequest::factory()->pending()->create();

    expect($responded->isResponded())->toBeTrue()
        ->and($pending->isResponded())->toBeFalse();
});

test('isExpired returns true when expires_at is past and not responded', function () {
    $expired = InteractionRequest::factory()->expired()->create();
    $pending = InteractionRequest::factory()->pending()->create();
    $respondedExpired = InteractionRequest::factory()->expired()->responded()->create();

    expect($expired->isExpired())->toBeTrue()
        ->and($pending->isExpired())->toBeFalse()
        ->and($respondedExpired->isExpired())->toBeFalse();
});

test('isPending returns true when not responded and not expired', function () {
    $pending = InteractionRequest::factory()->pending()->create();
    $expired = InteractionRequest::factory()->expired()->create();
    $responded = InteractionRequest::factory()->responded()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($expired->isPending())->toBeFalse()
        ->and($responded->isPending())->toBeFalse();
});

test('remaining_time returns seconds until expiration for pending interactions', function () {
    $interaction = InteractionRequest::factory()->create([
        'expires_at' => now()->addMinutes(5),
    ]);

    $remaining = $interaction->remaining_time;

    expect($remaining)->toBeInt()
        ->and($remaining)->toBeGreaterThan(290)
        ->and($remaining)->toBeLessThanOrEqual(300);
});

test('remaining_time returns null for responded interactions', function () {
    $interaction = InteractionRequest::factory()->responded()->create();

    expect($interaction->remaining_time)->toBeNull();
});

test('remaining_time returns null for expired interactions', function () {
    $interaction = InteractionRequest::factory()->expired()->create();

    expect($interaction->remaining_time)->toBeNull();
});

test('pending scope returns only pending interactions', function () {
    InteractionRequest::factory()->pending()->count(3)->create();
    InteractionRequest::factory()->responded()->count(2)->create();
    InteractionRequest::factory()->expired()->count(2)->create();

    $pending = InteractionRequest::pending()->get();

    expect($pending)->toHaveCount(3)
        ->and($pending->every(fn ($i) => $i->isPending()))->toBeTrue();
});

test('expired scope returns only expired interactions', function () {
    InteractionRequest::factory()->pending()->count(2)->create();
    InteractionRequest::factory()->expired()->count(3)->create();

    $expired = InteractionRequest::expired()->get();

    expect($expired)->toHaveCount(3)
        ->and($expired->every(fn ($i) => $i->isExpired()))->toBeTrue();
});

test('awaitingResponse scope returns interactions without response', function () {
    InteractionRequest::factory()->pending()->count(2)->create();
    InteractionRequest::factory()->expired()->count(2)->create();
    InteractionRequest::factory()->responded()->count(3)->create();

    $awaiting = InteractionRequest::awaitingResponse()->get();

    expect($awaiting)->toHaveCount(4)
        ->and($awaiting->every(fn ($i) => $i->responded_at === null))->toBeTrue();
});

test('forRun scope returns interactions for specific agent run', function () {
    $run1 = AgentRun::factory()->create();
    $run2 = AgentRun::factory()->create();

    InteractionRequest::factory()->count(3)->create(['agent_run_id' => $run1->id]);
    InteractionRequest::factory()->count(2)->create(['agent_run_id' => $run2->id]);

    $run1Interactions = InteractionRequest::forRun($run1->id)->get();
    $run2Interactions = InteractionRequest::forRun($run2->id)->get();

    expect($run1Interactions)->toHaveCount(3)
        ->and($run2Interactions)->toHaveCount(2);
});

test('can be created via factory', function () {
    $interaction = InteractionRequest::factory()->create();

    expect($interaction)->toBeInstanceOf(InteractionRequest::class)
        ->and($interaction->exists)->toBeTrue();
});

test('question type constants are defined', function () {
    expect(InteractionRequest::TYPE_TEXT)->toBe('text')
        ->and(InteractionRequest::TYPE_SELECT)->toBe('select')
        ->and(InteractionRequest::TYPE_CONFIRM)->toBe('confirm');
});

test('response channel constants are defined', function () {
    expect(InteractionRequest::VIA_DASHBOARD)->toBe('dashboard')
        ->and(InteractionRequest::VIA_SLACK)->toBe('slack')
        ->and(InteractionRequest::VIA_MCP)->toBe('mcp');
});
