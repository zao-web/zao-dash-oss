# Programmatic SEO System

Automated SEO content generation at scale for lead capture and organic growth.

## Overview

| Component | Purpose |
|-----------|---------|
| ProgrammaticSeoAgent | Orchestrates SEO research and content generation |
| SeoResearchService | Keyword research, SERP analysis, content generation |
| SEO Tools | Agent tools for all SEO operations |

**Philosophy:** Template + Data = Scale. Generate landing pages and blog posts programmatically based on patterns in our work and target keywords.

---

## Environment Variables

```bash
# Required for SEO functionality
GROK_API_KEY=xai-your-key  # Powers keyword research and content generation

# Optional - for production search volume data
SEMRUSH_API_KEY=           # More accurate volume data
AHREFS_API_KEY=            # Competitor analysis
```

---

## ProgrammaticSeoAgent

### Configuration

- **Schedule:** Monday 7am (before Marketing Agent)
- **Model:** Sonnet
- **Budget:** $8.00
- **Approval:** Required for all content

### Tools Available

| Tool | Purpose | Approval |
|------|---------|----------|
| `seo-keyword-research` | Find keywords for a topic | No |
| `seo-analyze-serp` | Analyze top-ranking content | No |
| `seo-competitor-gaps` | Find competitor keyword gaps | No |
| `seo-search-volume` | Get volume/difficulty data | No |
| `seo-generate-landing` | Create SEO landing page | No |
| `seo-generate-blog` | Create SEO blog post | No |
| `seo-optimize-content` | Optimize existing content | No |
| `get-quarterly-patterns` | Our work patterns for targeting | No |
| `wp-create-page` | Publish to WordPress | **Yes** |
| `wp-create-post` | Publish to WordPress | **Yes** |

### Weekly Workflow

```
Monday 7am: ProgrammaticSeoAgent runs
    │
    ├── 1. Analyze quarterly patterns (get-quarterly-patterns)
    │      └── Identify industries/services we've focused on
    │
    ├── 2. Keyword research (seo-keyword-research)
    │      └── Find keywords for each pattern
    │
    ├── 3. Prioritize opportunities (seo-search-volume)
    │      └── Score by volume × intent × our expertise
    │
    ├── 4. Analyze competition (seo-analyze-serp)
    │      └── Understand what ranks, find gaps
    │
    ├── 5. Generate content (seo-generate-landing/blog)
    │      └── Create SEO-optimized pages
    │
    └── 6. Submit for approval
           └── Human reviews in /approvals
```

---

## Content Types

### 1. Service + Industry Pages

Target keywords like "WordPress development for healthcare":

```
Keyword Pattern: [Service] for [Industry]
Examples:
- WordPress development for financial services
- WooCommerce for healthcare
- Laravel API development for SaaS
```

### 2. Service + Location Pages

Target keywords like "WordPress agency in Denver":

```
Keyword Pattern: [Service] in [Location]
Examples:
- WordPress development agency in Denver
- Laravel developers in Austin
- WooCommerce experts in Chicago
```

### 3. Problem-Solution Blog Posts

Target keywords around specific problems:

```
Keyword Pattern: How to [solve problem]
Examples:
- How to migrate from Shopify to WooCommerce
- How to secure WordPress for enterprise
- How to scale Laravel applications
```

### 4. Comparison Pages

Target keywords for decision-stage searches:

```
Keyword Pattern: [Option A] vs [Option B]
Examples:
- Laravel vs Django for enterprise
- WooCommerce vs Shopify for B2B
- WordPress vs headless CMS
```

---

## SeoResearchService

Located at `app/Services/Seo/SeoResearchService.php`.

### Key Methods

```php
// Keyword research
$service->keywordResearch($topic, $seedKeywords, $intentFilter);

// SERP analysis
$service->analyzeSerpForKeyword($keyword);

// Competitor gaps
$service->findCompetitorGaps($competitorUrls, $ourKeywords);

// Search volume
$service->getSearchVolume($keywords);

// Content generation
$service->generateLandingPage($keyword, $pageType, $templateStyle);
$service->generateBlogPost($keyword, $topicAngle, $wordCountTarget);

// Optimization
$service->optimizeContent($currentContent, $targetKeyword);
```

---

## On-Page SEO Standards

All generated content must include:

### Required Elements

- [ ] Meta title (under 60 chars, keyword front-loaded)
- [ ] Meta description (under 160 chars, includes CTA)
- [ ] H1 headline with primary keyword
- [ ] Primary keyword in first 100 words
- [ ] URL slug with primary keyword
- [ ] Proper heading hierarchy (H1 > H2 > H3)
- [ ] Image alt text with keyword variants
- [ ] Internal links to related content
- [ ] Schema markup (JSON-LD)

### Schema Types

| Page Type | Schema |
|-----------|--------|
| Service page | Service, Organization |
| Location page | LocalBusiness |
| Blog post | Article, FAQPage |
| Comparison | Article |

---

## Integration with Other Agents

### MarketingAgent
- Uses `get-x-trends` for social content
- Can reference SEO content for promotion

### LandingPageGeneratorAgent
- Now includes all SEO tools
- Can generate SEO-first landing pages

### ContentCreatorAgent
- Uses SEO tools for blog optimization
- Ensures case studies rank

### WordPressAgent
- Publishes SEO-generated content
- Handles schema markup implementation

---

## Usage Examples

### Manual Keyword Research

```bash
php artisan tinker
>>> $seo = app(\App\Services\Seo\SeoResearchService::class);
>>> $seo->keywordResearch('WordPress development', ['WooCommerce', 'Laravel'], 'commercial');
```

### Trigger Agent Manually

```bash
php artisan agent:trigger programmatic-seo
```

### Check Generated Content

Generated content awaits approval at `/approvals` before publishing.

---

## Performance Tracking

### Tools Available

| Tool | Purpose | Data Source |
|------|---------|-------------|
| `seo-get-rankings` | Keyword positions, impressions, clicks | Search Console |
| `seo-track-page` | Page KPIs with period comparison | Search Console |
| `seo-get-pseo-performance` | PSEO summary across all pages | Search Console |
| `seo-get-conversions` | Form submissions from organic | Analytics |

### SearchConsoleService

Located at `app/Services/Google/SearchConsoleService.php`.

Key methods:
```php
// Keyword rankings
$service->getKeywordRankings($user, $siteUrl, ['keyword1', 'keyword2'], 28);

// Page performance with period comparison
$service->trackSeoKpis($user, $siteUrl, $pageUrl);

// PSEO summary (filters by /services/, /industries/ URLs)
$service->getPseoPerformance($user, $siteUrl);

// Conversion data from Analytics
$service->getSeoConversions($user, $propertyId, 'form_submit');
```

### OAuth Scopes

Search Console and Analytics scopes are included in Google OAuth:
```php
'https://www.googleapis.com/auth/webmasters.readonly',    // Search Console
'https://www.googleapis.com/auth/analytics.readonly',     // Analytics
```

Users must re-authorize to get new scopes if already connected.

### KPIs to Track

| Metric | Target | Frequency |
|--------|--------|-----------|
| Indexed pages | 100+ | Weekly |
| Avg position | < 20 | Weekly |
| Organic CTR | > 3% | Weekly |
| Conversions/month | 10+ | Monthly |
| Traffic growth | +10% MoM | Monthly |

### Weekly Tracking Workflow

```
ProgrammaticSeoAgent (Monday 7am):
├── Review last week's content performance
│   └── seo-get-pseo-performance → overall metrics
├── Track new pages (published 2+ weeks ago)
│   └── seo-track-page → page-level KPIs
├── Monitor keyword movements
│   └── seo-get-rankings → position changes
├── Calculate ROI
│   └── seo-get-conversions → attribution
└── Adjust strategy based on data
    └── Prioritize content types with best ROI
```

---

## Troubleshooting

### "Content generation requires Grok API"

- Ensure `GROK_API_KEY` is set in `.env`
- Run `php artisan config:clear`

### Low-quality keyword suggestions

- Grok provides estimates; use SEMrush/Ahrefs for production data
- Add more seed keywords for better targeting

### Content not ranking

- Check Search Console for indexing issues
- Review on-page SEO checklist
- Consider content length vs competitors
- Add more internal links

---

## Future Enhancements

1. **SEMrush/Ahrefs Integration** - Real search volume data
2. **Rank Tracking** - Monitor generated pages automatically
3. **A/B Testing** - Test different page variants
4. **Content Refresh** - Automatically update aging content
5. **Featured Snippet Optimization** - Target position zero
