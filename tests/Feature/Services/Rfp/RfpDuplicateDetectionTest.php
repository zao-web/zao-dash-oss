<?php

use App\Models\RfpOpportunity;
use App\Models\User;
use App\Services\Rfp\RfpDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
    $this->discoveryService = app(RfpDiscoveryService::class);
});

test('detects exact title and organization match', function () {
    RfpOpportunity::factory()->create([
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
    ]);

    expect($this->discoveryService->isDuplicate(
        'City of Portland Website Redesign',
        'City of Portland'
    ))->toBeTrue();
});

test('detects case-insensitive exact match', function () {
    RfpOpportunity::factory()->create([
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
    ]);

    expect($this->discoveryService->isDuplicate(
        'city of portland website redesign',
        'CITY OF PORTLAND'
    ))->toBeTrue();
});

test('detects duplicate by source URL', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Something Totally Different',
        'issuing_organization' => 'Different Org',
        'source_url' => 'https://bids.example.com/rfp/12345',
    ]);

    expect($this->discoveryService->isDuplicate(
        'Portland Web Project',
        'City of Portland',
        'https://bids.example.com/rfp/12345'
    ))->toBeTrue();
});

test('detects fuzzy title match with word overlap', function () {
    RfpOpportunity::factory()->create([
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
    ]);

    // Same org, slightly different title wording
    expect($this->discoveryService->isDuplicate(
        'Portland Website Redesign RFP',
        'City of Portland'
    ))->toBeTrue();
});

test('allows different projects from the same organization', function () {
    RfpOpportunity::factory()->create([
        'title' => 'City of Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
    ]);

    // Same org but completely different project
    expect($this->discoveryService->isDuplicate(
        'Mobile App Development Project',
        'City of Portland'
    ))->toBeFalse();
});

test('allows same title from different organizations', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Website Redesign Project',
        'issuing_organization' => 'City of Portland',
    ]);

    expect($this->discoveryService->isDuplicate(
        'Website Redesign Project',
        'County of Denver'
    ))->toBeFalse();
});

test('manual RFP creation rejects duplicates', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Visit Traverse City Website Redesign',
        'issuing_organization' => 'Traverse City Tourism',
    ]);

    $response = $this->post('/rfp', [
        'title' => 'Visit Traverse City Website Redesign',
        'issuing_organization' => 'Traverse City Tourism',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    // Should not have created a second one
    expect(RfpOpportunity::where('issuing_organization', 'Traverse City Tourism')->count())->toBe(1);
});

test('manual RFP creation allows non-duplicates', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Visit Traverse City Website Redesign',
        'issuing_organization' => 'Traverse City Tourism',
    ]);

    $response = $this->post('/rfp', [
        'title' => 'Brand New Mobile App',
        'issuing_organization' => 'Completely Different Org',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('does not flag soft-deleted records as duplicates', function () {
    $rfp = RfpOpportunity::factory()->create([
        'title' => 'Archived Website Project',
        'issuing_organization' => 'City of Portland',
    ]);

    $rfp->delete(); // soft delete

    expect($this->discoveryService->isDuplicate(
        'Archived Website Project',
        'City of Portland'
    ))->toBeFalse();
});
