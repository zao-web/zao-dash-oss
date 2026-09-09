<?php

use App\Models\XBookmark;
use App\Models\XCredential;
use App\Services\X\XBookmarkEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->enricher = new XBookmarkEnricher;
});

it('returns original text when oembed fails', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => 'Original tweet text',
        'author_username' => 'testuser',
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['full_text'])->toBe('Original tweet text');
    expect($result['enrichment_source'])->toBe('original');
});

it('extracts full text from oembed html', function () {
    $oembedHtml = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">This is the full tweet text with more context that was truncated in the API response.</p></blockquote>';

    Http::fake([
        'publish.twitter.com/*' => Http::response([
            'html' => $oembedHtml,
        ]),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => 'This is the full...',
        'author_username' => 'testuser',
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['full_text'])->toBe('This is the full tweet text with more context that was truncated in the API response.');
    expect($result['enrichment_source'])->toBe('oembed');
    expect($result['oembed_html'])->toBe($oembedHtml);
});

it('fetches url metadata when not provided by x api', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
        'example.com/*' => Http::response('<html><head><title>Example Page</title><meta name="description" content="This is an example page description"></head></html>'),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => 'Check this out',
        'author_username' => 'testuser',
        'urls' => [
            ['url' => 'https://example.com/article', 'title' => null, 'description' => null],
        ],
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['url_summaries'])->toHaveCount(1);
    expect($result['url_summaries'][0]['title'])->toBe('Example Page');
    expect($result['url_summaries'][0]['summary'])->toBe('This is an example page description');
    expect($result['url_summaries'][0]['fetched'])->toBeTrue();
});

it('skips twitter urls in url summaries', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => 'Quote tweet',
        'author_username' => 'testuser',
        'urls' => [
            ['url' => 'https://twitter.com/someone/status/123', 'title' => null, 'description' => null],
            ['url' => 'https://x.com/someone/status/456', 'title' => null, 'description' => null],
        ],
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['url_summaries'])->toBeEmpty();
});

it('uses existing url metadata from x api', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => 'Check this out',
        'author_username' => 'testuser',
        'urls' => [
            ['url' => 'https://example.com/article', 'title' => 'Pre-fetched Title', 'description' => 'Pre-fetched description'],
        ],
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['url_summaries'])->toHaveCount(1);
    expect($result['url_summaries'][0]['title'])->toBe('Pre-fetched Title');
    expect($result['url_summaries'][0]['summary'])->toBe('Pre-fetched description');
    expect($result['url_summaries'][0]['fetched'])->toBeFalse();
});

it('detects reply tweets', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
    ]);

    $credential = XCredential::factory()->create();
    $bookmark = XBookmark::factory()->create([
        'x_credential_id' => $credential->id,
        'text' => '@someone This is a reply tweet',
        'author_username' => 'testuser',
    ]);

    $result = $this->enricher->enrich($bookmark);

    expect($result['thread_context'])->not->toBeNull();
    expect($result['thread_context']['is_reply'])->toBeTrue();
});

it('enriches batch of bookmarks', function () {
    Http::fake([
        'publish.twitter.com/*' => Http::response(null, 404),
    ]);

    $credential = XCredential::factory()->create();
    $bookmarks = XBookmark::factory()->count(3)->create([
        'x_credential_id' => $credential->id,
        'author_username' => 'testuser',
    ]);

    $enrichedCount = $this->enricher->enrichBatch($bookmarks, 0);

    expect($enrichedCount)->toBe(3);

    foreach ($bookmarks as $bookmark) {
        $bookmark->refresh();
        expect($bookmark->isEnriched())->toBeTrue();
        expect($bookmark->enriched_content)->not->toBeNull();
    }
});
