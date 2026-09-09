<?php

use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new OutreachCampaign)->getGuarded())->toBe([]);
});

test('casts target_industries to array', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'target_industries' => ['tech', 'finance'],
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->target_industries)->toBeArray()
        ->and($campaign->target_industries)->toBe(['tech', 'finance']);
});

test('casts target_titles to array', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'target_titles' => ['CEO', 'CTO'],
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->target_titles)->toBeArray()
        ->and($campaign->target_titles)->toBe(['CEO', 'CTO']);
});

test('casts use_email to boolean', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'use_email' => true,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->use_email)->toBeTrue();
});

test('casts use_linkedin to boolean', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'use_linkedin' => false,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->use_linkedin)->toBeFalse();
});

test('casts use_phone to boolean', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'use_phone' => true,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->use_phone)->toBeTrue();
});

test('belongs to icp relationship', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->icp())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many sequences relationship', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->sequences())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many through messages relationship', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasManyThrough::class);
});

test('auto generates slug on creation', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->slug)->not->toBeNull()
        ->and($campaign->slug)->toContain('test-campaign');
});

test('uses provided slug if given', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'slug' => 'custom-slug-1234',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign->slug)->toBe('custom-slug-1234');
});

test('active scope returns only active campaigns', function () {
    OutreachCampaign::create([
        'name' => 'Active Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
    ]);

    OutreachCampaign::create([
        'name' => 'Draft Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    $results = OutreachCampaign::active()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe(OutreachCampaign::STATUS_ACTIVE);
});

test('enrollProspect creates message from first sequence', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'enrolled_count' => 0,
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Hello {{first_name}}',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'is_active' => true,
    ]);

    $prospect = Prospect::create([
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'status' => Prospect::STATUS_QUALIFIED,
    ]);

    $message = $campaign->enrollProspect($prospect);

    expect($message)->toBeInstanceOf(OutreachMessage::class)
        ->and($message->prospect_id)->toBe($prospect->id)
        ->and($message->sequence_id)->toBe($sequence->id)
        ->and($campaign->fresh()->enrolled_count)->toBe(1);
});

test('enrollProspect returns null if no active sequence', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
    ]);

    $prospect = Prospect::create([
        'company_name' => 'Test Company',
        'status' => Prospect::STATUS_QUALIFIED,
    ]);

    $message = $campaign->enrollProspect($prospect);

    expect($message)->toBeNull();
});

test('conversion_rate accessor calculates correctly', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'enrolled_count' => 100,
        'converted_count' => 15,
    ]);

    expect($campaign->conversion_rate)->toBe(15.0);
});

test('conversion_rate accessor returns zero for no enrollments', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'enrolled_count' => 0,
        'converted_count' => 0,
    ]);

    expect($campaign->conversion_rate)->toBe(0.0);
});

test('reply_rate accessor calculates correctly', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'sent_count' => 100,
        'replied_count' => 25,
    ]);

    expect($campaign->reply_rate)->toBe(25.0);
});

test('reply_rate accessor returns zero for no sends', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'sent_count' => 0,
        'replied_count' => 0,
    ]);

    expect($campaign->reply_rate)->toBe(0.0);
});

test('open_rate accessor calculates correctly', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'sent_count' => 100,
        'opened_count' => 60,
    ]);

    expect($campaign->open_rate)->toBe(60.0);
});

test('open_rate accessor returns zero for no sends', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_ACTIVE,
        'sent_count' => 0,
        'opened_count' => 0,
    ]);

    expect($campaign->open_rate)->toBe(0.0);
});

test('can be created directly', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => OutreachCampaign::TYPE_COLD_OUTREACH,
        'status' => OutreachCampaign::STATUS_DRAFT,
    ]);

    expect($campaign)->toBeInstanceOf(OutreachCampaign::class)
        ->and($campaign->exists)->toBeTrue();
});
