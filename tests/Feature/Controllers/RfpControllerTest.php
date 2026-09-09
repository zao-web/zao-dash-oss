<?php

use App\Models\RfpOpportunity;
use App\Models\RfpSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

test('index page renders with kanban data', function () {
    RfpOpportunity::factory()->count(3)->create(['status' => 'discovered']);
    RfpOpportunity::factory()->create(['status' => 'qualified', 'fit_score' => 85]);

    $response = $this->get(route('rfp.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Rfp/Index')
        ->has('opportunities')
        ->has('stats')
    );
});

test('store creates a new rfp opportunity', function () {
    $response = $this->post(route('rfp.store'), [
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
        'description' => 'Full redesign of municipal website',
        'source_type' => 'manual',
        'budget_min' => 50000,
        'budget_max' => 100000,
        'priority' => 'high',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('rfp_opportunities', [
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
        'source_type' => 'manual',
        'status' => 'discovered',
    ]);
});

test('store requires title and organization', function () {
    $response = $this->post(route('rfp.store'), [
        'description' => 'Some description',
    ]);

    $response->assertSessionHasErrors(['title', 'issuing_organization']);
});

test('update modifies rfp opportunity', function () {
    $rfp = RfpOpportunity::factory()->create(['title' => 'Old Title']);

    $response = $this->put(route('rfp.update', $rfp), [
        'title' => 'Updated Title',
        'priority' => 'critical',
    ]);

    $response->assertRedirect();

    expect($rfp->fresh()->title)->toBe('Updated Title')
        ->and($rfp->fresh()->priority)->toBe('critical');
});

test('updateStatus changes rfp status', function () {
    $rfp = RfpOpportunity::factory()->create(['status' => 'discovered']);

    $response = $this->put(route('rfp.updateStatus', $rfp), [
        'status' => 'qualified',
    ]);

    $response->assertRedirect();
    expect($rfp->fresh()->status)->toBe('qualified');
});

test('updateStatus validates allowed status values', function () {
    $rfp = RfpOpportunity::factory()->create();

    $response = $this->put(route('rfp.updateStatus', $rfp), [
        'status' => 'invalid_status',
    ]);

    $response->assertSessionHasErrors('status');
});

test('destroy soft deletes rfp opportunity with decline reason', function () {
    $rfp = RfpOpportunity::factory()->create();

    $response = $this->delete(route('rfp.destroy', $rfp), [
        'decline_category' => 'wrong_industry',
        'decline_notes' => 'Not our niche',
    ]);

    $response->assertRedirect();
    $this->assertSoftDeleted('rfp_opportunities', ['id' => $rfp->id]);

    $deleted = RfpOpportunity::withTrashed()->find($rfp->id);
    expect($deleted->status)->toBe('declined')
        ->and($deleted->decline_reason)->toContain('Wrong industry');
});

test('rfp opportunity model has correct relationships', function () {
    $source = RfpSource::factory()->create();
    $rfp = RfpOpportunity::factory()->create([
        'rfp_source_id' => $source->id,
        'assigned_to' => $this->user->id,
    ]);

    expect($rfp->source->id)->toBe($source->id)
        ->and($rfp->assignee->id)->toBe($this->user->id)
        ->and($rfp->proposals)->toBeEmpty()
        ->and($rfp->outcome)->toBeNull();
});

test('rfp opportunity budget range helper works', function () {
    $rfp = RfpOpportunity::factory()->create([
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    expect($rfp->budgetRange())->toContain('50')
        ->and($rfp->budgetRange())->toContain('100');
});

test('rfp opportunity isExpired detects past deadlines', function () {
    $expired = RfpOpportunity::factory()->create([
        'submission_deadline' => now()->subDay(),
    ]);
    $active = RfpOpportunity::factory()->create([
        'submission_deadline' => now()->addWeek(),
    ]);

    expect($expired->isExpired())->toBeTrue()
        ->and($active->isExpired())->toBeFalse();
});

test('rfp source due for check scope works', function () {
    $due = RfpSource::factory()->create([
        'check_frequency_minutes' => 60,
        'last_checked_at' => now()->subHours(2),
        'is_active' => true,
    ]);
    $notDue = RfpSource::factory()->create([
        'check_frequency_minutes' => 60,
        'last_checked_at' => now()->subMinutes(30),
        'is_active' => true,
    ]);

    $dueSources = RfpSource::active()->dueForCheck()->get();

    expect($dueSources)->toHaveCount(1)
        ->and($dueSources->first()->id)->toBe($due->id);
});
