<?php

use App\Models\RfpLearningInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

test('can create a manual feedback insight', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'manual_feedback',
        'title' => 'Client loved our case study section',
        'description' => 'Received verbal feedback from City of Traverse City that our case studies were the strongest part of the proposal.',
        'impact_area' => 'content',
        'confidence' => 0.85,
        'actionable_recommendation' => 'Always include at least 3 relevant case studies in tourism proposals.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $insight = RfpLearningInsight::where('insight_type', 'manual_feedback')->first();
    expect($insight)->not->toBeNull()
        ->and($insight->title)->toBe('Client loved our case study section')
        ->and($insight->impact_area)->toBe('content')
        ->and((float) $insight->confidence)->toBe(0.85)
        ->and($insight->is_active)->toBeTrue()
        ->and($insight->evidence['source'])->toBe('manual_entry');
});

test('can create other insight types manually', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'pricing_insight',
        'title' => 'DMOs expect bundled pricing',
        'description' => 'Multiple DMO prospects have mentioned they prefer bundled pricing over itemized quotes.',
        'impact_area' => 'pricing',
        'confidence' => 0.70,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(RfpLearningInsight::where('insight_type', 'pricing_insight')->count())->toBe(1);
});

test('requires title and description', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'manual_feedback',
        'title' => '',
        'description' => '',
        'impact_area' => 'content',
        'confidence' => 0.80,
    ]);

    $response->assertSessionHasErrors(['title', 'description']);
});

test('validates insight type against allowed values', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'invalid_type',
        'title' => 'Test',
        'description' => 'Test description',
        'impact_area' => 'content',
        'confidence' => 0.80,
    ]);

    $response->assertSessionHasErrors('insight_type');
});

test('validates confidence is between 0 and 1', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'manual_feedback',
        'title' => 'Test',
        'description' => 'Test description',
        'impact_area' => 'content',
        'confidence' => 1.5,
    ]);

    $response->assertSessionHasErrors('confidence');
});

test('rfp index page loads for internal user', function () {
    // Regression guard: routes/web.php line 1364 previously referenced
    // EnsureInternalUser::class without a matching `use` import, causing
    // PHP to resolve it to the string "EnsureInternalUser" and throw
    // BindingResolutionException on every request in that group.
    $response = $this->get('/rfp');

    $response->assertOk();
});

test('actionable recommendation is optional', function () {
    $response = $this->post('/rfp/learning', [
        'insight_type' => 'win_pattern',
        'title' => 'Strong executive summaries correlate with wins',
        'description' => 'Analysis of last 5 wins shows they all had detailed executive summaries.',
        'impact_area' => 'content',
        'confidence' => 0.90,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $insight = RfpLearningInsight::where('title', 'Strong executive summaries correlate with wins')->first();
    expect($insight->actionable_recommendation)->toBeNull();
});
