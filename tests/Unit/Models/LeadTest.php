<?php

use App\Models\Lead;
use Illuminate\Database\Eloquent\SoftDeletes;

test('uses soft deletes', function () {
    expect(in_array(SoftDeletes::class, class_uses(Lead::class)))->toBeTrue();
});

test('has guarded attributes empty', function () {
    expect((new Lead)->getGuarded())->toBe(['*']);
});

test('casts deal_value to decimal', function () {
    $lead = Lead::factory()->create(['deal_value' => 5000.75]);

    expect($lead->deal_value)->toBeFloat()
        ->and((string) $lead->deal_value)->toBe('5000.75');
});

test('casts expected_close_date to date', function () {
    $lead = Lead::factory()->create(['expected_close_date' => '2025-12-31']);

    expect($lead->expected_close_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts last_contacted_at to datetime', function () {
    $lead = Lead::factory()->create(['last_contacted_at' => now()]);

    expect($lead->last_contacted_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts converted_at to datetime', function () {
    $lead = Lead::factory()->create(['converted_at' => now()]);

    expect($lead->converted_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts tags to array', function () {
    $lead = Lead::factory()->create(['tags' => ['hot', 'enterprise']]);

    expect($lead->tags)->toBeArray()
        ->and($lead->tags)->toBe(['hot', 'enterprise']);
});

test('belongs to assignee relationship', function () {
    $lead = Lead::factory()->create();

    expect($lead->assignee())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to converted client relationship', function () {
    $lead = Lead::factory()->create();

    expect($lead->convertedClient())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be soft deleted', function () {
    $lead = Lead::factory()->create();
    $lead->delete();

    expect($lead->trashed())->toBeTrue()
        ->and(Lead::withTrashed()->find($lead->id))->not->toBeNull();
});

test('can be created via factory', function () {
    $lead = Lead::factory()->create();

    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($lead->exists)->toBeTrue();
});
