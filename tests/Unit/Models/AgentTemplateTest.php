<?php

use App\Models\AgentTemplate;

test('has guarded attributes empty', function () {
    expect((new AgentTemplate)->getGuarded())->toBe(['*']);
});

test('casts default_tools to array', function () {
    $template = AgentTemplate::factory()->create(['default_tools' => ['tool1', 'tool2']]);

    expect($template->default_tools)->toBeArray()
        ->and($template->default_tools)->toBe(['tool1', 'tool2']);
});

test('casts config_schema to array', function () {
    $template = AgentTemplate::factory()->create(['config_schema' => ['field' => 'type']]);

    expect($template->config_schema)->toBeArray();
});

test('casts default_requires_approval to boolean', function () {
    $template = AgentTemplate::factory()->create(['default_requires_approval' => true]);

    expect($template->default_requires_approval)->toBeTrue();
});

test('casts is_public to boolean', function () {
    $template = AgentTemplate::factory()->create(['is_public' => false]);

    expect($template->is_public)->toBeFalse();
});

test('casts default_budget_usd to decimal', function () {
    $template = AgentTemplate::factory()->create(['default_budget_usd' => 25.50]);

    expect($template->default_budget_usd)->toBeFloat()
        ->and((string) $template->default_budget_usd)->toBe('25.50');
});

test('belongs to created by user relationship', function () {
    $template = AgentTemplate::factory()->create();

    expect($template->createdBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('scopePublic filters public templates', function () {
    AgentTemplate::factory()->create(['is_public' => true]);
    AgentTemplate::factory()->create(['is_public' => false]);

    $publicTemplates = AgentTemplate::public()->count();

    expect($publicTemplates)->toBe(1);
});

test('scopeCategory filters by category', function () {
    AgentTemplate::factory()->create(['category' => 'marketing']);
    AgentTemplate::factory()->create(['category' => 'development']);

    $marketingTemplates = AgentTemplate::category('marketing')->count();

    expect($marketingTemplates)->toBe(1);
});

test('can be created via factory', function () {
    $template = AgentTemplate::factory()->create();

    expect($template)->toBeInstanceOf(AgentTemplate::class)
        ->and($template->exists)->toBeTrue();
});
