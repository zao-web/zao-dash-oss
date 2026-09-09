<?php

use App\Jobs\SyncGoogleDriveJob;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Google\DriveService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncGoogleDriveJob::dispatch();

    Queue::assertPushed(SyncGoogleDriveJob::class);
});

test('job can be dispatched for specific user', function () {
    Queue::fake();

    SyncGoogleDriveJob::dispatch(userId: 123);

    Queue::assertPushed(SyncGoogleDriveJob::class, function ($job) {
        return $job->userId === 123;
    });
});

test('handle syncs all users with credentials', function () {
    $user1 = User::factory()->hasGoogleCredential()->create();
    $user2 = User::factory()->hasGoogleCredential()->create();

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')
        ->twice()
        ->andReturn([]);
    $driveService->shouldReceive('indexDocument')->never();

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);
});

test('handle syncs specific user when userId provided', function () {
    $targetUser = User::factory()->hasGoogleCredential()->create();
    $otherUser = User::factory()->hasGoogleCredential()->create();

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')
        ->once()
        ->with(Mockery::on(fn ($user) => $user->id === $targetUser->id))
        ->andReturn([]);

    $job = new SyncGoogleDriveJob(userId: $targetUser->id);
    $job->handle($driveService);
});

test('handle discovers and indexes documents', function () {
    $user = User::factory()->hasGoogleCredential()->create();

    $files = [
        ['id' => '1', 'name' => 'Doc 1'],
        ['id' => '2', 'name' => 'Doc 2'],
    ];

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')
        ->once()
        ->andReturn($files);
    $driveService->shouldReceive('indexDocument')
        ->twice();

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);
});

test('handle logs sync progress', function () {
    Log::spy();

    $user = User::factory()->hasGoogleCredential()->create();

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')
        ->andReturn([['id' => '1']]);
    $driveService->shouldReceive('indexDocument')->once();

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    Log::shouldHaveReceived('info')
        ->with('Syncing Google Drive for user', Mockery::any());
    Log::shouldHaveReceived('info')
        ->with('Google Drive sync complete', Mockery::any());
});

test('handle catches and logs errors for individual users', function () {
    Log::spy();

    $user1 = User::factory()->hasGoogleCredential()->create();
    $user2 = User::factory()->hasGoogleCredential()->create();

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')
        ->twice()
        ->andReturnUsing(function ($user) use ($user1) {
            if ($user->id === $user1->id) {
                throw new Exception('API error');
            }

            return [];
        });

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    Log::shouldHaveReceived('error')
        ->with('Google Drive sync failed for user', Mockery::any());
});

test('handle auto-links documents to clients', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);

    $doc = Document::factory()->create([
        'client_id' => null,
        'extracted_client_name' => 'Acme',
    ]);

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')->andReturn([]);

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    $doc->refresh();
    expect($doc->client_id)->toBe($client->id);
});

test('handle uses fuzzy matching for client linking', function () {
    $client = Client::factory()->create(['name' => 'Acme Corporation']);

    $doc = Document::factory()->create([
        'client_id' => null,
        'extracted_client_name' => 'Acme',
    ]);

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')->andReturn([]);

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    $doc->refresh();
    expect($doc->client_id)->toBe($client->id);
});

test('handle only links unlinked documents', function () {
    $client1 = Client::factory()->create(['name' => 'Acme Corp']);
    $client2 = Client::factory()->create(['name' => 'Test Inc']);

    // Already linked - should not change
    $linkedDoc = Document::factory()->create([
        'client_id' => $client1->id,
        'extracted_client_name' => 'Test',
    ]);

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')->andReturn([]);

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    $linkedDoc->refresh();
    expect($linkedDoc->client_id)->toBe($client1->id); // Unchanged
});

test('handle logs auto-linking', function () {
    Log::spy();

    $client = Client::factory()->create(['name' => 'Test Corp']);
    $doc = Document::factory()->create([
        'client_id' => null,
        'extracted_client_name' => 'Test',
    ]);

    $driveService = Mockery::mock(DriveService::class);
    $driveService->shouldReceive('discoverDocuments')->andReturn([]);

    $job = new SyncGoogleDriveJob;
    $job->handle($driveService);

    Log::shouldHaveReceived('info')
        ->with('Auto-linked document to client', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncGoogleDriveJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncGoogleDriveJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
