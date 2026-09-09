<?php

use App\Models\User;
use App\Models\VaultSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create vault secret', function () {
    $response = $this->post(route('vault.store'), [
        'name' => 'API Key',
        'key' => 'test_api_key',
        'value' => 'secret-value-123',
        'type' => 'api_key',
        'service' => 'Stripe',
        'description' => 'Test API key',
        'expires_at' => now()->addDays(30)->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Secret added successfully');

    $this->assertDatabaseHas('vault_secrets', [
        'name' => 'API Key',
        'key' => 'test_api_key',
        'type' => 'api_key',
        'service' => 'Stripe',
        'created_by' => $this->user->id,
    ]);

    // Verify value is encrypted
    $secret = VaultSecret::where('key', 'test_api_key')->first();
    expect(Crypt::decryptString($secret->encrypted_value))->toBe('secret-value-123');
});

test('vault secret creation requires name', function () {
    $response = $this->post(route('vault.store'), [
        'key' => 'test_key',
        'value' => 'secret',
        'type' => 'api_key',
        'service' => 'Test',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('vault secret creation requires unique key', function () {
    VaultSecret::factory()->create(['key' => 'existing_key']);

    $response = $this->post(route('vault.store'), [
        'name' => 'Test Secret',
        'key' => 'existing_key',
        'value' => 'secret',
        'type' => 'api_key',
        'service' => 'Test',
    ]);

    $response->assertSessionHasErrors(['key']);
});

test('vault secret type must be valid', function () {
    $response = $this->post(route('vault.store'), [
        'name' => 'Test Secret',
        'key' => 'test_key',
        'value' => 'secret',
        'type' => 'invalid-type',
        'service' => 'Test',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('can update vault secret', function () {
    $secret = VaultSecret::factory()->create([
        'name' => 'Old Name',
        'type' => 'api_key',
    ]);

    $response = $this->put(route('vault.update', $secret), [
        'name' => 'New Name',
        'type' => 'oauth_token',
        'service' => 'Updated Service',
        'description' => 'Updated description',
        'expires_at' => now()->addDays(60)->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Secret updated successfully');

    $secret->refresh();
    expect($secret->name)->toBe('New Name');
    expect($secret->type)->toBe('oauth_token');
    expect($secret->service)->toBe('Updated Service');
});

test('can delete vault secret', function () {
    $secret = VaultSecret::factory()->create();

    $response = $this->delete(route('vault.destroy', $secret));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Secret deleted successfully');

    $this->assertDatabaseMissing('vault_secrets', [
        'id' => $secret->id,
    ]);
});

test('can rotate vault secret with manual value', function () {
    $secret = VaultSecret::factory()->create([
        'encrypted_value' => Crypt::encryptString('old-value'),
    ]);

    $response = $this->post(route('vault.rotate', $secret), [
        'new_value' => 'new-secret-value',
        'auto_generate' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Secret rotated successfully');

    $secret->refresh();
    expect(Crypt::decryptString($secret->encrypted_value))->toBe('new-secret-value');
    expect($secret->last_rotated)->not->toBeNull();
});

test('can rotate vault secret with auto generation', function () {
    $secret = VaultSecret::factory()->create([
        'encrypted_value' => Crypt::encryptString('old-value'),
    ]);

    $response = $this->post(route('vault.rotate', $secret), [
        'auto_generate' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Secret rotated successfully');

    $secret->refresh();
    $newValue = Crypt::decryptString($secret->encrypted_value);
    expect($newValue)->not->toBe('old-value');
    expect(strlen($newValue))->toBe(32);
});

test('secret rotation requires value when not auto generating', function () {
    $secret = VaultSecret::factory()->create();

    $response = $this->post(route('vault.rotate', $secret), [
        'auto_generate' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['new_value']);
});
