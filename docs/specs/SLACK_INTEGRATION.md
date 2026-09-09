# Slack Integration Technical Spec

## Overview

Monitor Slack workspaces for client communications, extract action items, track repeated requests (sentiment decay), and surface signals in the dashboard. Must handle threads deeply.

---

## Real-Time Philosophy

**Webhooks via Slack Events API.**

| Event Type | Real-Time Method | Latency Target |
|------------|------------------|----------------|
| Messages | Events API (Socket Mode or HTTP) | < 10 seconds |
| Thread replies | Events API | < 10 seconds |
| Reactions | Events API | < 30 seconds |
| Channel membership | Events API | < 1 minute |

**Fallback**: If Events API misses something, conversation history sync every 5 minutes for active channels.

---

## 1. Workspace Structure

### Multi-Workspace Support
- **Primary**: Zao internal workspace
- **Secondary**: Client workspaces where Zao is invited
- **Slack Connect**: Shared channels bridging workspaces

### Database: `slack_workspaces`
```
id
workspace_id (Slack team ID)
workspace_name
access_token (encrypted)
bot_user_id
is_primary (boolean)
connected_at
```

### OAuth Flow
- Each workspace requires separate OAuth authorization
- Use Slack's "Add to Slack" flow for each workspace
- Store tokens per workspace
- Bot must be added to relevant channels

---

## 2. Channel Classification

### Automatic Detection
On workspace connect, fetch all channels bot has access to and classify:

| Classification | Detection Logic | Monitoring Level |
|----------------|-----------------|------------------|
| Internal-only | Name contains "internal", "team", "xeo" | Low (no action extraction) |
| Client channel | Has external guests OR is Slack Connect | High (full processing) |
| Project channel | Contains client name + project keywords | High |
| General/random | Default workspace channels | Low |

### Database: `slack_channels`
```
id
workspace_id
channel_id (Slack channel ID)
channel_name
is_private
is_shared (Slack Connect)
classification (internal, client, project, general)
client_id (nullable - linked client)
monitoring_enabled (boolean)
last_synced_at
```

### Manual Override
- Admin can reclassify channels in dashboard
- Link channels to specific clients
- Enable/disable monitoring per channel

---

## 3. Message Processing

### What Gets Processed
- All messages in monitored channels
- All thread replies (critical - threads contain context)
- DMs with the bot (if Slack plan allows)
- DMs between users (requires `users:read` scope - may need Enterprise Grid)

### Message Flow

```
Slack Events API webhook
    ↓
Receive: message.channels, message.groups, message.im
    ↓
Filter: Is channel monitored?
    ↓
Yes → SlackMessageProcessor
    ├── Store message in database
    ├── If thread reply → fetch full thread context
    ├── Classify sender (internal vs external/client)
    ├── Run action item extraction
    ├── Check for repeated request pattern
    └── Create outputs (tasks, notifications, agent triggers)
```

### Database: `slack_messages`
```
id
workspace_id
channel_id
message_ts (Slack timestamp - unique ID)
thread_ts (parent thread, nullable)
user_id
user_name
user_is_external (boolean)
content
attachments (json)
client_id (nullable - detected)
has_action_item (boolean)
action_item_extracted (text, nullable)
is_repeated_request (boolean)
repeated_request_count (int)
processed_at
```

---

## 4. Action Item Extraction

### SlackActionExtractorAgent

**Trigger**: Every message from external user (client) in monitored channel

**Detection Patterns**:
- Explicit requests: "Can you...", "Could you...", "Please...", "We need..."
- Questions requiring action: "When will...", "What's the status of..."
- Agreements: Internal user says "Yes", "Sure", "We can do that", "I'll handle it"
- Deadlines: "by Friday", "end of week", "ASAP", "urgent"

**Output**:
```json
{
  "has_action_item": true,
  "action_item": "Update the homepage banner with new campaign",
  "assignee_hint": "internal team",
  "deadline_hint": "by Friday",
  "confidence": 0.85,
  "source_message_ts": "1234567890.123456",
  "thread_context": "Client requested banner change in thread about Q1 campaign"
}
```

**Task Creation**:
- If confidence > 0.7 → auto-create task
- If confidence 0.4-0.7 → create draft task, surface for review
- If confidence < 0.4 → log but don't create task

---

## 5. Repeated Request Detection (Sentiment Decay)

### Logic
Track when a client asks about the same topic multiple times:

1. **Semantic Similarity**: Compare new message to client's recent messages (last 30 days)
2. **Topic Clustering**: Group messages by topic/intent
3. **Count Repeats**: If same topic appears 2+ times without resolution

### Scoring
```
repeat_count = 1 → No penalty
repeat_count = 2 → Warning: "Client asked about X twice"
repeat_count = 3 → Alert: "Client frustrated - asked about X 3 times"
repeat_count = 4+ → Critical: "Repeated ask - immediate attention needed"
```

### Impact on Client Health
- Each repeat beyond 1 reduces client health score by 0.5 points
- Dashboard shows: "Repeated requests detected" badge on client card
- Notification: "Client X has asked about Y 3 times this month"

### Database: `slack_request_patterns`
```
id
client_id
topic_embedding (vector - for similarity search)
topic_summary
first_asked_at
last_asked_at
ask_count
is_resolved (boolean)
resolved_at
messages (json array of message_ts)
```

---

## 6. Thread Handling

### Critical Requirement
Threads often contain the most important context. Many action items are buried in thread replies.

### Implementation
1. When receiving a thread reply (`thread_ts` present):
   - Fetch full thread via `conversations.replies`
   - Process entire thread as context unit
   - Don't extract action items from each reply individually
   - Extract action items from thread holistically

2. Thread summarization:
   - For long threads (10+ messages), summarize before processing
   - Store thread summary for quick context

### Database: `slack_threads`
```
id
channel_id
thread_ts
message_count
participants (json - user IDs)
has_external_participant (boolean)
summary (AI-generated)
action_items_extracted (json)
last_reply_at
```

---

## 7. DM Handling

### Slack Plan Considerations
| Plan | DM Access |
|------|-----------|
| Free/Pro | Bot DMs only |
| Business+ | Bot DMs only |
| Enterprise Grid | Full DM access with admin approval |

### Strategy
- **If full DM access**: Monitor all DMs with external users
- **If limited**:
  - Encourage piping actionable DMs to channels
  - Create `/zao` slash command to forward DM content to system
  - Bot can be DMed directly to log action items

### Slash Command: `/zao`
```
/zao task "Update the banner by Friday" @client-channel
→ Creates task and logs in system
```

---

## 8. Outputs & Triggers

### Decision Matrix

| Signal | Confidence | Output |
|--------|------------|--------|
| Clear action item | High (>0.7) | Create task + notify |
| Possible action item | Medium (0.4-0.7) | Draft task + dashboard review |
| Repeated request (2x) | - | Warning notification |
| Repeated request (3x+) | - | Alert + reduce health score |
| Urgent keywords | High | High-priority task + immediate notify |
| Question pending >24h | - | Reminder notification |

### Notification Types (New System)
```
{
  "type": "slack_signal",
  "subtype": "action_item" | "repeated_request" | "urgent" | "pending_response",
  "title": "New action item from Client X",
  "body": "Update homepage banner by Friday",
  "source": {
    "workspace": "client-workspace",
    "channel": "#project-website",
    "message_ts": "1234567890.123456",
    "permalink": "https://slack.com/..."
  },
  "actions": [
    { "label": "View in Slack", "url": "..." },
    { "label": "Create Task", "action": "create_task" },
    { "label": "Dismiss", "action": "dismiss" }
  ]
}
```

### Agent Triggers
| Signal | Agent | Approval |
|--------|-------|----------|
| Dev task detected | DevAgent | Auto to staging, approval for prod |
| Content request | ContentCreatorAgent | Approval required |
| Meeting request | (Calendar integration) | Auto-suggest times |
| Bug report | DevAgent + QAAgent | Auto to staging |

---

## 9. Slack App Configuration

### Required Scopes (Bot Token)
```
channels:history      - Read public channel messages
channels:read         - List channels
groups:history        - Read private channel messages
groups:read           - List private channels
im:history            - Read DMs with bot
im:read               - List DMs
mpim:history          - Read group DMs
mpim:read             - List group DMs
users:read            - Get user info
users:read.email      - Get user emails (for client matching)
chat:write            - Send messages (for responses)
commands              - Slash commands
reactions:read        - Read reactions
```

### Event Subscriptions
```
message.channels      - Public channel messages
message.groups        - Private channel messages
message.im            - Direct messages
message.mpim          - Group direct messages
member_joined_channel - Track channel membership
member_left_channel
channel_created
channel_deleted
reaction_added        - Track acknowledgments
```

### Socket Mode vs HTTP
- **Recommended**: Socket Mode (simpler, no public URL needed for events)
- **Alternative**: HTTP Events API with `/webhooks/slack` endpoint

---

## 10. New Agents

| Agent | Trigger | Approval | Purpose |
|-------|---------|----------|---------|
| SlackActionExtractorAgent | Every monitored message | No | Extract action items from messages |
| SlackThreadSummarizerAgent | Long threads (10+ msgs) | No | Summarize thread context |
| RepeatedRequestDetectorAgent | Periodic (hourly) | No | Analyze patterns, update health scores |

---

## 11. Dashboard Integration

### New Components

#### Slack Activity Panel
- Recent messages from client channels (last 24h)
- Click to expand thread
- "Create Task" quick action

#### Notification Center (NEW)
- Bell icon in header with unread count
- Dropdown showing recent notifications
- Types: Slack signals, agent completions, approvals needed
- Mark as read / dismiss / take action

#### Client Card Enhancements
- "Last Slack activity: 2 hours ago"
- "Repeated requests" warning badge
- Sentiment indicator based on communication patterns

---

## 12. Database Migrations Summary

New tables:
- `slack_workspaces` - OAuth tokens per workspace
- `slack_channels` - Channel registry with classification
- `slack_messages` - Message log with extracted data
- `slack_threads` - Thread summaries
- `slack_request_patterns` - Repeated request tracking
- `notifications` - Dashboard notification system

---

## 13. Implementation Order

1. **Slack OAuth flow** - Connect primary workspace
2. **Events API setup** - Socket Mode connection
3. **Channel sync** - Fetch and classify channels
4. **Message ingestion** - Store incoming messages
5. **SlackActionExtractorAgent** - Extract action items
6. **Task creation flow** - Messages → Tasks
7. **Thread handling** - Deep thread processing
8. **Notification system** - Dashboard notifications
9. **Repeated request detection** - Pattern analysis
10. **Multi-workspace** - Connect client workspaces
11. **DM handling** - Based on plan capabilities

---

## 14. Security Considerations

- All tokens encrypted at rest
- Messages containing sensitive data flagged
- Audit log for all Slack data access
- Retention policy: Keep messages 90 days, then archive
- User can request data deletion (GDPR)
- No storage of file attachments (just metadata)
