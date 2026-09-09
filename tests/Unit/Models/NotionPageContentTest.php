<?php

use App\Models\NotionConnection;
use App\Models\NotionPage;
use App\Models\NotionPageContent;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new NotionPageContent)->getGuarded())->toBe(['*']);
});

test('casts content_blocks to array', function () {
    $user = User::factory()->create();
    $connection = NotionConnection::create([
        'user_id' => $user->id,
        'workspace_name' => 'Test Workspace',
        'workspace_id' => 'workspace-123',
        'access_token' => 'token',
        'bot_id' => 'bot-123',
    ]);

    $page = NotionPage::create([
        'notion_connection_id' => $connection->id,
        'notion_page_id' => 'page-123',
        'title' => 'Test Page',
        'url' => 'https://notion.so/page-123',
    ]);

    $content = NotionPageContent::create([
        'notion_page_id' => $page->id,
        'content_blocks' => [
            ['type' => 'paragraph', 'text' => 'Hello'],
            ['type' => 'heading_1', 'text' => 'Title'],
        ],
        'plain_text' => 'Test content',
        'synced_at' => now(),
    ]);

    expect($content->content_blocks)->toBeArray()
        ->and($content->content_blocks)->toHaveCount(2);
});

test('casts synced_at to datetime', function () {
    $user = User::factory()->create();
    $connection = NotionConnection::create([
        'user_id' => $user->id,
        'workspace_name' => 'Test Workspace',
        'workspace_id' => 'workspace-123',
        'access_token' => 'token',
        'bot_id' => 'bot-123',
    ]);

    $page = NotionPage::create([
        'notion_connection_id' => $connection->id,
        'notion_page_id' => 'page-123',
        'title' => 'Test Page',
        'url' => 'https://notion.so/page-123',
    ]);

    $content = NotionPageContent::create([
        'notion_page_id' => $page->id,
        'plain_text' => 'Test content',
        'synced_at' => now(),
    ]);

    expect($content->synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to page relationship', function () {
    $user = User::factory()->create();
    $connection = NotionConnection::create([
        'user_id' => $user->id,
        'workspace_name' => 'Test Workspace',
        'workspace_id' => 'workspace-123',
        'access_token' => 'token',
        'bot_id' => 'bot-123',
    ]);

    $page = NotionPage::create([
        'notion_connection_id' => $connection->id,
        'notion_page_id' => 'page-123',
        'title' => 'Test Page',
        'url' => 'https://notion.so/page-123',
    ]);

    $content = NotionPageContent::create([
        'notion_page_id' => $page->id,
        'plain_text' => 'Test content',
        'synced_at' => now(),
    ]);

    expect($content->page())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be created', function () {
    $user = User::factory()->create();
    $connection = NotionConnection::create([
        'user_id' => $user->id,
        'workspace_name' => 'Test Workspace',
        'workspace_id' => 'workspace-123',
        'access_token' => 'token',
        'bot_id' => 'bot-123',
    ]);

    $page = NotionPage::create([
        'notion_connection_id' => $connection->id,
        'notion_page_id' => 'page-123',
        'title' => 'Test Page',
        'url' => 'https://notion.so/page-123',
    ]);

    $content = NotionPageContent::create([
        'notion_page_id' => $page->id,
        'plain_text' => 'Test content',
        'synced_at' => now(),
    ]);

    expect($content)->toBeInstanceOf(NotionPageContent::class)
        ->and($content->exists)->toBeTrue();
});
