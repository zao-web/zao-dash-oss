# LinkedIn & X (Twitter) Integration

Social media integrations for the MarketingAgent to post content directly to LinkedIn and X.

> **COMPLIANCE NOTE**: Before using or extending these integrations, review the [Social Media Compliance Policy](../SOCIAL_MEDIA_COMPLIANCE.md). Misuse can result in permanent account bans.

## Overview

| Platform | OAuth Version | Posting | Threads | Company Pages |
|----------|---------------|---------|---------|---------------|
| LinkedIn | OAuth 2.0 | Yes | No | Yes |
| X (Twitter) | OAuth 2.0 + PKCE | Yes | Yes | No |

---

## Environment Variables

```bash
# LinkedIn
LINKEDIN_CLIENT_ID=your-client-id
LINKEDIN_CLIENT_SECRET=your-client-secret
LINKEDIN_REDIRECT_URI=https://yourdomain.com/auth/linkedin/callback

# X (Twitter)
X_CLIENT_ID=your-client-id
X_CLIENT_SECRET=your-client-secret
X_REDIRECT_URI=https://yourdomain.com/auth/x/callback
```

---

## LinkedIn Setup

### 1. Create LinkedIn App

1. Go to [LinkedIn Developers](https://developer.linkedin.com)
2. Create a new app
3. Request products:
   - **Share on LinkedIn** (required for posting)
   - **Sign In with LinkedIn using OpenID Connect**
4. Under Auth tab, add OAuth 2.0 redirect URL

### 2. Required Scopes

```
openid
profile
email
w_member_social      # Post as member
w_organization_social # Post as company (optional)
```

### 3. OAuth Flow

```
GET /auth/linkedin
  → Redirects to LinkedIn authorization
  → User grants permissions
  → Callback to /auth/linkedin/callback
  → Stores LinkedInCredential
```

### 4. Database Schema

```php
// linked_in_credentials table
Schema::create('linked_in_credentials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('linkedin_id')->unique();
    $table->string('name')->nullable();
    $table->string('email')->nullable();
    $table->string('headline')->nullable();
    $table->string('profile_url')->nullable();
    $table->text('access_token');
    $table->text('refresh_token')->nullable();
    $table->timestamp('token_expires_at')->nullable();
    $table->json('scopes')->nullable();
    $table->string('organization_id')->nullable();  // For company posting
    $table->string('organization_name')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 5. Service Methods

```php
// app/Services/LinkedIn/LinkedInService.php

// Create a text post
$linkedin->createPost($credential, "Post content here");

// Share an article with commentary
$linkedin->shareArticle($credential, "Commentary", "https://article.url", "Article Title");

// Post as company page
$linkedin->postAsOrganization($credential, "Company update");
```

---

## X (Twitter) Setup

### 1. Create X Developer App

1. Go to [X Developer Portal](https://developer.x.com)
2. Create a project and app
3. Set app permissions to **Read and write**
4. Enable OAuth 2.0 with **Confidential client**
5. Add callback URL

> **Note**: X API requires paid tier ($100/mo Basic) for posting. Free tier is read-only.

### 2. Required Scopes

```
tweet.read
tweet.write
users.read
offline.access  # For refresh tokens
```

### 3. OAuth Flow (with PKCE)

```
GET /auth/x
  → Generates code_verifier + code_challenge
  → Stores in session
  → Redirects to X authorization
  → User grants permissions
  → Callback to /auth/x/callback
  → Exchanges code with code_verifier
  → Stores XCredential
```

### 4. Database Schema

```php
// x_credentials table
Schema::create('x_credentials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('x_user_id')->unique();
    $table->string('username');
    $table->string('name')->nullable();
    $table->string('profile_image_url')->nullable();
    $table->boolean('verified')->default(false);
    $table->unsignedInteger('followers_count')->default(0);
    $table->unsignedInteger('tweet_count')->default(0);
    $table->text('access_token');
    $table->text('refresh_token')->nullable();
    $table->timestamp('token_expires_at')->nullable();
    $table->json('scopes')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 5. Service Methods

```php
// app/Services/X/XService.php

// Post a single tweet
$x->tweet($credential, "Tweet content");

// Reply to a tweet
$x->tweet($credential, "Reply content", $replyToId);

// Post a thread (array of tweets)
$x->postThread($credential, ["Tweet 1", "Tweet 2", "Tweet 3"]);
```

---

## Agent Tools

### PostToLinkedInTool

Used by MarketingAgent to post to LinkedIn.

```php
// Tool ID: post-to-linked-in
// Requires approval: Yes

$params = [
    'content' => 'Post text (max 3000 chars)',
    'post_type' => 'text',  // or 'article'
    'article_url' => 'https://...',  // if post_type is article
    'article_title' => 'Optional title',
    'as_organization' => false,  // true to post as company
];
```

### PostToXTool

Used by MarketingAgent to post to X/Twitter.

```php
// Tool ID: post-to-x
// Requires approval: Yes

$params = [
    'content' => 'Tweet content',
    'is_thread' => false,  // true to auto-split long content
    'reply_to_id' => null,  // optional tweet ID to reply to
];
```

---

## API Endpoints

### LinkedIn

```
GET  /auth/linkedin                    # Start OAuth flow
GET  /auth/linkedin/callback           # OAuth callback
GET  /api/integrations/linkedin/status # Connection status
POST /api/integrations/linkedin/disconnect
POST /api/integrations/linkedin/post   # Manual post (requires auth)
POST /api/integrations/linkedin/article
POST /api/integrations/linkedin/organization-post
```

### X (Twitter)

```
GET  /auth/x                    # Start OAuth flow
GET  /auth/x/callback           # OAuth callback
GET  /api/integrations/x/status # Connection status
POST /api/integrations/x/disconnect
POST /api/integrations/x/tweet          # Manual tweet
POST /api/integrations/x/thread         # Manual thread
```

---

## Social Posts Tracking

All posts are tracked in the `social_posts` table:

```php
Schema::create('social_posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('platform');  // linkedin, x
    $table->string('platform_post_id')->nullable();
    $table->text('content');
    $table->string('status');  // draft, scheduled, published, failed
    $table->timestamp('scheduled_for')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->json('metrics')->nullable();  // likes, shares, etc.
    $table->string('error_message')->nullable();
    $table->timestamps();
});
```

---

## Integration Settings UI

Navigate to **Settings → Integrations** to:

1. Connect LinkedIn account
2. Connect X account
3. View connection status and token expiration
4. Disconnect accounts

Each integration card shows:
- Connection status
- Profile info (name, username, followers)
- Organization info (LinkedIn only)
- Token expiration date
- Setup guide with step-by-step instructions

---

## MarketingAgent Workflow

The MarketingAgent uses these integrations to:

1. Analyze completed projects and client verticals
2. Draft social media content
3. Submit posts for approval via `post-to-linked-in` and `post-to-x` tools
4. Posts are published after human approval

```
MarketingAgent (Monday 8am)
    ↓
Drafts LinkedIn post about recent project
    ↓
Calls post-to-linked-in tool
    ↓
ApprovalRequest created (pending)
    ↓
Human approves in /approvals
    ↓
Post published to LinkedIn
```

---

## Troubleshooting

### LinkedIn "insufficient permissions"
- Ensure "Share on LinkedIn" product is approved
- Re-authenticate to refresh scopes

### X "403 Forbidden"
- Check API tier (Basic required for posting)
- Verify app permissions are "Read and write"

### Token refresh failing
- LinkedIn tokens expire in 60 days
- X tokens expire in 2 hours (refresh automatically)
- Check `token_expires_at` in credentials table

### Rate limits
- LinkedIn: 100 posts/day per member
- X: Varies by tier, check developer dashboard
