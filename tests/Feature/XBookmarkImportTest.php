<?php

use App\Models\User;
use App\Models\XBookmark;
use App\Models\XCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->credential = XCredential::factory()->create([
        'user_id' => $this->user->id,
    ]);
});

it('previews import and identifies duplicates', function () {
    XBookmark::create([
        'x_credential_id' => $this->credential->id,
        'tweet_id' => 'existing123',
        'author_id' => 'author1',
        'text' => 'Existing tweet',
    ]);

    $response = $this->postJson('/bookmarks/import/preview', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            ['tweet_id' => 'existing123', 'text' => 'Existing tweet'],
            ['tweet_id' => 'new456', 'text' => 'New tweet'],
            ['tweet_id' => 'new789', 'text' => 'Another new tweet'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'total' => 3,
        'new_count' => 2,
        'duplicate_count' => 1,
        'invalid_count' => 0,
    ]);

    $bookmarks = $response->json('bookmarks');
    expect($bookmarks[0]['is_duplicate'])->toBeTrue();
    expect($bookmarks[1]['is_duplicate'])->toBeFalse();
    expect($bookmarks[2]['is_duplicate'])->toBeFalse();
});

it('preview identifies invalid bookmarks without tweet_id', function () {
    $response = $this->postJson('/bookmarks/import/preview', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            ['text' => 'No tweet id'],
            ['tweet_id' => 'valid123', 'text' => 'Valid tweet'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'total' => 2,
        'new_count' => 1,
        'duplicate_count' => 0,
        'invalid_count' => 1,
    ]);
});

it('preview returns 404 for invalid credential', function () {
    $response = $this->postJson('/bookmarks/import/preview', [
        'credential_id' => 99999,
        'bookmarks' => [
            ['tweet_id' => '123', 'text' => 'Test'],
        ],
    ]);

    $response->assertNotFound();
});

it('imports new bookmarks', function () {
    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            [
                'tweet_id' => 'tweet1',
                'text' => 'First tweet',
                'author_username' => 'user1',
                'like_count' => 100,
            ],
            [
                'tweet_id' => 'tweet2',
                'text' => 'Second tweet',
                'author_username' => 'user2',
            ],
        ],
        'skip_duplicates' => true,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'created' => 2,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'tweet1',
        'text' => 'First tweet',
        'author_username' => 'user1',
        'like_count' => 100,
    ]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'tweet2',
        'text' => 'Second tweet',
    ]);
});

it('skips duplicates when skip_duplicates is true', function () {
    XBookmark::create([
        'x_credential_id' => $this->credential->id,
        'tweet_id' => 'existing123',
        'author_id' => 'author1',
        'text' => 'Existing tweet',
        'like_count' => 50,
    ]);

    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            ['tweet_id' => 'existing123', 'text' => 'Existing tweet updated', 'like_count' => 100],
            ['tweet_id' => 'new456', 'text' => 'New tweet'],
        ],
        'skip_duplicates' => true,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'created' => 1,
        'updated' => 0,
        'skipped' => 1,
        'errors' => 0,
    ]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'existing123',
        'like_count' => 50,
    ]);
});

it('updates duplicates when skip_duplicates is false', function () {
    XBookmark::create([
        'x_credential_id' => $this->credential->id,
        'tweet_id' => 'existing123',
        'author_id' => 'author1',
        'text' => 'Existing tweet',
        'like_count' => 50,
    ]);

    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            ['tweet_id' => 'existing123', 'text' => 'Existing tweet', 'like_count' => 200],
        ],
        'skip_duplicates' => false,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'created' => 0,
        'updated' => 1,
        'skipped' => 0,
    ]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'existing123',
        'like_count' => 200,
    ]);
});

it('normalizes various field name formats', function () {
    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            [
                'tweetId' => 'tweet1',
                'full_text' => 'Content from full_text',
                'authorUsername' => 'camelUser',
                'likeCount' => 50,
            ],
            [
                'id' => 'tweet2',
                'content' => 'Content from content field',
                'screen_name' => 'snakeUser',
                'favorite_count' => 75,
            ],
        ],
        'skip_duplicates' => true,
    ]);

    $response->assertSuccessful();
    $response->assertJson(['created' => 2]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'tweet1',
        'text' => 'Content from full_text',
        'author_username' => 'camelUser',
        'like_count' => 50,
    ]);

    $this->assertDatabaseHas('x_bookmarks', [
        'tweet_id' => 'tweet2',
        'text' => 'Content from content field',
        'author_username' => 'snakeUser',
        'like_count' => 75,
    ]);
});

it('prevents import to another users credential', function () {
    $otherUser = User::factory()->create();
    $otherCredential = XCredential::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $otherCredential->id,
        'bookmarks' => [
            ['tweet_id' => '123', 'text' => 'Test'],
        ],
    ]);

    $response->assertNotFound();
});

it('requires authentication', function () {
    auth()->logout();

    $response = $this->postJson('/bookmarks/import', [
        'credential_id' => $this->credential->id,
        'bookmarks' => [
            ['tweet_id' => '123', 'text' => 'Test'],
        ],
    ]);

    $response->assertUnauthorized();
});

it('validates required fields', function () {
    $response = $this->postJson('/bookmarks/import', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['credential_id', 'bookmarks']);
});
