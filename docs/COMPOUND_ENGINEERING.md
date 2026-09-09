# Compound Engineering Integration

Server-side integration for Compound Engineering workflows with real-time interactive capabilities.

---

## Overview

Compound Engineering enables running Claude Code workflows (`/workflows:plan`, `/workflows:work`, `/workflows:review`, `/workflows:compound`, `/lfg`) from Slack, the dashboard, and MCP tools. When Claude needs user input via `AskUserQuestion`, the system broadcasts the question as a real-time popup to the dashboard and posts it to Slack, collecting responses and piping them back to the running process.

### Entry Points

1. **Slack @mention**: Trigger with `@bot run /workflows:plan plans/my-feature.md`
2. **Dashboard**: Use the command palette or agent trigger buttons
3. **MCP Tools**: External Claude Code sessions can trigger and respond via MCP

---

## Architecture

### Checkpoint-Resume Pattern

Instead of blocking queue workers waiting for user input (which would tie up workers for 60+ minutes), we use a **checkpoint-resume pattern**:

1. Agent runs until it needs user input
2. Job saves state (checkpoint) to the database
3. Job exits, freeing the worker
4. User responds via dashboard or Slack
5. New job dispatches to resume from checkpoint

This allows efficient worker utilization and supports long-running interactive sessions.

### Key Components

| Component | Purpose |
|-----------|---------|
| `InteractionRequest` | Model for storing pending questions from agents |
| `InteractiveClaudeRunner` | Service that runs Claude with stdin input via Symfony InputStream |
| `RunInteractiveAgentJob` | Queue job with checkpoint-resume for interactive sessions |
| `InteractionResponseController` | API endpoint for submitting responses with race protection |
| `InteractionModal.vue` | Dashboard popup for responding to agent questions |
| `useInteractionRealtime.ts` | Vue composable for real-time interaction state management |

---

## Database Schema

### interaction_requests

Stores questions from agents that need human responses.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `agent_run_id` | bigint | Foreign key to agent_runs |
| `question_type` | string | One of: `text`, `select`, `confirm` |
| `question_content` | text | The question being asked |
| `options` | json | Available options for select/confirm types |
| `context` | json | Additional context (header, Slack info, etc.) |
| `response` | text | User's response (null until answered) |
| `responded_at` | timestamp | When the response was submitted |
| `responded_via` | string | Channel: `dashboard`, `slack`, or `mcp` |
| `responded_by_id` | bigint | User who responded |
| `idempotency_key` | string | Prevents duplicate submissions |
| `expires_at` | timestamp | Deadline for response (default: 5 minutes) |

---

## API Endpoints

### List Pending Interactions

```
GET /api/interactions/pending
```

Returns pending interactions ordered by expiration time (soonest first).

**Response:**
```json
{
  "interactions": [
    {
      "id": 123,
      "run_id": 456,
      "agent_name": "Compound Engineering",
      "question_type": "select",
      "question": "Which approach should we use?",
      "options": [
        {"label": "Option A", "description": "First approach"},
        {"label": "Option B", "description": "Second approach"}
      ],
      "remaining_seconds": 245,
      "expires_at": "2026-01-19T12:05:00Z"
    }
  ]
}
```

### Get Interaction Details

```
GET /api/interactions/{interaction}
```

Returns full details of a specific interaction including response state.

### Submit Response

```
POST /api/interactions/{interaction}/respond
```

**Request:**
```json
{
  "response": "Option A",
  "idempotency_key": "client-generated-uuid"
}
```

**Response:**
```json
{
  "message": "Response recorded successfully",
  "interaction": {
    "id": 123,
    "response": "Option A",
    "responded_at": "2026-01-19T12:02:30Z",
    "responded_via": "dashboard",
    "is_responded": true
  }
}
```

**Error Cases:**
- `422 Unprocessable`: Interaction expired or validation failed
- Already responded returns `already_responded: true` with original response details

---

## MCP Tools

Three MCP tools enable agent-native access to interactive workflows:

### trigger-compound-engineering

Starts an interactive Compound Engineering workflow.

**Parameters:**
- `skill` (required): One of `workflows:plan`, `workflows:work`, `workflows:review`, `workflows:compound`, `lfg`
- `args` (optional): Instructions or file path for the skill
- `project_id` (optional): Associate with a project

### list-interaction-requests

Lists pending interaction requests from agents.

**Parameters:**
- `status` (optional): `pending`, `expired`, `responded`, or `all`
- `run_id` (optional): Filter to a specific agent run
- `limit` (optional): Max results (default: 20, max: 50)

### respond-to-interaction

Submit a response to a pending interaction.

**Parameters:**
- `interaction_id` (required): The interaction to respond to
- `response` (required): The response text

---

## Real-Time Broadcasting

### Events

| Event | Channel | Purpose |
|-------|---------|---------|
| `InteractionRequestCreated` | `user.{id}`, `agent-runs.{run_id}` | New question from agent |
| `InteractionResponseReceived` | `interactions`, `agent-runs.{run_id}` | Response submitted |
| `InteractionExpired` | `interactions`, `agent-runs.{run_id}` | Question timed out |

### Frontend State Machine

The `useInteractionRealtime` composable manages interaction state:

```
PENDING → SUBMITTING → SUBMITTED → (close modal)
    ↓                      ↓
EXPIRED           RESPONDED_ELSEWHERE
```

- **PENDING**: Awaiting user input
- **SUBMITTING**: Response being sent to server
- **SUBMITTED**: Response confirmed, resuming agent
- **EXPIRED**: Timeout reached, no response submitted
- **RESPONDED_ELSEWHERE**: Another tab/user responded first

Multi-tab coordination uses the `BroadcastChannel` API to close modals when responded elsewhere.

---

## Slack Integration

When an interaction is created, it's posted to the relevant Slack channel as an interactive message:

1. Question text with context header
2. For `select` type: Radio buttons with options
3. For `confirm` type: Yes/No buttons
4. For `text` type: Instructions to reply in thread

Responses from Slack:
1. Button clicks trigger the interaction callback endpoint
2. Response is validated and stored
3. Agent resumes with the response
4. Original Slack message is updated to show "Responded"

---

## Scheduled Commands

### Expire Pending Interactions

```bash
php artisan interactions:expire
```

Runs every minute (scheduled). Expires pending interactions that have passed their deadline and fails associated agent runs.

**Options:**
- `--dry-run`: Preview what would be expired
- `--slack`: Update Slack messages for expired interactions

---

## Configuration

### Queue Configuration

Interactive agent jobs run on a dedicated queue for isolation:

```php
// In RunInteractiveAgentJob constructor
$this->onQueue('interactive-agents');
```

Configure Horizon to handle this queue:

```php
'interactive-agents' => [
    'connection' => 'redis',
    'queue' => 'interactive-agents',
    'balance' => 'auto',
    'processes' => 3,
    'tries' => 1,
    'timeout' => 3600, // 60 minutes
],
```

### Timeout Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Interaction expiry | 5 minutes | Time before question expires |
| Job timeout | 60 minutes | Max time for agent execution |
| Max retries | 1 | No retries; checkpoints handle resume |

---

## Testing

### Running Tests

```bash
# Run all interaction-related tests
php artisan test --filter=Interaction

# Model tests
php artisan test tests/Unit/Models/InteractionRequestTest.php

# Controller tests
php artisan test tests/Feature/Controllers/Api/InteractionResponseControllerTest.php
```

### Factory States

The `InteractionRequestFactory` provides common states:

```php
// Question types
InteractionRequest::factory()->text()->create();
InteractionRequest::factory()->select()->create();
InteractionRequest::factory()->confirm()->create();

// Response states
InteractionRequest::factory()->pending()->create();
InteractionRequest::factory()->responded()->create();
InteractionRequest::factory()->expired()->create();

// With context
InteractionRequest::factory()->withSlackContext()->create();
```

---

## Troubleshooting

### Agent Run Stuck in "awaiting_input"

1. Check if there's a pending interaction:
   ```bash
   php artisan tinker
   > InteractionRequest::pending()->where('agent_run_id', $runId)->get()
   ```

2. If the interaction expired, the scheduled command should have failed the run. Check logs:
   ```bash
   php artisan interactions:expire --dry-run
   ```

3. Force-expire manually if needed:
   ```php
   $interaction->update(['expires_at' => now()->subMinute()]);
   (new ExpireInteractionRequestsCommand)->handle();
   ```

### Race Conditions

The system uses row-level locking (`lockForUpdate()`) inside transactions to prevent race conditions when multiple clients (dashboard tabs, Slack, MCP) attempt to respond simultaneously. Only the first response is accepted; others receive an `already_responded` status.

### Slack Messages Not Updating

1. Verify the Slack bot has proper permissions
2. Check that `--slack` flag is included in the scheduled command
3. Review logs for Slack API errors

---

## Security Considerations

### Authentication

All API endpoints require authentication via Laravel Sanctum/session.

### Authorization

- Users can only respond to interactions they have access to
- Channel-level authorization verifies user access to agent runs
- Slack responses are verified via Slack's request signing

### Rate Limiting

Response endpoints are rate-limited to prevent abuse:
- 60 requests/minute per user (standard API rate)

### Input Validation

- Response text limited to 10,000 characters
- Idempotency keys limited to 255 characters
- Question types strictly validated

---

## Related Documentation

- [Agentic Workflows](./AGENTIC_WORKFLOWS.md) - Best practices for agent design
- [Agents](./AGENTS.md) - Agent configuration and management
- [Slack AI Integration](./SLACK_AI_INTEGRATION.md) - Slack bot setup
- [MCP Server](./MCP_SERVER.md) - MCP tool configuration
