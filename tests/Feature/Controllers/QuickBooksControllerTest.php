<?php

use App\Models\QuickBooksConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('redirect initiates oauth flow', function () {
    $response = $this->get(route('quickbooks.redirect'));

    $response->assertRedirect();
    expect(session()->has('qbo_oauth_state'))->toBeTrue();
});

test('callback with invalid state fails', function () {
    $response = $this->get(route('quickbooks.callback', [
        'code' => 'test-code',
        'realmId' => 'realm-123',
        'state' => 'invalid-state',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
});

test('callback requires code and realm id', function () {
    session(['qbo_oauth_state' => 'test-state']);

    $response = $this->get(route('quickbooks.callback', [
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
});

test('can disconnect quickbooks', function () {
    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->delete(route('quickbooks.disconnect', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('quickbooks_connections', [
        'id' => $connection->id,
    ]);
});

test('sync triggers data sync', function () {
    Http::fake([
        'quickbooks.api.intuit.com/*' => Http::response(['QueryResponse' => []]),
    ]);

    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('quickbooks.sync', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('sync handles errors gracefully', function () {
    Http::fake([
        'quickbooks.api.intuit.com/*' => Http::response([], 500),
    ]);

    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('quickbooks.sync', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('snapshot requires period dates', function () {
    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('quickbooks.snapshot', $connection), []);

    $response->assertSessionHasErrors(['period_start', 'period_end']);
});

test('snapshot end date must be after start date', function () {
    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('quickbooks.snapshot', $connection), [
        'period_start' => '2024-02-01',
        'period_end' => '2024-01-01',
    ]);

    $response->assertSessionHasErrors(['period_end']);
});

test('can create financial snapshot', function () {
    Http::fake([
        'quickbooks.api.intuit.com/*' => Http::response([
            'Rows' => [
                'Row' => [
                    [
                        'Header' => ['ColData' => [['value' => 'Income']]],
                        'Summary' => ['ColData' => [[], ['value' => '50000']]],
                    ],
                    [
                        'Header' => ['ColData' => [['value' => 'Expenses']]],
                        'Summary' => ['ColData' => [[], ['value' => '30000']]],
                    ],
                ],
            ],
        ]),
    ]);

    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('quickbooks.snapshot', $connection), [
        'period_start' => '2024-01-01',
        'period_end' => '2024-01-31',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('reports endpoint returns snapshots and invoices', function () {
    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->get(route('quickbooks.reports', $connection));

    $response->assertOk();
    $response->assertJsonStructure([
        'snapshots',
        'invoices',
        'recent_transactions',
    ]);
});

test('cashFlow requires date range', function () {
    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->get(route('quickbooks.cashFlow', $connection));

    $response->assertSessionHasErrors(['start_date', 'end_date']);
});

test('cashFlow returns report data', function () {
    Http::fake([
        'quickbooks.api.intuit.com/*' => Http::response(['Rows' => []]),
    ]);

    $connection = QuickBooksConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->get(route('quickbooks.cashFlow', $connection), [
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    $response->assertOk();
});

test('quickbooks requires authentication', function () {
    auth()->logout();

    $response = $this->get(route('quickbooks.redirect'));

    $response->assertRedirect(route('login'));
});
