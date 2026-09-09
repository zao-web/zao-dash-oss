# Meta Ads Integration

AI-powered Facebook & Instagram ads optimization using the Meta Marketing API and MCP (Model Context Protocol).

## Overview

This integration allows you to:
- Analyze campaign performance in natural language
- Auto-pause underperforming ads
- Optimize budgets based on ROI
- Create hyper-local campaigns
- Get daily performance alerts
- Generate insights and recommendations

Perfect for **local business clients** who need Meta Ads management without hiring an agency.

---

## Prerequisites

1. **Meta Business Account** with ads manager access
2. **Meta Developer App** with Marketing API permissions
3. **Ad Account ID** for the business you're managing

---

## Setup Instructions

### Step 1: Create Meta Developer App

1. Go to [Meta for Developers](https://developers.facebook.com/)
2. Click **My Apps** → **Create App**
3. Choose **Business** as app type
4. Fill in app details:
   - **App Name**: "Zao AI Ads Manager"
   - **Contact Email**: your email
   - **Business Account**: Link your Meta Business account

5. Once created, go to **Settings** → **Basic**
   - Note your **App ID** and **App Secret**

### Step 2: Add Marketing API Product

1. In your app dashboard, click **Add Product**
2. Find **Marketing API** and click **Set Up**
3. Complete the setup wizard

### Step 3: Generate Access Token

You have two options:

#### Option A: User Access Token (Quick Start)

1. Go to [Meta Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Select your app from the dropdown
3. Click **Generate Access Token**
4. Grant these permissions:
   - `ads_read`
   - `ads_management`
   - `business_management`
5. Copy the access token
6. **Important**: This token expires in 60 days. For production, use Option B.

#### Option B: Long-Lived Token (Production)

```bash
# Exchange short-lived token for long-lived (60 days)
curl -G "https://graph.facebook.com/v19.0/oauth/access_token" \
  -d "grant_type=fb_exchange_token" \
  -d "client_id=YOUR_APP_ID" \
  -d "client_secret=YOUR_APP_SECRET" \
  -d "fb_exchange_token=SHORT_LIVED_TOKEN"
```

For permanent access, use **System User** tokens:
1. Go to Business Settings → Users → System Users
2. Create new system user
3. Assign ad account access
4. Generate token (never expires)

### Step 4: Find Your Ad Account ID

1. Go to [Meta Ads Manager](https://business.facebook.com/adsmanager/)
2. Look at URL: `https://business.facebook.com/adsmanager/manage/campaigns?act=XXXXXXXXX`
3. Your Ad Account ID is `act_XXXXXXXXX` (including the `act_` prefix)

### Step 5: Configure Zao Dashboard

Update your `.env` file:

```bash
# Meta Ads Integration
META_ADS_ACCESS_TOKEN=EAABwzLixnjYB...   # Your access token
META_ADS_APP_ID=123456789012345           # From app settings
META_ADS_APP_SECRET=abcdef123456...       # From app settings
META_ADS_AD_ACCOUNT_ID=act_123456789      # From ads manager URL
META_ADS_BUSINESS_ID=                     # Optional
```

---

## Install Meta Ads MCP Server

The Meta Ads MCP server allows Claude (and your agents) to interact with Meta Ads API.

### Installation

```bash
cd /path/to/zao-dash

# Install the Meta Ads MCP server package
npm install @pipeboard-co/meta-ads-mcp
# OR use another MCP implementation:
# npm install @gomarble/facebook-ads-mcp-server
```

### Register MCP Server

Add to `routes/ai.php`:

```php
use Laravel\Mcp\Facades\Mcp;

// Register Meta Ads MCP server
Mcp::stdio('meta-ads', [
    'command' => 'npx',
    'args' => ['-y', '@pipeboard-co/meta-ads-mcp'],
    'env' => [
        'META_ACCESS_TOKEN' => config('services.meta.access_token'),
        'META_AD_ACCOUNT_ID' => config('services.meta.ad_account_id'),
    ],
]);
```

### Configure Service

Create `config/services.php` entry (or add to existing):

```php
'meta' => [
    'access_token' => env('META_ADS_ACCESS_TOKEN'),
    'app_id' => env('META_ADS_APP_ID'),
    'app_secret' => env('META_ADS_APP_SECRET'),
    'ad_account_id' => env('META_ADS_AD_ACCOUNT_ID'),
    'business_id' => env('META_ADS_BUSINESS_ID'),
],
```

---

## Available MCP Tools

Once configured, these tools are available to agents:

| Tool | Description |
|------|-------------|
| `list_campaigns` | Get all campaigns with status and spend |
| `get_campaign_insights` | Detailed performance metrics for a campaign |
| `list_adsets` | Get ad sets with targeting and budgets |
| `get_adset_insights` | Performance data for specific ad set |
| `list_ads` | Get individual ads with creative info |
| `get_ad_insights` | Detailed ad performance |
| `pause_ad` | Pause underperforming ad |
| `update_budget` | Adjust campaign or ad set budget |
| `create_campaign` | Create new campaign (advanced) |

---

## Create Meta Ads Optimizer Agent

### Option 1: Via Database Seeder

Add to `database/seeders/DatabaseSeeder.php`:

```php
Agent::create([
    'name' => 'Meta Ads Optimizer',
    'slug' => 'meta-ads-optimizer',
    'description' => 'Monitors Facebook/Instagram ads, pauses losers, scales winners, provides daily insights.',
    'model' => 'sonnet',
    'budget_usd' => 2.00,
    'requires_approval' => false,  // Set true for budget changes
    'is_active' => true,
    'schedule' => '0 9 * * *',  // Daily at 9am
    'system_prompt' => <<<'PROMPT'
You are a Meta Ads optimization agent for local businesses.

Your job:
1. Analyze campaign performance daily
2. Identify underperforming ads (high spend, low ROI)
3. Pause ads with CTR < 1% and spend > $20 without conversions
4. Recommend budget increases for high-performing ads (CTR > 3%, CPA below target)
5. Alert on anomalies (sudden drops in performance, budget overspend)

Always use the Meta Ads MCP tools to fetch real data.

Output format:
{
  "summary": "Brief overview of account health",
  "alerts": ["List of urgent issues"],
  "paused_ads": ["IDs of ads paused"],
  "recommendations": ["Actionable suggestions"],
  "top_performers": ["Best performing ads/campaigns"]
}

Client Context:
Business Type: {{business_type}}
Target CPA: {{target_cpa}}
Monthly Budget: {{monthly_budget}}
Service Area: {{service_area}}
PROMPT,
    'tools' => ['meta_ads_mcp'],
    'output_destinations' => ['slack_notify', 'email_summary'],
]);
```

### Option 2: Via UI

1. Navigate to `/agents` in Zao Dashboard
2. Click **Create Agent**
3. Fill in details:
   - **Name**: Meta Ads Optimizer
   - **Slug**: `meta-ads-optimizer`
   - **Description**: Daily Meta Ads monitoring and optimization
   - **Model**: Sonnet (cost-effective for daily checks)
   - **Budget**: $2.00/run
   - **Schedule**: `0 9 * * *` (9am daily)
   - **Tools**: Select "Meta Ads MCP"
4. Paste the system prompt from above
5. Click **Create**

---

## Usage Examples

### Manual Trigger

```bash
php artisan agent:run meta-ads-optimizer
```

### Via API

```bash
curl -X POST https://example.com/api/agents/meta-ads-optimizer/trigger \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "business_type": "plumbing",
    "target_cpa": 50,
    "monthly_budget": 2000,
    "service_area": "Denver Metro"
  }'
```

### Via Agent Chain

```php
// After website project completes, trigger ad setup
Agent::where('slug', 'meta-ads-setup')
    ->first()
    ?->execute([
        'client_id' => $client->id,
        'campaign_objective' => 'lead_generation',
        'daily_budget' => 50,
    ]);
```

---

## Client Package: Local Ads Management

Here's how to package this for local business clients:

### Starter Ad Management - $750/month

**What's Included:**
- Meta Ads account setup
- AI monitors ads daily
- Auto-pauses underperformers
- Weekly performance reports
- Monthly strategy review call

**Client Setup:**
1. Get Meta Business Manager access
2. Set target CPA and monthly budget
3. Agent runs daily at 9am
4. Client receives Slack/email summaries

### Premium Ad Management - $1,500/month

**Everything in Starter, plus:**
- AI writes new ad copy variations
- A/B test recommendations
- Audience expansion suggestions
- Competitor analysis
- Bi-weekly optimization calls

---

## Hyper-Local Targeting Setup

For local businesses, use geofencing and local awareness features:

```php
// Example agent config for local targeting
'targeting' => [
    'geo_locations' => [
        'cities' => [
            [
                'key' => '2490299',  // Denver
                'name' => 'Denver',
                'country' => 'US',
                'region' => 'Colorado',
            ],
        ],
        'radius' => 10,  // 10 mile radius
        'unit' => 'mile',
    ],
    'age_min' => 25,
    'age_max' => 65,
    'interests' => [
        'Home Improvement',
        'Home Services',
    ],
],
```

### Geo-Targeting Best Practices

1. **Start Narrow**: 5-10 mile radius around business
2. **Layer Interests**: Home services + homeowners + relevant keywords
3. **Time-of-Day**: Higher bids during business hours
4. **Exclude**: Areas you don't serve to save budget

---

## Monitoring & Alerts

### Daily Health Check

The agent should check:
- [ ] Campaign status (active/paused)
- [ ] Daily spend vs. budget
- [ ] CTR benchmarks (>1% is healthy)
- [ ] CPA vs. target
- [ ] New leads/conversions
- [ ] Ad frequency (>3 is ad fatigue)

### Alert Triggers

Set up Slack/email alerts when:
- Spend exceeds 110% of daily budget
- Zero conversions after spending $50+
- CTR drops below 0.5%
- CPA exceeds 2x target
- Campaign gets disabled by Meta (policy violation)

### Example Alert

```
🚨 Meta Ads Alert - ABC Plumbing

Campaign: Emergency Plumber - Denver
Status: Underperforming
Spend Today: $87.50
Conversions: 0
CTR: 0.3%

Action Taken:
✅ Paused ad set "Broad Targeting"
💡 Recommendation: Try narrower geo (5mi radius)

View Details: https://example.com/campaigns/123
```

---

## ROI Tracking

### Link to CRM

When leads come in via Meta Ads:

1. **Lead Form Submission** → Gravity Forms webhook captures lead
2. **Lead Record Created** with `source: meta_ads`, `campaign_id`, `ad_id`
3. **Revenue Attribution** when lead converts to customer
4. **ROI Calculation**:
   ```
   ROI = (Revenue - Ad Spend) / Ad Spend * 100
   ```

### Dashboard Metrics

Track these KPIs:
- **Cost Per Lead**: Ad spend / # leads
- **Lead-to-Customer Rate**: % of leads that become customers
- **Customer Acquisition Cost**: Total spend / # customers
- **Return on Ad Spend (ROAS)**: Revenue / Ad Spend
- **Lifetime Value**: Avg customer spend over 12 months

---

## Testing & Validation

### Test the Integration

```bash
# 1. Check MCP server is accessible
php artisan mcp:inspect meta-ads

# 2. Test listing campaigns
php artisan tinker
>>> $mcp = app(\Laravel\Mcp\McpClient::class);
>>> $campaigns = $mcp->callTool('meta-ads', 'list_campaigns', []);
>>> dd($campaigns);

# 3. Run agent in dry-run mode
php artisan agent:dry-run meta-ads-optimizer

# 4. Full test run
php artisan agent:run meta-ads-optimizer --verbose
```

### Verify Permissions

Make sure your access token has these permissions:
```bash
curl -G "https://graph.facebook.com/v19.0/me/permissions" \
  -d "access_token=YOUR_TOKEN"
```

Should return:
- `ads_read`
- `ads_management`
- `business_management`

---

## Troubleshooting

### Error: "Invalid OAuth access token"

**Cause**: Token expired or invalid
**Fix**:
1. Generate new access token in Graph API Explorer
2. Update `META_ADS_ACCESS_TOKEN` in `.env`
3. Restart queue workers: `php artisan queue:restart`

### Error: "Ad account not accessible"

**Cause**: System user doesn't have access to ad account
**Fix**:
1. Go to Business Settings → Ad Accounts
2. Find your ad account
3. Click **Assign People** → Add system user
4. Grant **Manage** permission

### Error: "Rate limit exceeded"

**Cause**: Too many API calls in short time
**Fix**:
1. Reduce agent frequency (daily instead of hourly)
2. Implement rate limiting in agent code
3. Cache campaign data for 1 hour

### No Campaigns Returned

**Cause**: Wrong ad account ID format
**Fix**: Ensure ID starts with `act_`
```
❌ Wrong: 123456789
✅ Correct: act_123456789
```

---

## Security Best Practices

1. **Never commit tokens** to git
2. **Use System Users** for production (tokens don't expire)
3. **Rotate tokens** every 90 days
4. **Limit permissions** to what's needed (don't request unnecessary scopes)
5. **Monitor API usage** in Meta App dashboard
6. **Enable 2FA** on Meta Business account

---

## Cost Breakdown

### API Costs
- Meta Marketing API: **Free** (no per-call charges)
- Rate limits: 200 calls/hour per ad account (plenty for daily checks)

### Agent Costs
- **Sonnet model**: ~$0.50-2.00 per daily run
- **Monthly**: ~$15-60 for daily monitoring
- **Scale**: Can manage 10+ clients with same agent

### Client Pricing
- **Your Cost**: $15-60/month (agent + API)
- **Client Price**: $750-1,500/month (50-100x markup)
- **Profit Margin**: 95%+

---

## Next Steps

1. **Set up Meta Developer App** (15 minutes)
2. **Generate access token** (5 minutes)
3. **Configure zao-dash** (10 minutes)
4. **Create first agent** (15 minutes)
5. **Test with your own ad account** (30 minutes)
6. **Onboard first client** (1 week)

---

## Resources

- [Meta Marketing API Docs](https://developers.facebook.com/docs/marketing-apis)
- [Meta Business Help Center](https://www.facebook.com/business/help)
- [Pipeboard Meta Ads MCP](https://github.com/pipeboard-co/meta-ads-mcp)
- [Zao Agent Documentation](./AGENTS.md)

---

*Built to help local businesses compete with enterprise-level ad automation.*
