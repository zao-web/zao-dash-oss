# Google Workspace Integration Technical Spec

## Overview

Integrate Google Workspace to enable real-time proactive action item extraction from meetings, emails, and documents. Primary inbox: `owner@example.com`.

---

## Real-Time Philosophy

**Webhooks first, 5-minute polling fallback.**

| Service | Real-Time Method | Fallback |
|---------|------------------|----------|
| Gmail | Pub/Sub push notifications | Poll every 5 min |
| Calendar | Pub/Sub push notifications (`events.watch()`) | Poll every 5 min |
| Drive | Pub/Sub push notifications (`changes.watch()`) | Poll every 5 min |

All push notification watches expire (Gmail: 7 days, Calendar/Drive: configurable). A background job renews watches before expiration.

**Latency targets:**
- Email received → processed: < 30 seconds
- Meeting created → synced: < 30 seconds
- Document uploaded → indexed: < 2 minutes

---

## 1. Google Meet + Gemini Transcripts

### Business Logic
- Gemini enabled for ALL client meetings (default setting)
- Transcripts arrive as emails to owner@example.com after meeting ends
- System must detect Gemini transcript emails and process them

### Data Extraction
From each transcript, extract:
| Item Type | Description | Auto-Create Task? |
|-----------|-------------|-------------------|
| Action Items | Tasks assigned during meeting | Yes |
| Commitments | Promises made to client | Yes |
| Decisions | Finalized agreements | Yes (for tracking) |
| Follow-ups | Requested next meetings/calls | Yes |

### Technical Implementation

```
Email arrives (Gemini transcript)
    ↓
Gmail webhook / push notification
    ↓
Detect: from:meet-recordings-noreply@google.com OR subject contains "Meeting transcript"
    ↓
MeetingTranscriptAgent
    ├── Parse transcript content
    ├── Extract structured items (action, commitment, decision, follow-up)
    ├── Identify meeting participants → match to clients
    └── Create tasks (no approval needed)
    ↓
Tasks appear in dashboard
```

### Agent: MeetingTranscriptAgent
- **Trigger**: Gmail webhook (transcript email detected)
- **Approval Required**: No (auto-execute)
- **Output**: Task records linked to client/project
- **Skill Prompt Focus**:
  - Identify WHO is responsible for each action
  - Distinguish internal vs client-facing items
  - Extract deadlines if mentioned
  - Link to meeting date/participants

---

## 2. Gmail Integration

### Business Logic
- Monitor: `owner@example.com` only
- Process: All emails from known client contacts
- Client contacts stored in system, matched by email domain or specific addresses

### On Email Receipt
1. **Match to Client**: Lookup sender email → find associated client
2. **Sentiment Analysis**: Run through sentiment analyzer
   - Score: positive / neutral / negative / urgent
   - Flag if negative or urgent → surface in dashboard
3. **Action Item Extraction**: Parse email body for:
   - Explicit requests ("Can you...", "Please...", "We need...")
   - Questions requiring response
   - Deadlines mentioned
   - Deliverables referenced
4. **Create Tasks**: Auto-create for any extracted items

### Technical Implementation

```
Email arrives
    ↓
Gmail Push Notification (Pub/Sub)
    ↓
Fetch full email content via Gmail API
    ↓
EmailProcessorAgent
    ├── Match sender to client
    ├── Run sentiment analysis
    ├── Extract action items
    ├── Create tasks
    └── Update client health score if sentiment negative
    ↓
Dashboard shows:
    - New email logged
    - Sentiment indicator on client card
    - Action items in task list
```

### Agent: EmailProcessorAgent
- **Trigger**: Gmail push notification
- **Approval Required**: No
- **Output**:
  - Email record (logged for context)
  - Sentiment score
  - Task records
- **Skill Prompt Focus**:
  - Don't over-extract (not every email needs a task)
  - Identify urgency signals
  - Recognize FYI vs action-required emails

### Client Email Mapping
- Database table: `client_contacts`
  - `client_id`, `email`, `name`, `role`
- On email receipt, lookup by:
  1. Exact email match
  2. Domain match (if client has domain registered)
  3. Unknown sender → log but don't process

---

## 3. Google Drive Integration

### Business Logic
- Drive is "barely organized" - no strict folder structure
- Important documents: MSAs, Statements of Work (SOWs)
- Access model: On-demand + proactive discovery

### Features

#### A. Searchable Document Index
- Periodic scan of Drive for legal/contract documents
- Index: filename, content excerpt, document type, dates
- UI: Search bar in dashboard to find documents
- Link documents to clients/projects manually or auto-suggest

#### B. Proactive Document Discovery
- **DocumentDiscoveryAgent** runs weekly (or on-demand)
- Searches for: "MSA", "Statement of Work", "SOW", "Agreement", "Contract"
- For each found:
  - Extract client name (from content or filename)
  - Extract dates (effective, expiration)
  - Extract value if present
  - Suggest client/project association
- Surface in dashboard: "Found 3 unlinked documents → Review"

#### C. On-Demand Access
- When viewing client, show linked documents
- "Search Drive" button to find more
- Attach documents to projects/tasks

### Technical Implementation

```
Weekly cron job OR manual trigger
    ↓
DocumentDiscoveryAgent
    ├── Google Drive API: search for keywords
    ├── For each result:
    │   ├── Download/read content
    │   ├── Extract metadata (client, dates, value)
    │   └── Store in documents table
    └── Surface unlinked docs in dashboard
```

### Database: `documents`
```
id
google_drive_id (unique)
filename
document_type (msa, sow, proposal, other)
client_id (nullable - can be unlinked)
project_id (nullable)
extracted_client_name
effective_date
expiration_date
contract_value
content_excerpt
indexed_at
```

---

## 4. Google Calendar Integration

### Business Logic
- Know about upcoming client meetings
- Enable pre-meeting context and post-meeting follow-up

### Features

#### A. Pre-Meeting Brief
- 30 minutes before client meeting:
  - Pull recent emails with client
  - Pull recent tasks/action items
  - Pull client health score
  - Pull any open issues
- Surface as notification or dashboard card: "Meeting with Client X in 30 min → View brief"

#### B. Post-Meeting Trigger
- After meeting ends (calendar event time passes):
  - Wait for Gemini transcript email (up to 1 hour)
  - If no transcript, prompt: "Add meeting notes for Client X call?"
  - Trigger follow-up reminder if no activity in 24 hours

#### C. Deadline Tracking
- Scan calendar for events with deadline-like titles
- "SOW Due: Client X", "Launch: Project Y"
- Create/link to tasks

### Technical Implementation

```
REAL-TIME: Calendar push notifications (via Pub/Sub)
    ↓
Webhook receives event create/update/delete
    ↓
Immediately sync event to database
    ↓
Schedule pre-meeting brief job (30 min before)
    ↓
Schedule post-meeting follow-up job (at end time)

FALLBACK: If push fails, poll every 5 minutes
```

**Note**: Google Calendar API supports push notifications just like Gmail. We'll use `events.watch()` to subscribe to calendar changes in real-time.

---

## 5. OAuth & API Setup

### Google Cloud Console Configuration
1. Create project: `zao-dash`
2. Enable APIs:
   - Gmail API
   - Google Drive API
   - Google Calendar API
3. OAuth consent screen:
   - Internal (if Workspace) or External
   - Scopes:
     - `gmail.readonly` (read emails)
     - `gmail.modify` (mark as read, labels)
     - `drive.readonly` (read files)
     - `calendar.readonly` (read events)
4. Create OAuth 2.0 credentials
   - Web application
   - Redirect URI: `https://app.example.com/oauth/google/callback`

### Gmail Push Notifications (Pub/Sub)
1. Create Pub/Sub topic: `gmail-notifications`
2. Create subscription with push endpoint: `https://app.example.com/webhooks/gmail`
3. Watch inbox via Gmail API: `users.watch()`
4. Renew watch every 7 days (cron job)

### Environment Variables
```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GOOGLE_PUBSUB_TOPIC=
```

---

## 6. Database Migrations

### `google_credentials` table
```
user_id
access_token (encrypted)
refresh_token (encrypted)
expires_at
scopes (json)
watch_expiration (for Gmail push)
```

### `emails` table
```
id
google_message_id (unique)
client_id (nullable)
from_address
from_name
to_addresses (json)
subject
body_text
body_html
received_at
sentiment_score
sentiment_label
is_processed
processed_at
```

### `client_contacts` table
```
id
client_id
email
name
role
is_primary
```

### `documents` table
(defined above)

### `calendar_events` table
```
id
google_event_id (unique)
client_id (nullable)
project_id (nullable)
title
start_at
end_at
attendees (json)
is_client_meeting
pre_brief_sent
post_followup_sent
```

---

## 7. New Agents

| Agent | Trigger | Approval | Purpose |
|-------|---------|----------|---------|
| MeetingTranscriptAgent | Gmail webhook (transcript email) | No | Extract action items from Gemini transcripts |
| EmailProcessorAgent | Gmail push notification | No | Sentiment + action extraction from client emails |
| DocumentDiscoveryAgent | Weekly cron / manual | No | Find and index MSAs/SOWs in Drive |
| PreMeetingBriefAgent | 30 min before meeting | No | Generate context brief for upcoming call |

---

## 8. Dashboard Integration

### New Components
1. **Email Activity Feed**: Recent client emails with sentiment badges
2. **Upcoming Meetings Card**: Next 24h meetings with "View Brief" button
3. **Document Search**: Search bar to find Drive documents
4. **Unlinked Documents Alert**: "3 documents found - review and link"

### Proactive Insights (from Google data)
- "Negative sentiment detected in email from Client X" → View email
- "Meeting with Client Y in 30 min" → View brief
- "No response to Client Z in 3 days" → Draft follow-up
- "SOW for Client W expires in 30 days" → Review

---

## 9. Implementation Order

1. **OAuth flow** - Connect Google account, store tokens
2. **Gmail push setup** - Pub/Sub webhook receiving
3. **Email sync** - Fetch and store emails, match to clients
4. **EmailProcessorAgent** - Sentiment + action extraction
5. **MeetingTranscriptAgent** - Detect and parse Gemini transcripts
6. **Calendar sync** - Fetch events, identify client meetings
7. **Pre-meeting briefs** - Generate context before calls
8. **Drive search** - On-demand document lookup
9. **DocumentDiscoveryAgent** - Proactive MSA/SOW indexing

---

## 10. Security Considerations

- All tokens encrypted at rest (use Laravel's encryption)
- Refresh tokens stored securely, auto-refresh on expiry
- Pub/Sub webhook validates message authenticity
- Email content stored encrypted (contains client data)
- Document content excerpts only (not full files)
- Audit log for all Google API access
