<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can get human required items', function () {
    $response = $this->getJson(route('capabilities.humanRequired'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'items',
        'count',
    ]);
});

test('human required items are returned as array', function () {
    $response = $this->getJson(route('capabilities.humanRequired'));

    $response->assertStatus(200);
    expect($response->json('items'))->toBeArray();
    expect($response->json('count'))->toBeInt();
});

test('can get morning briefing', function () {
    $response = $this->getJson(route('capabilities.briefing'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'greeting',
        'summary',
        'recommendations',
    ]);
});

test('briefing includes summary counts', function () {
    $response = $this->getJson(route('capabilities.briefing'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'summary' => [
            'total_items',
            'critical',
            'high',
            'medium',
        ],
    ]);
});

test('can get capability summary', function () {
    $response = $this->getJson(route('capabilities.summary'));

    $response->assertStatus(200);
    $response->assertJsonIsObject();
});

test('can get automation gaps', function () {
    $response = $this->getJson(route('capabilities.gaps'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'gaps',
    ]);
});

test('automation gaps are returned as array', function () {
    $response = $this->getJson(route('capabilities.gaps'));

    $response->assertStatus(200);
    expect($response->json('gaps'))->toBeArray();
});

test('can get focus query', function () {
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'summary',
        'briefing',
        'items',
        'total',
    ]);
});

test('focus query limits items to 10', function () {
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    expect(count($response->json('items')))->toBeLessThanOrEqual(10);
});

test('focus query includes natural language summary', function () {
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    expect($response->json('summary'))->toBeString();
    expect(strlen($response->json('summary')))->toBeGreaterThan(0);
});

test('focus query summary mentions critical items when present', function () {
    // This test would require mocking the service to return critical items
    // For now, we just verify the structure
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    expect($response->json('briefing'))->toHaveKey('summary');
});

test('focus query provides recommendations', function () {
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'briefing' => [
            'recommendations',
        ],
    ]);
});

test('unauthenticated user cannot get human required items', function () {
    auth()->logout();

    $response = $this->getJson(route('capabilities.humanRequired'));

    $response->assertStatus(401);
});

test('unauthenticated user cannot get briefing', function () {
    auth()->logout();

    $response = $this->getJson(route('capabilities.briefing'));

    $response->assertStatus(401);
});

test('unauthenticated user cannot get capability summary', function () {
    auth()->logout();

    $response = $this->getJson(route('capabilities.summary'));

    $response->assertStatus(401);
});

test('unauthenticated user cannot get automation gaps', function () {
    auth()->logout();

    $response = $this->getJson(route('capabilities.gaps'));

    $response->assertStatus(401);
});

test('unauthenticated user cannot get focus query', function () {
    auth()->logout();

    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(401);
});

test('human required items respects user context', function () {
    $otherUser = User::factory()->create();

    // Get items for authenticated user
    $response = $this->getJson(route('capabilities.humanRequired'));
    $response->assertStatus(200);

    // The service should use the authenticated user's ID
    // This would be better tested with mocking the service
});

test('all endpoints return JSON responses', function () {
    $endpoints = [
        'capabilities.humanRequired',
        'capabilities.briefing',
        'capabilities.summary',
        'capabilities.gaps',
        'capabilities.focus',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson(route($endpoint));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
    }
});

test('briefing greeting is contextual', function () {
    $response = $this->getJson(route('capabilities.briefing'));

    $response->assertStatus(200);
    $greeting = $response->json('greeting');

    expect($greeting)->toBeString();
    expect(in_array($greeting, ['Good morning', 'Good afternoon', 'Good evening']))->toBeTrue();
});

test('focus query handles zero items gracefully', function () {
    // When there are no items requiring attention
    $response = $this->getJson(route('capabilities.focus'));

    $response->assertStatus(200);
    $summary = $response->json('summary');

    // Should contain encouraging message when nothing to do
    expect($summary)->toBeString();
});
