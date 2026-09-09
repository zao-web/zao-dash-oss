# X Bookmarks to PRs

Automatically sync your X (Twitter) bookmarks, analyze them with AI, and generate pull requests for actionable ideas.

## Overview

This feature allows you to:
1. **Sync your X bookmarks** once per day (free tier compatible)
2. **AI analysis** categorizes each bookmark and determines if it's actionable for the Zao Dashboard project
3. **Auto-generate PRs** for actionable bookmarks using Claude Code

## Architecture

```
X Bookmarks API → SyncXBookmarksJob → x_bookmarks table
                                           ↓
                               AnalyzeXBookmarksJob (Claude AI)
                                           ↓
                               CreatePRFromBookmarkJob (Claude Code)
                                           ↓
                                    GitHub PR Created
```

## Setup

### 1. X API Configuration

Ensure your X app has the following scopes:
- `tweet.read`
- `users.read`
- `bookmark.read` (required for this feature)
- `offline.access`

### 2. Re-authenticate X Account

Existing X connections need to be re-authenticated to grant the `bookmark.read` scope:

1. Go to **Settings → Integrations**
2. Disconnect your X account
3. Reconnect with "include_bookmarks=1" parameter

Or visit: `/auth/x?include_bookmarks=1`

### 3. Environment Variables

```env
# Existing X credentials
X_CLIENT_ID=your_client_id
X_CLIENT_SECRET=your_client_secret
X_REDIRECT_URI=https://your-domain.com/auth/x/callback

# Anthropic API (for AI analysis)
ANTHROPIC_API_KEY=your_api_key
```

## Usage

### Dashboard

Access the bookmarks dashboard at `/bookmarks`. From here you can:

- View all synced bookmarks
- Filter by status, category, or relevance score
- Trigger sync (once per 24 hours on free tier)
- Run AI analysis on pending bookmarks
- Create PRs from actionable bookmarks
- Dismiss irrelevant bookmarks

### Artisan Commands

```bash
# Sync bookmarks for all credentials with bookmark scope
php artisan x:sync-bookmarks

# Sync for specific user
php artisan x:sync-bookmarks --user=1

# Force sync (ignore 24h cooldown)
php artisan x:sync-bookmarks --force

# Sync and analyze in one command
php artisan x:sync-bookmarks --sync --analyze
```

### Scheduled Sync

Add to your scheduler (`app/Console/Kernel.php`):

```php
// Sync bookmarks daily at 6 AM
$schedule->command('x:sync-bookmarks')
    ->dailyAt('06:00')
    ->withoutOverlapping();
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/bookmarks` | Dashboard view |
| POST | `/bookmarks/sync/{credentialId}` | Trigger sync |
| POST | `/bookmarks/analyze/{credentialId}` | Trigger AI analysis |
| GET | `/bookmarks/{id}` | Get bookmark details |
| POST | `/bookmarks/{id}/create-pr` | Generate PR |
| POST | `/bookmarks/{id}/dismiss` | Dismiss bookmark |
| POST | `/bookmarks/bulk` | Bulk actions |
| GET | `/bookmarks/scope-status` | Check bookmark scope |

## Data Model

### XBookmark

| Field | Type | Description |
|-------|------|-------------|
| `tweet_id` | string | X tweet ID |
| `author_username` | string | Tweet author |
| `text` | text | Tweet content |
| `urls` | json | Extracted URLs |
| `status` | enum | pending, analyzed, actionable, pr_created, dismissed |
| `category` | string | AI-assigned category |
| `relevance_score` | int | 0-100 relevance to project |
| `action_summary` | text | What action to take |
| `pr_url` | string | Created PR URL |

### Categories

- `ai_model` - New AI models, APIs, or capabilities
- `prompt_technique` - Prompting strategies, agent patterns
- `feature_idea` - Feature for Zao Dashboard
- `integration` - New service integration
- `bug_fix` - Bug report or fix
- `tool` - Development tool or library
- `research` - Interesting research
- `other` - Doesn't fit other categories

## Rate Limits

### X API (Free Tier)
- **Bookmarks endpoint**: 1 request per 24 hours
- **Max bookmarks returned**: 800 most recent

The system enforces a 24-hour cooldown between syncs to stay within free tier limits.

### Anthropic API
- Analysis uses `claude-sonnet-4-20250514` model
- Batched in groups of 10 bookmarks
- Uses standard rate limiting middleware

## PR Generation

When you click "Create PR" on an actionable bookmark:

1. A new branch is created: `bookmark/{category}/{summary}-{id}`
2. Claude Code receives the bookmark context and implements the change
3. Changes are committed with a descriptive message
4. A PR is created with:
   - Link to the original tweet
   - AI analysis summary
   - Test checklist

### Customizing PR Generation

The prompt for Claude Code is in `CreatePRFromBookmarkJob.php`. You can modify:
- The branch naming convention
- The system prompt
- The PR template

## Troubleshooting

### "Missing bookmark.read scope"

Your X account was connected before bookmark support was added. Reconnect:
1. Go to Settings → Integrations
2. Disconnect X
3. Reconnect (bookmarks scope is now default)

### "Rate limited (24h)"

The free tier only allows 1 bookmark sync per day. Wait until the cooldown expires or upgrade your X API plan.

### "Bookmark access denied"

Verify your X app has the `bookmark.read` scope enabled in the X Developer Portal.

### Analysis fails

Check:
- `ANTHROPIC_API_KEY` is set
- API key has sufficient credits
- Check logs: `storage/logs/laravel.log`

## Files

| File | Purpose |
|------|---------|
| `app/Models/XBookmark.php` | Bookmark model |
| `app/Services/X/XService.php` | X API methods |
| `app/Jobs/SyncXBookmarksJob.php` | Sync job |
| `app/Jobs/AnalyzeXBookmarksJob.php` | AI analysis |
| `app/Jobs/CreatePRFromBookmarkJob.php` | PR generation |
| `app/Http/Controllers/XBookmarkController.php` | API controller |
| `app/Console/Commands/SyncXBookmarksCommand.php` | CLI command |
| `resources/js/Pages/Bookmarks/Index.vue` | Dashboard UI |
| `database/migrations/*_create_x_bookmarks_table.php` | Schema |
