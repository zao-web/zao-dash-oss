<?php

use App\Models\IdealCustomerProfile;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list prospects', function () {
    Prospect::factory()->count(3)->create();

    $response = $this->get(route('prospects.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 3)
        ->has('stats')
        ->has('icps')
    );
});

test('can filter prospects by status', function () {
    Prospect::factory()->create(['status' => 'qualified']);
    Prospect::factory()->create(['status' => 'new']);
    Prospect::factory()->create(['status' => 'qualified']);

    $response = $this->get(route('prospects.index', ['status' => 'qualified']));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 2)
    );
});

test('can filter prospects by ICP', function () {
    $icp = IdealCustomerProfile::factory()->create();
    Prospect::factory()->create(['icp_id' => $icp->id]);
    Prospect::factory()->create(['icp_id' => null]);

    $response = $this->get(route('prospects.index', ['icp_id' => $icp->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 1)
    );
});

test('can filter prospects by minimum ICP score', function () {
    Prospect::factory()->create(['icp_score' => 80]);
    Prospect::factory()->create(['icp_score' => 50]);
    Prospect::factory()->create(['icp_score' => 90]);

    $response = $this->get(route('prospects.index', ['min_score' => 75]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 2)
    );
});

test('can search prospects by company name', function () {
    Prospect::factory()->create(['company_name' => 'Acme Corporation']);
    Prospect::factory()->create(['company_name' => 'TechCo']);
    Prospect::factory()->create(['company_name' => 'Acme Solutions']);

    $response = $this->get(route('prospects.index', ['search' => 'Acme']));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 2)
    );
});

test('can search prospects by contact email', function () {
    Prospect::factory()->create(['contact_email' => 'john@acme.com']);
    Prospect::factory()->create(['contact_email' => 'jane@techco.com']);

    $response = $this->get(route('prospects.index', ['search' => 'john@acme']));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->has('prospects.data', 1)
    );
});

test('prospects are ordered by ICP score descending', function () {
    $lowScore = Prospect::factory()->create(['icp_score' => 50]);
    $highScore = Prospect::factory()->create(['icp_score' => 90]);
    $midScore = Prospect::factory()->create(['icp_score' => 70]);

    $response = $this->get(route('prospects.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->where('prospects.data.0.id', $highScore->id)
        ->where('prospects.data.1.id', $midScore->id)
        ->where('prospects.data.2.id', $lowScore->id)
    );
});

test('can view prospect details', function () {
    $prospect = Prospect::factory()->create([
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
    ]);

    $response = $this->get(route('prospects.show', $prospect));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Show')
        ->has('prospect')
        ->where('prospect.company_name', 'Test Company')
        ->where('prospect.contact_name', 'John Doe')
    );
});

test('can create prospect', function () {
    $response = $this->post(route('prospects.store'), [
        'company_name' => 'New Company',
        'company_website' => 'https://newcompany.com',
        'contact_name' => 'Jane Smith',
        'contact_email' => 'jane@newcompany.com',
        'contact_title' => 'CEO',
        'industry' => 'Technology',
        'company_size' => '50-100',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Prospect created.');

    $this->assertDatabaseHas('prospects', [
        'company_name' => 'New Company',
        'contact_name' => 'Jane Smith',
        'contact_email' => 'jane@newcompany.com',
        'status' => 'new',
        'source' => 'manual',
    ]);
});

test('prospect creation requires company name', function () {
    $response = $this->post(route('prospects.store'), [
        'contact_email' => 'test@example.com',
    ]);

    $response->assertSessionHasErrors(['company_name']);
});

test('prospect website must be valid URL', function () {
    $response = $this->post(route('prospects.store'), [
        'company_name' => 'Test Company',
        'company_website' => 'not-a-url',
    ]);

    $response->assertSessionHasErrors(['company_website']);
});

test('contact email must be valid', function () {
    $response = $this->post(route('prospects.store'), [
        'company_name' => 'Test Company',
        'contact_email' => 'invalid-email',
    ]);

    $response->assertSessionHasErrors(['contact_email']);
});

test('can update prospect', function () {
    $prospect = Prospect::factory()->create([
        'company_name' => 'Old Name',
        'status' => 'new',
    ]);

    $response = $this->put(route('prospects.update', $prospect), [
        'company_name' => 'Updated Name',
        'status' => 'qualified',
        'research_notes' => 'Good fit for our services',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Prospect updated.');

    $prospect->refresh();
    expect($prospect->company_name)->toBe('Updated Name');
    expect($prospect->status)->toBe('qualified');
    expect($prospect->research_notes)->toBe('Good fit for our services');
});

test('prospect status must be valid', function () {
    $prospect = Prospect::factory()->create();

    $response = $this->put(route('prospects.update', $prospect), [
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('can delete prospect', function () {
    $prospect = Prospect::factory()->create();

    $response = $this->delete(route('prospects.destroy', $prospect));

    $response->assertRedirect(route('prospects.index'));
    $response->assertSessionHas('success', 'Prospect deleted.');

    $this->assertDatabaseMissing('prospects', [
        'id' => $prospect->id,
    ]);
});

test('can convert prospect to lead', function () {
    $prospect = Prospect::factory()->create([
        'status' => 'qualified',
        'company_name' => 'Test Company',
    ]);

    $response = $this->post(route('prospects.convert', $prospect), [
        'deal_value' => 50000,
        'notes' => 'High priority lead',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Prospect converted to lead.');

    $prospect->refresh();
    expect($prospect->status)->toBe('converted');
    expect($prospect->converted_at)->not->toBeNull();
    expect($prospect->converted_to_lead_id)->not->toBeNull();

    $this->assertDatabaseHas('leads', [
        'company' => 'Test Company',
    ]);
});

test('cannot convert already converted prospect', function () {
    $prospect = Prospect::factory()->create([
        'status' => 'converted',
        'converted_at' => now(),
    ]);

    $response = $this->post(route('prospects.convert', $prospect), [
        'deal_value' => 50000,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Prospect already converted.');
});

test('deal value must be numeric when converting', function () {
    $prospect = Prospect::factory()->create([
        'status' => 'qualified',
    ]);

    $response = $this->post(route('prospects.convert', $prospect), [
        'deal_value' => 'not-a-number',
    ]);

    $response->assertSessionHasErrors(['deal_value']);
});

test('deal value must be positive when converting', function () {
    $prospect = Prospect::factory()->create([
        'status' => 'qualified',
    ]);

    $response = $this->post(route('prospects.convert', $prospect), [
        'deal_value' => -1000,
    ]);

    $response->assertSessionHasErrors(['deal_value']);
});

test('creating prospect with ICP auto-scores', function () {
    $icp = IdealCustomerProfile::factory()->create([
        'criteria' => [
            'industries' => ['Technology'],
            'company_size' => ['50-100'],
        ],
    ]);

    $response = $this->post(route('prospects.store'), [
        'company_name' => 'Tech Company',
        'industry' => 'Technology',
        'company_size' => '50-100',
        'icp_id' => $icp->id,
    ]);

    $response->assertRedirect();

    $prospect = Prospect::where('company_name', 'Tech Company')->first();
    expect($prospect->icp_score)->toBeGreaterThan(0);
});

test('unauthenticated user cannot view prospects', function () {
    auth()->logout();

    $response = $this->get(route('prospects.index'));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('unauthenticated user cannot create prospect', function () {
    auth()->logout();

    $response = $this->post(route('prospects.store'), [
        'company_name' => 'Test Company',
    ]);

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('index shows prospect stats', function () {
    Prospect::factory()->count(5)->create(['status' => 'new']);
    Prospect::factory()->count(3)->create(['status' => 'qualified']);
    Prospect::factory()->count(2)->create(['status' => 'converted']);

    $response = $this->get(route('prospects.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Prospects/Index')
        ->where('stats.total', 10)
        ->where('stats.new', 5)
        ->where('stats.qualified', 3)
        ->where('stats.converted', 2)
    );
});
