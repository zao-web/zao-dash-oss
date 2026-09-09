<?php

use App\Models\VaultSecret;
use App\Models\VaultSecretValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->secret = VaultSecret::factory()->create([
        'key' => 'test.api.key',
        'name' => 'Test API Key',
    ]);
});

test('vault secret value belongs to vault secret', function () {
    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
    ]);

    expect($value->secret)->toBeInstanceOf(VaultSecret::class)
        ->and($value->secret->id)->toBe($this->secret->id);
});

test('vault secret can have multiple environment values', function () {
    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('prod-value'),
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('staging-value'),
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => null,
        'encrypted_value' => Crypt::encryptString('default-value'),
    ]);

    expect($this->secret->values)->toHaveCount(3);
});

test('vault secret value encrypts and decrypts value correctly', function () {
    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('my-secret-value'),
    ]);

    expect($value->getDecryptedValue())->toBe('my-secret-value');
});

test('vault secret value generates fingerprint on save', function () {
    $value = VaultSecretValue::create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('secret-value'),
    ]);

    expect($value->value_fingerprint)->not->toBeNull()
        ->and(strlen($value->value_fingerprint))->toBe(64);
});

test('vault secret value fingerprint changes when value changes', function () {
    $value = VaultSecretValue::create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('original-value'),
    ]);

    $originalFingerprint = $value->value_fingerprint;

    $value->update([
        'encrypted_value' => Crypt::encryptString('new-value'),
    ]);

    expect($value->value_fingerprint)->not->toBe($originalFingerprint);
});

test('vault secret value unique constraint on secret and environment', function () {
    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
    ]);

    expect(fn () => VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('vault secret value casts is_active to boolean', function () {
    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'is_active' => true,
    ]);

    expect($value->is_active)->toBeTrue()->toBeBool();
});

test('vault secret value casts expires_at to datetime', function () {
    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'expires_at' => now()->addMonth(),
    ]);

    expect($value->expires_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('vault secret value scope for environment', function () {
    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'production',
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
    ]);

    expect(VaultSecretValue::forEnvironment('production')->count())->toBe(1)
        ->and(VaultSecretValue::forEnvironment('staging')->count())->toBe(1);
});

test('vault secret value scope for active only', function () {
    VaultSecretValue::factory()->production()->create([
        'vault_secret_id' => $this->secret->id,
        'is_active' => true,
    ]);

    VaultSecretValue::factory()->staging()->create([
        'vault_secret_id' => $this->secret->id,
        'is_active' => false,
    ]);

    expect(VaultSecretValue::active()->count())->toBe(1);
});

test('vault secret value scope for not expired', function () {
    VaultSecretValue::factory()->production()->create([
        'vault_secret_id' => $this->secret->id,
        'expires_at' => now()->addMonth(),
    ]);

    VaultSecretValue::factory()->staging()->create([
        'vault_secret_id' => $this->secret->id,
        'expires_at' => now()->subDay(),
    ]);

    expect(VaultSecretValue::notExpired()->count())->toBe(1);
});

test('vault secret value is_expired attribute', function () {
    $active = VaultSecretValue::factory()->production()->create([
        'vault_secret_id' => $this->secret->id,
        'expires_at' => now()->addMonth(),
    ]);

    $expired = VaultSecretValue::factory()->staging()->create([
        'vault_secret_id' => $this->secret->id,
        'expires_at' => now()->subDay(),
    ]);

    expect($active->is_expired)->toBeFalse()
        ->and($expired->is_expired)->toBeTrue();
});

test('vault secret has many values relationship', function () {
    VaultSecretValue::factory()->production()->create(['vault_secret_id' => $this->secret->id]);
    VaultSecretValue::factory()->staging()->create(['vault_secret_id' => $this->secret->id]);
    VaultSecretValue::factory()->development()->create(['vault_secret_id' => $this->secret->id]);

    expect($this->secret->values)->toHaveCount(3)
        ->and($this->secret->values->first())->toBeInstanceOf(VaultSecretValue::class);
});
