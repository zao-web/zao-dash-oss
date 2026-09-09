<?php

use App\Models\ContentSuggestion;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new ContentSuggestion)->getGuarded())->toBe([]);
});

test('casts source_data to array', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
        'source_data' => ['topic' => 'Laravel', 'keywords' => ['php', 'framework']],
    ]);

    expect($suggestion->source_data)->toBeArray()
        ->and($suggestion->source_data)->toBe(['topic' => 'Laravel', 'keywords' => ['php', 'framework']]);
});

test('belongs to site relationship', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    expect($suggestion->site())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('pending scope returns pending suggestions', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Pending Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Approved Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'approved',
    ]);

    $results = ContentSuggestion::pending()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe('pending');
});

test('approved scope returns approved suggestions', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Pending Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Approved Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'approved',
    ]);

    $results = ContentSuggestion::approved()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe('approved');
});

test('published scope returns published suggestions', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Pending Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Published Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'published',
    ]);

    $results = ContentSuggestion::published()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe('published');
});

test('approve method sets status to approved', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    $suggestion->approve();

    expect($suggestion->fresh()->status)->toBe('approved');
});

test('dismiss method sets status to dismissed', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    $suggestion->dismiss();

    expect($suggestion->fresh()->status)->toBe('dismissed');
});

test('markPublished method sets status and wp_post_id', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'approved',
    ]);

    $wpPostId = 123;
    $suggestion->markPublished($wpPostId);

    expect($suggestion->fresh()->status)->toBe('published')
        ->and($suggestion->fresh()->wp_post_id)->toBe($wpPostId);
});

test('can be created directly', function () {
    $site = WordPressSite::create([
        'url' => 'https://example.com',
        'name' => 'Test Site',
        'username' => 'testuser',
        'application_password' => 'testpass',
    ]);

    $suggestion = ContentSuggestion::create([
        'wordpress_site_id' => $site->id,
        'title' => 'Test Post',
        'description' => 'Test description',
        'source_type' => 'manual',
        'status' => 'pending',
    ]);

    expect($suggestion)->toBeInstanceOf(ContentSuggestion::class)
        ->and($suggestion->exists)->toBeTrue();
});
