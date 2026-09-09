<?php

use App\Models\IdealCustomerProfile;
use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list outreach campaigns', function () {
    OutreachCampaign::factory()->count(3)->create();

    $response = $this->get(route('campaigns.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Index')
        ->has('campaigns', 3)
        ->has('stats')
        ->has('icps')
    );
});

test('index shows campaign stats', function () {
    OutreachCampaign::factory()->count(5)->create(['status' => 'active']);
    OutreachCampaign::factory()->count(2)->create(['status' => 'draft']);

    $response = $this->get(route('campaigns.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Index')
        ->where('stats.total', 7)
        ->where('stats.active', 5)
    );
});

test('can view campaign details', function () {
    $campaign = OutreachCampaign::factory()->create([
        'name' => 'Test Campaign',
        'description' => 'Test Description',
    ]);

    OutreachSequence::factory()->count(3)->create([
        'campaign_id' => $campaign->id,
    ]);

    $response = $this->get(route('campaigns.show', $campaign));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Show')
        ->has('campaign')
        ->where('campaign.name', 'Test Campaign')
        ->has('sequences', 3)
        ->has('metrics')
    );
});

test('campaign show includes metrics', function () {
    $campaign = OutreachCampaign::factory()->create([
        'metrics' => ['enrolled' => 50, 'converted' => 5],
    ]);

    $sequence = OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
    ]);

    OutreachMessage::factory()->count(10)->create([
        'sequence_id' => $sequence->id,
        'status' => 'sent',
    ]);

    OutreachMessage::factory()->count(3)->create([
        'sequence_id' => $sequence->id,
        'status' => 'sent',
        'opened_at' => now(),
    ]);

    $response = $this->get(route('campaigns.show', $campaign));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Show')
        ->where('metrics.enrolled', 50)
        ->where('metrics.sent', 13)
        ->where('metrics.opened', 3)
        ->where('metrics.converted', 5)
    );
});

test('can create campaign', function () {
    $icp = IdealCustomerProfile::factory()->create();

    $response = $this->post(route('campaigns.store'), [
        'name' => 'New Campaign',
        'description' => 'Campaign description',
        'type' => 'cold_outreach',
        'icp_id' => $icp->id,
        'min_icp_score' => 75,
        'target_industries' => ['Technology', 'SaaS'],
        'target_titles' => ['CTO', 'VP Engineering'],
        'use_email' => true,
        'use_linkedin' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Campaign created.');

    $this->assertDatabaseHas('outreach_campaigns', [
        'name' => 'New Campaign',
        'type' => 'cold_outreach',
        'icp_id' => $icp->id,
        'min_icp_score' => 75,
        'status' => 'draft',
    ]);
});

test('campaign creation requires name', function () {
    $response = $this->post(route('campaigns.store'), [
        'type' => 'cold_outreach',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('campaign creation requires type', function () {
    $response = $this->post(route('campaigns.store'), [
        'name' => 'Test Campaign',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('campaign type must be valid', function () {
    $response = $this->post(route('campaigns.store'), [
        'name' => 'Test Campaign',
        'type' => 'invalid-type',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('min ICP score must be between 0 and 100', function () {
    $response = $this->post(route('campaigns.store'), [
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'min_icp_score' => 150,
    ]);

    $response->assertSessionHasErrors(['min_icp_score']);
});

test('can update campaign', function () {
    $campaign = OutreachCampaign::factory()->create([
        'name' => 'Old Name',
        'min_icp_score' => 50,
    ]);

    $response = $this->put(route('campaigns.update', $campaign), [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'min_icp_score' => 80,
        'target_industries' => ['FinTech'],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Campaign updated.');

    $campaign->refresh();
    expect($campaign->name)->toBe('Updated Name');
    expect($campaign->min_icp_score)->toBe(80);
    expect($campaign->target_industries)->toBe(['FinTech']);
});

test('can activate campaign with sequences', function () {
    $campaign = OutreachCampaign::factory()->create([
        'status' => 'draft',
    ]);

    OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
    ]);

    $response = $this->post(route('campaigns.activate', $campaign));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Campaign activated.');

    $campaign->refresh();
    expect($campaign->status)->toBe('active');
});

test('cannot activate campaign without sequences', function () {
    $campaign = OutreachCampaign::factory()->create([
        'status' => 'draft',
    ]);

    $response = $this->post(route('campaigns.activate', $campaign));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Add at least one sequence before activating.');

    $campaign->refresh();
    expect($campaign->status)->toBe('draft');
});

test('can pause campaign', function () {
    $campaign = OutreachCampaign::factory()->create([
        'status' => 'active',
    ]);

    $response = $this->post(route('campaigns.pause', $campaign));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Campaign paused.');

    $campaign->refresh();
    expect($campaign->status)->toBe('paused');
});

test('campaigns are ordered by creation date descending', function () {
    $old = OutreachCampaign::factory()->create([
        'created_at' => now()->subDays(5),
    ]);
    $new = OutreachCampaign::factory()->create([
        'created_at' => now(),
    ]);

    $response = $this->get(route('campaigns.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Index')
        ->where('campaigns.0.id', $new->id)
        ->where('campaigns.1.id', $old->id)
    );
});

test('campaign includes ICP relationship', function () {
    $icp = IdealCustomerProfile::factory()->create(['name' => 'Enterprise SaaS']);
    $campaign = OutreachCampaign::factory()->create([
        'icp_id' => $icp->id,
    ]);

    $response = $this->get(route('campaigns.show', $campaign));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Show')
        ->has('campaign.icp')
        ->where('campaign.icp.name', 'Enterprise SaaS')
    );
});

test('campaign show includes recent messages', function () {
    $campaign = OutreachCampaign::factory()->create();
    $sequence = OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
    ]);

    $prospect = Prospect::factory()->create();

    OutreachMessage::factory()->count(5)->create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
    ]);

    $response = $this->get(route('campaigns.show', $campaign));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Show')
        ->has('messages', 5)
    );
});

test('unauthenticated user cannot view campaigns', function () {
    auth()->logout();

    $response = $this->get(route('campaigns.index'));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('unauthenticated user cannot create campaign', function () {
    auth()->logout();

    $response = $this->post(route('campaigns.store'), [
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
    ]);

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('campaign initializes with default metrics', function () {
    $response = $this->post(route('campaigns.store'), [
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'use_email' => true,
        'use_linkedin' => false,
    ]);

    $response->assertRedirect();

    $campaign = OutreachCampaign::where('name', 'Test Campaign')->first();
    expect($campaign->metrics)->toBe(['enrolled' => 0, 'converted' => 0]);
});

test('sequences are ordered by step number', function () {
    $campaign = OutreachCampaign::factory()->create();

    OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
        'step_number' => 3,
    ]);
    OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
    ]);
    OutreachSequence::factory()->create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
    ]);

    $response = $this->get(route('campaigns.show', $campaign));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Campaigns/Show')
        ->where('sequences.0.step_number', 1)
        ->where('sequences.1.step_number', 2)
        ->where('sequences.2.step_number', 3)
    );
});
