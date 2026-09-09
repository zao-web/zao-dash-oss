<?php

use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdSet;
use App\Models\Client;
use App\Models\MetaAdAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Storage::fake('public');

    $client = Client::factory()->create();

    $this->metaAdAccount = MetaAdAccount::create([
        'client_id' => $client->id,
        'account_id' => 'act_123456789',
        'name' => 'Test Sandbox Account',
        'access_token' => 'test_token',
        'status' => 'active',
        'currency' => 'USD',
        'timezone' => 'America/Los_Angeles',
        'is_sandbox' => true,
    ]);
});

it('can delete a draft campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'draft',
        'name' => 'Test Draft Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertRedirect('/meta-ads');
    $response->assertSessionHas('success');

    expect(AdCampaign::find($campaign->id))->toBeNull();
});

it('can delete a failed campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'failed',
        'name' => 'Test Failed Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertRedirect('/meta-ads');
    $response->assertSessionHas('success');

    expect(AdCampaign::find($campaign->id))->toBeNull();
});

it('can delete a paused campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'paused',
        'name' => 'Test Paused Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertRedirect('/meta-ads');
    $response->assertSessionHas('success');

    expect(AdCampaign::find($campaign->id))->toBeNull();
});

it('cannot delete an active campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'active',
        'name' => 'Test Active Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertSessionHas('error');

    expect(AdCampaign::find($campaign->id))->not->toBeNull();
});

it('cannot delete a pending approval campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'pending_approval',
        'name' => 'Test Pending Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertSessionHas('error');

    expect(AdCampaign::find($campaign->id))->not->toBeNull();
});

it('deletes associated ad sets and ads via cascade', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'draft',
        'name' => 'Test Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $adSet = AdSet::create([
        'ad_campaign_id' => $campaign->id,
        'name' => 'Test Ad Set',
        'status' => 'draft',
        'daily_budget' => 50.00,
        'optimization_goal' => 'LEAD_GENERATION',
        'targeting' => ['age_min' => 18, 'age_max' => 65],
    ]);

    $creative = AdCreative::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'name' => 'Test Creative',
        'type' => 'image',
        'headline' => 'Test Headline',
        'primary_text' => 'Test primary text',
    ]);

    $ad = Ad::create([
        'ad_set_id' => $adSet->id,
        'ad_creative_id' => $creative->id,
        'name' => 'Test Ad',
        'status' => 'draft',
        'destination_url' => 'https://example.com',
    ]);

    $this->delete("/meta-ads/campaigns/{$campaign->id}");

    expect(AdCampaign::find($campaign->id))->toBeNull()
        ->and(AdSet::find($adSet->id))->toBeNull()
        ->and(Ad::find($ad->id))->toBeNull();
});

it('cleans up S3 images when deleting campaign', function () {
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'draft',
        'name' => 'Test Campaign',
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $adSet = AdSet::create([
        'ad_campaign_id' => $campaign->id,
        'name' => 'Test Ad Set',
        'status' => 'draft',
        'daily_budget' => 50.00,
        'optimization_goal' => 'LEAD_GENERATION',
        'targeting' => ['age_min' => 18, 'age_max' => 65],
    ]);

    // Create a fake image file
    $imagePath = 'meta-ads/test_image.png';
    Storage::disk('public')->put($imagePath, 'fake-image-content');

    $creative = AdCreative::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'name' => 'Test Creative',
        'type' => 'image',
        'headline' => 'Test Headline',
        'primary_text' => 'Test primary text',
        'image_url' => Storage::disk('public')->url($imagePath),
    ]);

    Ad::create([
        'ad_set_id' => $adSet->id,
        'ad_creative_id' => $creative->id,
        'name' => 'Test Ad',
        'status' => 'draft',
        'destination_url' => 'https://example.com',
    ]);

    expect(Storage::disk('public')->exists($imagePath))->toBeTrue();

    $this->delete("/meta-ads/campaigns/{$campaign->id}");

    expect(Storage::disk('public')->exists($imagePath))->toBeFalse();
});

it('includes campaign name in success message', function () {
    $campaignName = 'My Awesome Campaign';
    $campaign = AdCampaign::create([
        'meta_ad_account_id' => $this->metaAdAccount->id,
        'status' => 'draft',
        'name' => $campaignName,
        'objective' => 'OUTCOME_LEADS',
        'daily_budget' => 50.00,
        'start_date' => now(),
        'targeting_config' => ['location' => ['city' => 'Portland', 'radius_miles' => 25]],
    ]);

    $response = $this->delete("/meta-ads/campaigns/{$campaign->id}");

    $response->assertSessionHas('success', fn ($message) => str_contains($message, $campaignName)
    );
});
