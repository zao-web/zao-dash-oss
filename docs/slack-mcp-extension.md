# Slack MCP Agentic Extension

The Zao Dashboard Slack integration enables natural language interactions with the dashboard through Slack. Users can create tasks, log notes, trigger agents, and manage approvals directly from Slack conversations.

## Quick Start

### Slash Commands

| Command | Description | Example |
|---------|-------------|---------|
| `/zao task [description]` | Create a new task | `/zao task Fix login bug on staging` |
| `/zao log [note]` | Log a client note | `/zao log Client approved new design` |
| `/zao agent [slug] [task]` | Trigger an AI agent | `/zao agent code-review Review the auth module` |
| `/zao status` | Show system status | `/zao status` |

### @Zao Mentions

Mention @Zao in any channel to interact naturally:

```
@Zao create a task to update the homepage hero section
@Zao what's the status of the Acme project?
@Zao run the brief-generator agent for next week's newsletter
@Zao search for tasks about authentication
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Slack                                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │ /zao cmd    │  │ @Zao mention│  │ Home Tab & Interactions │  │
│  └──────┬──────┘  └──────┬──────┘  └────────────┬────────────┘  │
└─────────┼────────────────┼──────────────────────┼───────────────┘
          │                │                      │
          ▼                ▼                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                   SlackWebhookController                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ • URL Verification    • Event Callbacks    • Block Actions  ││
│  │ • Slash Commands      • View Submissions                    ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
          │                │                      │
          ▼                ▼                      ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────────────┐
│ SlackApiService │ │ProcessSlackMen- │ │ SlackBotResponseService │
│ (API wrapper)   │ │tionJob (async)  │ │ (Block Kit formatting)  │
└─────────────────┘ └────────┬────────┘ └─────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              SlackIntentDetectionService                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ AI-powered intent detection using Claude                    ││
│  │ Fallback to regex patterns when AI unavailable              ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────┐
│              SlackMentionOrchestrator                            │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ • Execute detected intents                                  ││
│  │ • Create approval requests for sensitive operations         ││
│  │ • Manage thread context for multi-turn conversations        ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Intent Detection

The system detects 11 intents from natural language:

| Intent | Description | Requires Approval | Example |
|--------|-------------|-------------------|---------|
| `create_task` | Create a new task | No | "create a task to fix the login bug" |
| `log_note` | Log a client note | No | "log that client approved the design" |
| `trigger_agent` | Run an AI agent | Yes | "run the code-review agent" |
| `search` | Search dashboard data | No | "find tasks about authentication" |
| `get_status` | Get system status | No | "what's the status?" |
| `assign_task` | Assign task to user | No | "assign task 123 to John" |
| `update_task` | Update task details | No | "mark task 456 as complete" |
| `create_invoice` | Create an invoice | Yes | "create invoice for Acme Corp" |
| `delete` | Delete something | Yes | "delete task 789" |
| `help` | Show help message | No | "help" |
| `unknown` | Could not detect | No | (fallback) |

### Intent Parameters

Each intent extracts relevant parameters:

```json
{
  "intent": "create_task",
  "confidence": 0.92,
  "parameters": {
    "title": "Fix login bug on staging",
    "description": null,
    "project_id": null,
    "priority": "medium"
  },
  "reasoning": "User wants to create a new task about fixing a bug"
}
```

## Approval Workflow

Sensitive operations require explicit approval before execution.

### Flow

1. User requests sensitive action (e.g., "run the backup agent")
2. System detects intent and creates `PendingSlackApproval` record
3. Approval request posted to thread with Approve/Reject buttons
4. User clicks Approve or Reject
5. If approved, action is executed
6. Result posted to thread

### Approval Expiration

- Approvals expire after **60 minutes**
- Scheduler runs every 5 minutes to mark expired approvals
- Expired approvals cannot be approved

### Actions Requiring Approval

- `trigger_agent` - Running AI agents
- `create_invoice` - Creating invoices
- `delete` - Deleting any resource

## Home Tab

The Slack app Home Tab shows:

1. **Activity Summary** - Messages, intents, and response times
2. **Pending Approvals** - Actions awaiting approval with Review buttons
3. **Recent Tasks** - Latest tasks with status indicators
4. **Quick Actions** - Create task, view dashboard, check status

### Home Tab Buttons

| Button | Action |
|--------|--------|
| Review | Navigate to approval thread |
| Create Task | Open task creation modal |
| View Dashboard | Link to web dashboard |
| Check Status | Show workspace analytics |

## Thread Context

Multi-turn conversations are supported through thread context preservation:

```
User: @Zao what tasks are assigned to me?
Zao: Here are your 5 assigned tasks: [list]

User: @Zao mark the first one as complete
Zao: Done! Marked "Update documentation" as complete.
```

The system maintains context within Slack threads using the `SlackThreadContext` model.

## Setup

### Slack App Configuration

1. Create a Slack App at https://api.slack.com/apps
2. Enable the following features:

#### Bot Token Scopes
```
app_mentions:read
channels:history
channels:read
chat:write
commands
groups:history
groups:read
im:history
im:read
mpim:history
mpim:read
users:read
```

#### Event Subscriptions
```
app_home_opened
app_mention
message.channels
message.groups
message.im
message.mpim
```

#### Slash Commands
```
/zao - Interact with Zao Dashboard
```

#### Interactivity
Enable and set Request URL to: `https://your-domain.com/webhooks/slack/interactivity`

### Environment Variables

```env
SLACK_CLIENT_ID=your-client-id
SLACK_CLIENT_SECRET=your-client-secret
SLACK_SIGNING_SECRET=your-signing-secret
```

### OAuth Installation

1. User clicks "Add to Slack" button
2. OAuth flow creates `SlackWorkspace` record
3. Bot token stored encrypted in database
4. Workspace ready for use

## Database Models

### SlackWorkspace
Stores workspace credentials and bot tokens.

### SlackChannel
Links Slack channels to projects/clients.

### SlackThreadContext
Preserves conversation state for multi-turn interactions.

### PendingSlackApproval
Tracks pending approvals with:
- UUID `public_id` for secure references
- Status (pending, approved, rejected, executed, expired)
- 60-minute expiration
- Execution results

### SlackConversationAnalytic
Event tracking for analytics.

## Scheduler

The following scheduled task manages approvals:

```php
// routes/console.php
Schedule::command('slack:expire-approvals')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('slack-expire-approvals');
```

## Security

### UUID Approval IDs
Approval actions use UUIDs instead of sequential IDs to prevent guessing attacks.

### Database Locking
`lockForUpdate()` prevents race conditions when multiple users click approve simultaneously.

### Workspace Verification
All operations verify the request originates from a registered workspace.

### Request Signing
All incoming requests are verified using Slack's signing secret.

### Prompt Injection Mitigation
User input is sanitized before AI processing to prevent manipulation.

## Troubleshooting

### Bot not responding to mentions
1. Check Event Subscriptions are enabled
2. Verify `app_mention` event is subscribed
3. Check webhook URL is accessible
4. Review Laravel logs for errors

### Slash commands not working
1. Verify slash command is registered in Slack app
2. Check Request URL in slash command settings
3. Ensure channel has linked project/client

### Approvals not expiring
1. Verify scheduler is running: `php artisan schedule:list`
2. Check `slack:expire-approvals` command exists
3. Review Laravel logs for scheduler errors

### Home Tab not loading
1. Check `app_home_opened` event is subscribed
2. Verify bot has required scopes
3. Review Laravel logs for errors

### Intent detection failing
1. Check Claude API credentials
2. Verify AI service is responding
3. System falls back to regex patterns if AI fails

## API Reference

### Webhook Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/webhooks/slack` | POST | Event callbacks, slash commands |
| `/webhooks/slack/interactivity` | POST | Button clicks, modals |
| `/webhooks/slack/oauth/callback` | GET | OAuth installation |

### Artisan Commands

| Command | Purpose |
|---------|---------|
| `slack:expire-approvals` | Mark expired approvals as expired |

## Related Documentation

- [Slack Block Kit Builder](https://app.slack.com/block-kit-builder)
- [Slack Events API](https://api.slack.com/apis/connections/events-api)
- [Slack Interactivity](https://api.slack.com/interactivity)
