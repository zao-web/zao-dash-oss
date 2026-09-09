# Meta Ads System - End-to-End Testing Guide

## System Status: Ready ✅

All components are in place and ready for testing:
- ✅ 9 Database tables migrated
- ✅ MetaAdsService with Meta API integration
- ✅ AdCreativeGenerationService (Claude + Nano Banana Pro)
- ✅ Real-time websocket events (3 broadcast events)
- ✅ GenerateAdCreativesJob (async processing)
- ✅ useMetaAdsRealtime composable
- ✅ Campaign creation UI + real-time progress UI
- ✅ Broadcast connection configured (Reverb)
- ✅ Queue configured (database driver)
- ✅ Meta sandbox credentials in .env

## Pre-Testing Setup

### 1. Start Required Services

You need **3 terminal windows** running simultaneously:

**Terminal 1: Laravel Dev Server**
```bash
php artisan serve
```

**Terminal 2: Queue Worker** (processes GenerateAdCreativesJob)
```bash
php artisan queue:work --queue=default
```

**Terminal 3: Reverb Server** (websocket broadcasts)
```bash
php artisan reverb:start
```

**Terminal 4 (optional): Vite Dev Server** (if making frontend changes)
```bash
npm run dev
```

### 2. Verify Services Are Running

Check that all services started successfully:
- ✅ Laravel: `http://localhost:8000` accessible
- ✅ Queue worker: Shows "Processing jobs from queue: default"
- ✅ Reverb: Shows "Reverb server started on localhost:8080"

---

## Test Scenario: Create AI-Generated Campaign

### Step 1: Navigate to Campaign Creation
1. Open browser: `http://localhost:8000/meta-ads/campaigns/create`
2. You should see a 4-step wizard

### Step 2: Fill Out Campaign Form

The form has smart defaults pre-filled. Verify these values:

**Step 1: Campaign Details**
- Campaign Name: `AI Workflow Transformation - Yamhill County`
- Objective: `Lead Generation` (recommended)
- Daily Budget: `$20` ✅ (user requested lower budget)
- Client: `None (Personal Campaign)` or select a client

**Step 2: Targeting**
- Landing Page URL: `https://example.com/ai`
- Location: `Newberg, OR`
- Radius: `25 miles`
- Age Range: `30-65`
- Interests: `small business, entrepreneurship, business management, workflow automation`

**Step 3: Goals & Budget**
- Target Cost Per Lead: `$40`
- Maximum Cost Per Lead: `$75`
- Minimum CTR: `1.0%`
- AI Optimization: `Enabled` ✅

**Step 4: Creative**
- Brand Guidelines: `Use Default Guidelines`
- Generate Creatives with AI: `Enabled` ✅
- Number of Creative Variations: `3`

### Step 3: Submit & Watch Real-Time Progress

1. Click **"Create Campaign"** on Step 4
2. You'll be redirected to `/meta-ads/campaigns/{id}`
3. **Watch the real-time progress UI** animate through these steps:

#### Expected Real-Time Updates (6+ broadcasts):

```
🔄 Starting AI creative generation for 3 variations...
   ↓
✅ Loaded brand guidelines
   ↓
✅ Generated 3 copy variations with Claude
   ↓
🎨 Generating image 1/3 with Nano Banana Pro...
   ↓
✅ Creative 1/3 ready: Cut Admin Time 90%...
   ↓
🎨 Generating image 2/3 with Nano Banana Pro...
   ↓
✅ Creative 2/3 ready: 20+ Oregon Businesses...
   ↓
🎨 Generating image 3/3 with Nano Banana Pro...
   ↓
✅ Creative 3/3 ready: Save 10+ Hours/Week...
   ↓
🔧 Creating ad set with targeting...
   ↓
📤 Pushing to Meta Ads API...
   ↓
🎉 Successfully generated 3 ad creatives!
```

### Step 4: Verify Real-Time UI Elements

Watch for these UI components to appear/update:

1. **Animated Spinner** - Spins during generation
2. **Progress Bar** - Updates from 0% → 100%
3. **Step Messages** - Shows current action
4. **Progress Percentage** - Shows "X%" in top-right
5. **Generation Log** - Collapsible details with timestamps
6. **"Approve & Launch" Button** - Appears on completion

### Step 5: Inspect Campaign Details

After completion, verify these sections on the campaign detail page:

1. **Campaign Header**
   - Campaign name
   - Objective (e.g., "leads")
   - "3 ad sets" text

2. **Campaign Settings Cards**
   - Daily Budget: `$20.00`
   - Target CPA: `$40.00`
   - Max CPA: `$75.00`
   - Automation: `Enabled`

3. **Ad Sets Section**
   - Should show 1 ad set: "AI Workflow Transformation - Yamhill County - Primary Audience"
   - Status: `draft`
   - "3 ads · $20.00/day"

4. **Performance Trend**
   - Should be empty (no data yet since campaign is draft)

---

## Verification Checklist

### Backend Verification

**1. Database Records Created**
```bash
php artisan tinker
```
```php
// Check campaign was created
$campaign = \App\Models\AdCampaign::latest()->first();
$campaign->name; // "AI Workflow Transformation - Yamhill County"
$campaign->status; // "pending_approval"
$campaign->daily_budget; // 20.00

// Check ad set was created
$campaign->adSets->count(); // 1
$adSet = $campaign->adSets->first();
$adSet->name; // "AI Workflow Transformation - Yamhill County - Primary Audience"

// Check ads were created
$adSet->ads->count(); // 3
$adSet->ads->pluck('name')->toArray();
// [
//   "AI Workflow Transformation - Yamhill County - Variant A",
//   "AI Workflow Transformation - Yamhill County - Variant B",
//   "AI Workflow Transformation - Yamhill County - Variant C"
// ]

// Check creatives were generated
$creatives = \App\Models\AdCreative::latest()->take(3)->get();
$creatives->pluck('headline')->toArray();
// Should show 3 different headlines

$creatives->pluck('image_url')->toArray();
// Should show 3 image URLs (S3 or local storage)

// Check Meta API IDs were set
$campaign->campaign_id; // Should be a Meta campaign ID (e.g., "123456789")
$adSet->adset_id; // Should be a Meta adset ID
$adSet->ads->first()->ad_id; // Should be a Meta ad ID
$creatives->first()->creative_id; // Should be a Meta creative ID
```

**2. Job Execution Logs**
Check the queue worker terminal for successful job execution:
```
[2026-01-15 12:34:56] Processing: App\Jobs\GenerateAdCreativesJob
[2026-01-15 12:35:42] Processed:  App\Jobs\GenerateAdCreativesJob
```

**3. Reverb Broadcast Logs**
Check the Reverb terminal for websocket broadcasts:
```
[2026-01-15 12:34:57] Broadcasting: meta-ads.campaign.123 › .creative.generation.started
[2026-01-15 12:34:58] Broadcasting: meta-ads.campaign.123 › .creative.generation.progress
...
[2026-01-15 12:35:41] Broadcasting: meta-ads.campaign.123 › .creative.generation.completed
```

### Frontend Verification

**1. Browser Console** (F12 → Console tab)
Look for Echo connection logs:
```
Echo connected to meta-ads.campaign.123
Received creative.generation.started event
Received creative.generation.progress event
...
Received creative.generation.completed event
```

**2. Network Tab** (F12 → Network tab)
- Filter by `WS` (WebSocket)
- Should see connection to `ws://localhost:8080`
- Messages should be flowing with event data

**3. Vue DevTools** (if installed)
- Check `useMetaAdsRealtime` composable state:
  - `isGenerating`: false (after completion)
  - `isComplete`: true
  - `currentProgress`: last progress object
  - `completionData`: completion object with success=true
  - `progressHistory`: array of 6+ progress steps

---

## Meta API Verification (Sandbox)

### 1. Log into Meta Ads Manager
- Go to: https://business.facebook.com/adsmanager/
- Switch to sandbox account (if you have multiple accounts)

### 2. Verify Campaign Created
- Should see campaign: "AI Workflow Transformation - Yamhill County"
- Status: `Paused` (we create as PAUSED, activate on approval)
- Objective: `Lead Generation`

### 3. Verify Ad Set Created
- Click into campaign
- Should see 1 ad set with targeting:
  - Location: Newberg, OR (25 mile radius)
  - Age: 30-65
  - Interests: Small business, entrepreneurship, etc.

### 4. Verify Ads & Creatives
- Click into ad set
- Should see 3 ads (Variant A, B, C)
- Each ad should have:
  - Different headline
  - Different primary text
  - Different image (generated by Nano Banana Pro)
  - Call-to-action: "Learn More"
  - Destination URL: https://example.com/ai

---

## Test the Approval Flow

### Step 6: Approve Campaign

1. On campaign detail page, click **"Approve & Launch"**
2. Campaign status should update to `active`
3. In Meta Ads Manager, campaign status should change to `Active`
4. Ads will start delivering (in sandbox, no real money spent)

---

## Troubleshooting

### Issue: No Real-Time Updates

**Symptoms:**
- Progress bar doesn't move
- No step messages appear
- Spinner shows but nothing updates

**Causes & Fixes:**

1. **Queue worker not running**
   - Start: `php artisan queue:work`
   - Job won't process without this

2. **Reverb server not running**
   - Start: `php artisan reverb:start`
   - Websockets won't broadcast without this

3. **BROADCAST_CONNECTION not set to reverb**
   - Check `.env`: `BROADCAST_CONNECTION=reverb`
   - Restart servers after changing

4. **Echo not connecting to Reverb**
   - Check browser console for connection errors
   - Verify `VITE_REVERB_*` env vars match `REVERB_*`
   - Run `npm run build` to rebuild frontend

### Issue: Job Fails with Errors

**Symptoms:**
- Job fails in queue worker
- Campaign status becomes `failed`
- Error message in completion event

**Common Errors:**

1. **Meta API authentication failed**
   - Verify `META_ADS_SANDBOX_ACCESS_TOKEN` in `.env`
   - Token may be expired - regenerate at developers.facebook.com

2. **Claude API error**
   - Verify `ANTHROPIC_API_KEY` in `.env`
   - Check API quota/rate limits

3. **Image generation failed**
   - Check Gemini API credentials
   - Verify S3/storage permissions for saving images

4. **Meta API rate limit**
   - Sandbox has lower rate limits
   - Wait 5-10 minutes and retry

### Issue: "Approve & Launch" Button Doesn't Appear

**Cause:** `completionData.success` is not true

**Fix:**
- Check browser console for `completionData` object
- If `success: false`, check error_message
- Look at queue worker logs for job failure

### Issue: Campaign Created but Not in Meta

**Cause:** `pushToMetaAPI()` method failed

**Fix:**
- Check Meta API credentials in `.env`
- Verify Meta app has Marketing API permissions
- Check queue worker logs for specific Meta API error
- Test Meta API connection:
  ```bash
  php artisan tinker
  ```
  ```php
  $service = app(\App\Services\MetaAds\MetaAdsService::class);
  $account = \App\Models\MetaAdAccount::first();
  $service->getCampaigns($account); // Should return array without errors
  ```

---

## Next Steps After Testing

Once testing is successful:

1. **Create Client-Specific Brand Guidelines**
   - Navigate to `/brand-guidelines` (if exists)
   - Or create via database seeder
   - Test campaign creation with custom brand guidelines

2. **Test Optimization Agents**
   - Let campaign run for a few days (sandbox)
   - Run AdOptimizationAgent to test auto-pause/scale logic
   - Verify budget reallocation works

3. **Test Reporting Agent**
   - Run AdReportingAgent
   - Verify weekly report generation
   - Check email delivery

4. **Production Deployment**
   - Switch from sandbox to production Meta credentials
   - Set lower daily budgets for initial testing ($10-15/day)
   - Monitor first campaign closely

---

## Cost Estimation for Testing

**Per Campaign Test (3 creatives):**
- Claude copy generation: ~$0.10
- Nano Banana Pro images (3): ~$0.06 ($0.02/image)
- Infrastructure: $0
- **Total per test: ~$0.16**

**Safe to run 10-20 test campaigns** to validate everything works before going to production.

---

## Success Metrics

After successful testing, you should have:

✅ Campaign created in database
✅ 3 AI-generated creatives (copy + images)
✅ Campaign, adset, ads pushed to Meta API
✅ Real-time progress UI updated correctly
✅ Campaign shows as "pending_approval"
✅ "Approve & Launch" button works
✅ Campaign activates in Meta Ads Manager

**System is production-ready!**
