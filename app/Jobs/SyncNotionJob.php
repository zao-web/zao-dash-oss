<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\NotionConnection;
use App\Models\NotionDatabaseItem;
use App\Models\NotionPage;
use App\Services\Notion\NotionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncNotionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $connectionId = null
    ) {}

    public function handle(NotionService $notionService): void
    {
        $connections = $this->connectionId
            ? NotionConnection::where('id', $this->connectionId)->get()
            : NotionConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            try {
                $this->syncConnection($connection, $notionService);
            } catch (\Exception $e) {
                Log::error('Notion sync failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncConnection(NotionConnection $connection, NotionService $notionService): void
    {
        $this->initSyncTracking($connection);

        try {
            Log::info('Syncing Notion connection', ['workspace' => $connection->workspace_name]);

            // Sync shared pages
            $this->syncPages($connection, $notionService);
            $this->updateSyncProgress(50, 'pages');

            // Sync databases
            $this->syncDatabases($connection, $notionService);
            $this->updateSyncProgress(90, 'databases');

            $connection->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncPages(NotionConnection $connection, NotionService $notionService): void
    {
        $pages = $notionService->searchPages($connection);

        foreach ($pages as $pageData) {
            $page = NotionPage::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'notion_id' => $pageData['id'],
                ],
                [
                    'title' => $this->extractTitle($pageData),
                    'url' => $pageData['url'] ?? null,
                    'icon' => $pageData['icon']['emoji'] ?? null,
                    'cover_url' => $pageData['cover']['external']['url'] ?? $pageData['cover']['file']['url'] ?? null,
                    'parent_type' => $pageData['parent']['type'] ?? null,
                    'parent_id' => $this->extractParentId($pageData),
                    'is_archived' => $pageData['archived'] ?? false,
                    'last_edited_at' => isset($pageData['last_edited_time'])
                        ? \Carbon\Carbon::parse($pageData['last_edited_time'])
                        : null,
                    'properties' => $pageData['properties'] ?? [],
                ]
            );

            // Optionally sync page content
            if ($connection->sync_content) {
                $this->syncPageContent($page, $notionService);
            }
        }
    }

    protected function syncPageContent(NotionPage $page, NotionService $notionService): void
    {
        try {
            $blocks = $notionService->getPageContent($page->connection, $page->notion_id);

            $page->content()->updateOrCreate(
                ['notion_page_id' => $page->id],
                [
                    'blocks' => $blocks,
                    'plain_text' => $this->extractPlainText($blocks),
                    'synced_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::warning('Failed to sync page content', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncDatabases(NotionConnection $connection, NotionService $notionService): void
    {
        $databases = $notionService->searchDatabases($connection);

        foreach ($databases as $dbData) {
            $database = $connection->databases()->updateOrCreate(
                ['notion_id' => $dbData['id']],
                [
                    'title' => $this->extractDatabaseTitle($dbData),
                    'url' => $dbData['url'] ?? null,
                    'icon' => $dbData['icon']['emoji'] ?? null,
                    'properties_schema' => $dbData['properties'] ?? [],
                    'is_archived' => $dbData['archived'] ?? false,
                ]
            );

            // Sync database items
            if ($connection->sync_database_items) {
                $this->syncDatabaseItems($database, $notionService);
            }
        }
    }

    protected function syncDatabaseItems($database, NotionService $notionService): void
    {
        $items = $notionService->queryDatabase($database->connection, $database->notion_id);

        foreach ($items as $item) {
            NotionDatabaseItem::updateOrCreate(
                [
                    'database_id' => $database->id,
                    'notion_id' => $item['id'],
                ],
                [
                    'properties' => $item['properties'] ?? [],
                    'url' => $item['url'] ?? null,
                    'is_archived' => $item['archived'] ?? false,
                    'last_edited_at' => isset($item['last_edited_time'])
                        ? \Carbon\Carbon::parse($item['last_edited_time'])
                        : null,
                ]
            );
        }
    }

    protected function extractTitle(array $pageData): string
    {
        $props = $pageData['properties'] ?? [];

        foreach ($props as $prop) {
            if ($prop['type'] === 'title' && ! empty($prop['title'])) {
                return collect($prop['title'])->pluck('plain_text')->implode('');
            }
        }

        return 'Untitled';
    }

    protected function extractDatabaseTitle(array $dbData): string
    {
        $title = $dbData['title'] ?? [];

        return collect($title)->pluck('plain_text')->implode('') ?: 'Untitled Database';
    }

    protected function extractParentId(array $pageData): ?string
    {
        $parent = $pageData['parent'] ?? [];

        return $parent['page_id'] ?? $parent['database_id'] ?? $parent['workspace'] ?? null;
    }

    protected function extractPlainText(array $blocks): string
    {
        $text = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            $content = $block[$type] ?? [];

            if (isset($content['rich_text'])) {
                $text[] = collect($content['rich_text'])->pluck('plain_text')->implode('');
            }

            if (! empty($block['children'])) {
                $text[] = $this->extractPlainText($block['children']);
            }
        }

        return implode("\n", array_filter($text));
    }
}
