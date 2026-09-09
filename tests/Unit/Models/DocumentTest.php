<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new Document)->getGuarded())->toBe(['*']);
});

test('casts effective_date to date', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'effective_date' => '2025-01-01',
    ]);

    expect($document->effective_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts expiration_date to date', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => '2026-01-01',
    ]);

    expect($document->expiration_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts contract_value to decimal', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'contract',
        'contract_value' => 50000.50,
    ]);

    expect($document->contract_value)->toBeFloat()
        ->and((string) $document->contract_value)->toBe('50000.50');
});

test('casts google_modified_at to datetime', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'google_modified_at' => now(),
    ]);

    expect($document->google_modified_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts indexed_at to datetime', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'indexed_at' => now(),
    ]);

    expect($document->indexed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to client relationship', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
    ]);

    expect($document->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $document = Document::create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'title' => 'Test Document',
        'document_type' => 'sow',
    ]);

    expect($document->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isExpiringSoon returns true when within 30 days', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => now()->addDays(15),
    ]);

    expect($document->isExpiringSoon())->toBeTrue();
});

test('isExpiringSoon returns false when beyond 30 days', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => now()->addDays(60),
    ]);

    expect($document->isExpiringSoon())->toBeFalse();
});

test('isExpiringSoon returns false when no expiration date', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'proposal',
    ]);

    expect($document->isExpiringSoon())->toBeFalse();
});

test('isExpiringSoon returns false when already expired', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => now()->subDay(),
    ]);

    expect($document->isExpiringSoon())->toBeFalse();
});

test('isExpired returns true when past expiration', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => now()->subDay(),
    ]);

    expect($document->isExpired())->toBeTrue();
});

test('isExpired returns false when not expired', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
        'expiration_date' => now()->addDays(30),
    ]);

    expect($document->isExpired())->toBeFalse();
});

test('isExpired returns false when no expiration date', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'proposal',
    ]);

    expect($document->isExpired())->toBeFalse();
});

test('isLinked returns true when client_id exists', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
    ]);

    expect($document->isLinked())->toBeTrue();
});

test('isLinked returns false when client_id is null', function () {
    $document = Document::create([
        'title' => 'Test Document',
        'document_type' => 'proposal',
    ]);

    expect($document->isLinked())->toBeFalse();
});

test('getDocumentTypeLabel returns correct label for msa', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('Master Service Agreement');
});

test('getDocumentTypeLabel returns correct label for sow', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'sow',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('Statement of Work');
});

test('getDocumentTypeLabel returns correct label for proposal', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'proposal',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('Proposal');
});

test('getDocumentTypeLabel returns correct label for contract', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'contract',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('Contract');
});

test('getDocumentTypeLabel returns correct label for nda', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'nda',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('NDA');
});

test('getDocumentTypeLabel returns capitalized type for unknown', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'custom',
    ]);

    expect($document->getDocumentTypeLabel())->toBe('Custom');
});

test('can be created', function () {
    $client = Client::factory()->create();
    $document = Document::create([
        'client_id' => $client->id,
        'title' => 'Test Document',
        'document_type' => 'msa',
    ]);

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->exists)->toBeTrue();
});
