<?php

use App\Models\PromptTemplate;

test('has guarded attributes empty', function () {
    expect((new PromptTemplate)->getGuarded())->toBe(['*']);
});

test('casts variables to array', function () {
    $template = PromptTemplate::factory()->create(['variables' => ['var1', 'var2']]);

    expect($template->variables)->toBeArray();
});

test('casts tags to array', function () {
    $template = PromptTemplate::factory()->create(['tags' => ['tag1', 'tag2']]);

    expect($template->tags)->toBeArray();
});

test('casts metadata to array', function () {
    $template = PromptTemplate::factory()->create(['metadata' => ['key' => 'value']]);

    expect($template->metadata)->toBeArray();
});

test('casts is_active to boolean', function () {
    $template = PromptTemplate::factory()->create(['is_active' => true]);

    expect($template->is_active)->toBeTrue();
});

test('casts is_public to boolean', function () {
    $template = PromptTemplate::factory()->create(['is_public' => false]);

    expect($template->is_public)->toBeFalse();
});

test('has category constants', function () {
    expect(PromptTemplate::CATEGORY_SYSTEM)->toBe('system')
        ->and(PromptTemplate::CATEGORY_TASK)->toBe('task')
        ->and(PromptTemplate::CATEGORY_ANALYSIS)->toBe('analysis')
        ->and(PromptTemplate::CATEGORY_CONTENT)->toBe('content')
        ->and(PromptTemplate::CATEGORY_COMMUNICATION)->toBe('communication')
        ->and(PromptTemplate::CATEGORY_CODE)->toBe('code');
});

test('belongs to agent relationship', function () {
    $template = PromptTemplate::factory()->create();

    expect($template->agent())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to created by user relationship', function () {
    $template = PromptTemplate::factory()->create();

    expect($template->createdBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many versions relationship', function () {
    $template = PromptTemplate::factory()->create();

    expect($template->versions())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many runs relationship', function () {
    $template = PromptTemplate::factory()->create();

    expect($template->runs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('scopeCategory filters by category', function () {
    PromptTemplate::factory()->create(['category' => 'system']);
    PromptTemplate::factory()->create(['category' => 'task']);

    $systemTemplates = PromptTemplate::category('system')->count();

    expect($systemTemplates)->toBe(1);
});

test('scopePublic filters public templates', function () {
    PromptTemplate::factory()->create(['is_public' => true]);
    PromptTemplate::factory()->create(['is_public' => false]);

    $publicTemplates = PromptTemplate::public()->count();

    expect($publicTemplates)->toBe(1);
});

test('can be created via factory', function () {
    $template = PromptTemplate::factory()->create();

    expect($template)->toBeInstanceOf(PromptTemplate::class)
        ->and($template->exists)->toBeTrue();
});
