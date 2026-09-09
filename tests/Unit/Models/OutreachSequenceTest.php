<?php

use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\Prospect;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new OutreachSequence)->getGuarded())->toBe([]);
});

test('casts send_days to array', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'send_days' => ['monday', 'wednesday', 'friday'],
    ]);

    expect($sequence->send_days)->toBeArray()
        ->and($sequence->send_days)->toBe(['monday', 'wednesday', 'friday']);
});

test('casts requires_approval to boolean', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'requires_approval' => true,
    ]);

    expect($sequence->requires_approval)->toBeTrue();
});

test('casts is_active to boolean', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'is_active' => false,
    ]);

    expect($sequence->is_active)->toBeFalse();
});

test('belongs to campaign relationship', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
    ]);

    expect($sequence->campaign())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many messages relationship', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
    ]);

    expect($sequence->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('getNextStep returns next active sequence in campaign', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $step1 = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Step 1',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'is_active' => true,
    ]);

    $step2 = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
        'channel' => 'email',
        'subject_template' => 'Step 2',
        'body_template' => 'Test body',
        'delay_days' => 3,
        'is_active' => true,
    ]);

    $nextStep = $step1->getNextStep();

    expect($nextStep->id)->toBe($step2->id);
});

test('getNextStep returns null if no next step', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $lastStep = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 3,
        'channel' => 'email',
        'subject_template' => 'Last Step',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'is_active' => true,
    ]);

    $nextStep = $lastStep->getNextStep();

    expect($nextStep)->toBeNull();
});

test('shouldTriggerFor returns true for always condition', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'condition' => OutreachSequence::CONDITION_ALWAYS,
    ]);

    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);
    $previousMessage = OutreachMessage::create([
        'sequence_id' => $campaign->sequences()->first()->id ?? 1,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Previous',
        'body' => 'Test',
        'status' => 'sent',
    ]);

    expect($sequence->shouldTriggerFor($previousMessage))->toBeTrue();
});

test('shouldTriggerFor returns true for no_reply when no reply', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'condition' => OutreachSequence::CONDITION_NO_REPLY,
    ]);

    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);
    $step1 = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
    ]);

    $previousMessage = OutreachMessage::create([
        'sequence_id' => $step1->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Previous',
        'body' => 'Test',
        'status' => 'sent',
        'replied_at' => null,
    ]);

    expect($sequence->shouldTriggerFor($previousMessage))->toBeTrue();
});

test('shouldTriggerFor returns false for no_reply when replied', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
        'condition' => OutreachSequence::CONDITION_NO_REPLY,
    ]);

    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);
    $step1 = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
    ]);

    $previousMessage = OutreachMessage::create([
        'sequence_id' => $step1->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Previous',
        'body' => 'Test',
        'status' => 'replied',
        'replied_at' => now(),
    ]);

    expect($sequence->shouldTriggerFor($previousMessage))->toBeFalse();
});

test('getNextSendTime calculates correct time with delay', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 2,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 3,
        'send_time' => '09:00',
    ]);

    $baseTime = Carbon::parse('2025-01-06 14:30:00');
    $nextTime = $sequence->getNextSendTime($baseTime);

    expect($nextTime->toDateString())->toBe('2025-01-09')
        ->and($nextTime->format('H:i'))->toBe('09:00');
});

test('getNextSendTime respects send_days restriction', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 1,
        'send_time' => '09:00',
        'send_days' => ['Monday', 'Wednesday', 'Friday'],
    ]);

    // Start on Friday (2025-01-03), delay 1 day would be Saturday
    // Should skip to Monday
    $baseTime = Carbon::parse('2025-01-03 14:30:00');
    $nextTime = $sequence->getNextSendTime($baseTime);

    expect($nextTime->format('l'))->toBeIn(['Monday', 'Wednesday', 'Friday']);
});

test('personalizeFor replaces placeholders with prospect data', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Hi {{first_name}} from {{company_name}}',
        'body_template' => 'Dear {{contact_name}}, I noticed your company in {{industry}}...',
        'delay_days' => 0,
    ]);

    $prospect = Prospect::create([
        'company_name' => 'Acme Corp',
        'contact_name' => 'John Doe',
        'contact_title' => 'CEO',
        'industry' => 'Technology',
        'status' => 'new',
    ]);

    $result = $sequence->personalizeFor($prospect);

    expect($result['subject'])->toBe('Hi John from Acme Corp')
        ->and($result['body'])->toContain('Dear John Doe')
        ->and($result['body'])->toContain('Technology')
        ->and($result['context'])->toBeArray()
        ->and($result['context']['company_name'])->toBe('Acme Corp');
});

test('personalizeFor handles missing data gracefully', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Hi {{first_name}}',
        'body_template' => 'Dear {{contact_name}}',
        'delay_days' => 0,
    ]);

    $prospect = Prospect::create([
        'company_name' => 'Acme Corp',
        'status' => 'new',
    ]);

    $result = $sequence->personalizeFor($prospect);

    expect($result['subject'])->toBe('Hi ')
        ->and($result['body'])->toBe('Dear ');
});

test('can be created directly', function () {
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    $sequence = OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test',
        'body_template' => 'Test body',
        'delay_days' => 0,
    ]);

    expect($sequence)->toBeInstanceOf(OutreachSequence::class)
        ->and($sequence->exists)->toBeTrue();
});
