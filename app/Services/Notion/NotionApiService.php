<?php

namespace App\Services\Notion;

use App\Models\NotionConnection;
use App\Models\NotionDatabaseItem;
use App\Models\NotionPage;
use App\Models\NotionPageContent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class NotionApiService
{
    protected function client(NotionConnection $connection): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$connection->access_token}",
            'Notion-Version' => '2022-06-28',
            'Content-Type' => 'application/json',
        ])->baseUrl('https://api.notion.com/v1');
    }

    public function search(NotionConnection $connection, ?string $query = null, array $filter = []): array
    {
        $payload = [];

        if ($query) {
            $payload['query'] = $query;
        }

        if (! empty($filter)) {
            $payload['filter'] = $filter;
        }

        $response = $this->client($connection)->post('/search', $payload);

        if (! $response->successful()) {
            throw new \Exception('Notion search failed: '.$response->body());
        }

        return $response->json();
    }

    public function getPage(NotionConnection $connection, string $pageId): array
    {
        $response = $this->client($connection)->get("/pages/{$pageId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get page: '.$response->body());
        }

        return $response->json();
    }

    public function getPageBlocks(NotionConnection $connection, string $pageId): array
    {
        $blocks = [];
        $cursor = null;

        do {
            $params = ['page_size' => 100];
            if ($cursor) {
                $params['start_cursor'] = $cursor;
            }

            $response = $this->client($connection)->get("/blocks/{$pageId}/children", $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to get blocks: '.$response->body());
            }

            $data = $response->json();
            $blocks = array_merge($blocks, $data['results'] ?? []);
            $cursor = $data['has_more'] ? $data['next_cursor'] : null;
        } while ($cursor);

        return $blocks;
    }

    public function getDatabase(NotionConnection $connection, string $databaseId): array
    {
        $response = $this->client($connection)->get("/databases/{$databaseId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get database: '.$response->body());
        }

        return $response->json();
    }

    public function queryDatabase(NotionConnection $connection, string $databaseId, array $filter = [], array $sorts = []): array
    {
        $items = [];
        $cursor = null;

        do {
            $payload = ['page_size' => 100];
            if (! empty($filter)) {
                $payload['filter'] = $filter;
            }
            if (! empty($sorts)) {
                $payload['sorts'] = $sorts;
            }
            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $response = $this->client($connection)->post("/databases/{$databaseId}/query", $payload);

            if (! $response->successful()) {
                throw new \Exception('Failed to query database: '.$response->body());
            }

            $data = $response->json();
            $items = array_merge($items, $data['results'] ?? []);
            $cursor = $data['has_more'] ? $data['next_cursor'] : null;
        } while ($cursor);

        return $items;
    }

    public function syncAllPages(NotionConnection $connection): int
    {
        $count = 0;

        // Search for all pages
        $pagesResult = $this->search($connection, null, ['property' => 'object', 'value' => 'page']);
        foreach ($pagesResult['results'] ?? [] as $page) {
            $this->upsertPage($connection, $page, false);
            $count++;
        }

        // Search for all databases
        $dbResult = $this->search($connection, null, ['property' => 'object', 'value' => 'database']);
        foreach ($dbResult['results'] ?? [] as $db) {
            $this->upsertPage($connection, $db, true);
            $count++;
        }

        return $count;
    }

    protected function upsertPage(NotionConnection $connection, array $data, bool $isDatabase): NotionPage
    {
        $title = $this->extractTitle($data, $isDatabase);
        $parentType = $data['parent']['type'] ?? null;
        $parentId = match ($parentType) {
            'database_id' => $data['parent']['database_id'] ?? null,
            'page_id' => $data['parent']['page_id'] ?? null,
            'workspace' => null,
            default => null,
        };

        return NotionPage::updateOrCreate(
            ['page_id' => $data['id']],
            [
                'notion_connection_id' => $connection->id,
                'parent_type' => $parentType,
                'parent_id' => $parentId,
                'title' => $title,
                'icon' => $this->extractIcon($data),
                'cover_url' => $data['cover']['external']['url'] ?? $data['cover']['file']['url'] ?? null,
                'url' => $data['url'],
                'is_database' => $isDatabase,
                'properties_schema' => $isDatabase ? ($data['properties'] ?? null) : null,
                'archived' => $data['archived'] ?? false,
                'last_synced_at' => now(),
            ]
        );
    }

    protected function extractTitle(array $data, bool $isDatabase): string
    {
        if ($isDatabase) {
            $titleArray = $data['title'] ?? [];

            return collect($titleArray)->pluck('plain_text')->join('') ?: 'Untitled Database';
        }

        $properties = $data['properties'] ?? [];
        foreach ($properties as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                $titleArray = $prop['title'] ?? [];

                return collect($titleArray)->pluck('plain_text')->join('') ?: 'Untitled';
            }
        }

        return 'Untitled';
    }

    protected function extractIcon(array $data): ?string
    {
        $icon = $data['icon'] ?? null;
        if (! $icon) {
            return null;
        }

        return match ($icon['type'] ?? null) {
            'emoji' => $icon['emoji'],
            'external' => $icon['external']['url'] ?? null,
            'file' => $icon['file']['url'] ?? null,
            default => null,
        };
    }

    public function syncPageContent(NotionConnection $connection, NotionPage $page): NotionPageContent
    {
        $blocks = $this->getPageBlocks($connection, $page->page_id);
        $plainText = $this->extractPlainText($blocks);

        return NotionPageContent::updateOrCreate(
            ['notion_page_id' => $page->id],
            [
                'content_blocks' => $blocks,
                'plain_text' => $plainText,
                'synced_at' => now(),
            ]
        );
    }

    public function syncDatabaseItems(NotionConnection $connection, NotionPage $database): int
    {
        if (! $database->is_database) {
            throw new \Exception('Page is not a database');
        }

        $items = $this->queryDatabase($connection, $database->page_id);
        $count = 0;

        foreach ($items as $item) {
            $this->upsertDatabaseItem($database, $item);
            $count++;
        }

        return $count;
    }

    protected function upsertDatabaseItem(NotionPage $database, array $item): NotionDatabaseItem
    {
        $properties = $item['properties'] ?? [];
        $title = $this->extractItemTitle($properties);
        $status = $this->extractProperty($properties, 'status', 'Status');
        $priority = $this->extractProperty($properties, 'select', 'Priority');
        $assignee = $this->extractProperty($properties, 'people', 'Assignee');
        $dueDate = $this->extractProperty($properties, 'date', 'Due');

        return NotionDatabaseItem::updateOrCreate(
            ['item_id' => $item['id']],
            [
                'notion_page_id' => $database->id,
                'properties' => $properties,
                'title' => $title,
                'status' => $status,
                'priority' => $priority,
                'assignee' => $assignee,
                'due_date' => $dueDate,
                'url' => $item['url'],
                'synced_at' => now(),
            ]
        );
    }

    protected function extractItemTitle(array $properties): string
    {
        foreach ($properties as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                $titleArray = $prop['title'] ?? [];

                return collect($titleArray)->pluck('plain_text')->join('') ?: 'Untitled';
            }
        }

        return 'Untitled';
    }

    protected function extractProperty(array $properties, string $type, string $name): ?string
    {
        foreach ($properties as $propName => $prop) {
            if (stripos($propName, $name) !== false && ($prop['type'] ?? '') === $type) {
                return match ($type) {
                    'status' => $prop['status']['name'] ?? null,
                    'select' => $prop['select']['name'] ?? null,
                    'people' => collect($prop['people'] ?? [])->pluck('name')->first(),
                    'date' => $prop['date']['start'] ?? null,
                    default => null,
                };
            }
        }

        return null;
    }

    protected function extractPlainText(array $blocks): string
    {
        $text = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            $content = $block[$type] ?? [];

            if (isset($content['rich_text'])) {
                $text[] = collect($content['rich_text'])->pluck('plain_text')->join('');
            }
        }

        return implode("\n", array_filter($text));
    }
}
