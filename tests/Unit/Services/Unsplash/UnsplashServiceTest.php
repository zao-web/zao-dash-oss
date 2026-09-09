<?php

use App\Services\Unsplash\UnsplashService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.unsplash.access_key' => 'test-access-key']);
    $this->service = new UnsplashService;
});

test('isConfigured returns true when access key is set', function () {
    expect($this->service->isConfigured())->toBeTrue();
});

test('isConfigured returns false when access key is empty', function () {
    config(['services.unsplash.access_key' => '']);

    $service = new UnsplashService;

    expect($service->isConfigured())->toBeFalse();
});

test('search returns photos matching query', function () {
    Http::fake([
        'api.unsplash.com/search/photos*' => Http::response([
            'total' => 100,
            'total_pages' => 10,
            'results' => [
                [
                    'id' => 'photo123',
                    'urls' => ['regular' => 'https://images.unsplash.com/photo123'],
                    'user' => ['name' => 'John Doe', 'links' => ['html' => 'https://unsplash.com/@johndoe']],
                    'links' => ['html' => 'https://unsplash.com/photos/photo123', 'download_location' => 'https://api.unsplash.com/photos/photo123/download'],
                ],
            ],
        ], 200),
    ]);

    $result = $this->service->search('technology office');

    expect($result['total'])->toBe(100)
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['id'])->toBe('photo123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.unsplash.com/search/photos')
            && (str_contains($request->url(), 'query=technology+office') || str_contains($request->url(), 'query=technology%20office'))
            && $request->hasHeader('Authorization', 'Client-ID test-access-key');
    });
});

test('search throws exception when not configured', function () {
    config(['services.unsplash.access_key' => '']);

    $service = new UnsplashService;

    expect(fn () => $service->search('test'))
        ->toThrow(\Exception::class, 'Unsplash API is not configured');
});

test('getRandom returns a random photo', function () {
    Http::fake([
        'api.unsplash.com/photos/random*' => Http::response([
            'id' => 'random123',
            'urls' => ['regular' => 'https://images.unsplash.com/random123'],
            'user' => ['name' => 'Jane Doe'],
            'links' => ['html' => 'https://unsplash.com/photos/random123'],
        ], 200),
    ]);

    $result = $this->service->getRandom('business');

    expect($result['id'])->toBe('random123');
});

test('getRandom returns null when no photo found', function () {
    Http::fake([
        'api.unsplash.com/photos/random*' => Http::response([], 404),
    ]);

    $result = $this->service->getRandom('nonexistent-query');

    expect($result)->toBeNull();
});

test('getPhotoForContent returns photo for topic and playbook', function () {
    Http::fake([
        'api.unsplash.com/photos/random*' => Http::response([
            'id' => 'content-photo',
            'urls' => ['regular' => 'https://images.unsplash.com/content-photo'],
            'user' => ['name' => 'Photographer'],
            'links' => ['html' => 'https://unsplash.com/photos/content-photo'],
        ], 200),
    ]);

    $result = $this->service->getPhotoForContent('laravel development', 'location');

    expect($result['id'])->toBe('content-photo');
});

test('downloadAndSave tracks download and saves to storage', function () {
    Storage::fake('public');

    $photo = [
        'id' => 'save-photo',
        'urls' => ['regular' => 'https://images.unsplash.com/save-photo?w=1080'],
        'user' => [
            'name' => 'Photographer Name',
            'links' => ['html' => 'https://unsplash.com/@photographer'],
        ],
        'links' => [
            'html' => 'https://unsplash.com/photos/save-photo',
            'download_location' => 'https://api.unsplash.com/photos/save-photo/download',
        ],
    ];

    Http::fake([
        'api.unsplash.com/photos/save-photo/download' => Http::response([], 200),
        'images.unsplash.com/*' => Http::response('fake-image-data', 200),
    ]);

    $result = $this->service->downloadAndSave($photo, 'test/image');

    expect($result)->toHaveKey('path')
        ->and($result)->toHaveKey('url')
        ->and($result)->toHaveKey('attribution')
        ->and($result['attribution']['photographer'])->toBe('Photographer Name')
        ->and($result['attribution']['unsplash_id'])->toBe('save-photo');

    Storage::disk('public')->assertExists('test/image.jpg');

    // Verify download tracking was called
    Http::assertSent(fn ($request) => str_contains($request->url(), 'download'));
});

test('formatAttribution returns proper markdown with UTM params', function () {
    $attribution = [
        'photographer' => 'John Doe',
        'photographer_url' => 'https://unsplash.com/@johndoe',
        'unsplash_id' => 'photo123',
    ];

    $result = $this->service->formatAttribution($attribution);

    expect($result)->toContain('John Doe')
        ->and($result)->toContain('utm_source=')
        ->and($result)->toContain('utm_medium=referral')
        ->and($result)->toContain('Unsplash');
});

test('formatAttributionHtml returns proper HTML with UTM params', function () {
    $attribution = [
        'photographer' => 'Jane Doe',
        'photographer_url' => 'https://unsplash.com/@janedoe',
        'unsplash_id' => 'photo456',
    ];

    $result = $this->service->formatAttributionHtml($attribution);

    expect($result)->toContain('<a href=')
        ->and($result)->toContain('Jane Doe')
        ->and($result)->toContain('utm_source=')
        ->and($result)->toContain('Unsplash');
});

test('getHotlinkedUrl returns correct size URL', function () {
    $photo = [
        'urls' => [
            'raw' => 'https://images.unsplash.com/raw',
            'full' => 'https://images.unsplash.com/full',
            'regular' => 'https://images.unsplash.com/regular',
            'small' => 'https://images.unsplash.com/small',
            'thumb' => 'https://images.unsplash.com/thumb',
        ],
    ];

    expect($this->service->getHotlinkedUrl($photo, 'small'))->toBe('https://images.unsplash.com/small')
        ->and($this->service->getHotlinkedUrl($photo, 'full'))->toBe('https://images.unsplash.com/full')
        ->and($this->service->getHotlinkedUrl($photo))->toBe('https://images.unsplash.com/regular');
});

test('trackDownload calls download_location endpoint', function () {
    Http::fake([
        'api.unsplash.com/photos/track123/download' => Http::response([], 200),
    ]);

    $photo = [
        'id' => 'track123',
        'links' => [
            'download_location' => 'https://api.unsplash.com/photos/track123/download',
        ],
    ];

    $this->service->trackDownload($photo);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'photos/track123/download'));
});
