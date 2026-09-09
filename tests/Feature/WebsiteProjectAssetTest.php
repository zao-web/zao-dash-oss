<?php

use App\Models\User;
use App\Models\WebsiteProject;
use App\Models\WebsiteProjectAsset;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('it can upload a file to a website project', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    $service = app(WebsiteProjectAssetService::class);
    $asset = $service->uploadFile($project, $file, 'logo', 'Company logo', $user);

    expect($asset)->toBeInstanceOf(WebsiteProjectAsset::class)
        ->and($asset->website_project_id)->toBe($project->id)
        ->and($asset->uploaded_by_user_id)->toBe($user->id)
        ->and($asset->type)->toBe('image')
        ->and($asset->category)->toBe('logo')
        ->and($asset->description)->toBe('Company logo')
        ->and($asset->status)->toBe('ready')
        ->and($asset->original_filename)->toBe('logo.png');

    Storage::disk('local')->assertExists($asset->path);
});

test('it determines file type correctly', function () {
    expect(WebsiteProjectAsset::determineType('image/png'))->toBe('image')
        ->and(WebsiteProjectAsset::determineType('image/jpeg'))->toBe('image')
        ->and(WebsiteProjectAsset::determineType('application/pdf'))->toBe('document')
        ->and(WebsiteProjectAsset::determineType('video/mp4'))->toBe('video')
        ->and(WebsiteProjectAsset::determineType('audio/mpeg'))->toBe('audio')
        ->and(WebsiteProjectAsset::determineType('application/octet-stream'))->toBe('other');
});

test('it can upload multiple files via api', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);

    $files = [
        UploadedFile::fake()->image('hero.jpg', 1920, 1080),
        UploadedFile::fake()->image('logo.png', 200, 200),
    ];

    $response = $this->actingAs($user)->postJson("/api/website-builder/projects/{$project->id}/assets", [
        'files' => $files,
        'category' => 'hero',
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'total_uploaded' => 2,
            'total_errors' => 0,
        ]);

    expect($project->assets()->count())->toBe(2);
});

test('it prevents unauthorized users from uploading assets', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->image('test.png');

    $response = $this->actingAs($otherUser)->postJson("/api/website-builder/projects/{$project->id}/assets", [
        'files' => [$file],
    ]);

    $response->assertForbidden();
});

test('it can list assets for a project', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $service->uploadFile($project, UploadedFile::fake()->image('img1.png'), 'logo');
    $service->uploadFile($project, UploadedFile::fake()->image('img2.jpg'), 'hero');
    $service->uploadFile($project, UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'), 'brief');

    $response = $this->actingAs($user)->getJson("/api/website-builder/projects/{$project->id}/assets");

    $response->assertSuccessful()
        ->assertJsonPath('assets.total_count', 3)
        ->assertJsonCount(2, 'assets.images')
        ->assertJsonCount(1, 'assets.documents');
});

test('it can delete an asset', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $asset = $service->uploadFile($project, UploadedFile::fake()->image('test.png'));
    $path = $asset->path;

    Storage::disk('local')->assertExists($path);

    $response = $this->actingAs($user)->deleteJson("/api/website-builder/projects/{$project->id}/assets/{$asset->id}");

    $response->assertSuccessful();
    expect(WebsiteProjectAsset::find($asset->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('it can update asset category and description', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $asset = $service->uploadFile($project, UploadedFile::fake()->image('test.png'));

    $response = $this->actingAs($user)->putJson("/api/website-builder/projects/{$project->id}/assets/{$asset->id}", [
        'category' => 'logo',
        'description' => 'Updated description',
    ]);

    $response->assertSuccessful();

    $asset->refresh();
    expect($asset->category)->toBe('logo')
        ->and($asset->description)->toBe('Updated description');
});

test('it extracts image dimensions as metadata', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->image('test.png', 800, 600);

    $service = app(WebsiteProjectAssetService::class);
    $asset = $service->uploadFile($project, $file);

    expect($asset->metadata)->toHaveKey('width')
        ->and($asset->metadata['width'])->toBe(800)
        ->and($asset->metadata['height'])->toBe(600);
});

test('getAssetsForAgent returns properly structured data', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $service->uploadFile($project, UploadedFile::fake()->image('logo.png'), 'logo', 'Company logo');
    $service->uploadFile($project, UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'), 'brief');

    $assets = $service->getAssetsForAgent($project);

    expect($assets)->toHaveKeys(['images', 'documents', 'videos', 'audio', 'other', 'by_category', 'total_count', 'total_size'])
        ->and($assets['total_count'])->toBe(2)
        ->and($assets['images'])->toHaveCount(1)
        ->and($assets['documents'])->toHaveCount(1)
        ->and($assets['by_category'])->toHaveKey('logo')
        ->and($assets['by_category'])->toHaveKey('brief');
});

test('it auto-categorizes assets based on filename', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $logoAsset = $service->uploadFile($project, UploadedFile::fake()->image('company-logo.png', 200, 200));
    expect($logoAsset->category)->toBe('logo');

    $heroAsset = $service->uploadFile($project, UploadedFile::fake()->image('hero-banner.jpg', 1920, 600));
    expect($heroAsset->category)->toBe('hero');

    $briefAsset = $service->uploadFile($project, UploadedFile::fake()->create('project-brief.pdf', 100, 'application/pdf'));
    expect($briefAsset->category)->toBe('brief');

    $iconAsset = $service->uploadFile($project, UploadedFile::fake()->image('favicon.png', 32, 32));
    expect($iconAsset->category)->toBe('icon');
});

test('it auto-categorizes images by dimensions when filename has no hints', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);
    $service = app(WebsiteProjectAssetService::class);

    $smallImage = $service->uploadFile($project, UploadedFile::fake()->image('asset1.png', 64, 64));
    expect($smallImage->category)->toBe('icon');

    $wideImage = $service->uploadFile($project, UploadedFile::fake()->image('image123.jpg', 1920, 600));
    expect($wideImage->category)->toBe('hero');

    $regularPhoto = $service->uploadFile($project, UploadedFile::fake()->image('img_001.jpg', 800, 600));
    expect($regularPhoto->category)->toBe('photo');
});
