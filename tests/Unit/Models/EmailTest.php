<?php

use App\Models\Client;
use App\Models\Email;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new Email)->getGuarded())->toBe(['*']);
});

test('casts to_addresses to array', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'to_addresses' => ['recipient1@example.com', 'recipient2@example.com'],
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->to_addresses)->toBeArray()
        ->and($email->to_addresses)->toHaveCount(2);
});

test('casts received_at to datetime', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->received_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts processed_at to datetime', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    expect($email->processed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts sentiment_score to decimal', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'sentiment_score' => 0.85,
    ]);

    expect($email->sentiment_score)->toBeFloat()
        ->and((string) $email->sentiment_score)->toBe('0.85');
});

test('casts is_transcript to boolean', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'is_transcript' => true,
    ]);

    expect($email->is_transcript)->toBeTrue();
});

test('casts is_processed to boolean', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'is_processed' => false,
    ]);

    expect($email->is_processed)->toBeFalse();
});

test('belongs to client relationship', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isFromClient returns true when client_id exists', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'client@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->isFromClient())->toBeTrue();
});

test('isFromClient returns false when client_id is null', function () {
    $email = Email::create([
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->isFromClient())->toBeFalse();
});

test('isGeminiTranscript returns true when is_transcript is true', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'is_transcript' => true,
    ]);

    expect($email->isGeminiTranscript())->toBeTrue();
});

test('isGeminiTranscript returns true for meet recordings email', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'meet-recordings-noreply@google.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email->isGeminiTranscript())->toBeTrue();
});

test('isGeminiTranscript returns true for meeting transcript subject', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Meeting transcript for project review',
        'received_at' => now(),
    ]);

    expect($email->isGeminiTranscript())->toBeTrue();
});

test('isGeminiTranscript returns false for regular email', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Regular Email',
        'received_at' => now(),
    ]);

    expect($email->isGeminiTranscript())->toBeFalse();
});

test('getSentimentBadgeAttribute returns success for positive sentiment', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'sentiment_label' => 'positive',
    ]);

    expect($email->sentiment_badge)->toBe('success');
});

test('getSentimentBadgeAttribute returns danger for negative sentiment', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'sentiment_label' => 'negative',
    ]);

    expect($email->sentiment_badge)->toBe('danger');
});

test('getSentimentBadgeAttribute returns warning for urgent sentiment', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'sentiment_label' => 'urgent',
    ]);

    expect($email->sentiment_badge)->toBe('warning');
});

test('getSentimentBadgeAttribute returns secondary for unknown sentiment', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
        'sentiment_label' => 'neutral',
    ]);

    expect($email->sentiment_badge)->toBe('secondary');
});

test('can be created', function () {
    $client = Client::factory()->create();
    $email = Email::create([
        'client_id' => $client->id,
        'from_address' => 'sender@example.com',
        'subject' => 'Test Email',
        'received_at' => now(),
    ]);

    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->exists)->toBeTrue();
});
