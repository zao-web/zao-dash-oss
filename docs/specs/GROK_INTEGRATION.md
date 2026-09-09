# Grok Integration (X/Twitter Real-Time Search)

Real-time X/Twitter data via xAI's Grok API using the `x_search` and `web_search` tools.

## IMPORTANT: API Separation

**Grok API ≠ X API** - These are completely separate services:

| Service | Provider | Endpoint | Terms |
|---------|----------|----------|-------|
| **Grok API** | xAI | api.x.ai | [xAI Terms](https://x.ai/legal/terms-of-service) |
| **X API** | X Corp | api.twitter.com | [X Developer Agreement](https://developer.x.com/en/developer-terms/agreement) |

When we use `x_search`, Grok searches X in real-time on our behalf. This is xAI's official tool, not us scraping Twitter.

See [SOCIAL_MEDIA_COMPLIANCE.md](../SOCIAL_MEDIA_COMPLIANCE.md) for full policy.

---

## Overview

| Feature | Description |
|---------|-------------|
| Provider | xAI (Grok) |
| Endpoint | `/v1/responses` |
| Model | `grok-4-1-fast` |
| Tools | `x_search`, `web_search` |
| Pricing | Tools are **free** (only pay for tokens) |

**Key Advantage:** The `x_search` tool performs actual real-time searches on X, returning citations to real posts - not just model knowledge.

---

## Environment Variables

```bash
GROK_API_KEY=xai-your-api-key
```

---

## Setup

### 1. Get API Key

1. Go to [xAI Console](https://console.x.ai)
2. Create account or sign in
3. Generate API key
4. Add to `.env` as `GROK_API_KEY`

### 2. Verify Connection

```bash
php artisan tinker
>>> app(\App\Services\Grok\GrokService::class)->isConfigured();
# Should return true
```

---

## API Structure

The Grok service uses the `/v1/responses` endpoint with tools:

```bash
curl https://api.x.ai/v1/responses \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $XAI_API_KEY" \
  -d '{
  "model": "grok-4-1-fast",
  "input": [
    {"role": "user", "content": "What is trending in WordPress development?"}
  ],
  "tools": [
    {"type": "x_search", "from_date": "2025-12-25"}
  ]
}'
```

---

## GrokService Methods

Located at `app/Services/Grok/GrokService.php`.

### analyzeTrends(topic, options)

Search X for trends on a topic.

```php
$grok = app(GrokService::class);
$result = $grok->analyzeTrends('WordPress development');
// Returns: topic, analysis, citations, model, analyzed_at
```

### getTrendingHashtags(industry)

Get trending hashtags with real citations.

```php
$result = $grok->getTrendingHashtags('web development');
// Returns: industry, hashtags[], citations[], fetched_at
// Cached for 1 hour
```

### analyzeContentFormats(niche)

Analyze what content formats are performing well.

```php
$result = $grok->analyzeContentFormats('SaaS');
// Returns: niche, analysis, citations[], analyzed_at
```

### getTopicSentiment(topic)

Get sentiment around a topic.

```php
$result = $grok->getTopicSentiment('AI coding assistants');
// Returns: topic, sentiment, citations[], analyzed_at
```

### suggestPosts(brand, industry, recentWork)

Generate post suggestions based on current trends.

```php
$result = $grok->suggestPosts(
    brand: 'Zao',
    industry: 'WordPress/Laravel agency',
    recentWork: ['WooCommerce migration', 'Laravel API rebuild']
);
// Returns: brand, industry, suggestions, citations[], generated_at
```

### optimizePost(draft, goal)

Optimize a draft using current best practices.

```php
$result = $grok->optimizePost(
    draft: 'Just shipped a new feature!',
    goal: 'engagement'
);
// Returns: original, goal, optimization, citations[], analyzed_at
```

### webSearch(query, options)

General web search (not X-specific).

```php
$result = $grok->webSearch('Laravel 11 new features');
// Returns: query, result, citations[], searched_at
```

### query(prompt, options)

Low-level method for custom queries.

```php
$result = $grok->query('Search X for discussions about Gutenberg blocks', [
    'tools' => ['x_search'],
    'from_date' => '2025-12-01',
    'to_date' => '2025-12-31',
]);
```

---

## Tool Options

### x_search Options

| Option | Type | Description |
|--------|------|-------------|
| `from_date` | string | Start date (YYYY-MM-DD) |
| `to_date` | string | End date (YYYY-MM-DD) |
| `allowed_x_handles` | array | Only search these accounts |

### web_search Options

| Option | Type | Description |
|--------|------|-------------|
| `excluded_domains` | array | Exclude these domains (max 5) |
| `allowed_domains` | array | Only search these domains |

---

## Agent Tool: get-x-trends

The `GetXTrendsTool` exposes Grok's capabilities to AI agents.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| analysis_type | string | Yes | trends, hashtags, content_formats, sentiment, suggestions, optimize |
| topic | string | Yes | Topic/industry to analyze |
| draft | string | No | Post to optimize (only for optimize type) |
| recent_work | array | No | Recent projects (for suggestions) |

### Example Response

```json
{
  "success": true,
  "analysis_type": "hashtags",
  "topic": "WordPress development",
  "data": {
    "industry": "WordPress development",
    "hashtags": [
      {"hashtag": "#WordPress", "engagement": "high", "trend_direction": "stable"},
      {"hashtag": "#WPDev", "engagement": "medium", "trend_direction": "rising"}
    ],
    "fetched_at": "2026-01-01T08:00:00Z"
  },
  "citations": [
    {"url": "https://x.com/user/status/123", "title": "..."}
  ],
  "note": "Data sourced from real-time X search via Grok x_search tool."
}
```

---

## Citations

All responses include citations to actual X posts or web pages:

```php
$result = $grok->analyzeTrends('Laravel');

foreach ($result['citations'] as $citation) {
    echo $citation['url'];     // Link to source
    echo $citation['title'];   // Title/preview
}
```

---

## Caching

Hashtag data is cached for 1 hour:

```php
// Cache key format
"grok_hashtags_{industry}_{date-hour}"
```

Other methods are not cached (real-time is the point).

---

## Pricing

- **Tools**: Free (x_search, web_search)
- **Tokens**: Standard Grok pricing
- Check [xAI Pricing](https://x.ai/pricing) for current rates

---

## Troubleshooting

### "Grok API not configured"

```bash
# Verify key is set
php artisan tinker
>>> config('services.grok.api_key')

# Clear config cache
php artisan config:clear
```

### Empty or no citations

- x_search might not find relevant posts
- Try broader search terms
- Check the date range (default is 7 days)

### API errors

```bash
# Check logs
tail -f storage/logs/laravel.log | grep -i grok
```

### Rate limits

- Grok API has rate limits per minute
- The service uses 60s timeout for search queries
- If hitting limits, add delays between calls

---

## Security Notes

- API key stored in `.env`, never committed
- All requests logged for audit
- No user-specific tracking (topic-based only)
- Citations link to public posts only
