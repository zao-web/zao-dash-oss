<?php

use App\Http\Middleware\MaskDemoData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('web')->get('/__demo_test', fn () => Inertia::render('DemoTest', [
        'client' => [
            'id' => 1,
            'slug' => 'sensitive-client',
            'name' => 'Sensitive Client Co',
            'mrr' => 9999,
            'status' => 'active',
        ],
    ]));

    Route::middleware('web')->post('/__demo_write', fn () => response('written', 200));
});

function inertiaProps($test, string $uri): array
{
    $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    $response = $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
    ])->get($uri);

    return $response->json('props') ?? [];
}

it('does not mask when demo mode is off', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $props = inertiaProps($this->actingAs($owner), '/__demo_test');

    expect($props['client']['name'])->toBe('Sensitive Client Co')
        ->and($props['client']['mrr'])->toBe(9999);
});

it('masks every prop when demo mode is on', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $props = inertiaProps(
        $this->actingAs($owner)->withSession([
            MaskDemoData::SESSION_KEY => true,
            MaskDemoData::SEED_KEY => 'fixed-seed',
        ]),
        '/__demo_test',
    );

    expect($props['client']['name'])->not->toBe('Sensitive Client Co')
        ->and($props['client']['mrr'])->not->toBe(9999)
        ->and($props['client']['id'])->toBe(1)
        ->and($props['client']['slug'])->toBe('sensitive-client')
        ->and($props['client']['status'])->toBe('active');
});

it('also masks shared auth props in demo mode', function () {
    $owner = User::factory()->create(['role' => 'owner', 'name' => 'Justin Real', 'email' => 'justin@real.test']);

    $props = inertiaProps(
        $this->actingAs($owner)->withSession([
            MaskDemoData::SESSION_KEY => true,
            MaskDemoData::SEED_KEY => 'fixed-seed',
        ]),
        '/__demo_test',
    );

    expect($props['auth']['user']['name'])->not->toBe('Justin Real')
        ->and($props['auth']['user']['email'])->not->toBe('justin@real.test')
        ->and($props['auth']['user']['id'])->toBe($owner->id)
        ->and($props['auth']['user']['role'])->toBe('owner')
        ->and($props['demoMode'])->toBeTrue();
});

it('never masks for non-owners even if the flag is set', function () {
    $staff = User::factory()->create(['role' => 'staff']);

    $props = inertiaProps(
        $this->actingAs($staff)->withSession([MaskDemoData::SESSION_KEY => true]),
        '/__demo_test',
    );

    expect($props['client']['name'])->toBe('Sensitive Client Co')
        ->and($props['demoMode'])->toBeFalse();
});

it('lets an owner toggle demo mode on and off', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)->post('/demo-mode/toggle')->assertRedirect();
    expect(session(MaskDemoData::SESSION_KEY))->toBeTrue()
        ->and(session(MaskDemoData::SEED_KEY))->toBeString();

    $this->actingAs($owner)->post('/demo-mode/toggle')->assertRedirect();
    expect(session(MaskDemoData::SESSION_KEY))->toBeFalse();
});

it('forbids non-owners from toggling demo mode', function () {
    $staff = User::factory()->create(['role' => 'staff']);

    $this->actingAs($staff)->post('/demo-mode/toggle')->assertForbidden();
    expect(session(MaskDemoData::SESSION_KEY))->toBeNull();
});

it('blocks writes while demo mode is on to protect production data', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($owner)
        ->withSession([MaskDemoData::SESSION_KEY => true])
        ->from('/__demo_test')
        ->post('/__demo_write');

    $response->assertRedirect('/__demo_test');
    $response->assertSessionHas('error');
});

it('allows writes when demo mode is off', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->post('/__demo_write')
        ->assertOk()
        ->assertSee('written');
});

it('still lets the owner turn demo mode off and sign out while it is on', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->withSession([MaskDemoData::SESSION_KEY => true])
        ->post('/demo-mode/toggle')
        ->assertRedirect();

    expect(session(MaskDemoData::SESSION_KEY))->toBeFalse();
});

it('does not block reads while demo mode is on', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->withSession([MaskDemoData::SESSION_KEY => true])
        ->get('/__demo_test')
        ->assertOk();
});
