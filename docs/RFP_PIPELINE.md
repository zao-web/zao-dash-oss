# RFP Pipeline

The RFP Pipeline discovers, evaluates, and manages proposal opportunities across multiple channels with automated learning.

## Duplicate Detection

All entry points check for duplicates before creating an `RfpOpportunity`:

| Entry Point | Method | Location |
|---|---|---|
| Manual form | `RfpController::store()` | `/rfp` (POST) |
| Document upload/URL | `RfpController::uploadDocument()` | `/rfp/upload` (POST) |
| Email scanning | `RfpDiscoveryService::createFromTeaser()` | Automated job |

### Detection Layers

Detection runs via `RfpDiscoveryService::isDuplicate()` in three ordered steps:

1. **Source URL match** - If both records share a `source_url`, they are the same RFP regardless of title/org differences.
2. **Exact match** - Case-insensitive comparison of `title` + `issuing_organization`.
3. **Fuzzy word match** - Extracts significant words (3+ characters) from org and title. If all org words match an existing record's org, and 60%+ of title words overlap, it's flagged as a duplicate.

Soft-deleted records are excluded from all checks (declined RFPs can be re-discovered).

### User Experience

- Manual creation shows a flash error: "A similar RFP opportunity already exists..."
- Document upload shows the parsed title/org in the error for clarity
- Automated discovery silently skips duplicates (logged at debug level)

## Learning System

### Automatic Insight Generation

Insights are created automatically when:

- **Declining an RFP** - Creates a `decline_pattern` insight with org, industry, budget, and category data
- **Recording an outcome** - AI analyzes raw feedback into win/loss factors, competitor info, and pricing data
- **Weekly agent run** - `RfpLearningAgent` analyzes aggregate patterns (Fridays at 3pm)

### Manual Insight Creation

**Route:** `POST /rfp/learning` (`rfp.learning.store`)

Allows capturing feedback received outside the system (phone calls, emails, meetings).

**Fields:**

| Field | Required | Values |
|---|---|---|
| `insight_type` | Yes | `manual_feedback`, `win_pattern`, `loss_pattern`, `decline_pattern`, `pricing_insight`, `industry_trend`, `content_improvement` |
| `title` | Yes | Short descriptive title (max 255) |
| `description` | Yes | Detailed description (max 5000) |
| `impact_area` | Yes | `pricing`, `content`, `targeting`, `process`, `presentation` |
| `confidence` | Yes | 0.0 to 1.0 |
| `actionable_recommendation` | No | Specific next steps (max 2000) |

Manual insights are tagged with `evidence.source = 'manual_entry'` for provenance tracking.

### Insight Types

| Type | Source | Color |
|---|---|---|
| `win_pattern` | Outcome analysis | Green |
| `loss_pattern` | Outcome analysis | Red |
| `decline_pattern` | RFP decline action | Red (lighter) |
| `pricing_insight` | Agent/manual | Blue |
| `industry_trend` | Agent/manual | Purple |
| `content_improvement` | Agent/manual | Yellow |
| `manual_feedback` | Manual entry only | Teal |

## Discovery Sources

Configured via `/rfp/sources`. Types: `email_sender`, `government_api`, `rfp_board`, `rss_feed`, `web_scrape`.

### Artisan Commands

No artisan commands needed for day-to-day operation. Discovery runs on scheduled jobs:

- `ScanRfpEmailsJob` - Email monitoring (configurable frequency)
- `AggregateRfpsJob` - External source aggregation
- `EvaluateRfpJob` - Fit score calculation
- `RetrieveRfpDocumentJob` - Full document retrieval
- `LocateRfpContactJob` - Submission contact resolution
- `GenerateRfpProposalJob` - AI proposal generation
- `CritiqueRfpProposalJob` - Adversarial review + auto-revision

## Pipeline Stages (2026-05 refactor)

The full path from teaser email to Slack-delivered proposal:

```
Teaser email
     ↓
parseEmailTeasers (Haiku)          → returns extraction_confidence (0–1)
     ↓
RfpPreFilter::evaluate            → blocks federal, non-US, low-confidence
     ↓
RfpDiscoveryService::createFromTeaser
     ↓
EvaluateRfpJob                    → fit score + auto-decline below 35
     ↓ (if qualified)
RetrieveRfpDocumentJob            → parse full RFP for requirements, contact
     ↓
GenerateRfpProposalJob (gated)    → if no submission_email, routes to:
     ↓                              LocateRfpContactJob
                                     ↓ (finds contact → re-dispatches generation)
                                     ↓ (no contact → status: contact_needed, Slack notify)
     ↓ (contact present)
RfpProposalService::generateProposal
     ↓
CritiqueRfpProposalJob
   ├─ critique pass (Claude + Zao rubric)
   ├─ if blockers → revise pass (consortium debate when configured)
   └─ render PDF, then RfpSlackNotifier::notifyProposalReady
     ↓
Slack DM (PDF attached + "Send Proposal" / "Review First" buttons)
     ↓ (user clicks Send Proposal)
SlackWebhookController::handleRfpSendProposal
     ↓
RfpProposalSender::send           → emails opportunity.submission_email
     ↓
Proposal status: submitted
```

### Pre-Filter Rules

`app/Services/Rfp/RfpPreFilter.php` rejects opportunities before they enter scoring. Reasons:

| Reason | Trigger |
|---|---|
| `federal_blocked` | sam.gov URLs, org names containing "Department of", "U.S.", "United States", "Marine Corps", military branches, GSA, federal agencies |
| `non_us` | International procurement (Canada, UK, EU, etc.) |
| `low_confidence` | Teaser parser confidence below 0.6 — likely newsletter, not an RFP |

Adjust patterns in `isFederal()` and `isNonUS()` when targeting changes. Confidence threshold is the `CONFIDENCE_THRESHOLD` const on the class.

### Contact Resolution

`RfpContactLocator` searches signals in order, highest signal first:

1. `opportunity.submission_email` (already set)
2. Most recent Email with `from_address` matching the org's domain (`organization_website`)
3. Most recent Document with `extracted_client_name` matching the org — extracts email from `content_excerpt`

Does **not** fall back to generic `info@org.com` guesses. If nothing is found, the opportunity moves to status `contact_needed` and Slack DMs Justin with a link to add the contact.

### Adversarial Review

`RfpProposalCritic` runs after every draft generation. Two modes:

- **Blocker findings** trigger an automatic one-shot revision using the multi-model consortium debate (Gemma 4 → Llama critique → Gemma 4 revise → Claude judge) when configured; falls back to a single Claude pass otherwise.
- **Warning findings** surface in the Slack notification but do not block submission.

The critic's blocker categories include: duplicate sections (e.g. Executive Summary appearing twice), missing RFP requirements, hallucinated case studies, \$0 pricing line items, sales-y boilerplate.

The **Zao-specific rubric** is editable inline in `RfpProposalCritic::systemPrompt()` — that's where domain-specific "what makes a Zao proposal embarrassing" rules live. Add to it whenever a critic miss is caught manually.

### Slack Send Flow

When the proposal is ready, the Slack notification:

1. Pre-renders the PDF (`rfp-proposals/{rfp_id}/{proposal_id}.pdf`)
2. Uploads via `files.getUploadURLExternal` + `files.completeUploadExternal` to the user's DM
3. Posts action blocks: **Send Proposal** (with confirm dialog) + **Review First**

Clicking **Send Proposal** fires `block_actions` with `action_id=rfp_send_proposal`, handled by `SlackWebhookController::handleRfpSendProposal`, which delegates to `RfpProposalSender::send()`. The same sender is used by the web UI's send-email form, so behavior stays consistent.

The Send button only renders if `opportunity.submission_email` is set — otherwise the contact location job halts upstream.

### Status Values

`RfpOpportunity::status` values used by the pipeline:

| Status | Set by | Meaning |
|---|---|---|
| `discovered` | Discovery service | New opportunity, not yet evaluated |
| `evaluating` | EvaluateRfpJob | Score in 35–49 review band — needs manual triage |
| `qualified` | EvaluateRfpJob | Score ≥ 50, proposal will auto-generate |
| `pursuing` | Failed generation | Reset for retry |
| `proposal_drafting` | GenerateRfpProposalJob | Active generation |
| `proposal_review` | GenerateRfpProposalJob | Draft saved, awaiting review |
| `contact_needed` | LocateRfpContactJob | No submission contact — Justin must add one |
| `submitted` | RfpProposalSender | Proposal emailed |
| `declined` | EvaluateRfpJob | Auto-declined (score < 35, expired, or decline-pattern match) |
| `won` / `lost` | Manual / outcome reply | Final |
