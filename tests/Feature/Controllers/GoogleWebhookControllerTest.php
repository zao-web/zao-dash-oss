<?php

use App\Models\Email;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\CalendarService;
use App\Services\Google\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('gmail webhook rejects request without message', function () {
    $response = $this->postJson('/webhooks/google/gmail', []);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'No message']);
});

test('gmail webhook rejects request with invalid message data', function () {
    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => 'invalid-base64',
        ],
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid data']);
});

test('gmail webhook rejects request missing emailAddress', function () {
    $data = json_encode(['historyId' => '12345']);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Missing data']);
});

test('gmail webhook rejects request missing historyId', function () {
    $data = json_encode(['emailAddress' => 'test@example.com']);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Missing data']);
});

test('gmail webhook returns ok for unknown email address', function () {
    $data = json_encode([
        'emailAddress' => 'unknown@example.com',
        'historyId' => '12345',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('gmail webhook processes new messages', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
        'watch_resource_id' => '100',
    ]);

    $this->mock(GmailService::class)
        ->shouldReceive('getHistory')
        ->once()
        ->with($user, '100')
        ->andReturn([
            'history' => [
                [
                    'messagesAdded' => [
                        ['message' => ['id' => 'msg123']],
                        ['message' => ['id' => 'msg456']],
                    ],
                ],
            ],
        ])
        ->shouldReceive('syncAndStoreEmail')
        ->twice()
        ->andReturn(null);

    $data = json_encode([
        'emailAddress' => 'test@example.com',
        'historyId' => '12345',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);

    $credential->refresh();
    expect($credential->watch_resource_id)->toBe('12345');
});

test('gmail webhook updates history id even without new messages', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
        'watch_resource_id' => '100',
    ]);

    $this->mock(GmailService::class)
        ->shouldReceive('getHistory')
        ->once()
        ->andReturn(['history' => []]);

    $data = json_encode([
        'emailAddress' => 'test@example.com',
        'historyId' => '99999',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);

    $credential->refresh();
    expect($credential->watch_resource_id)->toBe('99999');
});

test('gmail webhook handles processing errors gracefully', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
        'watch_resource_id' => '100',
    ]);

    $this->mock(GmailService::class)
        ->shouldReceive('getHistory')
        ->once()
        ->andThrow(new \Exception('API Error'));

    $data = json_encode([
        'emailAddress' => 'test@example.com',
        'historyId' => '12345',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('calendar webhook ignores sync state', function () {
    $response = $this->postJson('/webhooks/google/calendar', [], [
        'X-Goog-Channel-ID' => 'zao-calendar-1-123',
        'X-Goog-Resource-ID' => 'resource-123',
        'X-Goog-Resource-State' => 'sync',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('calendar webhook processes exists state', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
    ]);

    $this->mock(CalendarService::class)
        ->shouldReceive('syncEvents')
        ->once()
        ->with($user);

    $response = $this->postJson('/webhooks/google/calendar', [], [
        'X-Goog-Channel-ID' => "zao-calendar-{$user->id}-123",
        'X-Goog-Resource-ID' => 'resource-123',
        'X-Goog-Resource-State' => 'exists',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('calendar webhook handles invalid channel id format', function () {
    $response = $this->postJson('/webhooks/google/calendar', [], [
        'X-Goog-Channel-ID' => 'invalid-format',
        'X-Goog-Resource-ID' => 'resource-123',
        'X-Goog-Resource-State' => 'exists',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('calendar webhook handles non-existent user', function () {
    $this->mock(CalendarService::class)
        ->shouldNotReceive('syncEvents');

    $response = $this->postJson('/webhooks/google/calendar', [], [
        'X-Goog-Channel-ID' => 'zao-calendar-99999-123',
        'X-Goog-Resource-ID' => 'resource-123',
        'X-Goog-Resource-State' => 'exists',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('calendar webhook handles sync errors gracefully', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
    ]);

    $this->mock(CalendarService::class)
        ->shouldReceive('syncEvents')
        ->once()
        ->andThrow(new \Exception('Calendar API Error'));

    $response = $this->postJson('/webhooks/google/calendar', [], [
        'X-Goog-Channel-ID' => "zao-calendar-{$user->id}-123",
        'X-Goog-Resource-ID' => 'resource-123',
        'X-Goog-Resource-State' => 'exists',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('gmail webhook processes transcript emails', function () {
    $user = User::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
        'watch_resource_id' => '100',
    ]);

    $email = Email::factory()->create([
        'is_transcript' => true,
        'from_address' => 'noreply@meet.google.com',
        'subject' => 'Meeting Transcript',
    ]);

    $this->mock(GmailService::class)
        ->shouldReceive('getHistory')
        ->once()
        ->andReturn([
            'history' => [
                [
                    'messagesAdded' => [
                        ['message' => ['id' => 'msg123']],
                    ],
                ],
            ],
        ])
        ->shouldReceive('syncAndStoreEmail')
        ->once()
        ->andReturn($email);

    $data = json_encode([
        'emailAddress' => 'test@example.com',
        'historyId' => '12345',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);
});

test('gmail webhook processes client emails', function () {
    $user = User::factory()->create();
    $client = \App\Models\Client::factory()->create();
    $credential = GoogleCredential::factory()->create([
        'user_id' => $user->id,
        'email' => 'test@example.com',
        'watch_resource_id' => '100',
    ]);

    $email = Email::factory()->create([
        'is_transcript' => false,
        'client_id' => $client->id,
        'from_address' => 'client@example.com',
        'subject' => 'Project Update',
    ]);

    $this->mock(GmailService::class)
        ->shouldReceive('getHistory')
        ->once()
        ->andReturn([
            'history' => [
                [
                    'messagesAdded' => [
                        ['message' => ['id' => 'msg123']],
                    ],
                ],
            ],
        ])
        ->shouldReceive('syncAndStoreEmail')
        ->once()
        ->andReturn($email);

    $data = json_encode([
        'emailAddress' => 'test@example.com',
        'historyId' => '12345',
    ]);
    $base64Data = base64_encode($data);

    $response = $this->postJson('/webhooks/google/gmail', [
        'message' => [
            'data' => $base64Data,
        ],
    ]);

    $response->assertStatus(200);
});
