# Lead Sources & Pipeline Integration

This document describes how leads are captured and flow into the sales pipeline.

## Lead Sources

### 1. Gravity Forms (Website Forms)

Leads from WordPress contact forms are captured via webhook.

**Setup:**
1. In WordPress, install Gravity Forms with the Webhooks add-on
2. Configure webhook URL: `{APP_URL}/webhooks/gravity-forms`
3. Set webhook secret in `.env`: `GRAVITY_FORMS_WEBHOOK_SECRET=your-secret`
4. In Gravity Forms webhook settings, add header: `X-GF-Signature: {signature}`

**Field Mapping:**
The webhook auto-detects common fields:
- Email fields (any field with "email" in name or valid email value)
- Name fields (name, first_name, last_name, your_name)
- Phone fields (phone, tel, mobile)
- Company fields (company, business, organization)
- Website fields (website, url, site)
- Message fields (message, description, project, details)
- Budget fields (budget, amount, price)

For custom mappings, configure in `config/services.php`:
```php
'gravity_forms' => [
    'field_maps' => [
        '1' => [  // Form ID
            '1.3' => 'contact_name',
            '1.6' => 'contact_email',
            '2' => 'company_name',
        ],
    ],
],
```

### 2. Email

Incoming emails not from existing clients are analyzed for lead potential.

**How it works:**
- `EmailAnalyzerService` processes incoming emails
- Emails NOT from existing clients or contractors are checked for lead signals
- AI analyzes content for: project inquiries, quote requests, service questions
- Leads are created automatically with deduplication

**Lead Signals Detected:**
- Asking about services or pricing
- Describing a project need
- Requesting a quote or proposal
- Expressing interest in working together

### 3. Slack (Including DMs)

External users in Slack channels and DMs are analyzed for lead potential.

**How it works:**
- `SlackMessageAnalyzerService` processes messages from external users
- Messages from users NOT linked to existing clients are checked
- DMs from external users are prioritized as potential leads
- AI determines if message represents a business inquiry

**External User Detection:**
- Users not in your Slack workspace
- Users in shared channels marked as external
- DM requests from unknown contacts

## Deduplication

The system prevents duplicate leads using:

1. **Email matching**: Same `contact_email` = same lead
2. **Company matching**: Same `company_name` (if no email)
3. **Stage filtering**: Only active leads (not won/lost) are matched

When a duplicate is detected:
- The existing lead is returned
- No new lead is created
- Source is logged for tracking

## Lead Stages

All new leads start at stage: `new`

Available stages:
- `new` - Just captured
- `qualified` - Vetted as real opportunity
- `proposal` - Proposal sent
- `negotiation` - Discussing terms
- `won` - Converted to client
- `lost` - Did not convert

## Pipeline Value Calculation

Pipeline value (`KpiCalculator::getPipelineStats()`) sums `deal_value` for leads in active stages:
- new
- qualified
- proposal
- negotiation

Won/lost leads are excluded from pipeline value.

## Testing Webhooks

### Gravity Forms
```bash
curl -X POST {APP_URL}/webhooks/gravity-forms \
  -H "Content-Type: application/json" \
  -d '{
    "form_id": "1",
    "entry_id": "123",
    "name": "John Doe",
    "email": "john@example.com",
    "company": "Acme Corp",
    "message": "We need a new website for our business."
  }'
```

### Check Pipeline
```bash
php artisan tinker --execute="
  \$calc = app(App\Services\Analytics\KpiCalculator::class);
  print_r(\$calc->getPipelineStats());
"
```

## Environment Variables

```
GRAVITY_FORMS_WEBHOOK_SECRET=  # Optional, for signature verification
```

## Related Files

- `app/Services/Leads/LeadDetectionService.php` - Core lead detection
- `app/Http/Controllers/GravityFormsWebhookController.php` - GF webhook handler
- `app/Services/Email/EmailAnalyzerService.php` - Email lead detection
- `app/Services/Slack/SlackMessageAnalyzerService.php` - Slack lead detection
- `app/Services/Analytics/KpiCalculator.php` - Pipeline calculations
