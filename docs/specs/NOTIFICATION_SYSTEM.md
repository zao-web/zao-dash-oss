# Dashboard Notification System Technical Spec

## Overview

A centralized notification system that surfaces signals from all integrations (Slack, Gmail, Calendar, etc.), agent activity, and approval requests. Real-time via WebSockets.

---

## 1. Notification Types

| Type | Source | Priority | Example |
|------|--------|----------|---------|
| `slack_signal` | Slack integration | Medium-High | "Action item detected in #client-channel" |
| `email_received` | Gmail integration | Medium | "New email from Client X" |
| `meeting_upcoming` | Calendar | High | "Meeting with Client X in 30 min" |
| `agent_started` | Agent system | Low | "DevAgent started for Task #123" |
| `agent_completed` | Agent system | Medium | "ContentCreatorAgent finished - review draft" |
| `agent_failed` | Agent system | High | "QAAgent failed - check logs" |
| `approval_needed` | Approval system | High | "DevAgent wants to deploy to production" |
| `task_created` | Task system | Low | "New task from Slack: Update banner" |
| `health_alert` | Client health | High | "Client X health dropped to 6.2" |
| `repeated_request` | Slack analysis | High | "Client Y asked about Z 3 times" |
| `document_found` | Drive integration | Low | "Found SOW for Client X - review" |

---

## 2. Database Schema

### `notifications` table
```sql
id                  BIGINT PRIMARY KEY
user_id             BIGINT (nullable - null = all users)
type                VARCHAR(50)
subtype             VARCHAR(50) nullable
title               VARCHAR(255)
body                TEXT nullable
priority            ENUM('low', 'medium', 'high', 'critical')
source_type         VARCHAR(50) (slack, gmail, agent, calendar, etc.)
source_id           VARCHAR(255) nullable (message_ts, email_id, agent_run_id)
source_url          VARCHAR(500) nullable (permalink to source)
client_id           BIGINT nullable
project_id          BIGINT nullable
actions             JSON nullable (available actions)
is_read             BOOLEAN default false
is_dismissed        BOOLEAN default false
read_at             TIMESTAMP nullable
dismissed_at        TIMESTAMP nullable
created_at          TIMESTAMP
expires_at          TIMESTAMP nullable
```

### `notification_preferences` table
```sql
user_id             BIGINT PRIMARY KEY
slack_signals       BOOLEAN default true
email_signals       BOOLEAN default true
agent_updates       BOOLEAN default true
approval_requests   BOOLEAN default true
task_updates        BOOLEAN default true
health_alerts       BOOLEAN default true
quiet_hours_start   TIME nullable
quiet_hours_end     TIME nullable
email_digest        ENUM('none', 'daily', 'weekly')
```

---

## 3. Real-Time Delivery

### WebSocket Channel
Using Laravel Reverb (already configured):

```javascript
// Frontend subscription
Echo.private(`notifications.${userId}`)
    .listen('NotificationReceived', (notification) => {
        // Add to notification dropdown
        // Update unread count
        // Show toast if high priority
    });
```

### Backend Event
```php
class NotificationReceived implements ShouldBroadcast
{
    public function __construct(
        public Notification $notification
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('notifications.' . $this->notification->user_id);
    }
}
```

---

## 4. UI Components

### Header Notification Bell
```
┌─────────────────────────────────────────────────┐
│  [Logo]  Dashboard  Clients  ...    🔔(3)  [User] │
└─────────────────────────────────────────────────┘
                                        │
                                        ▼
                        ┌─────────────────────────┐
                        │ Notifications           │
                        ├─────────────────────────┤
                        │ 🔴 Approval needed      │
                        │   DevAgent → production │
                        │   2 min ago    [Review] │
                        ├─────────────────────────┤
                        │ 💬 Slack signal         │
                        │   Action item: Update   │
                        │   banner by Friday      │
                        │   15 min ago  [Create]  │
                        ├─────────────────────────┤
                        │ 📧 Email from Client X  │
                        │   Re: Project timeline  │
                        │   1 hour ago   [View]   │
                        ├─────────────────────────┤
                        │ [Mark all read]  [Settings]│
                        └─────────────────────────┘
```

### Toast Notifications
For high-priority items, show a toast in bottom-right:
```
┌──────────────────────────────┐
│ ⚠️ Approval Required          │
│ DevAgent wants to deploy     │
│ [Review Now]  [Dismiss]      │
└──────────────────────────────┘
```

### Notification Center Page
Full-page view at `/notifications`:
- Filter by type, priority, read/unread
- Bulk actions (mark read, dismiss)
- Search notifications
- Grouped by day

---

## 5. Actions System

Notifications can have action buttons:

```json
{
  "actions": [
    {
      "label": "Review",
      "action": "navigate",
      "url": "/approvals/123"
    },
    {
      "label": "Create Task",
      "action": "api",
      "method": "POST",
      "endpoint": "/api/tasks",
      "payload": { "from_notification": 123 }
    },
    {
      "label": "Dismiss",
      "action": "dismiss"
    },
    {
      "label": "View in Slack",
      "action": "external",
      "url": "https://slack.com/..."
    }
  ]
}
```

---

## 6. Priority & Behavior

| Priority | Bell Badge | Toast | Sound | Email Digest |
|----------|------------|-------|-------|--------------|
| Critical | Red dot | Yes, persistent | Yes | Immediate |
| High | Red count | Yes, 10s | Optional | Included |
| Medium | Blue count | No | No | Included |
| Low | Gray count | No | No | Optional |

---

## 7. Notification Creation Helpers

### Service Class
```php
class NotificationService
{
    public function notify(
        string $type,
        string $title,
        ?string $body = null,
        string $priority = 'medium',
        ?int $userId = null,
        ?int $clientId = null,
        array $actions = [],
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $sourceUrl = null,
    ): Notification;

    public function slackSignal(string $title, string $body, array $source): Notification;
    public function agentStarted(AgentRun $run): Notification;
    public function agentCompleted(AgentRun $run): Notification;
    public function approvalNeeded(ApprovalRequest $approval): Notification;
    public function healthAlert(Client $client, float $newScore): Notification;
}
```

---

## 8. Digest Emails

For users who prefer email:
- Daily digest at 8am: All unread notifications from past 24h
- Weekly digest on Monday: Summary of week's activity
- Immediate email for critical items only

---

## 9. Implementation Order

1. **Database migration** - notifications + preferences tables
2. **Notification model + service** - Creation helpers
3. **WebSocket event** - Real-time broadcast
4. **Header bell component** - Dropdown UI
5. **Toast component** - High-priority popups
6. **Notification center page** - Full list view
7. **Preferences UI** - User settings
8. **Email digests** - Scheduled job
9. **Integration hooks** - Connect to Slack, Gmail, agents

---

## 10. API Endpoints

```
GET    /api/notifications              - List notifications (paginated)
GET    /api/notifications/unread-count - Get unread count
POST   /api/notifications/{id}/read    - Mark as read
POST   /api/notifications/{id}/dismiss - Dismiss
POST   /api/notifications/mark-all-read
DELETE /api/notifications/{id}         - Delete
GET    /api/notification-preferences   - Get preferences
PUT    /api/notification-preferences   - Update preferences
```
