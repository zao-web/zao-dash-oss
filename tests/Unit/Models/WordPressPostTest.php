<?php

use App\Models\WordPressPost;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new WordPressPost)->getGuarded())->toBe([]);
});

test('casts categories to array', function () {
    $post = WordPressPost::factory()->create([
        'categories' => ['News', 'Updates'],
    ]);

    expect($post->categories)->toBeArray()
        ->and($post->categories)->toBe(['News', 'Updates']);
});

test('casts tags to array', function () {
    $post = WordPressPost::factory()->create([
        'tags' => ['php', 'laravel'],
    ]);

    expect($post->tags)->toBeArray()
        ->and($post->tags)->toBe(['php', 'laravel']);
});

test('casts published_at to datetime', function () {
    $post = WordPressPost::factory()->create([
        'published_at' => now(),
    ]);

    expect($post->published_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts modified_at to datetime', function () {
    $post = WordPressPost::factory()->create([
        'modified_at' => now(),
    ]);

    expect($post->modified_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts synced_at to datetime', function () {
    $post = WordPressPost::factory()->create([
        'synced_at' => now(),
    ]);

    expect($post->synced_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('belongs to site relationship', function () {
    $post = WordPressPost::factory()->create();

    expect($post->site())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('published scope filters by status', function () {
    $published = WordPressPost::factory()->create(['status' => 'publish']);
    $draft = WordPressPost::factory()->create(['status' => 'draft']);

    $results = WordPressPost::published()->get();

    expect($results->contains($published))->toBeTrue()
        ->and($results->contains($draft))->toBeFalse();
});

test('drafts scope filters by status', function () {
    $published = WordPressPost::factory()->create(['status' => 'publish']);
    $draft = WordPressPost::factory()->create(['status' => 'draft']);

    $results = WordPressPost::drafts()->get();

    expect($results->contains($draft))->toBeTrue()
        ->and($results->contains($published))->toBeFalse();
});

test('ofType scope filters by type', function () {
    $post = WordPressPost::factory()->create(['type' => 'post']);
    $page = WordPressPost::factory()->create(['type' => 'page']);

    $results = WordPressPost::ofType('post')->get();

    expect($results->contains($post))->toBeTrue()
        ->and($results->contains($page))->toBeFalse();
});

test('can be created via factory', function () {
    $post = WordPressPost::factory()->create();

    expect($post)->toBeInstanceOf(WordPressPost::class)
        ->and($post->exists)->toBeTrue();
});
