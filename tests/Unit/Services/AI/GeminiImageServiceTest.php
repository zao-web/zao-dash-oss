<?php

use App\Services\AI\GeminiImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.google.gemini_api_key' => 'test-api-key']);
});

test('isConfigured returns true when api key is set', function () {
    $service = new GeminiImageService;

    expect($service->isConfigured())->toBeTrue();
});

test('isConfigured returns false when api key is empty', function () {
    config(['services.google.gemini_api_key' => '']);

    $service = new GeminiImageService;

    expect($service->isConfigured())->toBeFalse();
});

test('availableModels returns all model aliases', function () {
    $service = new GeminiImageService;

    expect($service->availableModels())->toBe(['gemini-3.1-flash', 'imagen-4', 'gemini-flash', 'gemini-pro']);
});

test('generate throws exception when not configured', function () {
    config(['services.google.gemini_api_key' => '']);

    $service = new GeminiImageService;

    expect(fn () => $service->generate('test prompt'))
        ->toThrow(\Exception::class, 'Gemini API key is not configured');
});

test('legacy imagen-4 alias routes to gemini-3.1-flash-image', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'data' => base64_encode('fake-image-data'),
                                    'mimeType' => 'image/png',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new GeminiImageService;
    $result = $service->generate('a blue circle', 'imagen-4', [
        'aspectRatio' => '16:9',
    ]);

    expect($result)->toHaveKey('images')
        ->and($result)->toHaveKey('model')
        ->and($result['model'])->toBe('gemini-3.1-flash-image')
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['mimeType'])->toBe('image/png');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gemini-3.1-flash-image:generateContent')
            && ! str_contains($request->url(), 'imagen')
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && $request['contents'][0]['parts'][0]['text'] === 'a blue circle'
            && $request['generationConfig']['imageConfig']['aspectRatio'] === '16:9';
    });
});

test('generate with gemini model sends correct request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Here is your image'],
                            [
                                'inlineData' => [
                                    'data' => base64_encode('fake-image-data'),
                                    'mimeType' => 'image/png',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new GeminiImageService;
    $result = $service->generate('a red square', 'gemini-flash', [
        'aspectRatio' => '1:1',
    ]);

    expect($result)->toHaveKey('images')
        ->and($result)->toHaveKey('text')
        ->and($result)->toHaveKey('model')
        ->and($result['model'])->toBe('gemini-2.5-flash-image')
        ->and($result['text'])->toBe('Here is your image')
        ->and($result['images'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gemini-2.5-flash-image:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && $request['contents'][0]['parts'][0]['text'] === 'a red square'
            && $request['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE'];
    });
});

test('generate throws exception on api error', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Rate limit exceeded'],
        ], 429),
    ]);

    $service = new GeminiImageService;

    expect(fn () => $service->generate('test prompt'))
        ->toThrow(\Exception::class);
});

test('generateAndSave stores image to disk', function () {
    Storage::fake('public');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'data' => base64_encode('fake-image-data'),
                                    'mimeType' => 'image/png',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new GeminiImageService;
    $result = $service->generateAndSave('a green triangle', 'images/test-image');

    expect($result)->toHaveKey('path')
        ->and($result)->toHaveKey('model')
        ->and($result['path'])->toBe('images/test-image.png');

    Storage::disk('public')->assertExists('images/test-image.png');
});

test('generateAndSave throws exception when no images generated', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'predictions' => [],
        ], 200),
    ]);

    $service = new GeminiImageService;

    expect(fn () => $service->generateAndSave('test', 'path'))
        ->toThrow(\Exception::class, 'No images generated');
});

test('generate defaults to gemini-3.1-flash-image model', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['inlineData' => ['data' => base64_encode('data'), 'mimeType' => 'image/png']],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new GeminiImageService;
    $result = $service->generate('test prompt');

    expect($result['model'])->toBe('gemini-3.1-flash-image');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.1-flash-image:generateContent'));
});
