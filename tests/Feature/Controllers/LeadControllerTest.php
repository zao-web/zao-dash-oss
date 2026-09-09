<?php

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create lead', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'contact_email' => 'john@test.com',
        'contact_phone' => '123-456-7890',
        'website' => 'https://test.com',
        'description' => 'Test lead',
        'stage' => 'qualified',
        'source' => 'referral',
        'deal_value' => 50000,
        'probability' => 75,
        'expected_close_date' => now()->addDays(30)->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Lead created successfully.');

    $this->assertDatabaseHas('leads', [
        'company_name' => 'Test Company',
        'contact_email' => 'john@test.com',
        'stage' => 'qualified',
        'deal_value' => 50000,
    ]);
});

test('lead creation requires company name', function () {
    $response = $this->post(route('leads.store'), [
        'contact_name' => 'John Doe',
        'contact_email' => 'john@test.com',
    ]);

    $response->assertSessionHasErrors(['company_name']);
});

test('lead creation requires contact name', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_email' => 'john@test.com',
    ]);

    $response->assertSessionHasErrors(['contact_name']);
});

test('lead creation requires valid email', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'contact_email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors(['contact_email']);
});

test('lead stage must be valid', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'contact_email' => 'john@test.com',
        'stage' => 'invalid-stage',
    ]);

    $response->assertSessionHasErrors(['stage']);
});

test('lead source must be valid', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'contact_email' => 'john@test.com',
        'source' => 'invalid-source',
    ]);

    $response->assertSessionHasErrors(['source']);
});

test('probability must be between 0 and 100', function () {
    $response = $this->post(route('leads.store'), [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'contact_email' => 'john@test.com',
        'probability' => 150,
    ]);

    $response->assertSessionHasErrors(['probability']);
});

test('can update lead', function () {
    $lead = Lead::factory()->create([
        'company_name' => 'Old Company',
        'stage' => 'new',
    ]);

    $response = $this->put(route('leads.update', $lead), [
        'company_name' => 'New Company',
        'contact_name' => 'Jane Doe',
        'contact_email' => 'jane@new.com',
        'stage' => 'proposal',
        'deal_value' => 75000,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Lead updated successfully.');

    $lead->refresh();
    expect($lead->company_name)->toBe('New Company');
    expect($lead->stage)->toBe('proposal');
});

test('can delete lead', function () {
    $lead = Lead::factory()->create();

    $response = $this->delete(route('leads.destroy', $lead));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Lead deleted successfully.');

    $this->assertDatabaseMissing('leads', [
        'id' => $lead->id,
    ]);
});

test('can update lead stage', function () {
    $lead = Lead::factory()->create(['stage' => 'new']);

    $response = $this->put(route('leads.updateStage', $lead), [
        'stage' => 'won',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Lead stage updated successfully.');

    $lead->refresh();
    expect($lead->stage)->toBe('won');
});

test('can update lead stage with position', function () {
    $lead = Lead::factory()->create(['stage' => 'new']);

    $response = $this->put(route('leads.updateStage', $lead), [
        'stage' => 'qualified',
        'position' => 3,
    ]);

    $response->assertRedirect();

    $lead->refresh();
    expect($lead->stage)->toBe('qualified');
    expect($lead->position)->toBe(3);
});

test('can reorder leads', function () {
    $lead1 = Lead::factory()->create();
    $lead2 = Lead::factory()->create();

    $response = $this->post(route('leads.reorder'), [
        'leads' => [
            ['id' => $lead1->id, 'position' => 1, 'stage' => 'qualified'],
            ['id' => $lead2->id, 'position' => 0, 'stage' => 'new'],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Leads reordered successfully.');
});

test('can assign lead to user', function () {
    $lead = Lead::factory()->create(['assigned_to' => null]);
    $assignee = User::factory()->create();

    $response = $this->put(route('leads.assign', $lead), [
        'assigned_to' => $assignee->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Lead assigned successfully.');

    $lead->refresh();
    expect($lead->assigned_to)->toBe($assignee->id);
});
