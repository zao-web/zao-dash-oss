<?php

namespace Tests\Unit\Services\Notion;

use App\Models\NotionConnection;
use App\Models\NotionPage;
use App\Models\User;
use App\Services\Notion\NotionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NotionApiService $service;

    protected NotionConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NotionApiService;

        $user = User::factory()->create();
        $this->connection = NotionConnection::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'workspace_id' => 'workspace-123',
        ]);
    }

    /** @test */
    public function it_searches_notion()
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [
                    ['id' => 'page-1', 'object' => 'page'],
                    ['id' => 'page-2', 'object' => 'page'],
                ],
            ], 200),
        ]);

        $results = $this->service->search($this->connection, 'test query');

        $this->assertCount(2, $results['results']);
    }

    /** @test */
    public function it_throws_exception_on_failed_search()
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Notion search failed');

        $this->service->search($this->connection, 'test');
    }

    /** @test */
    public function it_gets_page()
    {
        Http::fake([
            'api.notion.com/v1/pages/*' => Http::response([
                'id' => 'page-123',
                'properties' => [
                    'title' => [
                        'type' => 'title',
                        'title' => [['plain_text' => 'Test Page']],
                    ],
                ],
            ], 200),
        ]);

        $page = $this->service->getPage($this->connection, 'page-123');

        $this->assertEquals('page-123', $page['id']);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_page()
    {
        Http::fake([
            'api.notion.com/v1/pages/*' => Http::response('Not found', 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get page');

        $this->service->getPage($this->connection, 'invalid-page');
    }

    /** @test */
    public function it_gets_page_blocks_with_pagination()
    {
        Http::fake([
            'api.notion.com/v1/blocks/*/children*cursor=*' => Http::response([
                'results' => [
                    ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Block 3']]]],
                ],
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*/children*' => Http::response([
                'results' => [
                    ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Block 1']]]],
                    ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Block 2']]]],
                ],
                'has_more' => true,
                'next_cursor' => 'cursor-123',
            ], 200),
        ]);

        $blocks = $this->service->getPageBlocks($this->connection, 'page-123');

        $this->assertCount(3, $blocks);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_blocks()
    {
        Http::fake([
            'api.notion.com/v1/blocks/*/children*' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get blocks');

        $this->service->getPageBlocks($this->connection, 'page-123');
    }

    /** @test */
    public function it_gets_database()
    {
        Http::fake([
            'api.notion.com/v1/databases/*' => Http::response([
                'id' => 'db-123',
                'title' => [['plain_text' => 'Test Database']],
            ], 200),
        ]);

        $database = $this->service->getDatabase($this->connection, 'db-123');

        $this->assertEquals('db-123', $database['id']);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_database()
    {
        Http::fake([
            'api.notion.com/v1/databases/*' => Http::response('Not found', 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get database');

        $this->service->getDatabase($this->connection, 'invalid-db');
    }

    /** @test */
    public function it_queries_database_with_pagination()
    {
        Http::fake([
            'api.notion.com/v1/databases/*/query*cursor=*' => Http::response([
                'results' => [
                    ['id' => 'item-3'],
                ],
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/databases/*/query' => Http::response([
                'results' => [
                    ['id' => 'item-1'],
                    ['id' => 'item-2'],
                ],
                'has_more' => true,
                'next_cursor' => 'cursor-456',
            ], 200),
        ]);

        $items = $this->service->queryDatabase($this->connection, 'db-123');

        $this->assertCount(3, $items);
    }

    /** @test */
    public function it_throws_exception_on_failed_query_database()
    {
        Http::fake([
            'api.notion.com/v1/databases/*/query' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to query database');

        $this->service->queryDatabase($this->connection, 'db-123');
    }

    /** @test */
    public function it_syncs_all_pages()
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [
                        [
                            'id' => 'page-1',
                            'object' => 'page',
                            'parent' => ['type' => 'workspace'],
                            'properties' => [
                                'title' => ['type' => 'title', 'title' => [['plain_text' => 'Page 1']]],
                            ],
                            'url' => 'https://notion.so/page-1',
                        ],
                    ],
                ], 200)
                ->push([
                    'results' => [
                        [
                            'id' => 'db-1',
                            'object' => 'database',
                            'parent' => ['type' => 'workspace'],
                            'title' => [['plain_text' => 'Database 1']],
                            'properties' => [],
                            'url' => 'https://notion.so/db-1',
                        ],
                    ],
                ], 200),
        ]);

        $count = $this->service->syncAllPages($this->connection);

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('notion_pages', [
            'page_id' => 'page-1',
            'is_database' => false,
        ]);
        $this->assertDatabaseHas('notion_pages', [
            'page_id' => 'db-1',
            'is_database' => true,
        ]);
    }

    /** @test */
    public function it_syncs_page_content()
    {
        $page = NotionPage::factory()->create([
            'notion_connection_id' => $this->connection->id,
            'page_id' => 'page-123',
        ]);

        Http::fake([
            'api.notion.com/v1/blocks/*/children*' => Http::response([
                'results' => [
                    ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Hello world']]]],
                    ['type' => 'heading_1', 'heading_1' => ['rich_text' => [['plain_text' => 'Title']]]],
                ],
                'has_more' => false,
            ], 200),
        ]);

        $content = $this->service->syncPageContent($this->connection, $page);

        $this->assertNotNull($content);
        $this->assertStringContainsString('Hello world', $content->plain_text);
        $this->assertStringContainsString('Title', $content->plain_text);
    }

    /** @test */
    public function it_syncs_database_items()
    {
        $database = NotionPage::factory()->create([
            'notion_connection_id' => $this->connection->id,
            'page_id' => 'db-123',
            'is_database' => true,
        ]);

        Http::fake([
            'api.notion.com/v1/databases/*/query' => Http::response([
                'results' => [
                    [
                        'id' => 'item-1',
                        'properties' => [
                            'Name' => ['type' => 'title', 'title' => [['plain_text' => 'Item 1']]],
                            'Status' => ['type' => 'status', 'status' => ['name' => 'In Progress']],
                        ],
                        'url' => 'https://notion.so/item-1',
                    ],
                ],
                'has_more' => false,
            ], 200),
        ]);

        $count = $this->service->syncDatabaseItems($this->connection, $database);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('notion_database_items', [
            'item_id' => 'item-1',
            'title' => 'Item 1',
            'status' => 'In Progress',
        ]);
    }

    /** @test */
    public function it_throws_exception_when_syncing_non_database()
    {
        $page = NotionPage::factory()->create([
            'notion_connection_id' => $this->connection->id,
            'is_database' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Page is not a database');

        $this->service->syncDatabaseItems($this->connection, $page);
    }

    /** @test */
    public function it_sends_correct_headers()
    {
        Http::fake([
            'api.notion.com/*' => Http::response(['results' => []], 200),
        ]);

        $this->service->search($this->connection, 'test');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-access-token') &&
                   $request->hasHeader('Notion-Version', '2022-06-28') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    /** @test */
    public function it_extracts_title_from_page()
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [
                    [
                        'id' => 'page-1',
                        'object' => 'page',
                        'parent' => ['type' => 'workspace'],
                        'properties' => [
                            'Name' => ['type' => 'title', 'title' => [['plain_text' => 'My Page Title']]],
                        ],
                        'url' => 'https://notion.so/page-1',
                    ],
                ],
            ], 200),
        ]);

        $this->service->syncAllPages($this->connection);

        $this->assertDatabaseHas('notion_pages', [
            'page_id' => 'page-1',
            'title' => 'My Page Title',
        ]);
    }

    /** @test */
    public function it_extracts_icon_from_page()
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [
                    [
                        'id' => 'page-1',
                        'object' => 'page',
                        'parent' => ['type' => 'workspace'],
                        'properties' => [
                            'title' => ['type' => 'title', 'title' => [['plain_text' => 'Page']]],
                        ],
                        'icon' => ['type' => 'emoji', 'emoji' => '🚀'],
                        'url' => 'https://notion.so/page-1',
                    ],
                ],
            ], 200),
        ]);

        $this->service->syncAllPages($this->connection);

        $this->assertDatabaseHas('notion_pages', [
            'page_id' => 'page-1',
            'icon' => '🚀',
        ]);
    }
}
