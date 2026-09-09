<?php

use App\Models\PromptVersion;

test('has guarded attributes empty', function () {
    expect((new PromptVersion)->getGuarded())->toBe(['*']);
});

test('casts variables to array', function () {
    $version = PromptVersion::factory()->create(['variables' => ['var1', 'var2']]);

    expect($version->variables)->toBeArray();
});

test('casts is_active to boolean', function () {
    $version = PromptVersion::factory()->create(['is_active' => true]);

    expect($version->is_active)->toBeTrue();
});

test('casts ab_test_weight to integer', function () {
    $version = PromptVersion::factory()->create(['ab_test_weight' => 50]);

    expect($version->ab_test_weight)->toBeInt();
});

test('belongs to template relationship', function () {
    $version = PromptVersion::factory()->create();

    expect($version->template())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to created by user relationship', function () {
    $version = PromptVersion::factory()->create();

    expect($version->createdBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many runs relationship', function () {
    $version = PromptVersion::factory()->create();

    expect($version->runs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be created via factory', function () {
    $version = PromptVersion::factory()->create();

    expect($version)->toBeInstanceOf(PromptVersion::class)
        ->and($version->exists)->toBeTrue();
});
