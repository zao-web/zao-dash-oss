<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can get proactive insights', function () {
    $response = $this->getJson(route('insights.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'insights',
        'count',
    ]);
});

test('can limit number of insights returned', function () {
    $response = $this->getJson(route('insights.index', ['limit' => 3]));

    $response->assertStatus(200);
    expect(count($response->json('insights')))->toBeLessThanOrEqual(3);
});

test('unauthenticated user cannot get insights', function () {
    auth()->logout();

    $response = $this->getJson(route('insights.index'));

    $response->assertStatus(401);
});

test('insights are returned as array', function () {
    $response = $this->getJson(route('insights.index'));

    $response->assertStatus(200);
    expect($response->json('insights'))->toBeArray();
    expect($response->json('count'))->toBeInt();
});
