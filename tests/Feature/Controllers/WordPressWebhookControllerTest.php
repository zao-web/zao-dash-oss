<?php

use App\Jobs\SyncWordPressJob;
use App\Models\User;
use App\Models\WordPressPost;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('rejects webhook with invalid signature', function () {
    $site = WordPressSite::factory()->create([
        'webhook_secret' => 'test-secret',
    ]);

    $payload = json_encode([
        'event' => 'post_published',
        'site_url' => $site->url,
    ]);

    $response = $this->postJson('/webhooks/wordpress', json_decode($payload, true), [
        'X-WP-Webhook-Signature' => 'invalid-signature',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Invalid signature']);
});

test('accepts webhook with valid signature', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'webhook_secret' => 'test-secret',
        'url' => 'https://example.com',
    ]);

    $payload = json_encode([
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 123,
        'post' => [
            'post_title' => 'Test Post',
            'post_name' => 'test-post',
            'post_type' => 'post',
        ],
    ]);

    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

    $response = $this->postJson('/webhooks/wordpress', json_decode($payload, true), [
        'X-WP-Webhook-Signature' => $expectedSignature,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['status' => 'ok']);
});

test('accepts webhook with secret in payload', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'webhook_secret' => 'test-secret',
        'url' => 'https://example.com',
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'webhook_secret' => 'test-secret',
        'post_id' => 123,
        'post' => [
            'post_title' => 'Test Post',
        ],
    ]);

    $response->assertStatus(200);
});

test('returns error for missing event type', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'site_url' => 'https://example.com',
        'post_id' => 123,
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Missing event type']);
});

test('returns error for unknown site', function () {
    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://unknown-site.com',
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Unknown site']);
});

test('handles post published event', function () {
    Queue::fake();

    $user = User::factory()->create();
    $site = WordPressSite::factory()->create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 123,
        'post' => [
            'post_title' => 'New Blog Post',
            'post_name' => 'new-blog-post',
            'post_type' => 'post',
            'post_content' => 'This is the content',
            'post_excerpt' => 'This is an excerpt',
        ],
    ]);

    $response->assertStatus(200);

    $post = WordPressPost::where('wp_post_id', 123)->first();
    expect($post)->not->toBeNull();
    expect($post->title)->toBe('New Blog Post');
    expect($post->slug)->toBe('new-blog-post');
    expect($post->status)->toBe('publish');

    Queue::assertPushed(SyncWordPressJob::class);
});

test('handles post published with alternative field names', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post.published',
        'site_url' => 'https://example.com',
        'ID' => 456,
        'title' => 'Alternative Post',
        'slug' => 'alternative-post',
        'content' => 'Content here',
        'excerpt' => 'Excerpt here',
    ]);

    $response->assertStatus(200);

    $post = WordPressPost::where('wp_post_id', 456)->first();
    expect($post)->not->toBeNull();
    expect($post->title)->toBe('Alternative Post');
});

test('handles post updated event', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $post = WordPressPost::factory()->create([
        'wordpress_site_id' => $site->id,
        'wp_post_id' => 123,
        'title' => 'Original Title',
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_updated',
        'site_url' => 'https://example.com',
        'post_id' => 123,
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncWordPressJob::class);
});

test('handles post deleted event', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $post = WordPressPost::factory()->create([
        'wordpress_site_id' => $site->id,
        'wp_post_id' => 123,
    ]);

    expect(WordPressPost::where('wp_post_id', 123)->exists())->toBeTrue();

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_deleted',
        'site_url' => 'https://example.com',
        'post_id' => 123,
    ]);

    $response->assertStatus(200);
    expect(WordPressPost::where('wp_post_id', 123)->exists())->toBeFalse();
});

test('handles post deleted with alternative id field', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $post = WordPressPost::factory()->create([
        'wordpress_site_id' => $site->id,
        'wp_post_id' => 456,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'delete_post',
        'site_url' => 'https://example.com',
        'ID' => 456,
    ]);

    $response->assertStatus(200);
    expect(WordPressPost::where('wp_post_id', 456)->exists())->toBeFalse();
});

test('handles comment added event', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'comment_added',
        'site_url' => 'https://example.com',
        'comment_ID' => 789,
        'comment_post_ID' => 123,
    ]);

    $response->assertStatus(200);
});

test('handles comment with alternative field names', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'wp_insert_comment',
        'site_url' => 'https://example.com',
        'comment_id' => 999,
        'post_id' => 456,
    ]);

    $response->assertStatus(200);
});

test('handles user registered event', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'user_registered',
        'site_url' => 'https://example.com',
        'user_id' => 321,
        'user_email' => 'newuser@example.com',
    ]);

    $response->assertStatus(200);
});

test('handles user registered with alternative field names', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'user_register',
        'site_url' => 'https://example.com',
        'ID' => 654,
        'email' => 'another@example.com',
    ]);

    $response->assertStatus(200);
});

test('finds site by url variations', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    // With trailing slash
    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com/',
        'post_id' => 123,
        'post' => ['post_title' => 'Test'],
    ]);

    $response->assertStatus(200);

    // Without trailing slash
    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 124,
        'post' => ['post_title' => 'Test 2'],
    ]);

    $response->assertStatus(200);
});

test('finds site by webhook secret when url not found', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => 'unique-secret',
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://different-url.com',
        'webhook_secret' => 'unique-secret',
        'post_id' => 123,
        'post' => ['post_title' => 'Test'],
    ]);

    $response->assertStatus(200);
});

test('handles unhandled event types gracefully', function () {
    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'unknown_event',
        'site_url' => 'https://example.com',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['status' => 'ok']);
});

test('dispatches sync job to integrations queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $site = WordPressSite::factory()->create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 123,
        'post' => ['post_title' => 'Test'],
    ]);

    $response->assertStatus(200);

    Queue::assertPushed(SyncWordPressJob::class, function ($job) {
        return $job->queue === 'integrations';
    });
});

test('updates existing post on republish', function () {
    Queue::fake();

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $post = WordPressPost::factory()->create([
        'wordpress_site_id' => $site->id,
        'wp_post_id' => 123,
        'title' => 'Old Title',
        'status' => 'draft',
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 123,
        'post' => [
            'post_title' => 'Updated Title',
            'post_name' => 'updated-title',
            'post_type' => 'post',
        ],
    ]);

    $response->assertStatus(200);

    $post->refresh();
    expect($post->title)->toBe('Updated Title');
    expect($post->status)->toBe('publish');
});

test('allows webhook in non-production when no secret configured', function () {
    Queue::fake();

    app()->detectEnvironment(function () {
        return 'local';
    });

    $site = WordPressSite::factory()->create([
        'url' => 'https://example.com',
        'webhook_secret' => null,
    ]);

    $response = $this->postJson('/webhooks/wordpress', [
        'event' => 'post_published',
        'site_url' => 'https://example.com',
        'post_id' => 123,
        'post' => ['post_title' => 'Test'],
    ]);

    $response->assertStatus(200);
});
