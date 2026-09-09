# Blog Publishing Pipeline

End-to-end blog publishing from content creation to WordPress publication.

## Overview

| Component | Purpose | Tool ID |
|-----------|---------|---------|
| Voice Analysis | Extract brand tone from existing posts | `analyze-blog-voice` |
| Content Generation | Create SEO blog posts | `seo-generate-blog` |
| Featured Images | Generate images with Flux Pro | `generate-featured-image` |
| WordPress Publishing | Create posts as drafts | `wp-create-post` |
| WordPress Update | Update an existing post | `wp-update-post` |

---

## Environment Variables

```bash
# Required for image generation (choose one)
REPLICATE_API_TOKEN=r8_...   # Preferred: Flux Pro via Replicate
OPENAI_API_KEY=sk-...         # Fallback: DALL-E 3

# WordPress is configured via UI (Settings → Integrations)
```

---

## Tools

### analyze-blog-voice

Reads existing published posts and extracts voice/tone patterns.

**Input:**
- `num_posts` (int): Number of posts to analyze (default: 10, max: 20)
- `refresh` (bool): Force bypass 24h cache

**Output:**
- `style_guide`: Narrative summary of writing style
- `patterns`: Structured data (tone, vocabulary, structure, headlines, CTAs)

**Usage:**
```php
$registry->execute('analyze-blog-voice', [
    'num_posts' => 10,
]);
```

---

### generate-featured-image

Creates featured images using Flux Pro (via Replicate) or DALL-E 3 fallback.

**Input:**
- `title` (string, required): Blog post title
- `topic` (string, required): Main topic/subject
- `style` (enum): professional, technical, creative, minimalist
- `upload_to_wordpress` (bool): Auto-upload to WP media library
- `custom_prompt` (string): Override auto-generated prompt

**Output:**
- `image_url`: Generated image URL
- `model`: flux-1.1-pro or dall-e-3
- `wordpress.media_id`: WP media ID (if uploaded)

**Cost:** ~$0.05/image (Flux Pro)

---

### wp-create-post

Creates WordPress posts via the Dash WordPress integration (REST, using the stored application password). Also exposed on the **zao-dash** MCP server so Editorial can publish without triggering a Dash agent.

**Input:**
- `title` (string, required unless `dry_run`): Post title
- `content` (string, required unless `dry_run`): HTML content
- `excerpt` (string): SEO excerpt
- `status` (enum): draft, publish, future, pending
- `categories` (int[]): Category IDs
- `tags` (int[]): Tag IDs
- `featured_media` (int): Media ID from image generation or `wordpress-media-upload`
- `schedule_date` (string): ISO 8601 date for scheduling
- `dry_run` (bool): Handshake REST auth and persist `rest_url` without creating a post

**Output:**
- `post_id`: WordPress post ID
- `post_url`: Public URL
- `edit_url`: WP admin edit link

On `dry_run: true` the tool returns `rest_url`, `authenticated_user`, and `published: false`. No post is created.

### wp-update-post

Updates an existing WordPress post through the **zao-dash** MCP server. Editorial should call this instead of creating a new post when a `post_id` already exists (for example WP 53642). It does not trigger Publisher or Content Synchronization agents.

The MCP tool wraps `WpUpdatePostTool`, which calls `WordPressMcpService::updatePost($site, $postId, $data)`. That method PUTs `https://{site}/wp-json/wp/v2/posts/{id}` using the stored Dash application password.

**Input:**
- `post_id` (int, required): WordPress post ID
- `title` (string): Updated title
- `content` (string): Updated HTML content
- `excerpt` (string): SEO excerpt
- `status` (enum): draft, publish, future, pending
- `categories` (int[]): Category IDs
- `tags` (int[]): Tag IDs
- `featured_media` (int): Media ID
- `schedule_date` (string): ISO 8601 date when `status` is `future`
- `slug` (string): Custom URL slug

At least one field besides `post_id` must be provided.

**Output:**
- `post_id`: WordPress post ID
- `post_url`: Public URL
- `edit_url`: WP admin edit link
- `status`: Resulting post status

**Invoke (MCP `tools/call`):**

```json
{
  "tool": "wp-update-post",
  "arguments": {
    "post_id": 53642,
    "title": "Updated title",
    "content": "<p>Updated HTML.</p>",
    "status": "draft"
  }
}
```

The Cursor user-zao-dash MCP surface is the same tool name: `wp-update-post`.

### wordpress-media-upload

Uploads a file to the connected WordPress media library using Dash-stored credentials. Do not mint a separate application password.

**Input:**
- `file_path` (string): Absolute path under Dash `wordpress-media`, public uploads, or temp. Paths outside that root (including `.env`) are rejected. Uploads require approval; `dry_run` only handshakes.
- `file_url` (string): Public http(s) URL. Private, link-local, and metadata IPs are blocked; the HTTP client is pinned to the validated IP (`CURLOPT_RESOLVE`) so a short-TTL rebind cannot retarget the connection; redirects are re-checked; downloads cap at 10MB.
- `file_base64` (string): Base64 file bytes (use with `filename`)
- `filename` (string): WordPress filename
- `alt_text` (string): Image alt text
- `dry_run` (bool): Handshake only

**Output:**
- `media_id`: WordPress media ID
- `media_url`: Public source URL

---

## Agents Using These Tools

| Agent | Tools Used | Trigger |
|-------|------------|---------|
| **ContentCreatorAgent** | All | Manual |
| **WordPressAgent** | All (chained) | After ContentCreator |
| **ProgrammaticSeoAgent** | wp-create-post | Scheduled |

---

## Workflow: Full Blog Post

1. **Analyze Voice** (optional but recommended)
   ```
   ContentCreatorAgent → analyze-blog-voice
   → Returns style guide to follow
   ```

2. **Generate Content**
   ```
   ContentCreatorAgent → seo-generate-blog
   → Creates HTML content matching voice
   ```

3. **Generate Featured Image**
   ```
   ContentCreatorAgent → generate-featured-image
   → Returns image URL and optionally uploads to WP
   ```

4. **Publish to WordPress**
   ```
   ContentCreatorAgent/WordPressAgent → wp-create-post
   → Creates draft post with featured image
   ```

5. **Human Review**
   - Post created as draft
   - Review in WP admin
   - Publish when ready

---

## Anti-AI-Slop Safeguards

All content generation follows strict voice rules:

**BANNED:**
- Em dashes (—)
- "Delve", "dive into"
- "Leverage", "unlock"
- "Seamlessly", "effortlessly"
- "In today's fast-paced world"
- Corporate buzzwords

**REQUIRED:**
- Short, punchy sentences
- Specific examples
- Contractions (we're, it's)
- Senior dev explaining to peer tone

---

## Testing

### Test Voice Analysis
```bash
php artisan tinker
>>> $registry = app(\App\Agents\ToolRegistry::class);
>>> $result = $registry->execute('analyze-blog-voice', ['num_posts' => 5]);
>>> dd($result['result']['style_guide']);
```

### Test Image Generation
```bash
# Requires REPLICATE_API_TOKEN in .env
>>> $result = $registry->execute('generate-featured-image', [
...     'title' => 'Building Scalable WordPress APIs',
...     'topic' => 'WordPress REST API development',
...     'style' => 'technical',
... ]);
>>> dd($result['result']['image_url']);
```

### Test WordPress Post Creation
```bash
>>> $result = $registry->execute('wp-create-post', [
...     'title' => 'Test Post',
...     'content' => '<p>Test content.</p>',
...     'status' => 'draft',
... ]);
>>> dd($result['result']['edit_url']);
```

---

## Image Generation Models

| Model | Provider | Quality | Cost |
|-------|----------|---------|------|
| Flux 1.1 Pro | Replicate | Excellent | ~$0.05/img |
| DALL-E 3 | OpenAI | Good (fallback) | ~$0.04/img |

Flux Pro is preferred for:
- More realistic, less "AI-looking" output
- Better text handling (if needed)
- More creative control

---

## Troubleshooting

### "No WordPress site configured"
Go to Settings → Integrations → Add WordPress Site

### "Replicate API error"
Check `REPLICATE_API_TOKEN` in .env

### Voice analysis returns empty
Ensure you have published posts synced. Run WordPress sync first.

### Image generation slow
Flux Pro can take 10-30 seconds. The tool polls until complete.
