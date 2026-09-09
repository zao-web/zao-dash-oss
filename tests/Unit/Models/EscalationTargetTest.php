<?php

use App\Models\EscalationTarget;

test('has guarded attributes empty', function () {
    expect((new EscalationTarget)->getGuarded())->toBe(['*']);
});

test('casts is_active to boolean', function () {
    $target = EscalationTarget::factory()->create(['is_active' => true]);

    expect($target->is_active)->toBeTrue();
});

test('has level constants', function () {
    expect(EscalationTarget::LEVEL_ACCOUNT_MANAGER)->toBe(0)
        ->and(EscalationTarget::LEVEL_MANAGER)->toBe(1)
        ->and(EscalationTarget::LEVEL_DIRECTOR)->toBe(2)
        ->and(EscalationTarget::LEVEL_EXECUTIVE)->toBe(3);
});

test('belongs to user relationship', function () {
    $target = EscalationTarget::factory()->create();

    expect($target->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('scopeActive filters active targets', function () {
    EscalationTarget::factory()->create(['is_active' => true]);
    EscalationTarget::factory()->create(['is_active' => false]);

    $activeTargets = EscalationTarget::active()->count();

    expect($activeTargets)->toBe(1);
});

test('scopeForLevel filters by level', function () {
    EscalationTarget::factory()->create(['level' => 0]);
    EscalationTarget::factory()->create(['level' => 1]);

    $managerTargets = EscalationTarget::forLevel(1)->count();

    expect($managerTargets)->toBe(1);
});

test('getLevelName returns correct names', function () {
    expect(EscalationTarget::getLevelName(0))->toBe('Account Manager')
        ->and(EscalationTarget::getLevelName(1))->toBe('Manager')
        ->and(EscalationTarget::getLevelName(2))->toBe('Director')
        ->and(EscalationTarget::getLevelName(3))->toBe('Executive')
        ->and(EscalationTarget::getLevelName(99))->toBe('Unknown');
});

test('can be created via factory', function () {
    $target = EscalationTarget::factory()->create();

    expect($target)->toBeInstanceOf(EscalationTarget::class)
        ->and($target->exists)->toBeTrue();
});
