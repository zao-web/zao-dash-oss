<?php

use App\Models\User;

test('has fillable attributes', function () {
    $fillable = (new User)->getFillable();

    expect($fillable)->toContain('name')
        ->and($fillable)->toContain('email')
        ->and($fillable)->toContain('password')
        ->and($fillable)->toContain('role')
        ->and($fillable)->toContain('phone')
        ->and($fillable)->toContain('title')
        ->and($fillable)->toContain('department')
        ->and($fillable)->toContain('permissions')
        ->and($fillable)->toContain('client_id');
});

test('has hidden attributes', function () {
    $hidden = (new User)->getHidden();

    expect($hidden)->toContain('password')
        ->and($hidden)->toContain('remember_token');
});

test('casts permissions to array', function () {
    $user = User::factory()->create(['permissions' => ['view', 'edit']]);

    expect($user->permissions)->toBeArray()
        ->and($user->permissions)->toBe(['view', 'edit']);
});

test('casts email_verified_at to datetime', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->email_verified_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('has many tasks relationship', function () {
    $user = User::factory()->create();

    expect($user->tasks())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has one google credential relationship', function () {
    $user = User::factory()->create();

    expect($user->googleCredential())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('has one harvest credential relationship', function () {
    $user = User::factory()->create();

    expect($user->harvestCredential())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('has many time entries relationship', function () {
    $user = User::factory()->create();

    expect($user->timeEntries())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has one linkedin credential relationship', function () {
    $user = User::factory()->create();

    expect($user->linkedInCredential())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('has one x credential relationship', function () {
    $user = User::factory()->create();

    expect($user->xCredential())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('belongs to client relationship', function () {
    $user = User::factory()->create();

    expect($user->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isClientUser returns true for client role', function () {
    $user = User::factory()->create(['role' => 'client']);

    expect($user->isClientUser())->toBeTrue();
});

test('isClientUser returns false for non-client role', function () {
    $user = User::factory()->create(['role' => 'admin']);

    expect($user->isClientUser())->toBeFalse();
});

test('isInternalUser returns true for owner role', function () {
    $user = User::factory()->create(['role' => 'owner']);

    expect($user->isInternalUser())->toBeTrue();
});

test('isInternalUser returns true for admin role', function () {
    $user = User::factory()->create(['role' => 'admin']);

    expect($user->isInternalUser())->toBeTrue();
});

test('isInternalUser returns true for staff role', function () {
    $user = User::factory()->create(['role' => 'staff']);

    expect($user->isInternalUser())->toBeTrue();
});

test('isInternalUser returns false for client role', function () {
    $user = User::factory()->create(['role' => 'client']);

    expect($user->isInternalUser())->toBeFalse();
});

test('can be created via factory', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue();
});
