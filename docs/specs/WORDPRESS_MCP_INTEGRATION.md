# WordPress MCP Integration Technical Spec

## Overview

Integration with Zao's agency WordPress site via the Model Context Protocol (MCP) Adapter. Uses HTTP transport to connect to WordPress's Abilities API, enabling AI-driven content creation based on GitHub activity and Harvest time tracking data.

---

## 1. Use Cases

| Use Case | Description |
|----------|-------------|
| Content creation | Generate blog posts about recent work |
| Work summaries | Pull GitHub commits + Harvest hours to suggest content |
| Publishing | Create and publish posts via MCP tools |
| Content discovery | Find existing posts to avoid duplicates |
| SEO optimization | Analyze and improve content |

---

## 2. Architecture

### WordPress Side (on example.com)
1. Install `wordpress/mcp-adapter` plugin
2. Configure HTTP transport endpoint
3. Create application password for authentication
4. Register custom abilities for content operations

### Dashboard Side
1. Store WordPress credentials
2. Connect as MCP client via HTTP
3. Discover available abilities/tools
4. Invoke tools for content operations

---

## 3. MCP Connection

### HTTP Transport
```
POST https://example.com/wp-json/mcp/mcp-adapter-default-server
Authorization: Basic base64(username:application_password)
Content-Type: application/json
```

### Available MCP Operations
- **Tools**: Execute WordPress abilities (create post, get posts, etc.)
- **Resources**: Access WordPress data (posts, categories, media)
- **Prompts**: Get structured prompts for content generation

---

## 4. Database Schema

### `wordpress_sites` table
```sql
id                  BIGINT PRIMARY KEY
name                VARCHAR(255)
url                 VARCHAR(500)
rest_url            VARCHAR(500) -- /wp-json/mcp/...
username            VARCHAR(255)
application_password TEXT (encrypted)
mcp_enabled         BOOLEAN default true
last_connected_at   TIMESTAMP nullable
capabilities        JSON nullable -- discovered abilities
is_primary          BOOLEAN default false -- agency site
client_id           BIGINT nullable FK -- if client site
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE(url)
```

### `wordpress_posts` table (synced from WP)
```sql
id                  BIGINT PRIMARY KEY
wordpress_site_id   BIGINT FK
wp_post_id          BIGINT
title               VARCHAR(500)
slug                VARCHAR(255)
status              VARCHAR(50) -- draft, publish, pending
type                VARCHAR(50) -- post, page
excerpt             TEXT nullable
content_preview     TEXT nullable -- first 500 chars
author_name         VARCHAR(255) nullable
categories          JSON nullable
tags                JSON nullable
featured_image_url  VARCHAR(500) nullable
published_at        TIMESTAMP nullable
modified_at         TIMESTAMP nullable
url                 VARCHAR(500)
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(wordpress_site_id)
INDEX(status)
INDEX(type)
```

### `content_suggestions` table
```sql
id                  BIGINT PRIMARY KEY
wordpress_site_id   BIGINT FK
title               VARCHAR(500)
description         TEXT
content_type        VARCHAR(50) -- blog_post, case_study, tutorial
source_type         VARCHAR(50) -- github_activity, harvest_project, manual
source_data         JSON -- commits, time entries, etc.
suggested_outline   TEXT nullable
status              VARCHAR(50) -- pending, approved, in_progress, published, dismissed
generated_content   TEXT nullable
wp_post_id          BIGINT nullable -- once published
priority            VARCHAR(20) default 'medium'
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(wordpress_site_id)
INDEX(status)
```

---

### Editorial publish path (zao-dash MCP)

Do not trigger the WordPress Publisher or Content Synchronization agents for a one-off editorial post. Those agents are LLM runners. MCP `trigger-agent` now queues `RunAgentJob` on the `agents` queue, but Editorial should call the publisher tools directly:

1. `wp-create-post` with `dry_run: true` (title and content not required) to handshake REST auth and persist `rest_url`.
2. `wordpress-media-upload` for featured and inline images (`file_base64` or a public `file_url`). `file_path` is confined to Dash media directories; private/metadata URLs are rejected.
3. `wp-create-post` with HTML content, `featured_media`, and `status: draft` or `publish`.
4. `wp-update-post` with `post_id` and the fields to change. This calls `WordPressMcpService::updatePost`, which PUTs `/wp/v2/posts/{id}`. Do not create a new post when an existing `post_id` should be rewritten.

All three tools use the WordPress site stored in Dash (Settings → Integrations). They do not accept an application password from the caller.

---

## 5. API Endpoints

### Site Management
```
GET  /api/integrations/wordpress/sites           - List connected sites
POST /api/integrations/wordpress/sites           - Add new site
PUT  /api/integrations/wordpress/sites/{id}      - Update site
DELETE /api/integrations/wordpress/sites/{id}    - Remove site
POST /api/integrations/wordpress/sites/{id}/test - Test connection
```

### MCP Operations
```
GET  /api/integrations/wordpress/{id}/capabilities  - Discover MCP tools
POST /api/integrations/wordpress/{id}/invoke        - Invoke MCP tool
GET  /api/integrations/wordpress/{id}/posts         - List posts (via MCP)
```

### Content Suggestions
```
GET  /api/integrations/wordpress/suggestions        - List suggestions
POST /api/integrations/wordpress/suggestions/generate - Generate from activity
PUT  /api/integrations/wordpress/suggestions/{id}   - Update suggestion
POST /api/integrations/wordpress/suggestions/{id}/publish - Publish to WP
```

---

## 6. Service Classes

### WordPressMcpService
- `connect(site)` - Establish MCP connection
- `discoverCapabilities(site)` - Get available tools/resources
- `invokeTool(site, tool, params)` - Execute MCP tool
- `getPosts(site, filters)` - Get posts via MCP
- `createPost(site, data)` - Create post via MCP
- `updatePost(site, postId, data)` - Update post via MCP

### ContentSuggestionService
- `generateFromGitHub(dateRange)` - Analyze commits for content ideas
- `generateFromHarvest(dateRange)` - Analyze time entries for content
- `combineActivityData(github, harvest)` - Merge data sources
- `suggestBlogPost(activityData)` - Generate blog post suggestion
- `generateOutline(suggestion)` - AI-generate content outline
- `generateDraft(suggestion)` - AI-generate full draft

### WordPressSyncService
- `syncPosts(site)` - Sync posts from WordPress
- `syncCategories(site)` - Sync categories/tags
- `checkForDuplicates(title)` - Avoid duplicate content

---

## 7. Content Generation Flow

```
1. Analyze Activity
   ├── Fetch recent GitHub commits (past 2 weeks)
   ├── Fetch Harvest time entries (past 2 weeks)
   └── Group by project/client

2. Generate Suggestions
   ├── Identify significant work (>10 hours, major features)
   ├── Create content_suggestions records
   └── AI-generate titles and descriptions

3. Human Review
   ├── User reviews suggestions in dashboard
   ├── Approves, edits, or dismisses
   └── Selects content type (blog, case study, tutorial)

4. Content Generation
   ├── AI generates outline based on activity
   ├── AI generates full draft
   └── User reviews/edits draft

5. Publishing
   ├── Invoke WordPress MCP create_post tool
   ├── Store wp_post_id reference
   └── Mark suggestion as published
```

---

## 8. Configuration

```php
// config/services.php
'wordpress' => [
    'agency_site' => env('WORDPRESS_AGENCY_URL', 'https://example.com'),
],
```

```env
# Added per-site via UI, stored encrypted in database
# No global env vars needed except optional default
WORDPRESS_AGENCY_URL=https://example.com
```

---

## 9. MCP Tool Examples

### Discover Available Tools
```php
$capabilities = $mcpService->discoverCapabilities($site);
// Returns: ['create_post', 'get_posts', 'update_post', 'get_categories', ...]
```

### Create Post via MCP
```php
$mcpService->invokeTool($site, 'create_post', [
    'title' => 'Building Custom Gutenberg Blocks',
    'content' => $generatedContent,
    'status' => 'draft',
    'categories' => ['Development', 'WordPress'],
]);
```

---

## 10. Activity-to-Content Mapping

| Activity Pattern | Content Type | Example |
|-----------------|--------------|---------|
| Major feature shipped | Tutorial | "How We Built X Feature" |
| Bug fixes across projects | Tips post | "Common WordPress Pitfalls" |
| New plugin work | Announcement | "Introducing Our New Plugin" |
| Client project completed | Case study | "How We Helped Client X" |
| Learning new tech | Guide | "Getting Started with Y" |
| Significant Harvest hours | Project recap | "What We've Been Working On" |

---

## 11. WordPress Plugin Requirements

On example.com, ensure:
1. MCP Adapter plugin installed and activated
2. Application password created for API user
3. REST API accessible (not blocked by security plugins)
4. Custom abilities registered if needed

```php
// Example custom ability in WordPress
wp_register_ability('create_blog_post', [
    'title' => 'Create Blog Post',
    'description' => 'Creates a new blog post with SEO optimization',
    'callback' => 'create_optimized_blog_post',
    'schema' => [...],
]);
```
