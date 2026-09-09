<?php

use App\Models\OutreachCampaign;
use App\Models\OutreachSequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->campaign = OutreachCampaign::factory()->create();
});

test('can create sequence step', function () {
    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'channel' => 'email',
        'subject_template' => 'Follow up',
        'body_template' => 'Hello {{name}}',
        'delay_days' => 3,
        'condition' => 'no_reply',
        'requires_approval' => true,
        'is_active' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('outreach_sequences', [
        'outreach_campaign_id' => $this->campaign->id,
        'channel' => 'email',
        'delay_days' => 3,
        'step_number' => 1,
    ]);
});

test('sequence creation requires channel', function () {
    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'body_template' => 'Hello',
        'delay_days' => 3,
        'condition' => 'always',
    ]);

    $response->assertSessionHasErrors(['channel']);
});

test('sequence creation requires valid channel', function () {
    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'channel' => 'invalid-channel',
        'body_template' => 'Hello',
        'delay_days' => 3,
        'condition' => 'always',
    ]);

    $response->assertSessionHasErrors(['channel']);
});

test('sequence creation requires body template', function () {
    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'channel' => 'email',
        'delay_days' => 3,
        'condition' => 'always',
    ]);

    $response->assertSessionHasErrors(['body_template']);
});

test('sequence creation requires valid condition', function () {
    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'channel' => 'email',
        'body_template' => 'Hello',
        'delay_days' => 3,
        'condition' => 'invalid-condition',
    ]);

    $response->assertSessionHasErrors(['condition']);
});

test('sequence step numbers increment', function () {
    OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 1,
    ]);

    $response = $this->post(route('outreach-sequences.store', $this->campaign), [
        'channel' => 'email',
        'body_template' => 'Second step',
        'delay_days' => 5,
        'condition' => 'always',
    ]);

    $this->assertDatabaseHas('outreach_sequences', [
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 2,
    ]);
});

test('can update sequence step', function () {
    $sequence = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'channel' => 'email',
    ]);

    $response = $this->put(route('outreach-sequences.update', [$this->campaign, $sequence]), [
        'channel' => 'linkedin',
        'body_template' => 'Updated template',
        'delay_days' => 7,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $sequence->refresh();
    expect($sequence->channel)->toBe('linkedin');
    expect($sequence->delay_days)->toBe(7);
});

test('can delete sequence step', function () {
    $sequence = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 1,
    ]);

    $response = $this->delete(route('outreach-sequences.destroy', [$this->campaign, $sequence]));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('outreach_sequences', [
        'id' => $sequence->id,
    ]);
});

test('deleting sequence reorders remaining steps', function () {
    $seq1 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 1,
    ]);
    $seq2 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 2,
    ]);
    $seq3 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 3,
    ]);

    $this->delete(route('outreach-sequences.destroy', [$this->campaign, $seq2]));

    $seq3->refresh();
    expect($seq3->step_number)->toBe(2);
});

test('can reorder sequence step up', function () {
    $seq1 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 1,
    ]);
    $seq2 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 2,
    ]);

    $response = $this->post(route('outreach-sequences.reorder', [$this->campaign, $seq2]), [
        'position' => 1,
    ]);

    $response->assertRedirect();

    $seq1->refresh();
    $seq2->refresh();

    expect($seq2->step_number)->toBe(1);
    expect($seq1->step_number)->toBe(2);
});

test('can reorder sequence step down', function () {
    $seq1 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 1,
    ]);
    $seq2 = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
        'step_number' => 2,
    ]);

    $response = $this->post(route('outreach-sequences.reorder', [$this->campaign, $seq1]), [
        'position' => 2,
    ]);

    $response->assertRedirect();

    $seq1->refresh();
    $seq2->refresh();

    expect($seq1->step_number)->toBe(2);
    expect($seq2->step_number)->toBe(1);
});

test('reorder requires position', function () {
    $sequence = OutreachSequence::factory()->create([
        'outreach_campaign_id' => $this->campaign->id,
    ]);

    $response = $this->post(route('outreach-sequences.reorder', [$this->campaign, $sequence]), []);

    $response->assertSessionHasErrors(['position']);
});

test('sequences require authentication', function () {
    auth()->logout();

    $response = $this->post(route('outreach-sequences.store', $this->campaign), []);

    $response->assertRedirect(route('login'));
});
