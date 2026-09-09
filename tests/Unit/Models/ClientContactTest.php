<?php

use App\Models\ClientContact;

test('has guarded attributes empty', function () {
    expect((new ClientContact)->getGuarded())->toBe(['*']);
});

test('casts is_primary to boolean', function () {
    $contact = ClientContact::factory()->create(['is_primary' => true]);

    expect($contact->is_primary)->toBeTrue();
});

test('belongs to client relationship', function () {
    $contact = ClientContact::factory()->create();

    expect($contact->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be created via factory', function () {
    $contact = ClientContact::factory()->create();

    expect($contact)->toBeInstanceOf(ClientContact::class)
        ->and($contact->exists)->toBeTrue();
});
