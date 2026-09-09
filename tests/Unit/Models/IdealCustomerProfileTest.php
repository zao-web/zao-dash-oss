<?php

use App\Models\IdealCustomerProfile;

test('has guarded attributes empty', function () {
    expect((new IdealCustomerProfile)->getGuarded())->toBe(['*']);
});

test('casts industries to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['industries' => ['tech', 'finance']]);

    expect($icp->industries)->toBeArray();
});

test('casts company_sizes to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['company_sizes' => ['10-50', '51-200']]);

    expect($icp->company_sizes)->toBeArray();
});

test('casts locations to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['locations' => ['US', 'UK']]);

    expect($icp->locations)->toBeArray();
});

test('casts tech_stack to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['tech_stack' => ['Laravel', 'Vue']]);

    expect($icp->tech_stack)->toBeArray();
});

test('casts tools_used to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['tools_used' => ['Slack', 'GitHub']]);

    expect($icp->tools_used)->toBeArray();
});

test('casts buying_signals to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['buying_signals' => ['hiring', 'funding']]);

    expect($icp->buying_signals)->toBeArray();
});

test('casts pain_points to array', function () {
    $icp = IdealCustomerProfile::factory()->create(['pain_points' => ['scalability', 'security']]);

    expect($icp->pain_points)->toBeArray();
});

test('casts avg_deal_value to decimal', function () {
    $icp = IdealCustomerProfile::factory()->create(['avg_deal_value' => 25000.50]);

    expect($icp->avg_deal_value)->toBeFloat();
});

test('casts is_active to boolean', function () {
    $icp = IdealCustomerProfile::factory()->create(['is_active' => true]);

    expect($icp->is_active)->toBeTrue();
});

test('has many prospects relationship', function () {
    $icp = IdealCustomerProfile::factory()->create();

    expect($icp->prospects())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many campaigns relationship', function () {
    $icp = IdealCustomerProfile::factory()->create();

    expect($icp->campaigns())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('scopeActive filters active icps', function () {
    IdealCustomerProfile::factory()->create(['is_active' => true]);
    IdealCustomerProfile::factory()->create(['is_active' => false]);

    $active = IdealCustomerProfile::active()->count();

    expect($active)->toBe(1);
});

test('can be created via factory', function () {
    $icp = IdealCustomerProfile::factory()->create();

    expect($icp)->toBeInstanceOf(IdealCustomerProfile::class)
        ->and($icp->exists)->toBeTrue();
});
