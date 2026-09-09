# Notion Integration Technical Spec

## Overview

Read-only integration with Notion for accessing product planning docs, to-dos, plugin ideas, and client workspaces. Uses OAuth 2.0 for public integration to access multiple workspaces.

---

## 1. Use Cases

| Use Case | Description |
|----------|-------------|
| Product planning | Read roadmaps, feature specs, plugin ideas |
| Client workspaces | Access client Notion spaces we're invited to |
| To-do sync | Pull tasks from Notion databases |
| Meeting notes | Access shared meeting notes |
| Documentation | Reference client requirements docs |

---

## 2. Authentication

### OAuth 2.0 Flow (Public Integration)

```
User clicks "Connect Notion"
    → Redirect to Notion authorization URL
    → User selects pages/databases to share
    → Notion redirects back with code
    → Exchange code for access token
    → Store token (no expiration, but can be revoked)
```

### Notion API Details
- Base URL: `https://api.notion.com/v1`
- API Version: `2022-06-28` (or latest)
- Auth Header: `Authorization: Bearer {access_token}`
- Required Header: `Notion-Version: 2022-06-28`

---

## 3. Database Schema

### `notion_connections` table
```sql
id                  BIGINT PRIMARY KEY
user_id             BIGINT nullable (null = org-wide)
workspace_id        VARCHAR(255)
workspace_name      VARCHAR(255)
workspace_icon      VARCHAR(500) nullable
access_token        TEXT (encrypted)
bot_id              VARCHAR(255)
owner_type          VARCHAR(50) -- 'user' or 'workspace'
owner_id            VARCHAR(255) nullable
duplicated_template_id VARCHAR(255) nullable
request_id          VARCHAR(255) nullable
connected_at        TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE(workspace_id)
```

### `notion_pages` table
```sql
id                  BIGINT PRIMARY KEY
notion_connection_id BIGINT FK
page_id             VARCHAR(255) UNIQUE
parent_type         VARCHAR(50) -- 'database', 'page', 'workspace'
parent_id           VARCHAR(255) nullable
title               VARCHAR(500)
icon                VARCHAR(255) nullable
cover_url           VARCHAR(500) nullable
url                 VARCHAR(500)
is_database         BOOLEAN default false
properties_schema   JSON nullable -- for databases
last_synced_at      TIMESTAMP nullable
archived            BOOLEAN default false
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(notion_connection_id)
INDEX(parent_id)
```

### `notion_page_contents` table
```sql
id                  BIGINT PRIMARY KEY
notion_page_id      BIGINT FK
content_blocks      JSON -- block content
plain_text          TEXT -- extracted text for search
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

### `notion_database_items` table
```sql
id                  BIGINT PRIMARY KEY
notion_page_id      BIGINT FK -- the database page
item_id             VARCHAR(255)
properties          JSON
title               VARCHAR(500)
status              VARCHAR(100) nullable
priority            VARCHAR(50) nullable
assignee            VARCHAR(255) nullable
due_date            DATE nullable
url                 VARCHAR(500)
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(notion_page_id)
INDEX(status)
```

---

## 4. API Endpoints

### OAuth
```
GET  /auth/notion                    - Initiate OAuth flow
GET  /auth/notion/callback           - OAuth callback
```

### Connection Management
```
GET  /api/integrations/notion/status     - Connection status
POST /api/integrations/notion/disconnect - Disconnect workspace
GET  /api/integrations/notion/workspaces - List connected workspaces
```

### Data Access
```
POST /api/integrations/notion/sync           - Sync all accessible pages
GET  /api/integrations/notion/pages          - List synced pages
GET  /api/integrations/notion/pages/{id}     - Get page content
GET  /api/integrations/notion/databases      - List databases
GET  /api/integrations/notion/databases/{id}/items - Get database items
GET  /api/integrations/notion/search         - Search across pages
```

---

## 5. Service Classes

### NotionOAuthService
- `getAuthorizationUrl(state)` - Build OAuth URL
- `exchangeCodeForToken(code)` - Exchange code for access token
- `storeConnection(tokenData)` - Save connection to database

### NotionApiService
- `getUser()` - Get authenticated user/bot info
- `search(query, filter)` - Search pages and databases
- `getPage(pageId)` - Get page metadata
- `getPageContent(pageId)` - Get page blocks
- `getDatabase(databaseId)` - Get database schema
- `queryDatabase(databaseId, filter, sorts)` - Query database items
- `listAllPages()` - List all accessible pages

### NotionSyncService
- `syncAllPages(connectionId)` - Sync all accessible pages
- `syncPage(pageId)` - Sync single page content
- `syncDatabase(databaseId)` - Sync database items
- `extractPlainText(blocks)` - Extract searchable text

---

## 6. Sync Strategy

### Initial Sync
1. Call `search()` API to discover all accessible pages/databases
2. Store page metadata in `notion_pages`
3. For each database, query items and store in `notion_database_items`
4. Optionally fetch full content for important pages

### Incremental Sync
- Notion doesn't have webhooks for content changes
- Use `last_edited_time` filter in search to find recently changed pages
- Run sync job every 15-30 minutes
- Full re-sync weekly

---

## 7. Configuration

```php
// config/services.php
'notion' => [
    'client_id' => env('NOTION_CLIENT_ID'),
    'client_secret' => env('NOTION_CLIENT_SECRET'),
    'redirect_uri' => env('NOTION_REDIRECT_URI'),
],
```

```env
NOTION_CLIENT_ID=your-oauth-client-id
NOTION_CLIENT_SECRET=your-oauth-client-secret
NOTION_REDIRECT_URI=http://localhost:8000/auth/notion/callback
```

---

## 8. Client Workspace Access

Since you have access to client Notion workspaces:
- Each OAuth connection represents one workspace
- Can connect multiple workspaces (yours + client workspaces)
- Link pages to clients via `notion_pages.client_id` (add column if needed)
- Tag databases as "client: X" for organization

---

## 9. Example Queries

### Find all to-do items across workspaces
```php
NotionDatabaseItem::where('status', '!=', 'Done')
    ->whereNotNull('due_date')
    ->orderBy('due_date')
    ->get();
```

### Search for plugin ideas
```php
$notionApi->search('plugin idea', [
    'filter' => ['property' => 'object', 'value' => 'page']
]);
```

### Get client's project database
```php
NotionPage::where('is_database', true)
    ->where('client_id', $clientId)
    ->first();
```
