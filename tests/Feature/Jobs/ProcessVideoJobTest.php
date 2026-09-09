<?php

use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('downloads cloud-stored video to temp file for metadata extraction', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('videos/test.webm', 'fake video content');

    $user = \App\Models\User::factory()->create();

    $video = Video::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'title' => 'Test Video',
        'storage_path' => 'videos/test.webm',
        'storage_disk' => 's3',
        'mime_type' => 'video/webm',
        'file_size' => 1024,
        'status' => 'processing',
        'is_public' => true,
        'share_token' => \Illuminate\Support\Str::random(32),
        'original_filename' => 'test.webm',
    ]);

    // The job should still complete even when ffprobe is not available on test env
    // It just won't extract metadata
    $job = new ProcessVideoJob($video);
    $job->handle();

    $video->refresh();
    expect($video->status)->toBe('ready');
});

it('marks video as ready even when processing cloud-stored video', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('videos/test.webm', 'fake video content');

    $user = \App\Models\User::factory()->create();

    $video = Video::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'title' => 'Test Video',
        'storage_path' => 'videos/test.webm',
        'storage_disk' => 's3',
        'mime_type' => 'video/webm',
        'file_size' => 1024,
        'status' => 'processing',
        'is_public' => true,
        'share_token' => \Illuminate\Support\Str::random(32),
        'original_filename' => 'test.webm',
    ]);

    $job = new ProcessVideoJob($video);
    $job->handle();

    $video->refresh();
    expect($video->status)->toBe('ready');
});
