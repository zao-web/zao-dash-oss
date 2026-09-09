# Social Media API Compliance Policy

This document outlines compliance requirements for X (Twitter), LinkedIn, and other social platform integrations to prevent account bans and ToS violations.

## Why This Matters

Developers have had their **personal and business accounts permanently banned** for:
- Automated behavior that looks like spam or manipulation
- Using APIs to build user profiles or track individuals
- Exceeding rate limits or appearing to circumvent them
- Surveillance-like data collection patterns

This policy protects our connected accounts (@JS_Zao, @zaowebdev, LinkedIn profiles).

---

## API Separation: X API vs Grok API

We use **two separate APIs** from X/xAI with different purposes and terms:

| Service | Endpoint | Purpose | Terms |
|---------|----------|---------|-------|
| **X API** | api.twitter.com | Posting, reading our content | [X Developer Agreement](https://developer.x.com/en/developer-terms/agreement) |
| **Grok API** | api.x.ai | AI-powered trend analysis | [xAI Terms](https://x.ai/legal/terms-of-service) |

### X API (XService)
- Used for: Posting tweets, reading our timeline, OAuth
- Location: `app/Services/X/XService.php`
- Governed by: X Developer Agreement (strict)

### Grok API (GrokService)
- Used for: Trend analysis, hashtag research, content optimization
- Location: `app/Services/Grok/GrokService.php`
- Governed by: xAI Terms of Service (separate company, different rules)
- Note: Grok has live X data access but this is xAI's implementation, not our scraping

---

## X Developer Agreement Compliance

### Section III.A - Prohibited Actions

**We MUST NOT:**

| Prohibition | Reference | Our Safeguard |
|-------------|-----------|---------------|
| Create a Twitter clone | §III.A(c) | We're a business tool, not a social network |
| Redistribute X Content to third parties | §III.A(d) | Content stays internal |
| Train AI models on X Content | §III.A(k) | We use Grok API (separate service) |
| Target ads using X data | §III.A(l) | We don't serve ads |

### Section XIV.B - User Protection (CRITICAL)

**Absolutely PROHIBITED uses of X data:**

1. **Surveillance or intelligence gathering**
   - No tracking individual users' posting patterns
   - No monitoring who follows whom
   - No building profiles of users

2. **Investigating or tracking X users**
   - No following users to see their activity
   - No analyzing users' engagement patterns
   - No identifying leads from social activity

3. **Targeting based on sensitive information**
   - No targeting based on inferred health, politics, religion
   - No profiling based on X activity

### Safe Uses

| Use Case | Allowed? | Notes |
|----------|----------|-------|
| Post our own content | ✅ Yes | With human approval |
| Read our own metrics | ✅ Yes | Analytics for our posts |
| Search for topic trends | ✅ Yes | Topic-based, not user-based |
| Get trending hashtags | ✅ Yes | Via Grok API |
| Build prospect lists from followers | ❌ No | Surveillance |
| Track competitor engagement | ❌ No | Surveillance |
| Find leads from X activity | ❌ No | Tracking users |

---

## Rate Limits

Conservative daily limits to stay safe:

| Account | Limit | Reasoning |
|---------|-------|-----------|
| Personal (@JS_Zao) | 10 posts/day | High-signal thought leadership |
| Company (@zaowebdev) | 25 posts/day | Educational/promotional |

These are enforced in `PostToXTool.php` via `DAILY_LIMITS` constant.

---

## Agent Compliance Rules

All agents with social media access MUST follow these rules:

### Market Research Agent
```
NEVER use social media data to:
- Build profiles of individual users
- Track specific individuals' activity
- Identify leads from followers/following
- Monitor competitors' social engagement

ALLOWED:
- Web search for company information
- Published reports and news
- Aggregate trend data via Grok
```

### Lead Generation Agent
```
PROHIBITED:
- Scraping X/LinkedIn for prospects
- Building lists from social followers
- Monitoring users' posting for "signals"

ALLOWED:
- Web search for company websites
- Public business directories
- Job postings and press releases
```

### Content Agents (Marketing, ContentCreator)
```
ALLOWED:
- Analyze trends via Grok API
- Draft content for approval
- Post with human approval

REQUIRED:
- All posts need human approval
- No automated @mentions
- No duplicate content across accounts
```

---

## LinkedIn Compliance

LinkedIn has even stricter automation policies:

### Prohibited
- Automated connection requests
- Automated InMail/messaging
- Scraping profiles for lead data
- Mass data collection of any kind

### Allowed
- Manual posting (with approval workflow)
- Reading public company pages
- OAuth authentication

Our `PostToLinkedInTool` requires approval and only creates posts - no automation of connections or messaging.

---

## Audit Trail

All social media API calls are logged for compliance auditing:

```php
// XService.php - searchTweets logs all queries
Log::info('X API search executed', [
    'query' => $query,
    'max_results' => $maxResults,
    'user_id' => $credential->user_id,
]);
```

Review logs periodically:
```bash
grep "X API" storage/logs/laravel.log | tail -100
```

---

## If We Get a Warning

If X or LinkedIn contacts us about API usage:

1. **Immediately pause** all automated posting
2. **Review logs** for the time period mentioned
3. **Document** what agents were running
4. **Respond promptly** with explanation of business use case
5. **Adjust policies** based on feedback

---

## Code References

| File | Purpose | Risk Level |
|------|---------|------------|
| `app/Services/X/XService.php` | X API integration | Medium |
| `app/Services/Grok/GrokService.php` | Grok API (separate) | Low |
| `app/Agents/Tools/PostToXTool.php` | Posting with approval | Low |
| `app/Agents/Tools/GetXTrendsTool.php` | Trends via Grok | Low |
| `app/Agents/Definitions/MarketResearchAgent.php` | Research agent | Medium |
| `app/Agents/Definitions/LeadGenerationAgent.php` | Lead research | Medium |

---

## Compliance Checklist

Before adding new social media features:

- [ ] Does this track individual users? → If yes, STOP
- [ ] Does this build profiles from social data? → If yes, STOP
- [ ] Does this look like surveillance? → If yes, STOP
- [ ] Is there human approval in the loop? → Should be yes
- [ ] Are rate limits implemented? → Must be yes
- [ ] Is there an audit trail? → Should be yes
- [ ] Is the use case documented? → Must be yes

---

## Version History

| Date | Change |
|------|--------|
| 2026-01-01 | Initial compliance policy created |
